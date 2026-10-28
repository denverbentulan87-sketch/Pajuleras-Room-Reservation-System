<?php
declare(strict_types=1);

/**
 * Messages to tenants (rent reminders, GCash decisions, inquiry replies).
 *
 * Channels:
 *   1. E-mail    - sent automatically through your SMTP account (config/config.php > 'mail')
 *   2. Messenger - prepared in Admin > Payment reminders with a one-tap button. Facebook
 *                  does not allow automatic Messenger messages without an approved app.
 *   When e-mail is not set up (or fails), or the tenant has no e-mail, the message is
 *   prepared there for one-tap Messenger, e-mail, or a call.
 * Every attempt is written to the `notifications` table, so the landlord can
 * always see what was sent, to whom, and whether it worked.
 */

function public_base_url(): string
{
    $cfg = rtrim((string)cfg('public_url', ''), '/');
    if ($cfg !== '') {
        return $cfg;
    }
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']);
    return ($https ? 'https://' : 'http://') . $host . rtrim(url(''), '/');
}

function mail_config(): array
{
    $c = (array)cfg('mail', []);
    return $c + ['host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass' => '', 'from_email' => '', 'from_name' => '', 'verify_tls' => true];
}

function mail_configured(): bool
{
    $c = mail_config();
    $from = $c['from_email'] !== '' ? $c['from_email'] : $c['user'];
    return $c['host'] !== '' && valid_email((string)$from);
}

/** @return array{email:bool, messenger:bool} what can be sent automatically */
function notify_channels(): array
{
    return ['email' => mail_configured(), 'messenger' => false];
}

/* ---------------------------------------------------------------- SMTP */

function smtp_clean(string $s): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

/** @return array{0:int,1:string} */
function smtp_read($fp): array
{
    $text = '';
    $code = 0;
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line;
        $code = (int)substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return [$code, trim($text)];
}

function smtp_cmd($fp, string $cmd, array $ok, ?string &$error): bool
{
    fwrite($fp, $cmd . "\r\n");
    [$code, $text] = smtp_read($fp);
    if (!in_array($code, $ok, true)) {
        $error = 'The mail server answered: ' . mb_substr($text, 0, 160);
        return false;
    }
    return true;
}

function smtp_send(string $to, string $subject, string $body, ?string &$error = null): bool
{
    $c = mail_config();
    $to = smtp_clean($to);
    if (!valid_email($to)) {
        $error = 'The e-mail address is not valid.';
        return false;
    }
    if (!mail_configured()) {
        $error = 'E-mail is not set up.';
        return false;
    }
    $fromEmail = smtp_clean($c['from_email'] !== '' ? $c['from_email'] : $c['user']);
    $fromName = smtp_clean($c['from_name'] !== '' ? $c['from_name'] : setting('site_name', 'Boarding house'));
    $host = (string)$c['host'];
    $port = (int)$c['port'];
    $secure = (string)$c['secure'];

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool)$c['verify_tls'], 'verify_peer_name' => (bool)$c['verify_tls'], 'SNI_enabled' => true, 'peer_name' => $host,
    ]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $error = 'Could not connect to the mail server (' . $errstr . ').';
        return false;
    }
    stream_set_timeout($fp, 15);
    try {
        [$code, $text] = smtp_read($fp);
        if ($code !== 220) {
            $error = 'The mail server did not say hello.';
            return false;
        }
        $me = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        if (!smtp_cmd($fp, 'EHLO ' . $me, [250], $error)) {
            return false;
        }
        if ($secure === 'tls') {
            if (!smtp_cmd($fp, 'STARTTLS', [220], $error)) {
                return false;
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $error = 'Could not start a secure (TLS) connection.';
                return false;
            }
            if (!smtp_cmd($fp, 'EHLO ' . $me, [250], $error)) {
                return false;
            }
        }
        if ((string)$c['user'] !== '') {
            if (!smtp_cmd($fp, 'AUTH LOGIN', [334], $error)
                || !smtp_cmd($fp, base64_encode((string)$c['user']), [334], $error)
                || !smtp_cmd($fp, base64_encode((string)$c['pass']), [235], $error)) {
                $error = 'The mail server refused the user name or password.';
                return false;
            }
        }
        if (!smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250], $error) || !smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251], $error) || !smtp_cmd($fp, 'DATA', [354], $error)) {
            return false;
        }
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode(smtp_clean($subject)) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Auto-Response-Suppress: All',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace("\r\n", "\n", $body)), 76, "\r\n");
        fwrite($fp, $payload . "\r\n.\r\n");
        [$code, $text] = smtp_read($fp);
        if ($code !== 250) {
            $error = 'The mail server did not accept the message: ' . mb_substr($text, 0, 160);
            return false;
        }
        @fwrite($fp, "QUIT\r\n");
        return true;
    } finally {
        @fclose($fp);
    }
}

/* ------------------------------------------------------------- Sending */

function notification_log(array $row): int
{
    q("INSERT INTO notifications (tenant_id, reservation_id, inquiry_id, type, channel, recipient, subject, body, status, error, dedupe_key, sent_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [$row['tenant_id'] ?? null, $row['reservation_id'] ?? null, $row['inquiry_id'] ?? null, $row['type'], $row['channel'], $row['recipient'] ?? null,
       $row['subject'] ?? null, $row['body'], $row['status'], isset($row['error']) ? mb_substr((string)$row['error'], 0, 250) : null,
       $row['dedupe_key'] ?? null, $row['status'] === 'sent' ? date('Y-m-d H:i:s') : null]);
    return (int)db()->lastInsertId();
}

/**
 * Send one message to a tenant on the first channel that works.
 *
 * $msg  = ['subject' => ..., 'body' => ...]
 * $opts = ['reservation_id' => int, 'inquiry_id' => int, 'dedupe' => string, 'force' => bool,
 *          'auto_only' => bool (never prepare a by-hand message), 'email' => string (override), 'phone' => string (override)]
 * @return array{status:string, channel:string, note:string}  status: sent | manual | skipped | failed
 */
function notify_tenant(?array $tenant, string $type, array $msg, array $opts = []): array
{
    $dedupe = (string)($opts['dedupe'] ?? '');
    $base = ['tenant_id' => $tenant['id'] ?? null, 'reservation_id' => $opts['reservation_id'] ?? null, 'inquiry_id' => $opts['inquiry_id'] ?? null,
        'type' => $type, 'subject' => $msg['subject'] ?? null, 'dedupe_key' => $dedupe !== '' ? $dedupe : null];

    if ($dedupe !== '' && empty($opts['force'])) {
        if (fetch_val("SELECT 1 FROM notifications WHERE dedupe_key = ? AND status IN ('sent','manual','skipped') LIMIT 1", [$dedupe])) {
            return ['status' => 'skipped', 'channel' => '', 'note' => 'Already sent.'];
        }
        if ((int)fetch_val("SELECT COUNT(*) FROM notifications WHERE dedupe_key = ? AND status = 'failed'", [$dedupe]) >= 3) {
            return ['status' => 'skipped', 'channel' => '', 'note' => 'Gave up after 3 failed tries.'];
        }
    }

    $email = trim((string)($opts['email'] ?? ($tenant['email'] ?? '')));
    $phone = normalize_phone((string)($opts['phone'] ?? ($tenant['contact_no'] ?? '')));
    $failedNote = '';

    if ($email !== '' && valid_email($email) && mail_configured()) {
        $err = null;
        if (smtp_send($email, (string)($msg['subject'] ?? setting('site_name')), (string)$msg['body'], $err)) {
            notification_log($base + ['channel' => 'email', 'recipient' => $email, 'body' => $msg['body'], 'status' => 'sent']);
            return ['status' => 'sent', 'channel' => 'email', 'note' => 'E-mailed to ' . $email];
        }
        notification_log($base + ['channel' => 'email', 'recipient' => $email, 'body' => $msg['body'], 'status' => 'failed', 'error' => $err]);
        $failedNote = 'E-mail failed. ';
    }
    // Nothing could be sent automatically: prepare it for one-tap sending.
    // (Skipped for message types that are only worth sending automatically.)
    if (!empty($opts['auto_only'])) {
        return ['status' => 'skipped', 'channel' => '', 'note' => $failedNote . 'No automatic channel available.'];
    }
    if ($dedupe !== '' && fetch_val("SELECT 1 FROM notifications WHERE dedupe_key = ? AND status = 'manual' LIMIT 1", [$dedupe])) {
        return ['status' => 'manual', 'channel' => 'manual', 'note' => 'Already prepared in Admin > Payment reminders, waiting to be sent.'];   // never queue the same message twice
    }
    notification_log($base + ['channel' => 'manual', 'recipient' => $email !== '' ? $email : ($phone !== '' ? $phone : null), 'body' => $msg['body'], 'status' => 'manual', 'error' => $failedNote !== '' ? trim($failedNote) : null]);
    return ['status' => 'manual', 'channel' => 'manual', 'note' => 'Ready to send by hand in Admin > Reminders.'];
}

/** One-tap links for a manual notification row. */
function manual_links(array $n, ?array $tenant): array
{
    $links = [];
    $body = (string)$n['body'];
    $phone = normalize_phone((string)($tenant['contact_no'] ?? $n['recipient'] ?? ''));
    if (valid_phone($phone)) {
        $links[] = ['label' => 'Call ' . $phone, 'href' => 'tel:' . $phone, 'icon' => 'phone'];
    }
    $mail = (string)($tenant['email'] ?? '');
    if ($mail !== '' && valid_email($mail)) {
        $links[] = ['label' => 'E-mail', 'href' => 'mailto:' . $mail . '?subject=' . rawurlencode((string)$n['subject']) . '&body=' . rawurlencode($body), 'icon' => 'mail'];
    }
    $m = trim((string)($tenant['messenger'] ?? ''));
    if ($m !== '') {
        $m = preg_replace('#^https?://(www\.)?(m\.me|facebook\.com|messenger\.com)/#i', '', $m) ?? $m;
        $m = trim($m, '/@ ');
        if (preg_match('/^[A-Za-z0-9.\-_]{3,80}$/', $m)) {
            $links[] = ['label' => 'Messenger', 'href' => 'https://m.me/' . $m, 'icon' => 'message', 'external' => true, 'copy' => true];
        }
    }
    return $links;
}
