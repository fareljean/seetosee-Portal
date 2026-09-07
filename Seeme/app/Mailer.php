<?php
declare(strict_types=1);

namespace SeeToSee;

use RuntimeException;

final class Mailer
{
    public static function verifyEmail(string $name, string $email, string $token): void
    {
        // GET verification is read-only; the page moves this token into a deliberate POST.
        // Keep the token in the query so email clients/link rewriters preserve the handoff.
        $url = rtrim(Env::require('APP_URL'), '/') . '/api/auth/verify.php?token=' . rawurlencode($token);
        self::send($email, 'Verify your SeeToSee email', self::template(
            'Verify your email',
            'Welcome, ' . self::escape($name) . '. Confirm this email address to activate your SeeToSee account.',
            'Verify email',
            $url,
            'This link expires in 24 hours.'
        ));
    }

    public static function passwordReset(string $name, string $email, string $token): void
    {
        $url = rtrim(Env::require('APP_URL'), '/') . '/auth/reset.html?token=' . rawurlencode($token);
        self::send($email, 'Reset your SeeToSee password', self::template(
            'Reset your password',
            'A password reset was requested for ' . self::escape($name) . '. If this was not you, no action is needed.',
            'Choose a new password',
            $url,
            'This link expires in 24 hours and can be used once.'
        ));
    }

    public static function welcome(string $name, string $email): void
    {
        self::send($email, 'Welcome to SeeToSee', self::template(
            'Your SeeToSee account is active',
            'Welcome, ' . self::escape($name) . '. Your free membership includes one HTML timestamp gate each week.',
            'Open your dashboard',
            rtrim(Env::require('APP_URL'), '/') . '/dashboard/',
            'Protect it. Present it. Make it yours.'
        ));
    }

    public static function signerAlert(string $ownerName, string $notificationEmail, array $record): void
    {
        $body = '<p style="margin:0 0 16px">A signer entered one of your SeeToSee gates.</p>'
            . '<table style="width:100%;border-collapse:collapse">'
            . self::row('Signer', (string) $record['signer_name'])
            . self::row('Email', (string) $record['signer_email'])
            . self::row('Signed UTC', (string) $record['signed_at'])
            . self::row('Record ID', (string) $record['record_id'])
            . '</table>';
        self::send($notificationEmail, 'New SeeToSee signer — ' . $record['record_id'], self::template(
            'New signer record',
            'Hello ' . self::escape($ownerName) . '.',
            'View signer history',
            rtrim(Env::require('APP_URL'), '/') . '/dashboard/#signers',
            $body
        ));
    }

    public static function verifiedMemberAlert(string $name, string $email, string $purpose): void
    {
        $body = '<p style="margin:0 0 16px">A SeeToSee member completed email verification.</p>'
            . '<table style="width:100%;border-collapse:collapse">'
            . self::row('Name', $name)
            . self::row('Email', $email)
            . self::row('Purpose', $purpose)
            . self::row('Verified UTC', gmdate('Y-m-d H:i:s'))
            . '</table>';
        $html = self::template(
            'Member email verified',
            'The verification checkpoint completed successfully.',
            'Open the dashboard',
            rtrim(Env::require('APP_URL'), '/') . '/dashboard/',
            $body
        );
        foreach (self::adminRecipients() as $recipient) {
            try {
                self::send($recipient, 'SeeToSee verified member — ' . $email, $html);
            } catch (\Throwable $exception) {
                error_log('Admin verification notification delivery failed.');
            }
        }
    }

    public static function subscription(string $name, string $email, string $status): void
    {
        self::send($email, 'SeeToSee membership update', self::template(
            'Membership ' . self::escape($status),
            'Hello ' . self::escape($name) . '. Stripe has confirmed your membership status as ' . self::escape($status) . '.',
            'View membership',
            rtrim(Env::require('APP_URL'), '/') . '/dashboard/#membership',
            'Dashboard access is controlled by verified Stripe webhook state.'
        ));
    }

    public static function paymentFailed(string $name, string $email): void
    {
        self::send($email, 'Action needed for your SeeToSee membership', self::template(
            'Payment needs attention',
            'Hello ' . self::escape($name) . '. Stripe reported that your latest membership payment did not succeed.',
            'Update billing',
            rtrim(Env::require('APP_URL'), '/') . '/dashboard/#billing',
            'Use the secure Stripe Customer Portal from your dashboard.'
        ));
    }

    public static function emailChanged(string $name, string $oldEmail, string $newEmail): void
    {
        self::send($oldEmail, 'Your SeeToSee email was changed', self::template(
            'Account email changed',
            'Hello ' . self::escape($name) . '. Your account email is being changed to ' . self::escape($newEmail) . '.',
            'Contact SeeToSee',
            rtrim(Env::require('APP_URL'), '/') . '/',
            'If you did not make this change, contact support immediately.'
        ));
    }

    public static function communication(string $name, string $email, string $subject, string $body, string $receiptUrl): void
    {
        $safeBody = nl2br(self::escape($body), false);
        self::send($email, $subject, self::template(
            'SeeToSee communication',
            '<p style="margin:0 0 16px">Hello ' . self::escape($name !== '' ? $name : 'SeeToSee member') . '.</p>'
                . '<div style="padding:16px;border:1px solid #ffffff1c;border-radius:12px;background:#0b0d10;white-space:normal">' . $safeBody . '</div>',
            'Confirm receipt',
            $receiptUrl,
            'This one-time receipt link records mailbox access for this message. It does not replace account verification or sign-in.'
        ));
    }

    public static function send(string $to, string $subject, string $html): void
    {
        if (!Env::bool('MAIL_ENABLED', true)) {
            if (Env::get('APP_ENV', 'production') !== 'production') {
                $line = json_encode(['to' => $to, 'subject' => $subject, 'html' => $html, 'at' => gmdate('c')], JSON_UNESCAPED_SLASHES) . PHP_EOL;
                @file_put_contents(SEETOSEE_ROOT . '/storage/logs/mail-capture.log', $line, FILE_APPEND | LOCK_EX);
            }
            return;
        }

        $host = Env::require('SMTP_HOST');
        $port = Env::int('SMTP_PORT', 465);
        $encryption = strtolower(Env::get('SMTP_ENCRYPTION', 'ssl') ?? 'ssl');
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connection failed.');
        }
        stream_set_timeout($socket, 15);
        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO ' . (parse_url(Env::require('APP_URL'), PHP_URL_HOST) ?: 'seetosee.org'), [250]);
            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                self::command($socket, 'EHLO ' . (parse_url(Env::require('APP_URL'), PHP_URL_HOST) ?: 'seetosee.org'), [250]);
            }
            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode(Env::require('SMTP_USERNAME')), [334]);
            self::command($socket, base64_encode(Env::require('SMTP_PASSWORD')), [235]);
            $from = Env::require('MAIL_FROM_ADDRESS');
            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            $replyTo = trim((string) Env::get('MAIL_REPLYTO_ADDRESS', ''));
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . self::encodeHeader(Env::get('MAIL_FROM_NAME', 'SeeToSee') ?? 'SeeToSee') . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(Env::require('APP_URL'), PHP_URL_HOST) ?: 'seetosee.org') . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
            ];
            if ($replyTo !== '') {
                $headers[] = 'Reply-To: <' . $replyTo . '>';
            }
            $message = implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($html);
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            fwrite($socket, $message . "\r\n.\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $command, array $expected): void
    {
        fwrite($socket, $command . "\r\n");
        self::expect($socket, $expected);
    }

    private static function expect($socket, array $expected): void
    {
        $response = '';
        while (($line = fgets($socket, 4096)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            error_log('SMTP response code: ' . $code);
            throw new RuntimeException('SMTP delivery failed.');
        }
    }

    private static function adminRecipients(): array
    {
        $raw = trim((string) Env::get('MAIL_ADMIN_RECIPIENTS', ''));
        if ($raw === '') {
            return [];
        }
        $recipients = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $recipients[$candidate] = true;
            }
        }
        return array_keys($recipients);
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $value)) . '?=';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><td style="padding:7px;color:#8c9399;border-bottom:1px solid #ffffff1c">' . self::escape($label) . '</td><td style="padding:7px;color:#f5f5f1;border-bottom:1px solid #ffffff1c">' . self::escape($value) . '</td></tr>';
    }

    private static function template(string $title, string $intro, string $button, string $url, string $footer): string
    {
        return '<!doctype html><html><body style="margin:0;background:#07080a;color:#f5f5f1;font-family:Arial,sans-serif">'
            . '<div style="max-width:620px;margin:auto;padding:32px 18px"><div style="color:#e6c77a;font-weight:900;letter-spacing:.18em">SEE<span style="color:#d9dde0">TO</span>SEE</div>'
            . '<div style="margin-top:24px;padding:30px;border:1px solid #ffffff1c;border-radius:18px;background:#101216">'
            . '<h1 style="margin:0 0 16px;font-size:30px;color:#fff">' . self::escape($title) . '</h1><div style="color:#c7c9cb;line-height:1.65">' . $intro . '</div>'
            . '<p style="margin:24px 0"><a href="' . self::escape($url) . '" style="display:inline-block;padding:14px 20px;border-radius:999px;background:#e6c77a;color:#111;text-decoration:none;font-weight:900">' . self::escape($button) . '</a></p>'
            . '<div style="color:#8c9399;font-size:13px;line-height:1.6">' . $footer . '</div></div></div></body></html>';
    }
}
