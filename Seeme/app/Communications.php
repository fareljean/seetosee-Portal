<?php
declare(strict_types=1);

namespace SeeToSee;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Server-side Communications Control Center.
 *
 * Operator identity comes from a private environment allowlist. The browser
 * may display the feature, but every read and write is authorized here.
 */
final class Communications
{
    public static function requireOperator(array $session): array
    {
        $member = Auth::memberData((int) $session['user_id']);
        if (!self::isOperator($member)) {
            throw new ApiException(403, 'communications_forbidden', 'Communications Control Center access is not enabled for this account.');
        }
        return $member;
    }

    public static function isOperator(array $member): bool
    {
        if (($member['status'] ?? '') !== 'active' || ($member['email_verified'] ?? false) !== true) {
            return false;
        }
        $email = strtolower(trim((string) ($member['email'] ?? '')));
        return $email !== '' && in_array($email, self::operatorEmails(), true);
    }

    public static function operatorEmails(): array
    {
        $emails = [];
        foreach (Env::csv('CONTROL_CENTER_OPERATOR_EMAILS') as $candidate) {
            $email = strtolower(trim($candidate));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = true;
            }
        }
        return array_keys($emails);
    }

    public static function dashboard(): array
    {
        $pdo = Database::connection();
        $latest = self::latestCampaign($pdo);
        $campaignId = isset($_GET['campaign_id']) && ctype_digit((string) $_GET['campaign_id'])
            ? (int) $_GET['campaign_id']
            : (int) ($latest['id'] ?? 0);
        $selected = $campaignId > 0 ? self::campaignById($pdo, $campaignId) : null;
        if ($selected !== null) {
            self::refreshCampaignCounts($pdo, (int) $selected['id']);
            $selected = self::campaignById($pdo, (int) $selected['id']);
        }

        $memberCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND email_verified_at IS NOT NULL AND deleted_at IS NULL AND email <> ''")->fetchColumn();
        $schedule = $pdo->query('SELECT id, enabled, day_of_week, send_hour_utc, last_run_at, next_run_at FROM communications_schedule WHERE id = 1 LIMIT 1')->fetch() ?: null;
        $test = self::latestOperatorTest($pdo);

        return [
            'operator' => true,
            'operator_recipient_count' => count(self::operatorEmails()),
            'operator_test' => [
                'campaign_id' => $test !== null ? (int) $test['id'] : null,
                'status' => $test['status'] ?? 'not_started',
                'sent' => (int) ($test['sent_count'] ?? 0),
                'confirmed' => (int) ($test['confirmed_count'] ?? 0),
                'failed' => (int) ($test['failed_count'] ?? 0),
                'pending' => (int) ($test['pending_count'] ?? 0),
                'complete' => self::operatorTestComplete($pdo),
            ],
            'verified_active_member_count' => $memberCount,
            'weekly' => $schedule ? [
                'enabled' => (bool) $schedule['enabled'],
                'day_of_week' => (int) $schedule['day_of_week'],
                'send_hour_utc' => (int) $schedule['send_hour_utc'],
                'last_run_at' => $schedule['last_run_at'],
                'next_run_at' => $schedule['next_run_at'],
            ] : null,
            'selected_campaign' => $selected ? self::campaignView($pdo, $selected) : null,
            'history' => self::history($pdo),
        ];
    }

    public static function sendTest(int $userId, array $input): array
    {
        $subject = self::text($input['subject'] ?? null, 'subject', 1, 200);
        $body = self::text($input['body'] ?? null, 'body', 1, 20000);
        $recipients = self::operatorEmails();
        if (count($recipients) !== 3) {
            throw new ApiException(500, 'communications_configuration_incomplete', 'The three private operator recipients are not configured on the server.');
        }
        RateLimiter::assertAllowed('communications_test', (string) $userId, 3, 3600);

        $campaign = self::createCampaign($userId, 'operator_test', 'Operator delivery test', $subject, $body, 'Three approved operators');
        $results = [];
        foreach ($recipients as $email) {
            $user = self::userByEmail($email);
            $delivery = self::createDelivery($campaign['id'], $user, $email);
            $receipt = self::createReceipt($delivery['id']);
            $results[] = self::deliver($campaign, $delivery, $receipt, $body);
        }
        self::refreshCampaignCounts(Database::connection(), (int) $campaign['id']);
        Audit::log($userId, 'communications.operator_test_sent', ['campaign_id' => $campaign['public_id']]);
        return [
            'campaign' => self::campaignView(Database::connection(), self::campaignById(Database::connection(), (int) $campaign['id'])),
            'results' => $results,
            'operator_test_complete' => self::operatorTestComplete(Database::connection()),
        ];
    }

    public static function sendMemberCampaign(int $userId, array $input): array
    {
        self::assertOperatorTestComplete(Database::connection());
        $title = self::text($input['title'] ?? null, 'title', 1, 200);
        $subject = self::text($input['subject'] ?? null, 'subject', 1, 200);
        $body = self::text($input['body'] ?? null, 'body', 1, 20000);
        RateLimiter::assertAllowed('communications_campaign', (string) $userId, 1, 1800);
        return self::sendMemberCampaignInternal($userId, $title, $subject, $body, 'Verified active members');
    }

    public static function setWeekly(int $userId, string $action): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['pause', 'resume'], true)) {
            throw new ApiException(422, 'invalid_weekly_action', 'Choose pause or resume.');
        }
        $pdo = Database::connection();
        if ($action === 'resume') {
            self::assertOperatorTestComplete($pdo);
        }
        $schedule = $pdo->query('SELECT day_of_week, send_hour_utc FROM communications_schedule WHERE id = 1 LIMIT 1')->fetch();
        if (!is_array($schedule)) {
            throw new ApiException(500, 'communications_schedule_missing', 'The weekly schedule has not been installed.');
        }
        $enabled = $action === 'resume' ? 1 : 0;
        $next = $enabled === 1 ? self::nextWeeklyRun((int) $schedule['day_of_week'], (int) $schedule['send_hour_utc']) : null;
        $statement = $pdo->prepare('UPDATE communications_schedule SET enabled = ?, next_run_at = ?, updated_by = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = 1');
        $statement->execute([$enabled, $next, $userId,]);
        Audit::log($userId, 'communications.weekly_' . $action);
        return self::dashboard()['weekly'];
    }

    public static function confirmReceipt(string $token): array
    {
        $token = trim($token);
        if (strlen($token) < 20 || strlen($token) > 200) {
            throw new ApiException(400, 'invalid_receipt_token', 'This receipt link is invalid or expired.');
        }
        RateLimiter::assertAllowed('communications_receipt', Security::tokenHash($token), 10, 3600);
        $pdo = Database::connection();
        $result = Database::transaction(function (PDO $pdo) use ($token): array {
            $statement = $pdo->prepare('SELECT r.id AS receipt_id, r.delivery_id, r.used_at, r.expires_at, d.user_id, d.recipient_email, c.id AS campaign_id, c.public_id, c.title FROM communications_receipts r JOIN communications_deliveries d ON d.id = r.delivery_id JOIN communications_campaigns c ON c.id = d.campaign_id WHERE r.token_hash = ? LIMIT 1 FOR UPDATE');
            $statement->execute([Security::tokenHash($token)]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                return ['ok' => false];
            }
            if ($row['used_at'] !== null) {
                return ['ok' => true, 'already_confirmed' => true, 'row' => $row];
            }
            if (strtotime((string) $row['expires_at']) <= time()) {
                return ['ok' => false];
            }
            $pdo->prepare('UPDATE communications_receipts SET used_at = UTC_TIMESTAMP(6) WHERE id = ? AND used_at IS NULL')->execute([$row['receipt_id']]);
            $pdo->prepare("UPDATE communications_deliveries SET status = 'confirmed', confirmed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$row['delivery_id']]);
            return ['ok' => true, 'already_confirmed' => false, 'row' => $row];
        });
        if (($result['ok'] ?? false) !== true) {
            RateLimiter::record('communications_receipt', Security::tokenHash($token), false);
            throw new ApiException(400, 'invalid_receipt_token', 'This receipt link is invalid or expired.');
        }
        RateLimiter::record('communications_receipt', Security::tokenHash($token), true);
        $row = $result['row'];
        self::refreshCampaignCounts($pdo, (int) $row['campaign_id']);
        Audit::log($row['user_id'] !== null ? (int) $row['user_id'] : null, 'communications.receipt_confirmed', [
            'campaign_id' => $row['public_id'],
            'already_confirmed' => (bool) $result['already_confirmed'],
        ]);
        return [
            'confirmed' => true,
            'already_confirmed' => (bool) $result['already_confirmed'],
            'campaign_title' => (string) $row['title'],
        ];
    }

    /** Run from a private server cron. It never runs before the operator test is complete. */
    public static function runWeeklyIfDue(): array
    {
        $pdo = Database::connection();
        $schedule = $pdo->query('SELECT * FROM communications_schedule WHERE id = 1 LIMIT 1')->fetch();
        if (!is_array($schedule) || !(bool) $schedule['enabled']) {
            return ['ran' => false, 'reason' => 'paused'];
        }
        if ($schedule['next_run_at'] !== null && strtotime((string) $schedule['next_run_at']) > time()) {
            return ['ran' => false, 'reason' => 'not_due', 'next_run_at' => $schedule['next_run_at']];
        }
        if (!self::operatorTestComplete($pdo)) {
            $pdo->prepare('UPDATE communications_schedule SET enabled = 0, next_run_at = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = 1')->execute();
            error_log('Communications weekly run paused because the operator receipt test is incomplete.');
            return ['ran' => false, 'reason' => 'operator_test_incomplete'];
        }

        $next = self::nextWeeklyRun((int) $schedule['day_of_week'], (int) $schedule['send_hour_utc']);
        $pdo->prepare('UPDATE communications_schedule SET last_run_at = UTC_TIMESTAMP(6), next_run_at = ? WHERE id = 1')->execute([$next]);
        $result = self::sendMemberCampaignInternal(null, 'Weekly SeeToSee checkup', 'SeeToSee weekly checkup', 'This is your weekly SeeToSee checkup. Confirm receipt so the Communications Control Center can record that this message reached your mailbox.', 'Verified active members');
        return ['ran' => true, 'campaign' => $result['campaign']];
    }

    private static function sendMemberCampaignInternal(?int $userId, string $title, string $subject, string $body, string $audience): array
    {
        $pdo = Database::connection();
        $campaign = self::createCampaign($userId, $userId === null ? 'weekly' : 'member', $title, $subject, $body, $audience);
        $members = $pdo->query("SELECT id, name, email FROM users WHERE status = 'active' AND email_verified_at IS NOT NULL AND deleted_at IS NULL AND email <> '' ORDER BY id ASC")->fetchAll();
        if (!is_array($members) || $members === []) {
            $pdo->prepare("UPDATE communications_campaigns SET status = 'completed', completed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$campaign['id']]);
            return ['campaign' => self::campaignView($pdo, self::campaignById($pdo, (int) $campaign['id'])), 'results' => []];
        }
        $results = [];
        foreach ($members as $member) {
            $delivery = self::createDelivery($campaign['id'], $member, strtolower((string) $member['email']));
            $receipt = self::createReceipt($delivery['id']);
            $results[] = self::deliver($campaign, $delivery, $receipt, $body);
        }
        self::refreshCampaignCounts($pdo, (int) $campaign['id']);
        if ($userId !== null) {
            Audit::log($userId, 'communications.member_campaign_sent', ['campaign_id' => $campaign['public_id']]);
        } else {
            Audit::log(null, 'communications.weekly_campaign_sent', ['campaign_id' => $campaign['public_id']]);
        }
        return [
            'campaign' => self::campaignView($pdo, self::campaignById($pdo, (int) $campaign['id'])),
            'results' => $results,
        ];
    }

    private static function deliver(array $campaign, array $delivery, string $receiptToken, string $body): array
    {
        $pdo = Database::connection();
        $receiptUrl = rtrim(Env::require('APP_URL'), '/') . '/communications/receipt.html?token=' . rawurlencode($receiptToken);
        try {
            Mailer::communication(
                (string) $delivery['recipient_name'],
                (string) $delivery['recipient_email'],
                (string) $campaign['subject'],
                $body,
                $receiptUrl
            );
            $pdo->prepare("UPDATE communications_deliveries SET status = 'sent', sent_at = UTC_TIMESTAMP(6), error_message = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$delivery['id']]);
            return [
                'recipient' => (string) $delivery['recipient_email'],
                'status' => 'sent',
                'confirmation' => 'not_confirmed',
                'sent_at' => gmdate('c'),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $error = trim($exception->getMessage()) !== '' ? trim($exception->getMessage()) : 'Delivery failed.';
            $pdo->prepare("UPDATE communications_deliveries SET status = 'failed', error_message = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([substr($error, 0, 1000), $delivery['id']]);
            error_log('Communications delivery failed: ' . $error);
            return [
                'recipient' => (string) $delivery['recipient_email'],
                'status' => 'failed',
                'confirmation' => 'not_confirmed',
                'sent_at' => null,
                'error' => $error,
            ];
        }
    }

    private static function createCampaign(?int $userId, string $kind, string $title, string $subject, string $body, string $audience): array
    {
        $publicId = strtoupper(bin2hex(random_bytes(13)));
        $sender = Env::require('MAIL_FROM_ADDRESS');
        $statement = Database::connection()->prepare('INSERT INTO communications_campaigns (public_id, kind, title, subject, body_text, sender_email, audience_label, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $statement->execute([$publicId, $kind, $title, $subject, $body, $sender, $audience, 'sending', $userId]);
        return ['id' => (int) Database::connection()->lastInsertId(), 'public_id' => $publicId, 'subject' => $subject];
    }

    private static function createDelivery(int $campaignId, ?array $user, string $email): array
    {
        $statement = Database::connection()->prepare('INSERT INTO communications_deliveries (campaign_id, user_id, recipient_email, recipient_name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $statement->execute([$campaignId, $user['id'] ?? null, $email, (string) ($user['name'] ?? 'SeeToSee member'), 'queued']);
        return [
            'id' => (int) Database::connection()->lastInsertId(),
            'recipient_email' => $email,
            'recipient_name' => (string) ($user['name'] ?? 'SeeToSee member'),
        ];
    }

    private static function createReceipt(int $deliveryId): string
    {
        $token = Security::randomToken();
        $hours = max(1, min(8760, Env::int('COMMUNICATION_RECEIPT_HOURS', 168)));
        $statement = Database::connection()->prepare('INSERT INTO communications_receipts (delivery_id, token_hash, expires_at, created_at) VALUES (?, ?, UTC_TIMESTAMP(6) + INTERVAL ? HOUR, UTC_TIMESTAMP(6))');
        $statement->execute([$deliveryId, Security::tokenHash($token), $hours]);
        return $token;
    }

    private static function userByEmail(string $email): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, name, email, status, email_verified_at FROM users WHERE normalized_email = ? LIMIT 1');
        $statement->execute([strtolower($email)]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    private static function latestCampaign(PDO $pdo): ?array
    {
        $row = $pdo->query('SELECT * FROM communications_campaigns ORDER BY id DESC LIMIT 1')->fetch();
        return is_array($row) ? $row : null;
    }

    private static function latestOperatorTest(PDO $pdo): ?array
    {
        $row = $pdo->query("SELECT * FROM communications_campaigns WHERE kind = 'operator_test' ORDER BY id DESC LIMIT 1")->fetch();
        if (!is_array($row)) {
            return null;
        }
        self::refreshCampaignCounts($pdo, (int) $row['id']);
        $fresh = self::campaignById($pdo, (int) $row['id']);
        return $fresh ?: $row;
    }

    private static function campaignById(PDO $pdo, int $campaignId): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM communications_campaigns WHERE id = ? LIMIT 1');
        $statement->execute([$campaignId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private static function refreshCampaignCounts(PDO $pdo, int $campaignId): void
    {
        $statement = $pdo->prepare("SELECT COALESCE(SUM(status IN ('sent', 'confirmed')), 0), COALESCE(SUM(status = 'confirmed'), 0), COALESCE(SUM(status = 'failed'), 0), COALESCE(SUM(status IN ('queued', 'sent')), 0) FROM communications_deliveries WHERE campaign_id = ?");
        $statement->execute([$campaignId]);
        [$sent, $confirmed, $failed, $pending] = array_map('intval', $statement->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0]);
        $row = self::campaignById($pdo, $campaignId);
        if (!$row) {
            return;
        }
        $status = $pending > 0 ? 'awaiting_confirmation' : ($failed > 0 ? 'completed_with_errors' : 'completed');
        if ($row['kind'] === 'operator_test' && $confirmed < $sent) {
            $status = $failed > 0 ? 'sent_with_errors' : 'awaiting_confirmation';
        }
        $completedAt = $pending === 0 && $failed === 0 && $sent > 0 && ($row['kind'] !== 'operator_test' || $confirmed >= $sent) ? gmdate('Y-m-d H:i:s.u') : null;
        $update = $pdo->prepare('UPDATE communications_campaigns SET status = ?, sent_count = ?, confirmed_count = ?, failed_count = ?, pending_count = ?, sent_at = COALESCE(sent_at, CASE WHEN ? > 0 THEN UTC_TIMESTAMP(6) ELSE NULL END), completed_at = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $update->execute([$status, $sent, $confirmed, $failed, $pending, $sent, $completedAt, $campaignId]);
    }

    private static function operatorTestComplete(PDO $pdo): bool
    {
        $required = self::operatorEmails();
        if (count($required) !== 3) {
            return false;
        }
        $campaign = self::latestOperatorTest($pdo);
        if ($campaign === null || (int) $campaign['failed_count'] > 0 || (int) $campaign['confirmed_count'] < 3) {
            return false;
        }
        $statement = $pdo->prepare("SELECT LOWER(d.recipient_email), d.status FROM communications_deliveries d WHERE d.campaign_id = ?");
        $statement->execute([(int) $campaign['id']]);
        $confirmed = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            if ((string) $row[1] === 'confirmed') {
                $confirmed[(string) $row[0]] = true;
            }
        }
        sort($required);
        $actual = array_keys($confirmed);
        sort($actual);
        return $required === $actual;
    }

    private static function assertOperatorTestComplete(PDO $pdo): void
    {
        if (!self::operatorTestComplete($pdo)) {
            throw new ApiException(409, 'operator_test_incomplete', 'Complete and confirm the three-recipient operator test before sending a member campaign.');
        }
    }

    private static function campaignView(PDO $pdo, ?array $campaign): ?array
    {
        if ($campaign === null) {
            return null;
        }
        $statement = $pdo->prepare('SELECT recipient_email, recipient_name, status, error_message, sent_at, confirmed_at FROM communications_deliveries WHERE campaign_id = ? ORDER BY id ASC');
        $statement->execute([(int) $campaign['id']]);
        $deliveries = [];
        foreach ($statement->fetchAll() as $delivery) {
            $display = match ((string) $delivery['status']) {
                'confirmed' => 'confirmed',
                'failed' => 'failed',
                'sent' => 'not_confirmed',
                default => 'queued',
            };
            $deliveries[] = [
                'recipient' => (string) $delivery['recipient_email'],
                'name' => (string) $delivery['recipient_name'],
                'status' => $display,
                'error' => $delivery['error_message'],
                'sent_at' => $delivery['sent_at'],
                'confirmed_at' => $delivery['confirmed_at'],
            ];
        }
        return [
            'id' => (int) $campaign['id'],
            'public_id' => (string) $campaign['public_id'],
            'kind' => (string) $campaign['kind'],
            'title' => (string) $campaign['title'],
            'subject' => (string) $campaign['subject'],
            'sender' => (string) $campaign['sender_email'],
            'audience' => (string) $campaign['audience_label'],
            'status' => (string) $campaign['status'],
            'sent_at' => $campaign['sent_at'],
            'sent' => (int) $campaign['sent_count'],
            'confirmed' => (int) $campaign['confirmed_count'],
            'failed' => (int) $campaign['failed_count'],
            'pending' => (int) $campaign['pending_count'],
            'deliveries' => $deliveries,
        ];
    }

    private static function history(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, public_id, kind, title, sender_email, audience_label, status, sent_at, sent_count, confirmed_count, failed_count, pending_count FROM communications_campaigns ORDER BY id DESC LIMIT 50')->fetchAll();
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'public_id' => (string) $row['public_id'],
                'kind' => (string) $row['kind'],
                'title' => (string) $row['title'],
                'sender' => (string) $row['sender_email'],
                'audience' => (string) $row['audience_label'],
                'status' => (string) $row['status'],
                'sent_at' => $row['sent_at'],
                'sent' => (int) $row['sent_count'],
                'confirmed' => (int) $row['confirmed_count'],
                'failed' => (int) $row['failed_count'],
                'pending' => (int) $row['pending_count'],
            ];
        }, is_array($rows) ? $rows : []);
    }

    private static function text(mixed $value, string $field, int $min, int $max): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, 'validation_failed', ucfirst($field) . ' is required.');
        }
        $value = trim($value);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new ApiException(422, 'validation_failed', ucfirst($field) . ' is invalid or too long.');
        }
        return $value;
    }

    private static function nextWeeklyRun(int $dayOfWeek, int $hour): string
    {
        $dayOfWeek = max(0, min(6, $dayOfWeek));
        $hour = max(0, min(23, $hour));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $candidate = $now->setTime($hour, 0, 0);
        $days = ($dayOfWeek - (int) $candidate->format('w') + 7) % 7;
        if ($days === 0 && $candidate <= $now) {
            $days = 7;
        }
        return $candidate->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }
}
