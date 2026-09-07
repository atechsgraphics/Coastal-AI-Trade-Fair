<?php
declare(strict_types=1);

/**
 * Copy everything out of the SQLite database into a MySQL import file.
 * ---------------------------------------------------------------------------
 * Run from the command line, on the machine that holds the SQLite database:
 *
 *     php database/export-to-mysql.php
 *
 * It writes database/site-data.sql — the schema followed by every row the site
 * currently holds. Import that one file through phpMyAdmin and the live
 * database matches this one exactly.
 *
 * Reads only. The SQLite database is never modified.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

/* Read from SQLite regardless of what env.php now says the site should use. */
$sqliteFile = null;
foreach (glob(DATA_PATH . '/*.db') ?: [] as $candidate) {
    $sqliteFile = $candidate;
}
if ($sqliteFile === null) {
    fwrite(STDERR, "No SQLite database found in " . DATA_PATH . "\n");
    exit(1);
}

$source = new PDO('sqlite:' . $sqliteFile, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/* The order matters only for readability; there are no foreign keys. */
$tables = [
    'settings', 'users', 'pages', 'blocks', 'programme_days', 'packages',
    'stalls', 'bookings', 'partners', 'speakers', 'gallery', 'faqs',
    'enquiries', 'media', 'activity_log',
    'clients', 'services', 'service_hours', 'blocked_dates',
    'service_bookings', 'booking_guests', 'eft_payments', 'payment_proofs',
    'booking_tickets', 'booking_receipts', 'booking_requests',
    'email_log', 'booking_audit',
];

/* rate_hits is throwaway rate-limiting data — no point carrying it over. */

$out = [];
$out[] = '-- Coastal AI Summit & SME Trade Fair — full database';
$out[] = '-- Generated ' . date('d F Y, H:i') . ' from ' . basename($sqliteFile);
$out[] = '--';
$out[] = '-- Import this one file through phpMyAdmin: choose the database,';
$out[] = '-- open the Import tab, pick this file, press Go.';
$out[] = '';
$out[] = 'SET NAMES utf8mb4;';
$out[] = "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';";
$out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$out[] = '';
$out[] = '-- ---------------------------------------------------------- schema';
$out[] = '';
$out[] = trim((string) file_get_contents($root . '/database/mysql-schema.sql'));
$out[] = '';
$out[] = '-- ------------------------------------------------------------ data';

$reserved = ['key'];
$totalRows = 0;

foreach ($tables as $table) {
    $exists = $source->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name=" . $source->quote($table)
    )->fetch();
    if (!$exists) {
        continue;
    }

    $rows = $source->query("SELECT * FROM \"{$table}\"")->fetchAll();
    $out[] = '';
    $out[] = "-- {$table}: " . count($rows) . ' row(s)';
    if (!$rows) {
        continue;
    }

    $columns = array_keys($rows[0]);
    $quoted = array_map(
        static fn (string $c): string => in_array($c, $reserved, true) ? "`{$c}`" : $c,
        $columns
    );

    // A few hundred rows at most, so one INSERT per row keeps the file easy
    // to read and lets phpMyAdmin report exactly where anything goes wrong.
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column];
            if ($value === null) {
                $values[] = 'NULL';
            } elseif (is_int($value) || is_float($value)) {
                $values[] = (string) $value;
            } else {
                $values[] = $source->quote((string) $value);
            }
        }
        $out[] = 'INSERT INTO ' . $table . ' (' . implode(', ', $quoted) . ') VALUES ('
            . implode(', ', $values) . ');';
        $totalRows++;
    }
}

$out[] = '';
$out[] = 'SET FOREIGN_KEY_CHECKS = 1;';
$out[] = '';

$target = $root . '/database/site-data.sql';
file_put_contents($target, implode("\n", $out) . "\n");

echo "Wrote " . basename($target) . "\n";
echo "  tables : " . count($tables) . "\n";
echo "  rows   : " . $totalRows . "\n";
echo "  size   : " . round(filesize($target) / 1024) . " KB\n";
