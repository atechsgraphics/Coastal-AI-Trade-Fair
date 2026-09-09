<?php
declare(strict_types=1);

/**
 * EFT booking platform — outgoing email.
 * ---------------------------------------------------------------------------
 * Builds a proper multipart message (plain text + HTML + attachments) and
 * hands it either to PHP's mail() or to an SMTP server, whichever the site is
 * configured for. Every attempt is written to email_log, so the admin panel
 * can always show whether a confirmation actually went out.
 *
 * Credentials come from data/env.php or the real environment first and only
 * then from the settings table — see env() in platform.php.
 */

/* ============================================================== 1. SENDING */

/**
 * Send one message.
 *
 * @param array{
 *   to: string|string[], subject: string, html: string, text?: string,
 *   attachments?: array<int, array{path?:string, data?:string, name:string, mime?:string}>,
 *   booking_id?: int, client_id?: int, template?: string, reply_to?: string, cc?: string|string[]
 * } $message
 */
/**
 * Which way mail actually goes out.
 *
 * "auto", the default, means: use the mailbox on the host if it has been given
 * everything it needs, and fall back to PHP's own mail() if it has not. That
 * matters because the host's mail server is far more likely to be trusted than
 * a bare mail() call, but a half-filled SMTP setting would send nothing at all
 * — and silently sending nothing is worse than sending something that might
 * land in a spam folder. Choosing "smtp" or "mail" explicitly still wins.
 */
function bk_mail_transport(): string
{
    $choice = strtolower(trim(bk('bk_mail_transport', 'auto')));

    if ($choice === 'smtp' || $choice === 'mail') {
        return $choice;
    }

    $host = trim(bk('bk_smtp_host'));
    $user = trim(bk('bk_smtp_user'));
    $pass = trim(bk('bk_smtp_pass'));

    return ($host !== '' && $user !== '' && $pass !== '') ? 'smtp' : 'mail';
}

function bk_mail(array $message): bool
{
    $recipients = bk_mail_addresses($message['to'] ?? []);
    if (!$recipients) {
        return false;
    }

    $subject = bk_mail_clean((string) ($message['subject'] ?? ''));
    $html    = (string) ($message['html'] ?? '');
    $text    = (string) ($message['text'] ?? bk_mail_text_from_html($html));
    $files   = (array) ($message['attachments'] ?? []);
    $template = (string) ($message['template'] ?? '');

    $fromEmail = bk_mail_from_address();
    $fromName  = bk_mail_clean(bk('bk_from_name', setting('site_name', 'Bookings')));
    $replyTo   = bk_mail_clean((string) ($message['reply_to'] ?? bk('bk_reply_to', setting('email_primary'))));

    $attachments = bk_mail_load_attachments($files);
    $body = bk_mail_build($text, $html, $attachments, $boundaryHeaders);

    $headers = [
        'From'         => bk_mail_encode_name($fromName) . ' <' . $fromEmail . '>',
        'Reply-To'     => $replyTo !== '' ? $replyTo : $fromEmail,
        'MIME-Version' => '1.0',
        'Date'         => date('r'),
        'Message-ID'   => '<' . bin2hex(random_bytes(12)) . '@' . bk_mail_hostname() . '>',
        'Auto-Submitted' => 'auto-generated',
    ];
    if (!empty($message['cc'])) {
        $cc = bk_mail_addresses($message['cc']);
        if ($cc) {
            $headers['Cc'] = implode(', ', $cc);
        }
    }
    $headers += $boundaryHeaders;

    $transport = bk_mail_transport();
    $error = '';

    try {
        if ($transport === 'smtp') {
            $ok = bk_smtp_send($fromEmail, $recipients, $subject, $headers, $body, $error);
        } else {
            $ok = bk_mail_php($recipients, $subject, $headers, $body, $error);
        }
    } catch (Throwable $e) {
        $ok = false;
        $error = $e->getMessage();
    }

    bk_mail_log([
        'booking_id'  => (int) ($message['booking_id'] ?? 0),
        'client_id'   => (int) ($message['client_id'] ?? 0),
        'to_address'  => implode(', ', $recipients),
        'subject'     => $subject,
        'template'    => $template,
        'transport'   => $transport,
        'attachments' => implode(', ', array_column($attachments, 'name')),
        'status'      => $ok ? 'sent' : 'failed',
        'error'       => $error,
    ]);

    return $ok;
}

/** Deliver through PHP's own mail(). */
function bk_mail_php(array $recipients, string $subject, array $headers, string $body, string &$error): bool
{
    if (!function_exists('mail')) {
        $error = 'PHP mail() is not available on this server.';
        return false;
    }

    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }

    $sent = @mail(
        implode(', ', $recipients),
        bk_mail_encode_header($subject),
        $body,
        implode("\r\n", $lines)
    );

    if (!$sent) {
        $error = 'PHP mail() refused the message. On a local machine this is normal; configure SMTP for live delivery.';
    }
    return $sent;
}

/* ================================================================ 2. SMTP */

/**
 * A minimal SMTP client: EHLO, optional STARTTLS, optional AUTH LOGIN/PLAIN,
 * MAIL FROM / RCPT TO / DATA. Enough for every mainstream mail host.
 */
function bk_smtp_send(string $from, array $recipients, string $subject, array $headers, string $body, string &$error): bool
{
    $host   = bk('bk_smtp_host');
    $port   = bk_int('bk_smtp_port', 587);
    $secure = strtolower(bk('bk_smtp_secure', 'tls'));
    $user   = bk('bk_smtp_user');
    $pass   = bk('bk_smtp_pass');

    if ($host === '') {
        $error = 'SMTP is selected but no SMTP host has been configured.';
        return false;
    }

    $verify = bk_bool('bk_smtp_verify_peer', true);
    $context = stream_context_create(['ssl' => [
        'verify_peer'       => $verify,
        'verify_peer_name'  => $verify,
        'allow_self_signed' => !$verify,
        'SNI_enabled'       => true,
    ]]);

    $address = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($address, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $error = 'Could not reach the mail server: ' . $errstr . ' (' . $errno . ')';
        return false;
    }
    stream_set_timeout($socket, 20);

    $fail = static function (string $message) use (&$error, $socket): bool {
        $error = $message;
        @fclose($socket);
        return false;
    };

    if (!bk_smtp_expect($socket, 220, $reply)) {
        return $fail('The mail server did not greet us: ' . $reply);
    }

    $hostname = bk_mail_hostname();
    if (!bk_smtp_command($socket, 'EHLO ' . $hostname, 250, $reply)) {
        if (!bk_smtp_command($socket, 'HELO ' . $hostname, 250, $reply)) {
            return $fail('The mail server rejected EHLO: ' . $reply);
        }
    }
    $capabilities = strtoupper($reply);

    if ($secure === 'tls') {
        if (!bk_smtp_command($socket, 'STARTTLS', 220, $reply)) {
            return $fail('The mail server refused STARTTLS: ' . $reply);
        }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
            return $fail('The TLS handshake with the mail server failed.');
        }
        if (!bk_smtp_command($socket, 'EHLO ' . $hostname, 250, $reply)) {
            return $fail('The mail server rejected EHLO after STARTTLS: ' . $reply);
        }
        $capabilities = strtoupper($reply);
    }

    if ($user !== '' && $pass !== '') {
        if (str_contains($capabilities, 'AUTH') && str_contains($capabilities, 'PLAIN')) {
            $token = base64_encode("\0" . $user . "\0" . $pass);
            if (!bk_smtp_command($socket, 'AUTH PLAIN ' . $token, 235, $reply)) {
                return $fail('The mail server rejected the sign-in: ' . $reply);
            }
        } else {
            if (!bk_smtp_command($socket, 'AUTH LOGIN', 334, $reply)) {
                return $fail('The mail server refused AUTH LOGIN: ' . $reply);
            }
            if (!bk_smtp_command($socket, base64_encode($user), 334, $reply)) {
                return $fail('The mail server rejected the username: ' . $reply);
            }
            if (!bk_smtp_command($socket, base64_encode($pass), 235, $reply)) {
                return $fail('The mail server rejected the password: ' . $reply);
            }
        }
    }

    if (!bk_smtp_command($socket, 'MAIL FROM:<' . $from . '>', 250, $reply)) {
        return $fail('The mail server rejected the sender: ' . $reply);
    }
    foreach ($recipients as $recipient) {
        if (!bk_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], $reply)) {
            return $fail('The mail server rejected ' . $recipient . ': ' . $reply);
        }
    }
    if (!bk_smtp_command($socket, 'DATA', 354, $reply)) {
        return $fail('The mail server refused DATA: ' . $reply);
    }

    $lines = ['To: ' . implode(', ', $recipients), 'Subject: ' . bk_mail_encode_header($subject)];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    $data = implode("\r\n", $lines) . "\r\n\r\n" . $body;

    // Dot-stuffing, as the protocol requires.
    $data = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $data))) ?? $data;

    fwrite($socket, $data . "\r\n.\r\n");
    if (!bk_smtp_expect($socket, 250, $reply)) {
        return $fail('The mail server did not accept the message: ' . $reply);
    }

    bk_smtp_command($socket, 'QUIT', [221, 250], $reply);
    @fclose($socket);

    return true;
}

/** Send one command and check the reply code. */
function bk_smtp_command($socket, string $command, $expect, ?string &$reply = null): bool
{
    fwrite($socket, $command . "\r\n");
    return bk_smtp_expect($socket, $expect, $reply);
}

/** Read a (possibly multi-line) reply and compare its code. */
function bk_smtp_expect($socket, $expect, ?string &$reply = null): bool
{
    $expect = (array) $expect;
    $reply = '';
    $code = 0;

    while (($line = fgets($socket, 1024)) !== false) {
        $reply .= $line;
        if (strlen($line) >= 4 && $line[3] === '-') {
            continue;                                                       // more lines follow
        }
        $code = (int) substr($line, 0, 3);
        break;
    }

    $reply = trim($reply);
    return in_array($code, $expect, true);
}

/* =============================================================== 3. MIME */

/** Collect attachment bytes, skipping anything unreadable. */
function bk_mail_load_attachments(array $files): array
{
    $out = [];
    foreach ($files as $file) {
        $name = bk_mail_clean((string) ($file['name'] ?? 'attachment'));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'attachment';

        if (isset($file['data'])) {
            $data = (string) $file['data'];
        } elseif (!empty($file['path']) && is_file($file['path'])) {
            $data = (string) @file_get_contents($file['path']);
        } else {
            continue;
        }
        if ($data === '') {
            continue;
        }

        $out[] = [
            'name' => $name,
            'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
            'data' => $data,
        ];
    }
    return $out;
}

/**
 * Build the message body. Text and HTML go into a multipart/alternative part;
 * if there are attachments that part is wrapped in a multipart/mixed.
 *
 * @param-out array<string, string> $headers the Content-Type header to use
 */
function bk_mail_build(string $text, string $html, array $attachments, ?array &$headers = null): string
{
    $alternative = 'alt-' . bin2hex(random_bytes(10));

    $inner = '--' . $alternative . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . '--' . $alternative . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n"
        . '--' . $alternative . "--\r\n";

    if (!$attachments) {
        $headers = ['Content-Type' => 'multipart/alternative; boundary="' . $alternative . '"'];
        return $inner;
    }

    $mixed = 'mix-' . bin2hex(random_bytes(10));
    $body = '--' . $mixed . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $alternative . "\"\r\n\r\n"
        . $inner;

    foreach ($attachments as $file) {
        $body .= '--' . $mixed . "\r\n"
            . 'Content-Type: ' . $file['mime'] . '; name="' . $file['name'] . "\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . $file['name'] . "\"\r\n\r\n"
            . chunk_split(base64_encode($file['data'])) . "\r\n";
    }
    $body .= '--' . $mixed . "--\r\n";

    $headers = ['Content-Type' => 'multipart/mixed; boundary="' . $mixed . '"'];
    return $body;
}

/* ============================================================= 4. HELPERS */

/** Strip anything that could be used to inject an extra header. */
function bk_mail_clean(string $value): string
{
    return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
}

/** RFC 2047 encoding, so accented subjects survive. */
function bk_mail_encode_header(string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function bk_mail_encode_name(string $name): string
{
    if ($name === '') {
        return '';
    }
    if (preg_match('/^[\x20-\x7E]*$/', $name)) {
        return '"' . str_replace('"', '', $name) . '"';
    }
    return bk_mail_encode_header($name);
}

/**
 * Normalise one address or a comma-separated list into valid addresses.
 *
 * @param string|string[] $input
 * @return string[]
 */
function bk_mail_addresses($input): array
{
    $parts = is_array($input) ? $input : preg_split('/[,;]+/', (string) $input);
    $out = [];
    foreach ((array) $parts as $part) {
        $address = bk_mail_clean((string) $part);
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($address)] = $address;
        }
    }
    return array_values($out);
}

function bk_mail_hostname(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $host = preg_replace('/^www\./i', '', $host) ?? $host;
    return $host !== '' ? $host : 'localhost';
}

/** The address messages are sent from. */
function bk_mail_from_address(): string
{
    $configured = bk_mail_clean(bk('bk_from_email'));
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
        return $configured;
    }
    $fallback = bk_mail_clean(setting('email_primary'));
    if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
        return $fallback;
    }
    return 'no-reply@' . bk_mail_hostname();
}

/** Everyone who should hear about new bookings and payments. */
function bk_admin_recipients(): array
{
    $configured = bk('bk_admin_emails');
    if (trim($configured) === '') {
        $configured = setting('email_form_to', setting('email_primary'));
    }
    return bk_mail_addresses($configured);
}

/** A readable plain-text version of an HTML email. */
function bk_mail_text_from_html(string $html): string
{
    $text = preg_replace('#<(br|/p|/div|/tr|/h[1-6])[^>]*>#i', "\n", $html) ?? $html;
    $text = preg_replace('#<(/td|/th)>#i', "  ", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
}

/** Write one row to email_log. */
function bk_mail_log(array $row): void
{
    db_run(
        'INSERT INTO email_log (booking_id, client_id, to_address, subject, template, transport, attachments, status, error, created_at)
         VALUES (:b, :c, :to, :s, :t, :tr, :a, :st, :e, :at)',
        [
            ':b'  => (int) ($row['booking_id'] ?? 0),
            ':c'  => (int) ($row['client_id'] ?? 0),
            ':to' => mb_substr((string) $row['to_address'], 0, 400),
            ':s'  => mb_substr((string) $row['subject'], 0, 250),
            ':t'  => mb_substr((string) ($row['template'] ?? ''), 0, 60),
            ':tr' => (string) ($row['transport'] ?? ''),
            ':a'  => mb_substr((string) ($row['attachments'] ?? ''), 0, 400),
            ':st' => (string) $row['status'],
            ':e'  => mb_substr((string) ($row['error'] ?? ''), 0, 500),
            ':at' => date('Y-m-d H:i:s'),
        ]
    );
}

/* ============================================================ 5. TEMPLATE */

/**
 * Wrap message content in the site's branded email shell.
 *
 * @param array<int, array{0:string,1:string}> $facts label/value pairs
 * @param array<int, array{text:string, url:string}> $buttons
 */
function bk_email_html(string $heading, string $intro, array $facts = [], array $buttons = [], string $footNote = ''): string
{
    $accent = setting('theme_accent', '#22c9f0');
    $ink    = setting('theme_ink', '#04121f');
    $org    = e(setting('org_name'));
    $event  = e(setting('event_name'));

    $rows = '';
    foreach ($facts as $fact) {
        [$label, $value] = $fact;
        if (trim((string) $value) === '') {
            continue;
        }
        $rows .= '<tr>'
            . '<td style="padding:9px 0;border-bottom:1px solid #e6eaee;font:13px Arial,sans-serif;color:#5b6c7c;width:44%;vertical-align:top">' . e($label) . '</td>'
            . '<td style="padding:9px 0;border-bottom:1px solid #e6eaee;font:bold 14px Arial,sans-serif;color:#0d2032">' . nl2br(e((string) $value)) . '</td>'
            . '</tr>';
    }
    $table = $rows === '' ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:22px 0">' . $rows . '</table>';

    $actions = '';
    foreach ($buttons as $button) {
        $actions .= '<a href="' . e($button['url']) . '" style="display:inline-block;margin:0 10px 10px 0;padding:13px 22px;'
            . 'background:' . e($ink) . ';color:#ffffff;text-decoration:none;border-radius:8px;'
            . 'font:bold 14px Arial,sans-serif">' . e($button['text']) . '</a>';
    }

    $signOff = trim(bk('bk_email_footer'));
    if ($signOff === '') {
        $signOff = setting('org_name') . "\n" . setting('email_primary')
            . (setting('phone_1') !== '' ? ' · ' . setting('phone_1') : '');
    }

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f2f4f6">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f2f4f6;padding:26px 12px">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden">'
        . '<tr><td style="background:' . e($ink) . ';padding:24px 30px">'
        . '<div style="font:bold 17px Arial,sans-serif;color:#ffffff">' . $org . '</div>'
        . '<div style="font:13px Arial,sans-serif;color:rgba(255,255,255,.72);margin-top:4px">' . $event . '</div>'
        . '</td></tr>'
        . '<tr><td style="height:4px;background:' . e($accent) . '"></td></tr>'
        . '<tr><td style="padding:30px">'
        . '<h1 style="margin:0 0 14px;font:bold 21px Arial,sans-serif;color:#0d2032">' . e($heading) . '</h1>'
        . '<div style="font:15px/1.65 Arial,sans-serif;color:#3d4d5c">' . nl2br(e($intro)) . '</div>'
        . $table
        . ($actions !== '' ? '<div style="margin-top:6px">' . $actions . '</div>' : '')
        . ($footNote !== '' ? '<div style="margin-top:22px;padding:14px 16px;background:#f6f8f9;border-radius:10px;font:13px/1.6 Arial,sans-serif;color:#5b6c7c">' . nl2br(e($footNote)) . '</div>' : '')
        . '</td></tr>'
        . '<tr><td style="padding:20px 30px;background:#fafbfc;border-top:1px solid #e6eaee;font:12px/1.6 Arial,sans-serif;color:#7b8894">'
        . nl2br(e($signOff))
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}
