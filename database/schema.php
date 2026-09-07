<?php
declare(strict_types=1);

/**
 * Database schema and first-run seed data.
 * Called by setup.php. Safe to re-run: every statement uses IF NOT EXISTS and
 * seeding only happens for tables that are still empty.
 */

function schema_create(): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL DEFAULT '',
    email         TEXT NOT NULL DEFAULT '',
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'editor',
    created_at    TEXT NOT NULL DEFAULT '',
    last_login    TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS pages (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    slug             TEXT NOT NULL UNIQUE,
    title            TEXT NOT NULL DEFAULT '',
    nav_label        TEXT NOT NULL DEFAULT '',
    hero_label       TEXT NOT NULL DEFAULT '',
    hero_title       TEXT NOT NULL DEFAULT '',
    hero_intro       TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT '',
    show_in_nav      INTEGER NOT NULL DEFAULT 1,
    is_published     INTEGER NOT NULL DEFAULT 1,
    position         INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS blocks (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    page      TEXT NOT NULL DEFAULT 'home',
    section   TEXT NOT NULL DEFAULT 'general',
    title     TEXT NOT NULL DEFAULT '',
    subtitle  TEXT NOT NULL DEFAULT '',
    body      TEXT NOT NULL DEFAULT '',
    icon      TEXT NOT NULL DEFAULT '',
    image     TEXT NOT NULL DEFAULT '',
    link_text TEXT NOT NULL DEFAULT '',
    link_url  TEXT NOT NULL DEFAULT '',
    position  INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS programme_days (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    day_label TEXT NOT NULL DEFAULT '',
    date_text TEXT NOT NULL DEFAULT '',
    title     TEXT NOT NULL DEFAULT '',
    summary   TEXT NOT NULL DEFAULT '',
    details   TEXT NOT NULL DEFAULT '',
    position  INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS packages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tier_label  TEXT NOT NULL DEFAULT '',
    name        TEXT NOT NULL DEFAULT '',
    price       TEXT NOT NULL DEFAULT '',
    summary     TEXT NOT NULL DEFAULT '',
    features    TEXT NOT NULL DEFAULT '',
    is_featured INTEGER NOT NULL DEFAULT 0,
    position    INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS stalls (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    category        TEXT NOT NULL DEFAULT '',
    details         TEXT NOT NULL DEFAULT '',
    rate            TEXT NOT NULL DEFAULT '',
    note            TEXT NOT NULL DEFAULT '',
    kind            TEXT NOT NULL DEFAULT 'other',
    free_with_stall INTEGER NOT NULL DEFAULT 0,
    bookable        INTEGER NOT NULL DEFAULT 1,
    position        INTEGER NOT NULL DEFAULT 0,
    is_active       INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS bookings (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    reference            TEXT NOT NULL UNIQUE,
    token                TEXT NOT NULL DEFAULT '',
    company              TEXT NOT NULL DEFAULT '',
    contact_person       TEXT NOT NULL DEFAULT '',
    phone                TEXT NOT NULL DEFAULT '',
    email                TEXT NOT NULL DEFAULT '',
    product              TEXT NOT NULL DEFAULT '',
    special_requirements TEXT NOT NULL DEFAULT '',
    items                TEXT NOT NULL DEFAULT '[]',
    total                REAL NOT NULL DEFAULT 0,
    signature            TEXT NOT NULL DEFAULT '',
    agreed               INTEGER NOT NULL DEFAULT 0,
    status               TEXT NOT NULL DEFAULT 'pending',
    admin_notes          TEXT NOT NULL DEFAULT '',
    ip                   TEXT NOT NULL DEFAULT '',
    created_at           TEXT NOT NULL DEFAULT '',
    updated_at           TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS partners (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL DEFAULT '',
    role_label TEXT NOT NULL DEFAULT '',
    category   TEXT NOT NULL DEFAULT 'partner',
    logo       TEXT NOT NULL DEFAULT '',
    website    TEXT NOT NULL DEFAULT '',
    dark_logo  INTEGER NOT NULL DEFAULT 0,
    position   INTEGER NOT NULL DEFAULT 0,
    is_active  INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS speakers (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL DEFAULT '',
    role         TEXT NOT NULL DEFAULT '',
    organisation TEXT NOT NULL DEFAULT '',
    photo        TEXT NOT NULL DEFAULT '',
    bio          TEXT NOT NULL DEFAULT '',
    link_url     TEXT NOT NULL DEFAULT '',
    position     INTEGER NOT NULL DEFAULT 0,
    is_active    INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS gallery (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    image     TEXT NOT NULL DEFAULT '',
    caption   TEXT NOT NULL DEFAULT '',
    position  INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS faqs (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    question  TEXT NOT NULL DEFAULT '',
    answer    TEXT NOT NULL DEFAULT '',
    position  INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS enquiries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL DEFAULT '',
    company    TEXT NOT NULL DEFAULT '',
    email      TEXT NOT NULL DEFAULT '',
    phone      TEXT NOT NULL DEFAULT '',
    interest   TEXT NOT NULL DEFAULT '',
    option_key TEXT NOT NULL DEFAULT '',
    message    TEXT NOT NULL DEFAULT '',
    status     TEXT NOT NULL DEFAULT 'new',
    ip         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS media (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT NOT NULL DEFAULT '',
    original_name TEXT NOT NULL DEFAULT '',
    mime          TEXT NOT NULL DEFAULT '',
    size          INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS activity_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_name  TEXT NOT NULL DEFAULT '',
    action     TEXT NOT NULL DEFAULT '',
    detail     TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS rate_hits (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    key        TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_blocks_page ON blocks (page, section, position);
CREATE INDEX IF NOT EXISTS idx_partners_cat ON partners (category, position);
CREATE INDEX IF NOT EXISTS idx_enquiries_date ON enquiries (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bookings_date ON bookings (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_rate_hits ON rate_hits (key, created_at);
SQL;

    if (db_is_mysql()) {
        db_run_sql_file(ROOT_PATH . '/database/mysql-schema.sql');
    } else {
        db()->exec($sql);
    }

    schema_migrate();
}

/**
 * Add columns introduced after a site was first installed.
 * Runs on every setup and is a no-op once the columns exist.
 */
function schema_migrate(): void
{
    $additions = [
        'stalls' => [
            'kind'            => "TEXT NOT NULL DEFAULT 'other'",
            'free_with_stall' => 'INTEGER NOT NULL DEFAULT 0',
            'bookable'        => 'INTEGER NOT NULL DEFAULT 1',
        ],
    ];

    foreach ($additions as $table => $columns) {
        $existing = db_table_columns($table);
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                db()->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
    }
}

/** True when a table has no rows yet. */
function table_empty(string $table): bool
{
    $row = db_one("SELECT COUNT(*) AS c FROM {$table}");
    return (int) ($row['c'] ?? 0) === 0;
}

/**
 * The real, live event data. Everything below is editable in the admin panel
 * afterwards — this is only the starting point.
 */
function schema_seed(): void
{
    seed_settings();
    seed_pages();
    seed_blocks();
    seed_programme();
    seed_packages();
    seed_stalls();
    seed_partners();
    seed_faqs();
}

function seed_settings(): void
{
    $defaults = [
        /* Identity */
        'site_name'        => 'Coastal AI Summit & SME Trade Fair',
        'event_name'       => 'Coastal AI Summit & SME Trade Fair 2026',
        'event_tagline'    => 'Empowering MSMEs for the Digital Economy',
        'logo'             => 'images/generated/coastal-ai-summit-logo-revamped.png',
        'org_name'         => 'Atusheni Women, Youth & SME Foundation',
        'org_reg_no'       => '21/2025/0638 (incorporated under Section 21)',
        'org_collaboration' => 'In collaboration with the Ministry of Information and Communication Technology (MICT)',

        /* Event */
        'event_start_date' => '2026-09-30',
        'event_start_time' => '08:00',
        'event_end_date'   => '2026-10-03',
        'event_end_time'   => '17:00',
        'event_timezone'   => 'Africa/Windhoek',
        'countdown_enabled' => '1',
        'countdown_label'  => 'Doors open in',
        'countdown_live'   => 'The fair is live right now',
        'countdown_done'   => 'Thank you for joining us in 2026',
        'venue_name'       => 'Mondesa Multipurpose Centre Hall',
        'venue_address'    => 'Mondesa, Swakopmund',
        'venue_city'       => 'Swakopmund, Namibia',
        'venue_map_url'    => 'https://www.google.com/maps/search/?api=1&query=Mondesa+Multipurpose+Centre+Hall+Swakopmund',

        /* Contact */
        'email_primary'    => 'info@coastalaitradefair.com',
        'email_secondary'  => 'mary@coastalaitradefair.com',
        'email_form_to'    => 'info@coastalaitradefair.com, mary@coastalaitradefair.com',
        'phone_1'          => '+264 85 240 0750',
        'phone_2'          => '+264 81 040 0750',
        'phone_3'          => '+264 85 800 8906',
        'whatsapp'         => '+264 81 040 0750',
        'postal_address'   => 'P.O. Box 25304, Windhoek',
        'physical_address' => 'Abraham Mashego Street, Soweto Market, Stall SL 11, Katutura, Windhoek',

        /* Social */
        'facebook'  => '',
        'instagram' => '',
        'twitter'   => '',
        'linkedin'  => '',
        'youtube'   => '',

        /* Registration & payment (from the official 2026 registration form) */
        'registration_open'    => '1',
        'registration_form'    => 'images/Coastal AI Trade Fair Registration Form 2026 Updated.pdf',
        'bank_account_name'    => 'Atusheni Women, Youth & SME Foundation',
        'bank_name'            => 'First National Bank (FNB)',
        'bank_account_number'  => '62488155152',
        'bank_branch_code'     => '280172',
        'bank_account_type'    => 'Business Cheque Account',
        'payment_reference'    => 'Your company name',
        'payment_proof_email'  => 'info@coastalaitradefair.com',
        'terms_confirmation'   => 'Stalls and masterclass seats are only reserved upon receipt of proof of payment.',
        'terms_allocations'    => 'Prime stall locations are assigned on a first-paid, first-served basis.',
        'terms_cancellations'  => '50% refund if cancelled 30 days prior to the event; non-refundable thereafter.',

        /* Home hero */
        'hero_eyebrow'   => '30 SEPT — 03 OCT 2026 · SWAKOPMUND, NAMIBIA',
        'hero_title'     => 'Where coastal|{ambition} meets AI.',
        'hero_text'      => 'A four-day frontier for founders, makers and industry leaders shaping Namibia\'s digital economy.',
        'hero_cta_text'  => 'Book your space',
        'hero_cta_link'  => 'book.php',
        'hero_alt_text'  => 'Explore the fair',
        'hero_alt_link'  => '#about',
        'hero_image'     => 'images/generated/namibia-ai-hero-3d.png',
        'hero_image_alt' => 'Human and robotic hands meeting around an AI light orb on the Namibian coast',

        /* Section headings on the home page */
        'about_label'     => '01 — THE FAIR',
        'about_title'     => 'Technology feels different when it {belongs to everyone.}',
        'about_text'      => "The Coastal AI Summit & SME Trade Fair brings the people building tomorrow together with the communities who will shape it.\n\nDiscover practical tools, honest conversations and new possibilities for your business.",
        'showcase_label'  => 'NAMIBIA · TECHNOLOGY · TRADE',
        'showcase_title'  => 'Built on the coast.|{Connected to tomorrow.}',
        'showcase_text'   => 'One platform where Namibia\'s entrepreneurs, investors and technology leaders turn digital possibility into practical growth.',
        'showcase_image'  => 'images/generated/namibia-ai-coast-3d-v2.png',
        'showcase_tags'   => "AI readiness\nSME growth\nRegional trade",
        'programme_label' => '02 — PROGRAMME',
        'programme_title' => 'Four days. One shared horizon.',
        'exhibit_label'   => '03 — EXHIBIT WITH US',
        'exhibit_title'   => 'Put your work in front of the {right people.}',
        'exhibit_text'    => 'Showcase your product, meet decision-makers and become part of a new digital story for Namibia.',
        'venue_label'     => '04 — OUR VENUE',
        'venue_title'     => 'Made for the whole {community.}',
        'venue_text'      => 'We are taking over the Mondesa Multipurpose Centre Hall in Swakopmund — one place for practical learning, bold demonstrations and meaningful meetings.',
        'venue_image'     => 'images/WhatsApp Image 2026-09-01 at 21.33.32.jpeg',
        'partners_label'  => '05 — EVENT NETWORK',
        'partners_title'  => 'One platform.|{Distinct roles.}',
        'partners_text'   => 'Sponsors, partners, collaborators and exhibitors are presented separately — exactly as confirmed for the event.',
        'packages_label'  => 'SPONSORSHIP & EXHIBITING',
        'packages_title'  => 'Put your organisation at the {centre of progress.}',
        'packages_text'   => 'Reach corporate leaders, government stakeholders, investors and emerging SMEs through packages designed for serious visibility and measurable connections.',
        'cta_title'       => 'Come curious.|{Leave connected.}',
        'cta_text'        => 'Registration for the 2026 fair is open to exhibitors, sponsors, delegates and masterclass students.',
        'contact_label'   => '06 — CONTACT & BOOKINGS',
        'contact_title'   => 'Let\'s make your|{place at the fair.}',
        'contact_text'    => 'Interested in exhibiting, sponsoring, partnering or attending? Send your message directly to our event team.',

        /* Appearance */
        'theme_accent' => '#22c9f0',
        'theme_gold'   => '#f2b544',
        'theme_ink'    => '#04121f',

        /* SEO */
        'meta_description' => 'Coastal AI Summit & SME Trade Fair 2026 — 30 September to 03 October 2026 at the Mondesa Multipurpose Centre Hall, Swakopmund, Namibia. Empowering MSMEs for the digital economy.',
        'og_image'         => 'images/generated/coastal-ai-summit-logo-revamped.png',
        'analytics_code'   => '',
        'footer_note'      => 'Hosted by the Atusheni Women, Youth & SME Foundation in collaboration with MICT.',
    ];

    foreach ($defaults as $key => $value) {
        db_setting_put_missing($key, (string) $value);
    }
}

function seed_pages(): void
{
    if (!table_empty('pages')) {
        return;
    }

    $pages = [
        ['home', 'Coastal AI Summit & SME Trade Fair 2026', 'Home', '', '', '',
            'The Erongo region\'s flagship AI and SME trade event, 30 September – 03 October 2026 in Swakopmund.', 0, 0],
        ['about', 'About the Summit', 'About', 'ABOUT THE EVENT',
            'Erongo\'s home for {technology, trade} and growth.',
            'The Coastal AI Summit & SME Trade Fair 2026 is the Erongo region\'s premier hub for technological innovation and practical business growth.',
            'About the Coastal AI Summit & SME Trade Fair 2026 in Swakopmund, Namibia.', 1, 1],
        ['programme', 'Programme', 'Programme', '30 SEPTEMBER — 03 OCTOBER 2026',
            'Four days to {build what\'s next.}',
            'Strategic conversations, a vibrant SME marketplace and hands-on learning designed for Namibia\'s digital future.',
            'The four-day programme: high-level summit, AI masterclass and SME trade fair.', 1, 2],
        ['exhibit', 'Exhibit With Us', 'Exhibit', 'EXHIBIT WITH US',
            'Bring your business {to the coast.}',
            'Be seen by the people making decisions, creating opportunities and shaping Namibia\'s next growth story.',
            'Book an exhibition stand at the Coastal AI Summit & SME Trade Fair 2026.', 1, 3],
        ['packages', 'Sponsorship & Exhibitor Packages', 'Packages', 'SPONSORSHIP & EXHIBITOR PACKAGES',
            'Visibility that creates {real business value.}',
            'Position your organisation at the forefront of innovation, economic empowerment and regional trade — alongside decision-makers, government stakeholders and emerging SMEs.',
            'Sponsorship tiers, stall rates and masterclass tickets for the 2026 fair.', 1, 4],
        ['book', 'Book Your Space', 'Book now', 'ONLINE BOOKING',
            'Reserve your space in {four short steps.}',
            'Complete the official registration form online. You receive a booking reference and a PDF copy immediately.',
            'Book an exhibition stall, masterclass ticket or summit pass for the Coastal AI Summit & SME Trade Fair 2026.', 1, 5],
        ['partners', 'Partners & Sponsors', 'Partners', 'SPONSORS · PARTNERS · EXHIBITORS',
            'The organisations {behind the fair.}',
            'Sponsors, partners, collaborators and exhibitors are grouped by the roles confirmed for the event.',
            'Meet the sponsors, partners and corporate exhibitors of the 2026 fair.', 1, 6],
        ['contact', 'Contact & Bookings', 'Contact', 'CONTACT & BOOKINGS',
            'Your place at the {Coastal AI Summit} starts here.',
            'For attendance, exhibiting, sponsorship, partnerships and speaking opportunities, send our event team a message.',
            'Contact the Coastal AI Summit & SME Trade Fair 2026 event team.', 1, 7],
    ];

    foreach ($pages as $p) {
        db_run(
            'INSERT INTO pages (slug, title, nav_label, hero_label, hero_title, hero_intro, meta_description, show_in_nav, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $p
        );
    }
}

function seed_blocks(): void
{
    if (!table_empty('blocks')) {
        return;
    }

    $blocks = [
        // page, section, title, subtitle, body, icon, image, link_text, link_url
        ['home', 'stats', '04', 'days of ideas', '', '', '', '', ''],
        ['home', 'stats', '20+', 'expert speakers', '', '', '', '', ''],
        ['home', 'stats', '500', 'MSMEs & innovators', '', '', '', '', ''],

        ['home', 'focus', 'Learn', '', 'Hands-on sessions that make AI practical, useful and approachable.', '✦', '', '', ''],
        ['home', 'focus', 'Connect', '', 'Meet the builders, backers and partners behind the next wave.', '◈', '', '', ''],
        ['home', 'focus', 'Grow', '', 'Find the insights and tools to take your idea further, faster.', '↗', '', '', ''],

        ['about', 'stats', '30 SEP', 'Opening day', '', '', '', '', ''],
        ['about', 'stats', '03 OCT', 'Closing day', '', '', '', '', ''],
        ['about', 'stats', '04', 'Days of trade', '', '', '', '', ''],
        ['about', 'stats', '01', 'Shared horizon', '', '', '', '', ''],

        ['about', 'features', 'Strategy meets action', '', 'High-level keynotes and policy panels are balanced with hands-on tools that business owners can use immediately.', '', '', '', ''],
        ['about', 'features', 'Built for local enterprise', '', 'Every track is grounded in the needs of MSMEs, operational teams and entrepreneurs in Namibia\'s evolving economy.', '', '', '', ''],
        ['about', 'features', 'Connections with purpose', '', 'Executive networking, B2B matchmaking and pitch opportunities make each conversation count beyond the fair.', '', '', '', ''],

        ['exhibit', 'audience', 'Entrepreneurs', '', 'Ambitious founders, business owners and makers looking for the next practical move.', '', '', '', ''],
        ['exhibit', 'audience', 'Corporate leaders', '', 'Decision-makers searching for local capability, partnerships and future-ready solutions.', '', '', '', ''],
        ['exhibit', 'audience', 'Investors', '', 'Backers and ecosystem partners ready to meet growth-stage businesses.', '', '', '', ''],
        ['exhibit', 'audience', 'Tech enthusiasts', '', 'Early adopters, professionals and learners curious about what AI can unlock.', '', '', '', ''],

        ['programme', 'tracks', 'Automate smarter', 'MASTERCLASS 01', 'Learn the foundational systems that turn repetitive daily work into more time for customers and growth.', '', '', '', ''],
        ['programme', 'tracks', 'Tell your story', 'MASTERCLASS 02', 'Create compelling, targeted marketing with modern AI design, content and customer-engagement tools.', '', '', '', ''],
        ['programme', 'tracks', 'Trade with confidence', 'MASTERCLASS 03', 'Use practical digital finance and data strategies to run a more resilient, investment-ready business.', '', '', '', ''],
    ];

    $i = 0;
    foreach ($blocks as $b) {
        db_run(
            'INSERT INTO blocks (page, section, title, subtitle, body, icon, image, link_text, link_url, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge($b, [$i++])
        );
    }
}

function seed_programme(): void
{
    if (!table_empty('programme_days')) {
        return;
    }

    $days = [
        ['DAY 01', '30 September 2026', 'AI Fundamentals & Business Automation',
            'Opening the digital coast.',
            'Generative AI frameworks for daily operations, client communications, scheduling and administration, plus practical prompts for resource-constrained enterprises.'],
        ['DAY 02', '01 October 2026', 'Digital Marketing, Content Creation & Branding',
            'AI for business.',
            'AI design, copy and video tools; data-driven customer engagement; automated lead generation and conversion funnels.'],
        ['DAY 03', '02 October 2026', 'Trade Readiness & Financial Tech Strategy',
            'Build & belong.',
            'Data analytics for supply chains and inventory, digital payment gateways, and bankable tech-enabled proposals for pitch readiness.'],
        ['DAY 04', '03 October 2026', 'SME Ideas Market & Networking',
            'The ideas market.',
            'Exhibitor discovery, business matching, investor conversations and a final opportunity to build lasting coastal connections.'],
    ];

    $i = 0;
    foreach ($days as $d) {
        db_run(
            'INSERT INTO programme_days (day_label, date_text, title, summary, details, position) VALUES (?, ?, ?, ?, ?, ?)',
            array_merge($d, [$i++])
        );
    }
}

function seed_packages(): void
{
    if (!table_empty('packages')) {
        return;
    }

    $packages = [
        ['PLATINUM · TITLE SPONSOR', 'Premier Partner', 'N$ 50,000',
            'Premier naming rights, keynote access, double exhibition space and major media visibility.',
            "Category exclusivity and premier event naming rights\nPrime branding across main stage, website, flyers and media passes\nKeynote speaking slot and panel moderation opportunities\nDouble indoor booth (6m x 3m) in a high-traffic area\nFeatured PR interview, social spotlights and attendee email integration\nSix VIP passes with Executive Lounge and Networking Dinner access",
            1],
        ['GOLD · MASTERCLASS SPONSOR', 'Learning Partner', 'N$ 25,000',
            'Masterclass co-branding, workshop presentation rights, a standard booth and dedicated digital promotion.',
            "Co-branding rights for the AI & Digital Masterclass\nLogo on the website, training materials, signage and venue banners\nWorkshop or domain-expert presentation slot\nStandard indoor booth (3m x 3m)\nPress release mentions and dedicated social media features\nThree VIP passes and five complimentary Masterclass seats",
            0],
        ['SILVER · SME CATALYST', 'Connection Partner', 'N$ 12,000',
            'Event branding, a standard booth, social recognition and B2B matchmaking access.',
            "Logo on the landing page, summit banners and programme guide\nStandard indoor booth (3m x 3m)\nGroup recognition on social channels and event email campaigns\nTwo VIP passes\nFull access to structured B2B matchmaking sessions",
            0],
    ];

    $i = 0;
    foreach ($packages as $p) {
        db_run(
            'INSERT INTO packages (tier_label, name, price, summary, features, is_featured, position) VALUES (?, ?, ?, ?, ?, ?, ?)',
            array_merge($p, [$i++])
        );
    }
}

function seed_stalls(): void
{
    if (!table_empty('stalls')) {
        return;
    }

    // Rates taken directly from the official 2026 registration form.
    // kind: 'stall' spaces unlock the free masterclass ticket rule.
    $stalls = [
        // category, details, rate, note, kind, free_with_stall
        ['Indoor Corporate Stall', '3m x 3m exhibition space (2 chairs & 1 table)', 'N$ 9,999', '', 'stall', 0],
        ['Large Corporate Stall 4x3', '4m x 3m exhibition space', 'N$ 13,332', '', 'stall', 0],
        ['Large Corporate Stall 6x3', '6m x 3m exhibition space', 'N$ 19,998', '', 'stall', 0],
        ['Large Corporate Stall 9x3', '9m x 3m exhibition space', 'N$ 29,997', '', 'stall', 0],
        ['AI Masterclass Ticket — SME', 'Full 4-day training & practical hands-on AI modules', 'N$ 750', 'Free for MSME exhibitors who booked a stall or are sponsored', 'ticket', 1],
        ['Masterclass & Summit Pass', 'Full masterclass pass + summit VIP seating (per person)', 'N$ 1,500', '', 'ticket', 0],
        ['Food only', 'Vendor space', 'N$ 1,000', '', 'stall', 0],
        ['Food & Beverage', 'Vendor space', 'N$ 1,500', '', 'stall', 0],
        ['All Non-food', 'Retail / services vendor space', 'N$ 1,000', '', 'stall', 0],
        ['Kiddies Corner', 'Family activation space', 'N$ 1,000', '', 'stall', 0],
        ['1-pole tent', 'Outdoor space', 'N$ 2,000', '', 'stall', 0],
        ['2-pole tent', 'Outdoor space', 'N$ 2,500', '', 'stall', 0],
        ['3-pole tent', 'Outdoor space', 'N$ 3,000', '', 'stall', 0],
    ];

    $i = 0;
    foreach ($stalls as $s) {
        db_run(
            'INSERT INTO stalls (category, details, rate, note, kind, free_with_stall, bookable, position)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
            array_merge($s, [$i++])
        );
    }
}

function seed_partners(): void
{
    if (!table_empty('partners')) {
        return;
    }

    $partners = [
        ['Futuremedia', 'Main Sponsor', 'sponsor_main', 'images/WhatsApp Image 2026-09-01 at 21.33.51.jpeg', 0],
        ['FNB', 'Sponsor', 'sponsor', 'images/first-national-bank-logo-png_seeklogo-464484.png', 0],
        ['Amateta Graphics', 'Sponsor', 'sponsor', 'images/OUR LOGO REVISED.png', 0],

        ['Where in Namibia', 'Digital Marketing Partner', 'partner', 'images/WhatsApp Image 2026-09-01 at 21.33.41.jpeg', 0],
        ['Gobet', 'Corporate Exhibitor & Partner', 'partner', 'images/WhatsApp Image 2026-09-01 at 21.33.49.jpeg', 1],
        ['Coastal Trade Fair', 'Partner', 'partner', 'images/WhatsApp Image 2026-09-01 at 21.35.29.jpeg', 0],
        ['MICT', 'Event Collaboration', 'partner', 'images/WhatsApp Image 2026-09-01 at 21.33.57.jpeg', 0],

        ['Aphotic', 'Corporate Exhibitor', 'exhibitor', 'images/WhatsApp Image 2026-09-01 at 21.33.30.jpeg', 1],
        ['Goldstone', 'Corporate Exhibitor', 'exhibitor', 'images/WhatsApp Image 2026-09-01 at 21.33.36.jpeg', 0],
        ['Okamita', 'Corporate Exhibitor', 'exhibitor', 'images/WhatsApp Image 2026-09-01 at 21.33.45.jpeg', 0],
        ['Collexia', 'Corporate Exhibitor', 'exhibitor', 'images/WhatsApp Image 2026-09-01 at 21.33.27.jpeg', 0],
        ['Le Morgan', 'Corporate Exhibitor', 'exhibitor', 'images/WhatsApp Image 2026-09-01 at 21.33.28.jpeg', 0],

        ['Atusheni Women, Youth & SME Foundation', 'Host Foundation', 'foundation', 'images/WhatsApp Image 2026-09-01 at 20.42.24.jpeg', 0],
    ];

    $i = 0;
    foreach ($partners as $p) {
        db_run(
            'INSERT INTO partners (name, role_label, category, logo, dark_logo, position) VALUES (?, ?, ?, ?, ?, ?)',
            array_merge($p, [$i++])
        );
    }
}

function seed_faqs(): void
{
    if (!table_empty('faqs')) {
        return;
    }

    $faqs = [
        ['When and where does the fair take place?',
            'The Coastal AI Summit & SME Trade Fair 2026 runs from 30 September to 03 October 2026 at the Mondesa Multipurpose Centre Hall in Swakopmund, Namibia.'],
        ['How do I book an exhibition stall?',
            'Use the Book now page. Fill in your details, tick the spaces and tickets you want, and submit. You get a booking reference and a PDF copy of your registration straight away. Your space is confirmed once proof of payment is received.'],
        ['What happens after I book online?',
            'You receive a booking reference and a PDF of your completed registration form, plus a confirmation email. Pay by direct bank transfer using your company name as the reference, then email the proof of payment to the event team. We then mark your booking as confirmed.'],
        ['Is the AI Masterclass included with a stall booking?',
            'Yes. MSME exhibitors who have booked a stall — or who are sponsored — attend the full four-day masterclass free of charge. Otherwise the SME masterclass ticket is N$ 750.'],
        ['How are stall locations allocated?',
            'Prime stall locations are assigned on a first-paid, first-served basis.'],
        ['What is the cancellation policy?',
            'A 50% refund applies to cancellations made 30 days or more before the event. Bookings are non-refundable thereafter.'],
        ['Who organises the event?',
            'The fair is hosted by the Atusheni Women, Youth & SME Foundation (Reg. No. 21/2025/0638) in collaboration with the Ministry of Information and Communication Technology.'],
    ];

    $i = 0;
    foreach ($faqs as $f) {
        db_run('INSERT INTO faqs (question, answer, position) VALUES (?, ?, ?)', array_merge($f, [$i++]));
    }
}
