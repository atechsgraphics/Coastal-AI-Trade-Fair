<?php
declare(strict_types=1);

/**
 * Coastal AI Summit & SME Trade Fair 2026
 * ---------------------------------------
 * Core bootstrap: paths, database connection, settings cache and shared helpers.
 * Every public page and the admin panel include this file first.
 */

define('ROOT_PATH', dirname(__DIR__));
define('DATA_PATH', ROOT_PATH . '/storage');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('LOCK_FILE', DATA_PATH . '/installed.lock');

/**
 * Resolve the database file name.
 *
 * A fresh install gets an unguessable name (site-<random>.db) recorded in
 * storage/config.php. The folder is also blocked by .htaccess / web.config, but
 * this means the database still cannot be downloaded on a server that ignores
 * those files. Installs that already use the plain name keep working.
 */
if (!is_dir(DATA_PATH)) {
    @mkdir(DATA_PATH, 0775, true);
}

$coastalDbName = 'site.db';
$coastalConfig = DATA_PATH . '/config.php';

if (is_file($coastalConfig)) {
    $coastalCfg = @include $coastalConfig;
    if (is_array($coastalCfg) && !empty($coastalCfg['db'])) {
        $coastalDbName = basename((string) $coastalCfg['db']);
    }
} elseif (!is_file(DATA_PATH . '/site.db') && is_writable(DATA_PATH)) {
    $coastalDbName = 'site-' . bin2hex(random_bytes(8)) . '.db';
    @file_put_contents($coastalConfig, "<?php\n// Generated at install time. Do not edit.\nreturn ['db' => '" . $coastalDbName . "'];\n");
}

define('DB_FILE', DATA_PATH . '/' . $coastalDbName);
unset($coastalDbName, $coastalConfig, $coastalCfg);

define('MAX_UPLOAD_BYTES', 6 * 1024 * 1024);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Africa/Windhoek');

/* ------------------------------------------------------------------ database */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_PATH)) {
        @mkdir(DATA_PATH, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

/** Run a query and return every row. */
function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Run a query and return the first row (or null). */
function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Run a statement that changes data; returns the affected row count. */
function db_run(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function is_installed(): bool
{
    return is_file(DB_FILE) && is_file(LOCK_FILE);
}

/** Redirect to the installer when the site has not been set up yet. */
function require_installed(): void
{
    if (is_installed()) {
        return;
    }
    header('Location: setup.php');
    exit;
}

/* ------------------------------------------------------------------ settings */

/**
 * All site settings as a key => value map, loaded once per request.
 */
function settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings();
    $value = $all[$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

function setting_bool(string $key, bool $default = false): bool
{
    $all = settings();
    if (!array_key_exists($key, $all) || $all[$key] === '') {
        return $default;
    }
    return in_array(strtolower($all[$key]), ['1', 'yes', 'true', 'on'], true);
}

function setting_save(string $key, string $value): void
{
    db_run(
        'INSERT INTO settings (key, value) VALUES (:k, :v)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        [':k' => $key, ':v' => $value]
    );
}

/* ------------------------------------------------------------------- output */

/** HTML-escape a value for safe output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape text but keep author-friendly formatting:
 * blank lines become paragraphs, single newlines become <br>.
 */
function rich(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $blocks = preg_split('/\R{2,}/', $value) ?: [];
    $html = '';
    foreach ($blocks as $block) {
        $html .= '<p>' . nl2br(e(trim($block))) . '</p>';
    }
    return $html;
}

/** Escape a single-paragraph string but keep <br> for line breaks. */
function nl(?string $value): string
{
    return nl2br(e(trim((string) $value)));
}

/**
 * Split a textarea of one-item-per-line into a clean array.
 */
function lines(?string $value): array
{
    $parts = preg_split('/\R/', (string) $value) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

/**
 * Renders a headline where the part inside {curly braces} is emphasised.
 * "Where coastal {ambition} meets AI." => Where coastal <em>ambition</em> meets AI.
 * A pipe character forces a line break.
 */
function headline(?string $value): string
{
    $html = e(trim((string) $value));
    $html = preg_replace('/\{(.+?)\}/u', '<em>$1</em>', $html) ?? $html;
    return str_replace('|', '<br>', $html);
}

/** Path to an image, falling back to a placeholder when missing. */
function img_src(?string $path, string $fallback = ''): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return $fallback;
    }
    if (preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }
    return ltrim($path, '/');
}

/* ------------------------------------------------------------ addresses */

/**
 * The web address of the site's own root, ending in a slash.
 *
 * Pages live at different depths — index.php at the top, auth/login.php and
 * booking/pay.php one level down — so a link written as "about.php" cannot be
 * trusted to mean the same thing everywhere. Everything is written relative to
 * the site root instead and passed through url().
 *
 * Works whether the site is the whole domain or sits in a subfolder, as it
 * does under XAMPP.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $webDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    // Climb back up by however many folders the running script sits below the
    // site root, so the answer is the same from every page.
    $root = realpath(ROOT_PATH);
    $here = isset($_SERVER['SCRIPT_FILENAME']) ? realpath(dirname((string) $_SERVER['SCRIPT_FILENAME'])) : false;

    if ($root !== false && $here !== false && str_starts_with($here, $root)) {
        $inside = trim(str_replace('\\', '/', substr($here, strlen($root))), '/');
        if ($inside !== '') {
            foreach (explode('/', $inside) as $ignored) {
                $webDir = rtrim(str_replace('\\', '/', dirname($webDir)), '/');
            }
        }
    }

    $base = ($webDir === '' ? '' : $webDir) . '/';
    return $base;
}

/** A link to somewhere on this site, written from the site root. */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    if ($path !== '' && preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }
    return base_url() . $path;
}

/** Where a page in the pages table actually lives. */
function page_url(string $slug): string
{
    $special = [
        'home'    => 'index.php',
        'booking' => 'booking/',
    ];
    return url($special[$slug] ?? $slug . '.php');
}

function redirect(string $url): void
{
    // A bare path is always meant from the site root, never from the folder
    // the current script happens to be in.
    if (!preg_match('#^(https?:)?//#i', $url) && !str_starts_with($url, '/')) {
        $url = url($url);
    }
    header('Location: ' . $url, true, 303);
    exit;
}

/* ---------------------------------------------------------------- event time */

/**
 * The event start as a DateTimeImmutable, used by the countdown and by
 * "days to go" copy across the site.
 */
function event_start(): DateTimeImmutable
{
    $date = setting('event_start_date', '2026-09-30');
    $time = setting('event_start_time', '08:00');
    try {
        return new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(setting('event_timezone', 'Africa/Windhoek')));
    } catch (Exception $e) {
        return new DateTimeImmutable('2026-09-30 08:00');
    }
}

function event_end(): DateTimeImmutable
{
    $date = setting('event_end_date', '2026-10-03');
    $time = setting('event_end_time', '17:00');
    try {
        return new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(setting('event_timezone', 'Africa/Windhoek')));
    } catch (Exception $e) {
        return new DateTimeImmutable('2026-10-03 17:00');
    }
}

/** "30 September — 03 October 2026" built from the stored dates. */
function event_date_range(): string
{
    $start = event_start();
    $end = event_end();
    $startFmt = $start->format('Y') === $end->format('Y') && $start->format('M') === $end->format('M')
        ? $start->format('d')
        : $start->format('d F');
    return $startFmt . ' – ' . $end->format('d F Y');
}

/** running | upcoming | finished */
function event_phase(): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone(setting('event_timezone', 'Africa/Windhoek')));
    if ($now < event_start()) {
        return 'upcoming';
    }
    return $now <= event_end() ? 'running' : 'finished';
}

/* -------------------------------------------------------------- content data */

/** One page record by slug. */
function page_meta(string $slug): array
{
    $row = db_one('SELECT * FROM pages WHERE slug = :s', [':s' => $slug]);
    return $row ?? [
        'slug' => $slug, 'title' => setting('site_name'), 'nav_label' => ucfirst($slug),
        'hero_label' => '', 'hero_title' => '', 'hero_intro' => '',
        'meta_description' => setting('meta_description'), 'is_published' => 1,
    ];
}

/** Navigation entries, in order. */
function nav_pages(): array
{
    return db_all(
        'SELECT slug, nav_label FROM pages
         WHERE show_in_nav = 1 AND is_published = 1 AND slug != :home
         ORDER BY position, id',
        [':home' => 'home']
    );
}

/**
 * Content blocks for a page section, e.g. blocks('home', 'focus').
 */
function blocks(string $page, string $section): array
{
    return db_all(
        'SELECT * FROM blocks WHERE page = :p AND section = :s AND is_active = 1 ORDER BY position, id',
        [':p' => $page, ':s' => $section]
    );
}

/** Active rows of a content table, ordered for display. */
function active(string $table, string $where = '', array $params = []): array
{
    $allowed = ['programme_days', 'packages', 'stalls', 'partners', 'speakers', 'gallery', 'faqs', 'blocks'];
    if (!in_array($table, $allowed, true)) {
        return [];
    }
    $sql = "SELECT * FROM {$table} WHERE is_active = 1";
    if ($where !== '') {
        $sql .= " AND {$where}";
    }
    $sql .= ' ORDER BY position, id';
    return db_all($sql, $params);
}

/** Partners grouped by their category key. */
function partners_by_category(): array
{
    $grouped = [];
    foreach (active('partners') as $row) {
        $grouped[$row['category']][] = $row;
    }
    return $grouped;
}

function partner_categories(): array
{
    return [
        'sponsor_main' => 'Main Sponsor',
        'sponsor'      => 'Sponsor',
        'partner'      => 'Partner & Collaboration',
        'exhibitor'    => 'Corporate Exhibitor',
        'foundation'   => 'Foundation & Organiser',
    ];
}

/* ------------------------------------------------------------------ security */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_name('coastalai_session');
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    start_session();
    $sent = (string) ($_POST['_token'] ?? '');
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/** Simple per-key rate limiter backed by the database. */
function rate_limit(string $key, int $max, int $seconds): bool
{
    $since = date('Y-m-d H:i:s', time() - $seconds);
    db_run('DELETE FROM rate_hits WHERE created_at < :cut', [':cut' => date('Y-m-d H:i:s', time() - 86400)]);
    $row = db_one(
        'SELECT COUNT(*) AS c FROM rate_hits WHERE key = :k AND created_at >= :since',
        [':k' => $key, ':since' => $since]
    );
    if ((int) ($row['c'] ?? 0) >= $max) {
        return false;
    }
    db_run('INSERT INTO rate_hits (key, created_at) VALUES (:k, :t)', [':k' => $key, ':t' => date('Y-m-d H:i:s')]);
    return true;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/* ---------------------------------------------------- EFT booking platform */

/**
 * The booking platform's shared core. It only declares functions, so pages
 * that never touch bookings pay nothing for it.
 */
require_once __DIR__ . '/platform.php';

/**
 * Bring the booking tables up to date the first time a request arrives after
 * an upgrade. The check is a single file read; the migration itself is
 * additive and never rewrites existing tables or rows.
 */
if (is_installed() && !booking_schema_current()) {
    try {
        booking_migrate();
    } catch (Throwable $e) {
        error_log('Booking schema migration failed: ' . $e->getMessage());
    }
}
