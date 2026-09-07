<?php
declare(strict_types=1);

/**
 * EFT booking platform — database migrations.
 * ---------------------------------------------------------------------------
 * Purely additive. Every statement is CREATE TABLE IF NOT EXISTS, CREATE INDEX
 * IF NOT EXISTS or an ALTER TABLE guarded by a PRAGMA table_info() check, so
 * running this against a live database never touches an existing table, column
 * or row. It is safe to run repeatedly.
 *
 * The version below is written to data/schema-version.lock once the migration
 * has completed, which is how bootstrap.php knows it has nothing to do.
 */

/** Bump this when new tables, columns or seed rows are added below. */
const BOOKING_SCHEMA_VERSION = 6;

function booking_schema_marker(): string
{
    return DATA_PATH . '/schema-version.lock';
}

/** True when the booking tables are already at the current version. */
function booking_schema_current(): bool
{
    $marker = booking_schema_marker();
    if (!is_file($marker)) {
        return false;
    }
    return (int) trim((string) @file_get_contents($marker)) === BOOKING_SCHEMA_VERSION;
}

/**
 * Create every booking-platform table that is missing and add every column
 * introduced since the site was installed. Existing data is never rewritten.
 */
function booking_migrate(): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS clients (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    email             TEXT    NOT NULL UNIQUE,
    password_hash     TEXT    NOT NULL,
    full_name         TEXT    NOT NULL DEFAULT '',
    phone             TEXT    NOT NULL DEFAULT '',
    company           TEXT    NOT NULL DEFAULT '',
    address           TEXT    NOT NULL DEFAULT '',
    city              TEXT    NOT NULL DEFAULT '',
    country           TEXT    NOT NULL DEFAULT '',
    status            TEXT    NOT NULL DEFAULT 'active',
    email_verified_at TEXT    NOT NULL DEFAULT '',
    verify_hash       TEXT    NOT NULL DEFAULT '',
    verify_expires    TEXT    NOT NULL DEFAULT '',
    reset_hash        TEXT    NOT NULL DEFAULT '',
    reset_expires     TEXT    NOT NULL DEFAULT '',
    admin_notes       TEXT    NOT NULL DEFAULT '',
    last_login        TEXT    NOT NULL DEFAULT '',
    last_ip           TEXT    NOT NULL DEFAULT '',
    created_at        TEXT    NOT NULL DEFAULT '',
    updated_at        TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS services (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    name                   TEXT    NOT NULL DEFAULT '',
    slug                   TEXT    NOT NULL DEFAULT '',
    summary                TEXT    NOT NULL DEFAULT '',
    description            TEXT    NOT NULL DEFAULT '',
    location               TEXT    NOT NULL DEFAULT '',
    image                  TEXT    NOT NULL DEFAULT '',
    price                  REAL    NOT NULL DEFAULT 0,
    price_mode             TEXT    NOT NULL DEFAULT 'booking',
    duration_minutes       INTEGER NOT NULL DEFAULT 60,
    buffer_minutes         INTEGER NOT NULL DEFAULT 0,
    slot_capacity          INTEGER NOT NULL DEFAULT 1,
    capacity_unit          TEXT    NOT NULL DEFAULT 'booking',
    min_guests             INTEGER NOT NULL DEFAULT 1,
    max_guests             INTEGER NOT NULL DEFAULT 1,
    collect_guest_names    INTEGER NOT NULL DEFAULT 0,
    lead_time_hours        INTEGER NOT NULL DEFAULT 24,
    max_advance_days       INTEGER NOT NULL DEFAULT 180,
    payment_deadline_hours INTEGER NOT NULL DEFAULT 48,
    extra_field_label      TEXT    NOT NULL DEFAULT '',
    extra_field_help       TEXT    NOT NULL DEFAULT '',
    extra_field_required   INTEGER NOT NULL DEFAULT 0,
    instructions           TEXT    NOT NULL DEFAULT '',
    position               INTEGER NOT NULL DEFAULT 0,
    is_active              INTEGER NOT NULL DEFAULT 1,
    created_at             TEXT    NOT NULL DEFAULT '',
    updated_at             TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS service_hours (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id    INTEGER NOT NULL DEFAULT 0,
    weekday       INTEGER NOT NULL DEFAULT 1,
    start_time    TEXT    NOT NULL DEFAULT '09:00',
    end_time      TEXT    NOT NULL DEFAULT '17:00',
    slot_interval INTEGER NOT NULL DEFAULT 0,
    capacity      INTEGER NOT NULL DEFAULT 0,
    position      INTEGER NOT NULL DEFAULT 0,
    is_active     INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS blocked_dates (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL DEFAULT 0,
    start_date TEXT    NOT NULL DEFAULT '',
    end_date   TEXT    NOT NULL DEFAULT '',
    start_time TEXT    NOT NULL DEFAULT '',
    end_time   TEXT    NOT NULL DEFAULT '',
    reason     TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS service_bookings (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    reference        TEXT    NOT NULL UNIQUE,
    access_token     TEXT    NOT NULL DEFAULT '',
    client_id        INTEGER NOT NULL DEFAULT 0,
    service_id       INTEGER NOT NULL DEFAULT 0,
    service_name     TEXT    NOT NULL DEFAULT '',
    booking_date     TEXT    NOT NULL DEFAULT '',
    start_time       TEXT    NOT NULL DEFAULT '',
    end_time         TEXT    NOT NULL DEFAULT '',
    guests           INTEGER NOT NULL DEFAULT 1,
    seats            INTEGER NOT NULL DEFAULT 1,
    location         TEXT    NOT NULL DEFAULT '',
    unit_price       REAL    NOT NULL DEFAULT 0,
    amount_due       REAL    NOT NULL DEFAULT 0,
    amount_paid      REAL    NOT NULL DEFAULT 0,
    currency         TEXT    NOT NULL DEFAULT 'N$',
    status           TEXT    NOT NULL DEFAULT 'awaiting_eft',
    contact_name     TEXT    NOT NULL DEFAULT '',
    contact_email    TEXT    NOT NULL DEFAULT '',
    contact_phone    TEXT    NOT NULL DEFAULT '',
    company          TEXT    NOT NULL DEFAULT '',
    extra_details    TEXT    NOT NULL DEFAULT '',
    client_notes     TEXT    NOT NULL DEFAULT '',
    admin_notes      TEXT    NOT NULL DEFAULT '',
    decline_reason   TEXT    NOT NULL DEFAULT '',
    payment_deadline TEXT    NOT NULL DEFAULT '',
    confirmed_at     TEXT    NOT NULL DEFAULT '',
    cancelled_at     TEXT    NOT NULL DEFAULT '',
    completed_at     TEXT    NOT NULL DEFAULT '',
    reschedules      INTEGER NOT NULL DEFAULT 0,
    source           TEXT    NOT NULL DEFAULT 'online',
    ip               TEXT    NOT NULL DEFAULT '',
    created_at       TEXT    NOT NULL DEFAULT '',
    updated_at       TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS booking_guests (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL DEFAULT 0,
    full_name  TEXT    NOT NULL DEFAULT '',
    email      TEXT    NOT NULL DEFAULT '',
    phone      TEXT    NOT NULL DEFAULT '',
    notes      TEXT    NOT NULL DEFAULT '',
    position   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS eft_payments (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id           INTEGER NOT NULL DEFAULT 0,
    reference            TEXT    NOT NULL DEFAULT '',
    amount_due           REAL    NOT NULL DEFAULT 0,
    amount_declared      REAL    NOT NULL DEFAULT 0,
    amount_received      REAL    NOT NULL DEFAULT 0,
    currency             TEXT    NOT NULL DEFAULT 'N$',
    bank_reference       TEXT    NOT NULL DEFAULT '',
    admin_bank_reference TEXT    NOT NULL DEFAULT '',
    paid_on              TEXT    NOT NULL DEFAULT '',
    status               TEXT    NOT NULL DEFAULT 'awaiting',
    client_notes         TEXT    NOT NULL DEFAULT '',
    admin_notes          TEXT    NOT NULL DEFAULT '',
    decline_reason       TEXT    NOT NULL DEFAULT '',
    verified_by          INTEGER NOT NULL DEFAULT 0,
    verified_by_name     TEXT    NOT NULL DEFAULT '',
    verified_at          TEXT    NOT NULL DEFAULT '',
    refunded_at          TEXT    NOT NULL DEFAULT '',
    refund_reference     TEXT    NOT NULL DEFAULT '',
    created_at           TEXT    NOT NULL DEFAULT '',
    updated_at           TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS payment_proofs (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id       INTEGER NOT NULL DEFAULT 0,
    payment_id       INTEGER NOT NULL DEFAULT 0,
    stored_name      TEXT    NOT NULL DEFAULT '',
    original_name    TEXT    NOT NULL DEFAULT '',
    mime             TEXT    NOT NULL DEFAULT '',
    extension        TEXT    NOT NULL DEFAULT '',
    size             INTEGER NOT NULL DEFAULT 0,
    checksum         TEXT    NOT NULL DEFAULT '',
    amount           REAL    NOT NULL DEFAULT 0,
    bank_reference   TEXT    NOT NULL DEFAULT '',
    paid_on          TEXT    NOT NULL DEFAULT '',
    client_notes     TEXT    NOT NULL DEFAULT '',
    status           TEXT    NOT NULL DEFAULT 'submitted',
    uploaded_by      TEXT    NOT NULL DEFAULT 'client',
    uploaded_by_name TEXT    NOT NULL DEFAULT '',
    uploaded_ip      TEXT    NOT NULL DEFAULT '',
    reviewed_by_name TEXT    NOT NULL DEFAULT '',
    reviewed_at      TEXT    NOT NULL DEFAULT '',
    review_reason    TEXT    NOT NULL DEFAULT '',
    created_at       TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS booking_tickets (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id        INTEGER NOT NULL DEFAULT 0,
    ticket_number     TEXT    NOT NULL UNIQUE,
    verification_code TEXT    NOT NULL DEFAULT '',
    file_name         TEXT    NOT NULL DEFAULT '',
    status            TEXT    NOT NULL DEFAULT 'valid',
    issued_at         TEXT    NOT NULL DEFAULT '',
    checked_in_at     TEXT    NOT NULL DEFAULT '',
    checked_in_by     TEXT    NOT NULL DEFAULT '',
    created_at        TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS booking_receipts (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id     INTEGER NOT NULL DEFAULT 0,
    payment_id     INTEGER NOT NULL DEFAULT 0,
    receipt_number TEXT    NOT NULL UNIQUE,
    amount         REAL    NOT NULL DEFAULT 0,
    currency       TEXT    NOT NULL DEFAULT 'N$',
    file_name      TEXT    NOT NULL DEFAULT '',
    issued_at      TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS booking_requests (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id     INTEGER NOT NULL DEFAULT 0,
    client_id      INTEGER NOT NULL DEFAULT 0,
    type           TEXT    NOT NULL DEFAULT 'cancel',
    requested_date TEXT    NOT NULL DEFAULT '',
    requested_time TEXT    NOT NULL DEFAULT '',
    reason         TEXT    NOT NULL DEFAULT '',
    status         TEXT    NOT NULL DEFAULT 'pending',
    admin_response TEXT    NOT NULL DEFAULT '',
    handled_by     TEXT    NOT NULL DEFAULT '',
    handled_at     TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS email_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id  INTEGER NOT NULL DEFAULT 0,
    client_id   INTEGER NOT NULL DEFAULT 0,
    to_address  TEXT    NOT NULL DEFAULT '',
    subject     TEXT    NOT NULL DEFAULT '',
    template    TEXT    NOT NULL DEFAULT '',
    transport   TEXT    NOT NULL DEFAULT '',
    attachments TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'queued',
    error       TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS booking_audit (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL DEFAULT 0,
    actor_type TEXT    NOT NULL DEFAULT 'system',
    actor_id   INTEGER NOT NULL DEFAULT 0,
    actor_name TEXT    NOT NULL DEFAULT '',
    action     TEXT    NOT NULL DEFAULT '',
    detail     TEXT    NOT NULL DEFAULT '',
    ip         TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_sb_date      ON service_bookings (booking_date, start_time);
CREATE INDEX IF NOT EXISTS idx_sb_client    ON service_bookings (client_id, booking_date DESC);
CREATE INDEX IF NOT EXISTS idx_sb_status    ON service_bookings (status, booking_date);
CREATE INDEX IF NOT EXISTS idx_sb_service   ON service_bookings (service_id, booking_date);
CREATE INDEX IF NOT EXISTS idx_sb_created   ON service_bookings (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_hours_svc    ON service_hours (service_id, weekday);
CREATE INDEX IF NOT EXISTS idx_blocked_svc  ON blocked_dates (service_id, start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_pay_booking  ON eft_payments (booking_id);
CREATE INDEX IF NOT EXISTS idx_pay_status   ON eft_payments (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_proof_bkg    ON payment_proofs (booking_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ticket_bkg   ON booking_tickets (booking_id);
CREATE INDEX IF NOT EXISTS idx_ticket_code  ON booking_tickets (verification_code);
CREATE INDEX IF NOT EXISTS idx_receipt_bkg  ON booking_receipts (booking_id);
CREATE INDEX IF NOT EXISTS idx_request_bkg  ON booking_requests (booking_id, status);
CREATE INDEX IF NOT EXISTS idx_email_bkg    ON email_log (booking_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_bkg    ON booking_audit (booking_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_guests_bkg   ON booking_guests (booking_id, position);
CREATE INDEX IF NOT EXISTS idx_services_pos ON services (position, id);
SQL;

    if (db_is_mysql()) {
        db_run_sql_file(ROOT_PATH . '/database/mysql-schema.sql');
    } else {
        db()->exec($sql);
    }

    booking_add_columns();
    booking_seed_settings();
    booking_seed_page();
    booking_prepare_storage();

    @file_put_contents(booking_schema_marker(), (string) BOOKING_SCHEMA_VERSION);
}

/**
 * Give booking.php a row in the pages table so its title, menu label and hero
 * text are editable in the control panel like every other page. An existing
 * row is never touched, so anything the team has already written survives.
 */
function booking_seed_page(): void
{
    if (db_one("SELECT id FROM pages WHERE slug = 'booking'")) {
        return;
    }

    // Sit next to the existing "Book now" entry rather than at the end.
    $after = db_one("SELECT position FROM pages WHERE slug = 'book'");
    $position = $after ? (int) $after['position'] : 5;

    db_run(
        'INSERT INTO pages (slug, title, nav_label, hero_label, hero_title, hero_intro, meta_description, show_in_nav, is_published, position)
         VALUES (:slug, :title, :nav, :label, :heroTitle, :intro, :meta, 1, 1, :pos)',
        [
            ':slug'      => 'booking',
            ':title'     => 'Book a Service',
            ':nav'       => 'Book a service',
            ':label'     => 'ONLINE BOOKINGS',
            ':heroTitle' => 'Choose your date.|{Pay by EFT.}',
            ':intro'     => 'Pick the service you need, choose from the dates and times we still have open, and pay by electronic funds transfer. Your booking is confirmed as soon as our team has verified your payment.',
            ':meta'      => 'Book online with ' . setting('org_name', 'us') . '. Choose a service, date and time, then pay by EFT and upload your proof of payment.',
            ':pos'       => $position,
        ]
    );
}

/**
 * Columns added after the first release. Each is checked against
 * PRAGMA table_info() so nothing is ever dropped or redefined.
 *
 * @return string[] the columns that were added
 */
function booking_add_columns(): array
{
    /** @var array<string, array<string, string>> $additions table => column => definition */
    $additions = [];

    $added = [];
    foreach ($additions as $table => $columns) {
        if (!db_table_exists($table)) {
            continue;
        }
        $present = db_table_columns($table);
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $present, true)) {
                db()->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                $added[] = $table . '.' . $name;
            }
        }
    }

    return $added;
}

/**
 * Write the default value of every booking setting that does not exist yet.
 * A key already present in the settings table is left exactly as it is.
 */
function booking_seed_settings(): void
{
    foreach (booking_setting_defaults() as $key => $value) {
        db_setting_put_missing($key, (string) $value);
    }
}

/**
 * Defaults for every booking-platform setting.
 *
 * Banking details deliberately reuse the keys the site already holds
 * (bank_account_name, bank_name, …) so nothing has to be typed twice.
 */
function booking_setting_defaults(): array
{
    return [
        /* Platform */
        'bk_enabled'                => '1',
        'bk_nav_enabled'            => '1',
        'bk_nav_label'              => 'Book a service',
        'bk_intro_label'            => 'ONLINE BOOKINGS',
        'bk_intro_title'            => 'Reserve your place, {pay by EFT.}',
        'bk_intro_text'             => 'Choose a service, pick a date and time that suits you, then pay by electronic funds transfer. Your booking is confirmed as soon as our team has verified your payment.',
        'bk_currency'               => 'N$',
        'bk_reference_prefix'       => 'ATF',
        'bk_site_url'               => '',
        'nav_cta_text'              => 'Book online',
        'nav_cta_link'              => 'booking/',
        'bk_require_verified_email' => '1',

        /* Payment */
        'bk_payment_deadline_hours' => '48',
        'bk_bank_swift'             => '',
        'bk_payment_instructions'   => "Use your booking reference as the payment reference so we can match your transfer.\nTransfers can take up to 48 hours to reflect in our account.\nUpload your proof of payment as soon as the transfer is done.",
        'bk_payment_note'           => 'Your booking is only confirmed once a member of our team has verified your proof of payment. Uploading a file does not confirm the booking on its own.',

        /* Uploads */
        'bk_upload_max_mb'          => '6',
        'bk_upload_types'           => 'pdf,jpg,jpeg,png',
        'bk_storage_verified'       => '0',
        'bk_capacity_reviewed'      => '1',

        /* Business rules */
        'bk_cancel_enabled'         => '1',
        'bk_cancel_min_hours'       => '48',
        'bk_reschedule_enabled'     => '1',
        'bk_reschedule_min_hours'   => '48',
        'bk_reschedule_max'         => '2',
        'bk_cancel_policy'          => 'Cancellations requested more than 48 hours before the booking date are reviewed by our team. Refunds follow the terms you accepted when booking.',
        'bk_terms'                  => "Bookings are held until the payment deadline shown on your booking and released afterwards.\nProof of payment must show the booking reference.\nYour ticket must be presented at the venue, printed or on your phone.",
        'bk_ticket_instructions'    => 'Bring this ticket with you, printed or on your phone. Please arrive 15 minutes before your start time. This ticket is valid only for the date, time and guest count shown.',

        /* Email */
        'bk_from_name'              => '',
        'bk_from_email'             => '',
        'bk_reply_to'               => '',
        'bk_admin_emails'           => '',
        'bk_mail_transport'         => 'mail',
        'bk_smtp_host'              => '',
        'bk_smtp_port'              => '587',
        'bk_smtp_secure'            => 'tls',
        'bk_smtp_user'              => '',
        'bk_smtp_pass'              => '',
        'bk_smtp_verify_peer'       => '1',
        'bk_email_footer'           => '',
    ];
}

/**
 * Create the private storage folders and drop a deny-all rule into each.
 * They live under data/, which the site already blocks from the web.
 */
function booking_prepare_storage(): void
{
    $deny = "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\nphp_flag engine off\n";

    $webConfig = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<configuration>\n  <system.webServer>\n"
        . "    <authorization><deny users=\"*\" /></authorization>\n"
        . "    <handlers><clear /></handlers>\n"
        . "  </system.webServer>\n</configuration>\n";

    // tmp holds the PDF generator's font cache and the scaled logo.
    foreach (['proofs', 'tickets', 'receipts', 'tmp'] as $folder) {
        $path = DATA_PATH . '/' . $folder;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if (!is_dir($path)) {
            continue;
        }
        if (!is_file($path . '/.htaccess')) {
            @file_put_contents($path . '/.htaccess', $deny);
        }
        if (!is_file($path . '/web.config')) {
            @file_put_contents($path . '/web.config', $webConfig);
        }
        if (!is_file($path . '/index.html')) {
            @file_put_contents($path . '/index.html', '');
        }
    }
}
