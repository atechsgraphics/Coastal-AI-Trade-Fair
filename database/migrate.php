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
const BOOKING_SCHEMA_VERSION = 13;

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
    booking_correct_content();
    booking_correct_content_v11();
    booking_configure_mailbox();
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
    $additions = [
        // Set on an account whose password was handed over rather than chosen,
        // so the control panel makes them pick their own before going further.
        'users' => ['must_change_password' => 'INTEGER NOT NULL DEFAULT 0'],
    ];

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
        'section_bg_image'          => 'images/generated/namibia-ai-coast-3d-v2.png',
        'hero_slides'               => "images/generated/namibia-ai-hero-3d.png
images/generated/namibia-ai-coast-3d-v2.png",
        'cta_bg_image'              => 'images/generated/namibia-ai-coast-3d-v2.png',
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
        'bk_ticket_auto_issue' => '1',
        'bk_ticket_auto_email' => '1',
        'ticket_banner_image'  => 'images/generated/namibia-ai-hero-3d.png',

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


/* ==========================================================================
   CONTENT THE EVENT TEAM CONFIRMED (schema version 10)
   --------------------------------------------------------------------------
   The team supplied its final details after the site was first seeded: the
   two mailboxes on the event's own domain, and the fact that the AI & Digital
   Masterclass runs for three days alongside the four-day fair, not four.

   Every change below is conditional. A setting is rewritten only while it
   still holds the exact value the installer wrote, and wording is only
   touched where the stale phrase is still present, so anything the team has
   since edited in the control panel survives. Running it twice changes
   nothing the second time.
   ========================================================================== */

/** Replace a setting, but only while it still holds the value we seeded. */
function booking_setting_correct(string $key, string $stale, string $fresh): void
{
    $column = db_name('key');
    $row = db_one("SELECT value AS v FROM settings WHERE {$column} = :k", [':k' => $key]);
    if ($row === null) {
        db_setting_put_missing($key, $fresh);
        return;
    }
    if (trim((string) $row['v']) === $stale) {
        db_run("UPDATE settings SET value = :v WHERE {$column} = :k", [':v' => $fresh, ':k' => $key]);
    }
}

/** Give a setting a value only while it is still empty. */
function booking_setting_fill(string $key, string $value): void
{
    $column = db_name('key');
    $row = db_one("SELECT value AS v FROM settings WHERE {$column} = :k", [':k' => $key]);
    if ($row === null) {
        db_setting_put_missing($key, $value);
        return;
    }
    if (trim((string) $row['v']) === '') {
        db_run("UPDATE settings SET value = :v WHERE {$column} = :k", [':v' => $value, ':k' => $key]);
    }
}

function booking_correct_content(): void
{
    booking_correct_email_addresses();
    booking_correct_masterclass_length();
    booking_seed_event_structure();
}

/**
 * The site was seeded with two gmail addresses. The event runs its own
 * mailboxes now. The booking side had no sender, reply-to or staff recipient
 * configured at all, so confirmations had nowhere to come from and new
 * bookings notified nobody.
 */
function booking_correct_email_addresses(): void
{
    $primary   = 'info@coastalaitradefair.com';
    $secondary = 'mary@coastalaitradefair.com';
    $both      = $primary . ', ' . $secondary;

    booking_setting_correct('email_primary', 'coastalaisummit@gmail.com', $primary);
    booking_setting_correct('email_secondary', 'coastaltradefair@gmail.com', $secondary);
    booking_setting_correct(
        'email_form_to',
        'coastalaisummit@gmail.com, coastaltradefair@gmail.com',
        $both
    );
    booking_setting_correct('payment_proof_email', 'coastalaisummit@gmail.com', $primary);

    booking_setting_fill('bk_from_email', $primary);
    booking_setting_fill('bk_reply_to', $primary);
    booking_setting_fill('bk_admin_emails', $both);

    $keyColumn = db_name('key');
    $eventName = db_one("SELECT value AS v FROM settings WHERE {$keyColumn} = 'event_name'");
    booking_setting_fill('bk_from_name', trim((string) ($eventName['v'] ?? 'Coastal AI Summit & SME Trade Fair')));
}

/**
 * The masterclass is a three-day track running alongside the four-day fair.
 * The booking listing and the rate card both called it four days.
 */
function booking_correct_masterclass_length(): void
{
    $stale = 'Full 4-day training';
    $fresh = 'Full 3-day training';

    foreach (db_all("SELECT id, summary, description FROM services WHERE summary LIKE '%4-day training%' OR description LIKE '%4-day training%'") as $row) {
        db_run(
            'UPDATE services SET summary = :s, description = :d WHERE id = :id',
            [
                ':s'  => str_replace($stale, $fresh, (string) $row['summary']),
                ':d'  => str_replace($stale, $fresh, (string) $row['description']),
                ':id' => (int) $row['id'],
            ]
        );
    }

    foreach (db_all("SELECT id, details FROM stalls WHERE details LIKE '%4-day training%'") as $row) {
        db_run(
            'UPDATE stalls SET details = :d WHERE id = :id',
            [':d' => str_replace($stale, $fresh, (string) $row['details']), ':id' => (int) $row['id']]
        );
    }

    db_run(
        "UPDATE services SET instructions = :new WHERE instructions = :old AND slug = 'ai-masterclass-ticket-sme'",
        [
            ':old' => 'Please arrive 15 minutes before the first session. This ticket covers the full programme, 30 September to 3 October 2026.',
            ':new' => 'Please arrive 15 minutes before the first session. This ticket covers all three masterclass days, 30 September to 2 October 2026.',
        ]
    );
}

/**
 * The site described the character of the event but never said what it
 * actually consists of. These are the pillars from the event's own executive
 * summary. Seeded only when the section is empty, so the team's own wording
 * is never replaced.
 */
function booking_seed_event_structure(): void
{
    if (db_one("SELECT id FROM blocks WHERE page = 'about' AND section = 'structure'")) {
        return;
    }

    $cards = [
        [
            '2-Day High-Level Summit',
            '30 September – 01 October',
            'Keynotes, policy panels and sector-specific roundtables on AI integration in Namibian industry, trade readiness and regional investment.',
            '◈',
        ],
        [
            '4-Day SME Exhibition & Trade Fair',
            '30 September – 03 October',
            'A high-foot-traffic corporate and small-business exhibition showcasing local products, digital solutions and enterprise services.',
            '▣',
        ],
        [
            '3-Day AI & Digital Masterclass',
            'Featured track · 30 September – 02 October',
            'A hands-on intensive running alongside the trade fair, equipping MSMEs, operational managers and tech professionals with practical tools to automate and scale.',
            '✦',
        ],
        [
            'Executive & Investor Networking',
            'Across all four days',
            'Dedicated B2B matchmaking, executive lounge access and structured pitch platforms connecting investors with growth-stage businesses.',
            '↗',
        ],
    ];

    foreach ($cards as $position => $card) {
        db_run(
            'INSERT INTO blocks (page, section, title, subtitle, body, icon, image, link_text, link_url, position, is_active)
             VALUES (:page, :section, :title, :subtitle, :body, :icon, :image, :lt, :lu, :pos, 1)',
            [
                ':page'     => 'about',
                ':section'  => 'structure',
                ':title'    => $card[0],
                ':subtitle' => $card[1],
                ':body'     => $card[2],
                ':icon'     => $card[3],
                ':image'    => '',
                ':lt'       => '',
                ':lu'       => '',
                ':pos'      => $position,
            ]
        );
    }
}


/* ==========================================================================
   THE RATE CARD, THE ADMIN ACCOUNT AND WHERE MAIL GOES (schema version 11)
   --------------------------------------------------------------------------
   Checked against "Coastal AI Trade Fair Registration Form 2026 Updated.pdf"
   (Official Stall Rate Card & Proposal Booking Form, V2).

   Three things came out of that comparison:

     * The FNB account number on the site was 62488155152. The rate card says
       64288155152 — the 4 and the 2 are transposed. Every exhibitor paying by
       EFT was being given an account number that does not match the form they
       signed, so this is corrected here.

     * Four outdoor tiers and the three-phase power surcharge are on the rate
       card and in its tick-box selection list, but were never on the site, so
       nobody could book them.

     * Bookings, enquiries and proof of payment now reach all three mailboxes
       the team uses, the gmail one included.

   As with version 10, every change is conditional: a setting is only rewritten
   while it still holds the value we know to be stale, and rows are only added
   when they are absent. Running this twice does nothing the second time.
   ========================================================================== */

function booking_correct_content_v11(): void
{
    booking_correct_bank_account();
    booking_route_mail_everywhere();
    booking_add_rate_card_tiers();
    booking_seed_owner_account();
}

/**
 * The account number people are told to pay into. Wrong digits here means the
 * money does not arrive, so it is worth being exact about which value we are
 * willing to replace.
 */
function booking_correct_bank_account(): void
{
    booking_setting_correct('bank_account_number', '62488155152', '64288155152');
    booking_setting_correct('bank_branch_code', '280172', '280172');
    booking_setting_fill('bank_account_type', 'Business Cheque Account');
}

/**
 * The team works out of three mailboxes: the two on the event's own domain and
 * the gmail address the rate card still points people at. Everything the site
 * sends should reach all three.
 */
function booking_route_mail_everywhere(): void
{
    $all = 'info@coastalaitradefair.com, mary@coastalaitradefair.com, coastalaisummit@gmail.com';

    booking_setting_correct(
        'email_form_to',
        'info@coastalaitradefair.com, mary@coastalaitradefair.com',
        $all
    );
    booking_setting_correct(
        'bk_admin_emails',
        'info@coastalaitradefair.com, mary@coastalaitradefair.com',
        $all
    );
    booking_setting_correct('payment_proof_email', 'info@coastalaitradefair.com', $all);
}

/**
 * The outdoor rigs and the power surcharge from section C of the rate card.
 * Each one becomes a row on the public rate list and a service people can
 * actually book, matching how the indoor stalls already work.
 */
function booking_add_rate_card_tiers(): void
{
    $tiers = [
        [
            'slug'     => 'outdoor-corporate-stall-6x4',
            'name'     => 'Outdoor Corporate Stall 6x4',
            'category' => 'Outdoor Corporate Stall 6x4',
            'summary'  => '6m x 4m (24 m²) — dedicated clearance, open layout, 1x 15A power hookup',
            'price'    => 14500.00,
        ],
        [
            'slug'     => 'heavy-outdoor-stall-8x5',
            'name'     => 'Heavy Outdoor Stall 8x5',
            'category' => 'Heavy Outdoor Stall 8x5',
            'summary'  => '8m x 5m (40 m²) — extended drive-in length, display stage clearance, dual 15A power',
            'price'    => 21500.00,
        ],
        [
            'slug'     => 'outdoor-pavilion-6x6',
            'name'     => 'Outdoor Pavilion 6x6',
            'category' => 'Outdoor Pavilion 6x6',
            'summary'  => '6m x 6m (36 m²) — high-tension anchored structure, heavy footfall walkway placement',
            'price'    => 19500.00,
        ],
        [
            'slug'     => 'mega-pavilion-10x6',
            'name'     => 'Mega Pavilion XL 10x6',
            'category' => 'Mega Pavilion XL 10x6',
            'summary'  => '10m x 6m (60 m²) — multi-module high-tension, priority perimeter corner frontage',
            'price'    => 29997.00,
        ],
        [
            'slug'     => 'three-phase-power-hookup',
            'name'     => '3-Phase Power Connection',
            'category' => '3-Phase Power Connection',
            'summary'  => 'Surcharge per event for heavy trailer rigs or refrigeration units',
            'price'    => 1200.00,
        ],
    ];

    $venue = trim((string) (db_one(
        'SELECT value AS v FROM settings WHERE ' . db_name('key') . " = 'venue_name'"
    )['v'] ?? ''));
    $city = trim((string) (db_one(
        'SELECT value AS v FROM settings WHERE ' . db_name('key') . " = 'venue_city'"
    )['v'] ?? ''));
    $location = trim($venue . ($city !== '' ? ', ' . $city : ''), ', ');

    // Sit them after everything already on the list.
    $lastStall = (int) (db_one('SELECT MAX(position) AS p FROM stalls')['p'] ?? 0);
    $lastSvc   = (int) (db_one('SELECT MAX(position) AS p FROM services')['p'] ?? 0);

    // Copy the booking window from a stall that already works, so the new
    // tiers open on exactly the same day as the rest of the fair.
    $template = db_one("SELECT * FROM services WHERE slug = 'indoor-corporate-stall'");

    foreach ($tiers as $offset => $tier) {
        if (!db_one('SELECT id FROM services WHERE slug = :s', [':s' => $tier['slug']])) {
            db_run(
                'INSERT INTO services
                   (name, slug, summary, description, location, image, price, price_mode,
                    duration_minutes, buffer_minutes, slot_capacity, capacity_unit,
                    min_guests, max_guests, collect_guest_names, lead_time_hours,
                    max_advance_days, payment_deadline_hours, extra_field_label,
                    extra_field_help, extra_field_required, instructions, position,
                    is_active, created_at, updated_at)
                 VALUES
                   (:name, :slug, :summary, :description, :location, :image, :price, :mode,
                    :duration, :buffer, :capacity, :unit,
                    :ming, :maxg, :names, :lead,
                    :advance, :deadline, :xlabel,
                    :xhelp, :xreq, :instructions, :position,
                    1, :created, :updated)',
                [
                    ':name'        => $tier['name'],
                    ':slug'        => $tier['slug'],
                    ':summary'     => $tier['summary'],
                    ':description' => $tier['summary'] . "\n\nBooked for the full event, 30 September to 3 October 2026"
                                      . ($location !== '' ? ', at ' . $location : '') . '.',
                    ':location'    => $location,
                    ':image'       => '',
                    ':price'       => $tier['price'],
                    ':mode'        => 'booking',
                    ':duration'    => (int) ($template['duration_minutes'] ?? 540),
                    ':buffer'      => (int) ($template['buffer_minutes'] ?? 0),
                    ':capacity'    => (int) ($template['slot_capacity'] ?? 10),
                    ':unit'        => (string) ($template['capacity_unit'] ?? 'booking'),
                    ':ming'        => (int) ($template['min_guests'] ?? 1),
                    ':maxg'        => (int) ($template['max_guests'] ?? 1),
                    ':names'       => (int) ($template['collect_guest_names'] ?? 0),
                    ':lead'        => (int) ($template['lead_time_hours'] ?? 0),
                    ':advance'     => (int) ($template['max_advance_days'] ?? 400),
                    ':deadline'    => (int) ($template['payment_deadline_hours'] ?? 48),
                    ':xlabel'      => (string) ($template['extra_field_label'] ?? ''),
                    ':xhelp'       => (string) ($template['extra_field_help'] ?? ''),
                    ':xreq'        => (int) ($template['extra_field_required'] ?? 0),
                    ':instructions' => (string) ($template['instructions'] ?? ''),
                    ':position'    => $lastSvc + 1 + $offset,
                    ':created'     => date('Y-m-d H:i:s'),
                    ':updated'     => date('Y-m-d H:i:s'),
                ]
            );

            // Opening hours, copied from the stall the tier is modelled on.
            $newId = (int) (db_one('SELECT id FROM services WHERE slug = :s', [':s' => $tier['slug']])['id'] ?? 0);
            if ($newId > 0 && isset($template['id'])) {
                foreach (db_all('SELECT * FROM service_hours WHERE service_id = :s', [':s' => (int) $template['id']]) as $hour) {
                    db_run(
                        'INSERT INTO service_hours (service_id, weekday, start_time, end_time, slot_interval, capacity, position, is_active)
                         VALUES (:s, :w, :from, :to, :interval, :cap, :pos, 1)',
                        [
                            ':s'        => $newId,
                            ':w'        => (int) $hour['weekday'],
                            ':from'     => (string) $hour['start_time'],
                            ':to'       => (string) $hour['end_time'],
                            ':interval' => (int) $hour['slot_interval'],
                            ':cap'      => (int) $hour['capacity'],
                            ':pos'      => (int) $hour['position'],
                        ]
                    );
                }
            }
        }

        if (!db_one('SELECT id FROM stalls WHERE category = :c', [':c' => $tier['category']])) {
            db_run(
                'INSERT INTO stalls (category, details, rate, note, kind, free_with_stall, bookable, position, is_active)
                 VALUES (:category, :details, :rate, :note, :kind, 0, 1, :position, 1)',
                [
                    ':category' => $tier['category'],
                    ':details'  => $tier['summary'],
                    ':rate'     => 'N$ ' . number_format($tier['price'], 0, '.', ','),
                    ':note'     => '',
                    ':kind'     => $tier['slug'] === 'three-phase-power-hookup' ? 'extra' : 'stall',
                    ':position' => $lastStall + 1 + $offset,
                ]
            );
        }
    }
}

/**
 * The account the event's own administrator signs in with.
 *
 * The starting password is NOT written here. This file is in version control
 * and the repository is public, so a password committed alongside the address
 * it belongs to and the address of the live site would be a gift to anyone
 * scanning GitHub. It is read from storage/env.php instead, which is never
 * committed and never served to the web:
 *
 *     'ADMIN_INITIAL_PASSWORD' => '...'
 *
 * With nothing there, no account is made and the migration moves on. Whatever
 * is used is flagged for replacement the moment its owner signs in, so a
 * password that has travelled through a message or an email cannot stay in
 * use. Once they have chosen their own, the line can be deleted from env.php.
 */
function booking_seed_owner_account(): void
{
    $email    = 'mary@coastalaitradefair.com';
    $username = 'mary';

    if (db_one('SELECT id FROM users WHERE email = :e OR username = :u', [':e' => $email, ':u' => $username])) {
        return;
    }

    $initial = env('ADMIN_INITIAL_PASSWORD');
    if ($initial === '') {
        return;
    }

    db_run(
        'INSERT INTO users (username, name, email, password_hash, role, created_at, must_change_password)
         VALUES (:u, :n, :e, :h, :r, :c, 1)',
        [
            ':u' => $username,
            ':n' => 'Mary',
            ':e' => $email,
            ':h' => password_hash($initial, PASSWORD_DEFAULT),
            ':r' => 'admin',
            ':c' => date('Y-m-d H:i:s'),
        ]
    );
}

/* ==========================================================================
   THE HOST'S MAILBOX (schema version 13)
   --------------------------------------------------------------------------
   Taken from the account's own cPanel mail screen: the outgoing server is the
   domain itself on port 465, which is SSL from the first byte rather than
   STARTTLS, and it wants the full address as the username.

   The password is deliberately not here. It belongs in storage/env.php as
   BK_SMTP_PASS, which is never committed and never served to the web. Until it
   is set the site keeps using PHP mail(); the moment it is, "Automatic" sends
   everything through the mailbox instead. Nothing else needs changing.
   ========================================================================== */

function booking_configure_mailbox(): void
{
    booking_setting_fill('bk_smtp_host', 'coastalaitradefair.com');
    booking_setting_fill('bk_smtp_user', 'info@coastalaitradefair.com');

    // Port 465 is implicit SSL. Seeded with 587/STARTTLS, which would not
    // connect to this host, so correct it rather than only filling a blank.
    booking_setting_correct('bk_smtp_port', '587', '465');
    booking_setting_correct('bk_smtp_secure', 'tls', 'ssl');

    // Let the site decide for itself, so it starts using the mailbox the
    // moment a password appears rather than needing a second visit here.
    booking_setting_correct('bk_mail_transport', 'mail', 'auto');
}
