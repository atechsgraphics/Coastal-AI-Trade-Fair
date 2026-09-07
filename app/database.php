<?php
declare(strict_types=1);

/**
 * Database driver layer.
 * ---------------------------------------------------------------------------
 * The site was written for SQLite and now also runs on MySQL/MariaDB, which is
 * what shared hosting provides. Everything the two engines disagree about is
 * gathered here so the rest of the code can stay written once:
 *
 *   - how a connection is opened
 *   - quoting an identifier ("key" is a reserved word in MySQL)
 *   - asking whether a table or column exists
 *   - inserting-or-updating a settings row
 *   - taking a write lock before checking availability
 *
 * Which engine is used comes from storage/env.php:
 *
 *     'DB_DRIVER' => 'mysql',  'DB_HOST' => 'localhost',
 *     'DB_NAME'   => '...',    'DB_USER' => '...',  'DB_PASSWORD' => '...'
 *
 * With no DB_DRIVER set it stays on SQLite, so an existing install and local
 * development keep working untouched.
 */

/**
 * Connection settings, read once.
 *
 * env() lives in platform.php, which is loaded after this file, so the
 * environment file is read directly here.
 */
function db_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = [];
    $path = DATA_PATH . '/env.php';
    if (is_file($path)) {
        $loaded = @include $path;
        if (is_array($loaded)) {
            $file = $loaded;
        }
    }

    $get = static function (string $key, string $default = '') use ($file): string {
        if (isset($file[$key]) && (string) $file[$key] !== '') {
            return (string) $file[$key];
        }
        $env = getenv($key);
        return is_string($env) && $env !== '' ? $env : $default;
    };

    $driver = strtolower($get('DB_DRIVER', 'sqlite'));

    $config = [
        'driver'   => $driver === 'mysql' || $driver === 'mariadb' ? 'mysql' : 'sqlite',
        'host'     => $get('DB_HOST', 'localhost'),
        'port'     => $get('DB_PORT', '3306'),
        'name'     => $get('DB_NAME', ''),
        'user'     => $get('DB_USER', ''),
        'password' => $get('DB_PASSWORD', ''),
        'charset'  => $get('DB_CHARSET', 'utf8mb4'),
    ];

    return $config;
}

/** 'sqlite' or 'mysql'. */
function db_driver(): string
{
    return db_config()['driver'];
}

function db_is_mysql(): bool
{
    return db_driver() === 'mysql';
}

/**
 * Quote a column or table name for the engine in use.
 * Needed because `key` is a reserved word in MySQL.
 */
function db_name(string $identifier): string
{
    $identifier = preg_replace('/[^A-Za-z0-9_]/', '', $identifier) ?? '';
    return db_is_mysql() ? '`' . $identifier . '`' : '"' . $identifier . '"';
}

/**
 * True when the site has a database it can actually use. On MySQL that means
 * the connection works and the settings table is there; on SQLite, that the
 * file exists.
 */
function db_ready(): bool
{
    if (!db_is_mysql()) {
        return is_file(DB_FILE);
    }
    try {
        return db_table_exists('settings');
    } catch (Throwable $e) {
        return false;
    }
}

/** Does this table exist? Portable across both engines. */
function db_table_exists(string $table): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
    if ($table === '') {
        return false;
    }

    if (db_is_mysql()) {
        $row = db_one(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            [':t' => $table]
        );
        return $row !== null;
    }

    return db_one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t", [':t' => $table]) !== null;
}

/**
 * The column names of a table.
 *
 * @return string[]
 */
function db_table_columns(string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
    if ($table === '') {
        return [];
    }

    if (db_is_mysql()) {
        $rows = db_all(
            'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            [':t' => $table]
        );
    } else {
        $rows = db_all("PRAGMA table_info({$table})");
    }

    $names = [];
    foreach ($rows as $row) {
        $names[] = (string) ($row['name'] ?? $row['NAME'] ?? '');
    }
    return array_filter($names);
}

/**
 * Write a settings row, replacing any value already stored under that key.
 * The two engines spell this differently.
 */
function db_setting_put(string $key, string $value): void
{
    $column = db_name('key');

    if (db_is_mysql()) {
        db_run(
            "INSERT INTO settings ({$column}, value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [':k' => $key, ':v' => $value]
        );
        return;
    }

    db_run(
        "INSERT INTO settings ({$column}, value) VALUES (:k, :v)
         ON CONFLICT({$column}) DO UPDATE SET value = excluded.value",
        [':k' => $key, ':v' => $value]
    );
}

/** Write a settings row only when the key is not there yet. */
function db_setting_put_missing(string $key, string $value): void
{
    $column = db_name('key');

    if (db_is_mysql()) {
        db_run(
            "INSERT IGNORE INTO settings ({$column}, value) VALUES (:k, :v)",
            [':k' => $key, ':v' => $value]
        );
        return;
    }

    db_run(
        "INSERT INTO settings ({$column}, value) VALUES (:k, :v) ON CONFLICT({$column}) DO NOTHING",
        [':k' => $key, ':v' => $value]
    );
}

/**
 * Open a transaction that takes its write lock immediately, so two people
 * booking the last place cannot both pass the availability check.
 */
function db_begin_exclusive(): void
{
    $pdo = db();
    if (db_is_mysql()) {
        $pdo->exec('START TRANSACTION');
        return;
    }
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('BEGIN IMMEDIATE');
}

/**
 * Lock the rows this booking would compete for. SQLite already holds a write
 * lock from BEGIN IMMEDIATE; MySQL needs to be asked.
 */
function db_lock_slot(int $serviceId, string $date): void
{
    if (!db_is_mysql()) {
        return;
    }
    db_all(
        'SELECT id FROM service_bookings WHERE service_id = :s AND booking_date = :d FOR UPDATE',
        [':s' => $serviceId, ':d' => $date]
    );
}

/**
 * The DDL for whichever engine is in use. Both are idempotent, so running
 * this against an existing database changes nothing.
 */
function db_schema_file(string $which): string
{
    $folder = ROOT_PATH . '/database';
    return db_is_mysql() ? $folder . '/' . $which . '-mysql.sql' : '';
}

/**
 * Run a .sql file one statement at a time.
 * Only used for the MySQL schema; SQLite keeps its DDL inline in PHP.
 */
function db_run_sql_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $sql = (string) file_get_contents($path);

    // Strip comments, then split on semicolons at end of line.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;\s*[\r\n]+/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement === '' || $statement === ';') {
            continue;
        }
        db()->exec($statement);
    }
}
