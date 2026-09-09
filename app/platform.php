<?php
declare(strict_types=1);

/**
 * EFT booking platform — shared core.
 * ---------------------------------------------------------------------------
 * Loaded by bootstrap.php on every request. It stays deliberately small:
 * configuration, client accounts, statuses, money and the audit trail.
 * The heavier modules (services, EFT, PDFs, email) are pulled in only by the
 * pages that need them.
 */

require_once __DIR__ . '/../database/migrate.php';

/* ============================================================ 1. SETTINGS */

/**
 * Read a value from the private environment file, then the real environment,
 * then fall back to the given default.
 *
 * data/env.php is never served by the web server (the whole data folder is
 * blocked) and is the right place for SMTP passwords and other secrets.
 * It returns a plain array, for example:
 *
 *     <?php return ['BK_SMTP_PASS' => 's3cret'];
 */
function env(string $key, string $default = ''): string
{
    static $file = null;

    if ($file === null) {
        $file = [];
        $path = DATA_PATH . '/env.php';
        if (is_file($path)) {
            $loaded = @include $path;
            if (is_array($loaded)) {
                foreach ($loaded as $k => $v) {
                    $file[(string) $k] = (string) $v;
                }
            }
        }
    }

    if (isset($file[$key]) && $file[$key] !== '') {
        return $file[$key];
    }

    $fromEnv = getenv($key);
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }

    return $default;
}

/**
 * A booking setting, with the environment taking priority.
 * bk_smtp_pass, for instance, can live in data/env.php as BK_SMTP_PASS.
 */
function bk(string $key, string $default = ''): string
{
    $fromEnv = env(strtoupper($key));
    if ($fromEnv !== '') {
        return $fromEnv;
    }
    return setting($key, $default);
}

function bk_int(string $key, int $default = 0): int
{
    $value = bk($key, '');
    return $value === '' ? $default : (int) $value;
}

function bk_bool(string $key, bool $default = false): bool
{
    $value = bk($key, '');
    if ($value === '') {
        return $default;
    }
    return in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
}

/** True when the booking platform is switched on and installed. */
function bk_enabled(): bool
{
    return is_installed() && booking_schema_current() && bk_bool('bk_enabled', true);
}

/* ============================================================== 2. MONEY */

function bk_currency(): string
{
    return bk('bk_currency', 'N$');
}

/** "N$ 1,250.00" */
function bk_money(float $amount, ?string $currency = null): string
{
    return ($currency ?? bk_currency()) . ' ' . number_format($amount, 2, '.', ',');
}

/**
 * Read a money amount written by a person.
 *
 *   "N$ 9,999"   → 9999.00     (comma grouping thousands)
 *   "1 250,50"   → 1250.50     (comma as the decimal point)
 *   "1,250.50"   → 1250.50     (both, so the last one decides)
 *   "N$1250.5"   → 1250.50
 *   "750"        → 750.00
 *
 * The awkward case is a single separator followed by exactly three digits —
 * "9,999" or "1.500". In this currency that is nearly always thousands, so
 * that is how it is read.
 */
function bk_money_parse(string $input): float
{
    $clean = preg_replace('/[^0-9.,-]/', '', trim($input)) ?? '';
    if ($clean === '') {
        return 0.0;
    }

    $negative = str_starts_with($clean, '-');
    $clean = str_replace('-', '', $clean);

    $lastComma = strrpos($clean, ',');
    $lastDot   = strrpos($clean, '.');

    if ($lastComma !== false && $lastDot !== false) {
        // Both present: whichever comes last is the decimal point.
        $decimal = $lastComma > $lastDot ? ',' : '.';
    } elseif ($lastComma !== false || $lastDot !== false) {
        $separator = $lastComma !== false ? ',' : '.';
        $occurrences = substr_count($clean, $separator);
        $tail = strlen($clean) - (int) strrpos($clean, $separator) - 1;

        // Repeated, or exactly three digits after it: grouping, not decimals.
        $decimal = ($occurrences > 1 || $tail === 3) ? '' : $separator;
    } else {
        $decimal = '';
    }

    if ($decimal === '') {
        $clean = str_replace([',', '.'], '', $clean);
    } else {
        $clean = str_replace($decimal === ',' ? '.' : ',', '', $clean);
        $clean = str_replace($decimal, '.', $clean);
    }

    return round((float) $clean, 2) * ($negative ? -1 : 1);
}

/* =========================================================== 3. STATUSES */

/**
 * Every booking status, in the order a booking normally moves through them.
 * The keys are stored in the database; the labels are what people read.
 */
function bk_statuses(): array
{
    return [
        'awaiting_eft'      => 'Awaiting EFT Payment',
        'proof_submitted'   => 'Payment Proof Submitted',
        'under_review'      => 'Payment Under Review',
        'payment_confirmed' => 'Payment Confirmed',
        'confirmed'         => 'Booking Confirmed',
        'cancelled'         => 'Cancelled',
        'declined'          => 'Declined',
        'completed'         => 'Completed',
        'refunded'          => 'Refunded',
    ];
}

function bk_status_label(string $status): string
{
    return bk_statuses()[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * A colour family for the status pill, matching the palette already used by
 * the admin panel and the public site.
 */
function bk_status_tone(string $status): string
{
    return [
        'awaiting_eft'      => 'wait',
        'proof_submitted'   => 'info',
        'under_review'      => 'info',
        'payment_confirmed' => 'good',
        'confirmed'         => 'good',
        'completed'         => 'done',
        'cancelled'         => 'off',
        'declined'          => 'bad',
        'refunded'          => 'off',
    ][$status] ?? 'off';
}

/** Statuses that still occupy a place in the diary. */
function bk_active_statuses(): array
{
    return ['awaiting_eft', 'proof_submitted', 'under_review', 'payment_confirmed', 'confirmed', 'completed'];
}

/** Statuses where the client may still upload or replace a proof of payment. */
function bk_awaiting_payment_statuses(): array
{
    return ['awaiting_eft', 'proof_submitted', 'under_review', 'declined'];
}

/** The payment side of a booking, phrased for the client. */
function bk_payment_label(array $booking): string
{
    switch ($booking['status']) {
        case 'awaiting_eft':      return 'Awaiting EFT payment';
        case 'proof_submitted':   return 'Proof of payment received';
        case 'under_review':      return 'Payment under review';
        case 'payment_confirmed':
        case 'confirmed':
        case 'completed':         return 'Payment confirmed';
        case 'declined':          return 'Proof of payment declined';
        case 'refunded':          return 'Refunded';
        case 'cancelled':         return 'Cancelled';
    }
    return bk_status_label((string) $booking['status']);
}

/* ======================================================== 4. REFERENCES */

/**
 * A booking reference people can read out over the phone, such as
 * ATF-26F3K9. The random tail keeps references unguessable, which matters
 * because it is also the EFT payment reference.
 */
function bk_new_reference(): string
{
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', bk('bk_reference_prefix', 'ATF')) ?: 'ATF');
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // no 0/O/1/I

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $tail = '';
        for ($i = 0; $i < 6; $i++) {
            $tail .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $reference = $prefix . '-' . $tail;
        if (!db_one('SELECT id FROM service_bookings WHERE reference = :r', [':r' => $reference])) {
            return $reference;
        }
    }

    // Astronomically unlikely; fall back to something certainly unique.
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/** Sequential document numbers, e.g. TKT-2026-0007 or RCT-2026-0007. */
function bk_document_number(string $table, string $column, string $prefix): string
{
    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';
    $row = db_one(
        "SELECT {$column} AS value FROM {$table} WHERE {$column} LIKE :like ORDER BY id DESC LIMIT 1",
        [':like' => $like]
    );

    $next = 1;
    if ($row && preg_match('/-(\d+)$/', (string) $row['value'], $match)) {
        $next = (int) $match[1] + 1;
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = $prefix . '-' . $year . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        if (!db_one("SELECT id FROM {$table} WHERE {$column} = :v", [':v' => $candidate])) {
            return $candidate;
        }
        $next++;
    }

    return $prefix . '-' . $year . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/** A short human-checkable verification code, e.g. 7K2M-9QXA. */
function bk_verification_code(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $make = static function () use ($alphabet): string {
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === 4) {
                $out .= '-';
            }
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    };

    do {
        $code = $make();
    } while (db_one('SELECT id FROM booking_tickets WHERE verification_code = :c', [':c' => $code]));

    return $code;
}

/* ====================================================== 5. CLIENT ACCOUNTS */

/** The signed-in client, or null. */
function client_user(): ?array
{
    start_session();
    if (empty($_SESSION['client_id'])) {
        return null;
    }

    static $cache = null;
    static $cachedId = 0;

    $id = (int) $_SESSION['client_id'];
    if ($cache !== null && $cachedId === $id) {
        return $cache;
    }

    $row = db_one('SELECT * FROM clients WHERE id = :id', [':id' => $id]);
    if (!$row || $row['status'] !== 'active') {
        unset($_SESSION['client_id']);
        return null;
    }

    $cache = $row;
    $cachedId = $id;
    return $row;
}

/** Send anonymous visitors to the sign-in page, remembering where they were. */
function require_client(): array
{
    $client = client_user();
    if ($client) {
        return $client;
    }
    start_session();
    $_SESSION['client_after_login'] = bk_current_url();
    redirect('auth/login.php');
}

/** The path + query of the current request, used for post-login redirects. */
function bk_current_url(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    $query = parse_url($uri, PHP_URL_QUERY);
    $file = basename($path);
    if ($file === '' || !preg_match('/^[a-z0-9_-]+\.php$/i', $file)) {
        return 'booking/account.php';
    }
    return $file . ($query ? '?' . $query : '');
}

/** Where to send a client after they sign in. Always an internal page. */
function bk_take_redirect(string $fallback = 'booking/account.php'): string
{
    start_session();
    $target = (string) ($_SESSION['client_after_login'] ?? '');
    unset($_SESSION['client_after_login']);

    if ($target === '' || !preg_match('#^[a-z0-9_-]+\.php(\?[^\s"\'<>]*)?$#i', $target)) {
        return $fallback;
    }
    if (in_array(strtolower(explode('?', $target)[0]), ['admin/', 'setup.php'], true)) {
        return $fallback;
    }
    return $target;
}

function client_logged_in(): bool
{
    return client_user() !== null;
}

/** Sign a client in. Returns null on success, or a message to show. */
function client_login(string $email, string $password): ?string
{
    $email = strtolower(trim($email));
    $client = db_one('SELECT * FROM clients WHERE email = :e', [':e' => $email]);

    if (!$client || !password_verify($password, $client['password_hash'])) {
        usleep(350000);
        return 'That email address and password do not match an account.';
    }
    if ($client['status'] !== 'active') {
        return 'That account has been suspended. Please contact us so we can help.';
    }

    if (password_needs_rehash($client['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE clients SET password_hash = :h WHERE id = :id', [
            ':h'  => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $client['id'],
        ]);
    }

    start_session();
    session_regenerate_id(true);
    $_SESSION['client_id'] = (int) $client['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    db_run('UPDATE clients SET last_login = :t, last_ip = :ip WHERE id = :id', [
        ':t'  => date('Y-m-d H:i:s'),
        ':ip' => client_ip(),
        ':id' => $client['id'],
    ]);

    return null;
}

function client_logout(): void
{
    start_session();
    unset($_SESSION['client_id'], $_SESSION['client_after_login'], $_SESSION['bk_draft']);
    session_regenerate_id(true);
}

/** True when this client still has to click the link in their welcome email. */
function client_needs_verification(?array $client): bool
{
    if (!$client) {
        return false;
    }
    if (!bk_bool('bk_require_verified_email', true)) {
        return false;
    }
    return trim((string) $client['email_verified_at']) === '';
}

/**
 * Issue a single-use token. Only its hash is stored, so a leaked database
 * row cannot be turned back into a working link.
 *
 * @return string the raw token to put in the emailed link
 */
function client_issue_token(int $clientId, string $kind, int $ttlMinutes): string
{
    $raw = bin2hex(random_bytes(32));
    $column = $kind === 'reset' ? 'reset' : 'verify';

    db_run(
        "UPDATE clients SET {$column}_hash = :h, {$column}_expires = :e, updated_at = :u WHERE id = :id",
        [
            ':h'  => hash('sha256', $raw),
            ':e'  => date('Y-m-d H:i:s', time() + ($ttlMinutes * 60)),
            ':u'  => date('Y-m-d H:i:s'),
            ':id' => $clientId,
        ]
    );

    return $raw;
}

/** Look a client up from a raw token, or null when it is wrong or expired. */
function client_by_token(string $kind, string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/i', $raw)) {
        return null;
    }
    $column = $kind === 'reset' ? 'reset' : 'verify';
    $client = db_one(
        "SELECT * FROM clients WHERE {$column}_hash = :h",
        [':h' => hash('sha256', $raw)]
    );
    if (!$client) {
        return null;
    }
    $expires = (string) $client[$column . '_expires'];
    if ($expires === '' || strtotime($expires) < time()) {
        return null;
    }
    return $client;
}

function client_clear_token(int $clientId, string $kind): void
{
    $column = $kind === 'reset' ? 'reset' : 'verify';
    db_run(
        "UPDATE clients SET {$column}_hash = '', {$column}_expires = '' WHERE id = :id",
        [':id' => $clientId]
    );
}

/**
 * Validate a password against the site's rules.
 * Returns an error message, or null when the password is acceptable.
 */
function client_password_problem(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Please choose a password of at least 10 characters.';
    }
    if (strlen($password) > 200) {
        return 'That password is too long. Please keep it under 200 characters.';
    }
    if (preg_match('/^[0-9]+$/', $password)) {
        return 'Please use letters as well as numbers in your password.';
    }
    return null;
}

/* =========================================================== 6. AUDIT LOG */

/**
 * Record something that happened to a booking. Every payment, approval,
 * decline, document and email passes through here.
 */
function bk_audit(int $bookingId, string $action, string $detail = '', ?array $actor = null): void
{
    if ($actor === null) {
        $staff = function_exists('admin_user') ? admin_user() : null;
        if ($staff) {
            $actor = ['type' => 'staff', 'id' => (int) $staff['id'], 'name' => $staff['name'] ?: $staff['username']];
        } else {
            $client = client_user();
            $actor = $client
                ? ['type' => 'client', 'id' => (int) $client['id'], 'name' => $client['full_name'] ?: $client['email']]
                : ['type' => 'system', 'id' => 0, 'name' => 'System'];
        }
    }

    db_run(
        'INSERT INTO booking_audit (booking_id, actor_type, actor_id, actor_name, action, detail, ip, created_at)
         VALUES (:b, :t, :i, :n, :a, :d, :ip, :c)',
        [
            ':b'  => $bookingId,
            ':t'  => (string) $actor['type'],
            ':i'  => (int) $actor['id'],
            ':n'  => mb_substr((string) $actor['name'], 0, 120),
            ':a'  => mb_substr($action, 0, 120),
            ':d'  => mb_substr($detail, 0, 500),
            ':ip' => client_ip(),
            ':c'  => date('Y-m-d H:i:s'),
        ]
    );
}

/** The audit trail for one booking, newest first. */
function bk_audit_trail(int $bookingId, int $limit = 200): array
{
    return db_all(
        'SELECT * FROM booking_audit WHERE booking_id = :b ORDER BY id DESC LIMIT ' . max(1, $limit),
        [':b' => $bookingId]
    );
}

/* ====================================================== 7. PRIVATE STORAGE */

/** Absolute path of one of the private storage folders. */
function bk_storage_path(string $kind): string
{
    $allowed = ['proofs', 'tickets', 'receipts'];
    $kind = in_array($kind, $allowed, true) ? $kind : 'proofs';
    $path = DATA_PATH . '/' . $kind;
    if (!is_dir($path)) {
        booking_prepare_storage();
    }
    return $path;
}

/**
 * Resolve a stored file name to a real path inside its folder.
 * Anything containing a directory separator or traversal is rejected.
 */
function bk_storage_file(string $kind, string $name): ?string
{
    $name = trim($name);
    if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name) || str_contains($name, '..')) {
        return null;
    }
    $path = bk_storage_path($kind) . '/' . $name;
    $real = realpath($path);
    $base = realpath(bk_storage_path($kind));
    if ($real === false || $base === false || !str_starts_with($real, $base)) {
        return null;
    }
    return is_file($real) ? $real : null;
}

/* ======================================================== 8. SMALL HELPERS */

/** "Wed 14 October 2026" */
function bk_date_long(string $date): string
{
    $time = strtotime($date);
    return $time ? date('D d F Y', $time) : $date;
}

/**
 * "2.4 MB" / "312 KB". Lives here rather than with the client screens
 * because the control panel lists uploaded files too.
 */
function bk_filesize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/**
 * A duration written the way a person would say it.
 * 45 → "45 minutes", 150 → "2 hours 30 minutes", 540 → "Full day".
 */
function bk_duration_label(int $minutes): string
{
    $minutes = max(1, $minutes);
    if ($minutes >= 480) {
        return 'Full day';
    }
    if ($minutes < 60) {
        return $minutes . ' minutes';
    }

    $hours = intdiv($minutes, 60);
    $rest  = $minutes % 60;
    $label = $hours . ($hours === 1 ? ' hour' : ' hours');

    return $rest > 0 ? $label . ' ' . $rest . ' minutes' : $label;
}

/** "09:00 – 10:30" */
function bk_time_range(string $start, string $end): string
{
    if ($start === '') {
        return '';
    }
    return $end === '' ? $start : $start . ' – ' . $end;
}

/** The site's own absolute address, used in emails and QR codes. */
function bk_site_url(): string
{
    $configured = rtrim(bk('bk_site_url', env('APP_URL')), '/');
    if ($configured !== '') {
        return $configured;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = rtrim($dir, '/');

    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/** Absolute URL for one of the site's own pages. */
function bk_url(string $relative): string
{
    return bk_site_url() . '/' . ltrim($relative, '/');
}

/**
 * Compare two values in constant time, tolerating a missing or non-string
 * argument (query strings can be arrays).
 */
function bk_token_equals(?string $known, $given): bool
{
    if (!is_string($given) || $known === null || $known === '') {
        return false;
    }
    return hash_equals($known, $given);
}

/* ======================================================= 8. THE BOT TRAP */

/**
 * A secret of the site's own, made once and kept in settings. Used to sign the
 * timestamp below so a bot cannot simply post an older one.
 */
function bot_trap_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $row = db_one('SELECT value AS v FROM settings WHERE ' . db_name('key') . ' = :k', [':k' => 'form_secret']);
    $secret = trim((string) ($row['v'] ?? ''));
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        db_setting_put_missing('form_secret', $secret);
        $row = db_one('SELECT value AS v FROM settings WHERE ' . db_name('key') . ' = :k', [':k' => 'form_secret']);
        $secret = trim((string) ($row['v'] ?? $secret));
    }
    return $secret;
}

/**
 * Two checks that cost a real visitor nothing and are awkward for a script:
 * a field that is hidden from people but irresistible to form-fillers, and a
 * signed note of when the page was drawn. Both go inside every public form,
 * alongside the CSRF token that is already there.
 */
function bot_trap_fields(): string
{
    $stamp = (string) time();
    $sig   = hash_hmac('sha256', $stamp, bot_trap_secret());

    return '<label class="hp-field" aria-hidden="true">Leave this field empty'
        . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label>'
        . '<input type="hidden" name="form_started" value="' . e($stamp) . '">'
        . '<input type="hidden" name="form_started_sig" value="' . e($sig) . '">';
}

/**
 * Returns a reason to reject the submission, or null to let it through.
 *
 * The timing test is deliberately gentle. Nobody reads a booking form, types
 * their company name and their phone number and submits inside three seconds,
 * but somebody pasting into a short contact box might be quick, so the caller
 * chooses the threshold. A submission with no timestamp at all is treated as
 * suspicious rather than fatal: the form may have been cached before this went
 * live, so it only fails once the field exists and does not verify.
 */
function bot_trap_problem(int $minSeconds = 3, int $maxSeconds = 43200): ?string
{
    // The invisible field. A person never sees it, so never fills it.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        return 'honeypot';
    }

    $stamp = (string) ($_POST['form_started'] ?? '');
    $sig   = (string) ($_POST['form_started_sig'] ?? '');
    if ($stamp === '' && $sig === '') {
        return null;                                    // an older cached form
    }

    if (!ctype_digit($stamp) || !hash_equals(hash_hmac('sha256', $stamp, bot_trap_secret()), $sig)) {
        return 'tampered';
    }

    $age = time() - (int) $stamp;
    if ($age < $minSeconds) {
        return 'too fast';
    }
    if ($age > $maxSeconds) {
        return 'expired';
    }

    return null;
}

/**
 * Free text that reads like an advertisement rather than a message. Spam sent
 * through a contact form is nearly always several links and a wall of writing;
 * a genuine enquiry about a stall is neither.
 */
function bot_trap_text_problem(string $text): ?string
{
    $links = preg_match_all('#\bhttps?://|\bwww\.|\[url|\[link#i', $text);
    if ($links >= 3) {
        return 'links';
    }
    if (preg_match('#<a\s|</a>|\[/?url\]#i', $text)) {
        return 'markup';
    }
    // Cyrillic and CJK runs in an otherwise English form: not our audience.
    if (preg_match('/[\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}]{6,}/u', $text)) {
        return 'script';
    }
    return null;
}

/**
 * Note a blocked submission in the activity log. Worth keeping: if genuine
 * bookings ever stop arriving, this is the first place to look, and it shows
 * the team the traps are doing something rather than nothing.
 */
function bot_trap_log(string $where, string $reason): void
{
    db_run(
        'INSERT INTO activity_log (user_name, action, detail, created_at) VALUES (?, ?, ?, ?)',
        ['system', 'blocked a suspected bot', mb_substr($where . ' — ' . $reason . ' — ' . client_ip(), 0, 300), date('Y-m-d H:i:s')]
    );
}

/**
 * Carry the original timestamp through a multi-step form.
 *
 * The booking runs details -> review -> confirm. If the confirm step minted a
 * fresh stamp, the only thing measured would be how long somebody looked at
 * the review screen, and a person who has already read it and clicks straight
 * through would be treated as a bot. Re-emitting what came in means the check
 * covers the whole journey, which is what we actually care about.
 */
function bot_trap_relay(): string
{
    $stamp = (string) ($_POST['form_started'] ?? '');
    $sig   = (string) ($_POST['form_started_sig'] ?? '');

    if ($stamp !== '' && ctype_digit($stamp)
        && hash_equals(hash_hmac('sha256', $stamp, bot_trap_secret()), $sig)) {
        return '<label class="hp-field" aria-hidden="true">Leave this field empty'
            . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label>'
            . '<input type="hidden" name="form_started" value="' . e($stamp) . '">'
            . '<input type="hidden" name="form_started_sig" value="' . e($sig) . '">';
    }

    return bot_trap_fields();
}
