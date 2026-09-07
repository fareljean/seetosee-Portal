<?php
declare(strict_types=1);

namespace SeeToSee;

use PDO;
use PDOException;

final class Auth
{
    public static function register(array $input): void
    {
        $name = Validation::string($input, 'name', 2, 120);
        $email = Validation::email($input, 'email');
        $password = Validation::password($input);
        if (($input['terms_accepted'] ?? false) !== true) {
            throw new ApiException(422, 'validation_failed', 'You must accept the account terms.', ['terms_accepted' => 'Required.']);
        }
        RateLimiter::assertAllowed('register', $email, 5, 3600);

        $pdo = Database::connection();
        $existingStatement = $pdo->prepare('SELECT id, name, email, email_verified_at, status FROM users WHERE normalized_email = ? OR pending_email = ? LIMIT 1');
        $existingStatement->execute([$email, $email]);
        $existing = $existingStatement->fetch();
        if (is_array($existing)) {
            RateLimiter::record('register', $email, false);
            if ($existing['email_verified_at'] === null && $existing['status'] !== 'deleted') {
                self::createVerificationToken((int) $existing['id'], (string) $existing['name'], (string) $existing['email'], 'registration');
            }
            return;
        }

        $tokenData = Database::transaction(function (PDO $pdo) use ($name, $email, $password): array {
            $publicId = strtoupper(bin2hex(random_bytes(13)));
            $statement = $pdo->prepare('INSERT INTO users (public_id, name, email, normalized_email, password_hash, status, notification_email, joined_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
            try {
                $statement->execute([$publicId, $name, $email, $email, Security::passwordHash($password), 'pending_verification', $email]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    return [];
                }
                throw $exception;
            }
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO memberships (user_id, level, status, source, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')->execute([$userId, 'free', 'active', 'registration']);
            $token = Security::randomToken();
            $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, purpose, target_email, expires_at, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 24 HOUR, UTC_TIMESTAMP(6))')->execute([$userId, Security::tokenHash($token), 'registration', $email]);
            Audit::log($userId, 'account.registered');
            return ['token' => $token, 'user_id' => $userId];
        });

        RateLimiter::record('register', $email, $tokenData !== []);
        if ($tokenData !== []) {
            Mailer::verifyEmail($name, $email, $tokenData['token']);
        }
    }

    public static function login(array $input): array
    {
        $email = Validation::email($input, 'email');
        $password = is_string($input['password'] ?? null) ? (string) $input['password'] : '';
        if ($password === '' || strlen($password) > 1024) {
            throw new ApiException(401, 'invalid_credentials', 'Email or password is incorrect.');
        }
        RateLimiter::assertAllowed('login', $email, 10, 900);

        $statement = Database::connection()->prepare('SELECT * FROM users WHERE normalized_email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();
        $valid = is_array($user) && $user['status'] !== 'deleted' && password_verify($password, (string) $user['password_hash']);
        RateLimiter::record('login', $email, $valid);
        if (!$valid) {
            usleep(random_int(120000, 260000));
            throw new ApiException(401, 'invalid_credentials', 'Email or password is incorrect.');
        }
        if ($user['email_verified_at'] === null || $user['status'] === 'pending_verification') {
            throw new ApiException(403, 'email_not_verified', 'Verify your email before signing in.');
        }
        if ($user['status'] !== 'active') {
            throw new ApiException(403, 'account_unavailable', 'This account is not available.');
        }

        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            Database::connection()->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([Security::passwordHash($password), $user['id']]);
        }
        Database::connection()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$user['id']]);
        self::revokeCookieSession();
        $session = self::issueSession((int) $user['id']);
        Audit::log((int) $user['id'], 'account.login');
        return ['member' => self::memberData((int) $user['id']), 'csrf_token' => $session['csrf_token']];
    }

    public static function logout(array $session): void
    {
        Database::connection()->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$session['session_id']]);
        Audit::log((int) $session['user_id'], 'account.logout');
        Security::clearCookie(Env::get('SESSION_COOKIE', 'sts_session') ?? 'sts_session');
        Security::clearCookie('sts_csrf', false);
    }

    public static function currentSession(bool $required = true): ?array
    {
        $cookieName = Env::get('SESSION_COOKIE', 'sts_session') ?? 'sts_session';
        $token = (string) ($_COOKIE[$cookieName] ?? '');
        if ($token === '') {
            if ($required) {
                throw new ApiException(401, 'authentication_required', 'Sign in to continue.');
            }
            return null;
        }
        $statement = Database::connection()->prepare('SELECT s.id AS session_id, s.user_id, s.csrf_token, s.last_seen_at, s.expires_at, s.revoked_at, u.status, u.email_verified_at FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? LIMIT 1');
        $statement->execute([Security::tokenHash($token)]);
        $session = $statement->fetch();
        $idleCutoff = time() - max(300, Env::int('SESSION_IDLE_MINUTES', 120) * 60);
        if (!is_array($session) || $session['revoked_at'] !== null || strtotime((string) $session['expires_at']) <= time() || strtotime((string) $session['last_seen_at']) <= $idleCutoff || $session['status'] !== 'active' || $session['email_verified_at'] === null) {
            self::revokeCookieSession();
            if ($required) {
                throw new ApiException(401, 'session_expired', 'Your session expired. Sign in again.');
            }
            return null;
        }
        if (strtotime((string) $session['last_seen_at']) < time() - 300) {
            Database::connection()->prepare('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$session['session_id']]);
        }
        return $session;
    }

    public static function memberData(int $userId): array
    {
        $statement = Database::connection()->prepare('SELECT u.id, u.public_id, u.name, u.email, u.email_verified_at, u.status, u.notification_email, u.destination_url, u.profile_settings, u.joined_at, u.last_login_at, m.level AS membership_level, m.status AS membership_status, m.trial_ends_at, m.current_period_end, m.cancel_at_period_end FROM users u JOIN memberships m ON m.user_id = u.id WHERE u.id = ? LIMIT 1');
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new ApiException(404, 'member_not_found', 'Member not found.');
        }
        $prefixStatement = Database::connection()->prepare('SELECT handle, suffix, display_identity, destination_url, approved_at, reservation_started_at, reserved_until, reservation_status FROM prefix_identities WHERE user_id = ? LIMIT 1');
        $prefixStatement->execute([$userId]);
        $stripeStatement = Database::connection()->prepare('SELECT c.stripe_customer_id, s.stripe_subscription_id, s.status AS subscription_status FROM stripe_customers c LEFT JOIN stripe_subscriptions s ON s.user_id = c.user_id WHERE c.user_id = ? ORDER BY s.updated_at DESC LIMIT 1');
        $stripeStatement->execute([$userId]);
        $stripe = $stripeStatement->fetch() ?: null;
        $user['id'] = (int) $user['id'];
        $user['email_verified'] = $user['email_verified_at'] !== null;
        $user['cancel_at_period_end'] = (bool) $user['cancel_at_period_end'];
        $user['profile_settings'] = $user['profile_settings'] ? json_decode((string) $user['profile_settings'], true) : new \stdClass();
        $user['prefix'] = $prefixStatement->fetch() ?: null;
        $user['stripe'] = $stripe;
        return $user;
    }

    public static function requestPasswordReset(array $input): void
    {
        $email = Validation::email($input, 'email');
        RateLimiter::assertAllowed('password_reset', $email, 5, 3600);
        $statement = Database::connection()->prepare("SELECT id, name, email FROM users WHERE normalized_email = ? AND status IN ('active','pending_verification') LIMIT 1");
        $statement->execute([$email]);
        $user = $statement->fetch();
        RateLimiter::record('password_reset', $email, is_array($user));
        if (!is_array($user)) {
            return;
        }
        $token = Security::randomToken();
        Database::transaction(function (PDO $pdo) use ($user, $token): void {
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, UTC_TIMESTAMP(6) + INTERVAL 24 HOUR, UTC_TIMESTAMP(6))')->execute([$user['id'], Security::tokenHash($token)]);
        });
        Mailer::passwordReset((string) $user['name'], (string) $user['email'], $token);
        Audit::log((int) $user['id'], 'password.reset_requested');
    }

    public static function resetPassword(array $input): void
    {
        $token = Validation::string($input, 'token', 20, 200);
        $password = Validation::password($input, 'new_password');
        RateLimiter::assertAllowed('password_reset_complete', Security::tokenHash($token), 10, 3600);
        $statement = Database::connection()->prepare('SELECT pr.id, pr.user_id FROM password_reset_tokens pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > UTC_TIMESTAMP(6) AND u.status <> ? LIMIT 1');
        $statement->execute([Security::tokenHash($token), 'deleted']);
        $reset = $statement->fetch();
        RateLimiter::record('password_reset_complete', Security::tokenHash($token), is_array($reset));
        if (!is_array($reset)) {
            throw new ApiException(400, 'invalid_reset_token', 'This reset link is invalid or expired.');
        }
        Database::transaction(function (PDO $pdo) use ($reset, $password): void {
            $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([Security::passwordHash($password), $reset['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$reset['id']]);
            $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND revoked_at IS NULL')->execute([$reset['user_id']]);
        });
        Audit::log((int) $reset['user_id'], 'password.reset_completed');
    }

    public static function verifyEmailToken(string $token): bool
    {
        if (strlen($token) < 20 || strlen($token) > 200) {
            return false;
        }

        $result = Database::transaction(function (PDO $pdo) use ($token): array {
            $statement = $pdo->prepare('SELECT ev.id, ev.user_id, ev.purpose, ev.target_email, ev.used_at, (ev.expires_at > UTC_TIMESTAMP(6)) AS is_unexpired, u.name, u.email, u.email_verified_at, u.status FROM email_verification_tokens ev JOIN users u ON u.id = ev.user_id WHERE ev.token_hash = ? AND u.status <> ? LIMIT 1 FOR UPDATE');
            $statement->execute([Security::tokenHash($token), 'deleted']);
            $verification = $statement->fetch();
            if (!is_array($verification)) {
                return ['ok' => false];
            }

            $alreadyVerified = $verification['email_verified_at'] !== null
                && $verification['status'] === 'active'
                && ($verification['purpose'] !== 'email_change' || strtolower((string) $verification['email']) === strtolower((string) $verification['target_email']));

            // Verification is idempotent: reopening the same successful link still reports success.
            if ($verification['used_at'] !== null) {
                return ['ok' => $alreadyVerified, 'newly_verified' => false];
            }
            if ((int) $verification['is_unexpired'] !== 1) {
                return ['ok' => false];
            }

            // One successful token completes the purpose and retires every other outstanding token for it.
            $pdo->prepare('UPDATE email_verification_tokens SET used_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$verification['user_id'], $verification['purpose']]);
            if ($verification['purpose'] === 'email_change') {
                $pdo->prepare('UPDATE users SET email = ?, normalized_email = ?, pending_email = NULL, email_verified_at = UTC_TIMESTAMP(6), status = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$verification['target_email'], strtolower((string) $verification['target_email']), 'active', $verification['user_id']]);
            } else {
                $pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP(6), status = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute(['active', $verification['user_id']]);
            }

            return [
                'ok' => true,
                'newly_verified' => true,
                'user_id' => (int) $verification['user_id'],
                'name' => (string) $verification['name'],
                'email' => (string) $verification['target_email'],
                'purpose' => (string) $verification['purpose'],
            ];
        });

        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        if (($result['newly_verified'] ?? false) !== true) {
            return true;
        }

        // The account is already committed as verified. Secondary logging/email must never undo that success.
        try {
            Audit::log((int) $result['user_id'], 'email.verified', ['purpose' => $result['purpose']]);
        } catch (\Throwable $exception) {
            error_log('Email verification audit write failed after account activation.');
        }
        try {
            Mailer::welcome((string) $result['name'], (string) $result['email']);
        } catch (\Throwable $exception) {
            error_log('Welcome email delivery failed after successful account verification.');
        }
        try {
            Mailer::verifiedMemberAlert(
                (string) $result['name'],
                (string) $result['email'],
                (string) $result['purpose']
            );
        } catch (\Throwable $exception) {
            error_log('Admin verification notification failed after successful account verification.');
        }
        return true;
    }

    public static function resendVerification(array $input): void
    {
        $email = Validation::email($input, 'email');
        RateLimiter::assertAllowed('resend_verification', $email, 4, 3600);
        $statement = Database::connection()->prepare("SELECT id, name, email FROM users WHERE normalized_email = ? AND email_verified_at IS NULL AND status = 'pending_verification' LIMIT 1");
        $statement->execute([$email]);
        $user = $statement->fetch();
        RateLimiter::record('resend_verification', $email, is_array($user));
        if (is_array($user)) {
            self::createVerificationToken((int) $user['id'], (string) $user['name'], (string) $user['email'], 'registration');
        }
    }

    public static function changePassword(array $session, array $input): void
    {
        $current = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
        $new = Validation::password($input, 'new_password');
        $statement = Database::connection()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$session['user_id']]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($current, $hash)) {
            throw new ApiException(422, 'current_password_incorrect', 'Current password is incorrect.');
        }
        Database::transaction(function (PDO $pdo) use ($session, $new): void {
            $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([Security::passwordHash($new), $session['user_id']]);
            $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND id <> ? AND revoked_at IS NULL')->execute([$session['user_id'], $session['session_id']]);
        });
        Audit::log((int) $session['user_id'], 'password.changed');
    }

    public static function updateProfile(array $session, array $input): array
    {
        $name = Validation::string($input, 'name', 2, 120);
        $notification = Validation::email($input, 'notification_email', false);
        $destination = isset($input['destination_url']) && trim((string) $input['destination_url']) !== '' ? Security::safeDestination((string) $input['destination_url']) : null;
        $settings = isset($input['profile_settings']) && is_array($input['profile_settings']) ? $input['profile_settings'] : [];
        $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($settingsJson === false || strlen($settingsJson) > 10000) {
            throw new ApiException(422, 'invalid_settings', 'Profile settings are too large.');
        }
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET name = ?, notification_email = ?, destination_url = ?, profile_settings = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$name, $notification, $destination, $settingsJson, $session['user_id']]);

        if (isset($input['email']) && strtolower(trim((string) $input['email'])) !== '') {
            $newEmail = Validation::email($input, 'email');
            $member = self::memberData((int) $session['user_id']);
            if ($newEmail !== strtolower((string) $member['email'])) {
                $password = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
                $hashStatement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $hashStatement->execute([$session['user_id']]);
                if (!password_verify($password, (string) $hashStatement->fetchColumn())) {
                    throw new ApiException(422, 'current_password_incorrect', 'Current password is required to change email.');
                }
                $duplicate = $pdo->prepare('SELECT 1 FROM users WHERE (normalized_email = ? OR pending_email = ?) AND id <> ? LIMIT 1');
                $duplicate->execute([$newEmail, $newEmail, $session['user_id']]);
                if ($duplicate->fetchColumn()) {
                    throw new ApiException(409, 'email_unavailable', 'That email cannot be used.');
                }
                $pdo->prepare('UPDATE users SET pending_email = ?, email_verified_at = NULL, status = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$newEmail, 'pending_verification', $session['user_id']]);
                self::createVerificationToken((int) $session['user_id'], $name, $newEmail, 'email_change');
                Mailer::emailChanged($name, (string) $member['email'], $newEmail);
                $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE user_id = ?')->execute([$session['user_id']]);
                Security::clearCookie(Env::get('SESSION_COOKIE', 'sts_session') ?? 'sts_session');
                Audit::log((int) $session['user_id'], 'email.change_requested');
                return ['reauthentication_required' => true];
            }
        }
        Audit::log((int) $session['user_id'], 'profile.updated');
        return ['member' => self::memberData((int) $session['user_id'])];
    }

    public static function deleteAccount(array $session, array $input): void
    {
        if (($input['confirmation'] ?? '') !== 'DELETE') {
            throw new ApiException(422, 'confirmation_required', 'Type DELETE to confirm account deletion.');
        }
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $statement = Database::connection()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$session['user_id']]);
        if (!password_verify($password, (string) $statement->fetchColumn())) {
            throw new ApiException(422, 'current_password_incorrect', 'Password is incorrect.');
        }
        $activeSubscription = Database::connection()->prepare("SELECT 1 FROM stripe_subscriptions WHERE user_id = ? AND status IN ('active','trialing','past_due','unpaid') LIMIT 1");
        $activeSubscription->execute([$session['user_id']]);
        if ($activeSubscription->fetchColumn()) {
            throw new ApiException(409, 'cancel_membership_first', 'Cancel the active Stripe membership in Billing before deleting this account.');
        }
        Database::transaction(function (PDO $pdo) use ($session): void {
            $userId = (int) $session['user_id'];
            $tombstone = 'deleted+' . $userId . '+' . bin2hex(random_bytes(8)) . '@invalid.local';
            $pdo->prepare('DELETE FROM prefix_identities WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('UPDATE users SET name = ?, email = ?, normalized_email = ?, pending_email = NULL, password_hash = ?, notification_email = NULL, destination_url = NULL, profile_settings = NULL, email_verified_at = NULL, status = ?, deleted_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute(['Deleted Member', $tombstone, $tombstone, Security::passwordHash(Security::randomToken(48)), 'deleted', $userId]);
        });
        Audit::log(null, 'account.deleted', ['former_user_id' => (int) $session['user_id']]);
        Security::clearCookie(Env::get('SESSION_COOKIE', 'sts_session') ?? 'sts_session');
        Security::clearCookie('sts_csrf', false);
    }

    public static function hasUnlimitedAccess(int $userId): bool
    {
        $statement = Database::connection()->prepare("SELECT 1 FROM memberships WHERE user_id = ? AND level = 'unlimited' AND status IN ('active','trialing') AND (current_period_end IS NULL OR current_period_end > UTC_TIMESTAMP(6)) LIMIT 1");
        $statement->execute([$userId]);
        return (bool) $statement->fetchColumn();
    }

    private static function issueSession(int $userId): array
    {
        $token = Security::randomToken();
        $csrf = Security::randomToken();
        $days = max(1, min(90, Env::int('SESSION_LIFETIME_DAYS', 30)));
        $expires = time() + ($days * 86400);
        $statement = Database::connection()->prepare('INSERT INTO user_sessions (user_id, token_hash, csrf_token, ip_hash, user_agent_hash, last_seen_at, expires_at, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL ? DAY, UTC_TIMESTAMP(6))');
        $statement->execute([
            $userId,
            Security::tokenHash($token),
            $csrf,
            hash_hmac('sha256', Http::clientIp(), Env::require('APP_KEY')),
            hash('sha256', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000)),
            $days,
        ]);
        $cookieName = Env::get('SESSION_COOKIE', 'sts_session') ?? 'sts_session';
        Security::setCookie($cookieName, $token, $expires, true);
        Security::issueCsrf($csrf);
        return ['csrf_token' => $csrf, 'expires_at' => gmdate('c', $expires)];
    }

    private static function revokeCookieSession(): void
    {
        $cookieName = Env::get('SESSION_COOKIE', 'sts_session') ?? 'sts_session';
        $token = (string) ($_COOKIE[$cookieName] ?? '');
        if ($token !== '') {
            Database::connection()->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE token_hash = ? AND revoked_at IS NULL')->execute([Security::tokenHash($token)]);
        }
        Security::clearCookie($cookieName);
    }

    private static function createVerificationToken(int $userId, string $name, string $email, string $purpose): void
    {
        $token = Security::randomToken();
        Database::transaction(function (PDO $pdo) use ($userId, $email, $purpose, $token): void {
            // Keep still-valid resend links usable until one of them succeeds; only expire dead tokens here.
            $pdo->prepare('UPDATE email_verification_tokens SET used_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at <= UTC_TIMESTAMP(6)')->execute([$userId, $purpose]);
            $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, purpose, target_email, expires_at, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 24 HOUR, UTC_TIMESTAMP(6))')->execute([$userId, Security::tokenHash($token), $purpose, $email]);
        });
        Mailer::verifyEmail($name, $email, $token);
    }
}
