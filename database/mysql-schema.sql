-- ===========================================================================
--  Coastal AI Summit & SME Trade Fair — MySQL / MariaDB schema
-- ===========================================================================
--  Every statement is CREATE TABLE IF NOT EXISTS, so importing this into a
--  database that already has the site in it changes nothing. Safe to re-run.
--
--  Notes on the differences from the SQLite original:
--    * MySQL will not accept a DEFAULT on a TEXT column, so short fields that
--      need a default are VARCHAR and genuinely long ones are TEXT with no
--      default (the application always writes a value).
--    * `key` is a reserved word, so it is quoted with backticks.
--    * Columns that carry a UNIQUE index stay at 191 characters, which is the
--      longest utf8mb4 key older MySQL builds accept.
-- ===========================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- --------------------------------------------------------------- the site

CREATE TABLE IF NOT EXISTS settings (
  `key`  VARCHAR(191) NOT NULL,
  value  TEXT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(191) NOT NULL,
  name          VARCHAR(190) NOT NULL DEFAULT '',
  email         VARCHAR(190) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(20)  NOT NULL DEFAULT 'editor',
  created_at    VARCHAR(25)  NOT NULL DEFAULT '',
  last_login    VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug             VARCHAR(191) NOT NULL,
  title            VARCHAR(255) NOT NULL DEFAULT '',
  nav_label        VARCHAR(120) NOT NULL DEFAULT '',
  hero_label       VARCHAR(190) NOT NULL DEFAULT '',
  hero_title       TEXT NULL,
  hero_intro       TEXT NULL,
  meta_description TEXT NULL,
  show_in_nav      INT NOT NULL DEFAULT 1,
  is_published     INT NOT NULL DEFAULT 1,
  position         INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pages_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocks (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  page      VARCHAR(60)  NOT NULL DEFAULT 'home',
  section   VARCHAR(60)  NOT NULL DEFAULT 'general',
  title     VARCHAR(255) NOT NULL DEFAULT '',
  subtitle  VARCHAR(255) NOT NULL DEFAULT '',
  body      TEXT NULL,
  icon      VARCHAR(60)  NOT NULL DEFAULT '',
  image     VARCHAR(255) NOT NULL DEFAULT '',
  link_text VARCHAR(190) NOT NULL DEFAULT '',
  link_url  VARCHAR(255) NOT NULL DEFAULT '',
  position  INT NOT NULL DEFAULT 0,
  is_active INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_blocks_page (page, section, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programme_days (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  day_label VARCHAR(60)  NOT NULL DEFAULT '',
  date_text VARCHAR(120) NOT NULL DEFAULT '',
  title     VARCHAR(255) NOT NULL DEFAULT '',
  summary   TEXT NULL,
  details   TEXT NULL,
  position  INT NOT NULL DEFAULT 0,
  is_active INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tier_label  VARCHAR(190) NOT NULL DEFAULT '',
  name        VARCHAR(190) NOT NULL DEFAULT '',
  price       VARCHAR(60)  NOT NULL DEFAULT '',
  summary     TEXT NULL,
  features    TEXT NULL,
  is_featured INT NOT NULL DEFAULT 0,
  position    INT NOT NULL DEFAULT 0,
  is_active   INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stalls (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category        VARCHAR(190) NOT NULL DEFAULT '',
  details         VARCHAR(255) NOT NULL DEFAULT '',
  rate            VARCHAR(60)  NOT NULL DEFAULT '',
  note            VARCHAR(255) NOT NULL DEFAULT '',
  kind            VARCHAR(20)  NOT NULL DEFAULT 'other',
  free_with_stall INT NOT NULL DEFAULT 0,
  bookable        INT NOT NULL DEFAULT 1,
  position        INT NOT NULL DEFAULT 0,
  is_active       INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference            VARCHAR(191) NOT NULL,
  token                VARCHAR(64)  NOT NULL DEFAULT '',
  company              VARCHAR(190) NOT NULL DEFAULT '',
  contact_person       VARCHAR(190) NOT NULL DEFAULT '',
  phone                VARCHAR(60)  NOT NULL DEFAULT '',
  email                VARCHAR(190) NOT NULL DEFAULT '',
  product              VARCHAR(500) NOT NULL DEFAULT '',
  special_requirements TEXT NULL,
  items                TEXT NULL,
  total                DECIMAL(12,2) NOT NULL DEFAULT 0,
  signature            VARCHAR(190) NOT NULL DEFAULT '',
  agreed               INT NOT NULL DEFAULT 0,
  status               VARCHAR(30)  NOT NULL DEFAULT 'pending',
  admin_notes          TEXT NULL,
  ip                   VARCHAR(45)  NOT NULL DEFAULT '',
  created_at           VARCHAR(25)  NOT NULL DEFAULT '',
  updated_at           VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_reference (reference),
  KEY idx_bookings_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partners (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(190) NOT NULL DEFAULT '',
  role_label VARCHAR(190) NOT NULL DEFAULT '',
  category   VARCHAR(60)  NOT NULL DEFAULT 'partner',
  logo       VARCHAR(255) NOT NULL DEFAULT '',
  website    VARCHAR(255) NOT NULL DEFAULT '',
  dark_logo  INT NOT NULL DEFAULT 0,
  position   INT NOT NULL DEFAULT 0,
  is_active  INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_partners_cat (category, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS speakers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(190) NOT NULL DEFAULT '',
  role         VARCHAR(190) NOT NULL DEFAULT '',
  organisation VARCHAR(190) NOT NULL DEFAULT '',
  photo        VARCHAR(255) NOT NULL DEFAULT '',
  bio          TEXT NULL,
  link_url     VARCHAR(255) NOT NULL DEFAULT '',
  position     INT NOT NULL DEFAULT 0,
  is_active    INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  image     VARCHAR(255) NOT NULL DEFAULT '',
  caption   VARCHAR(255) NOT NULL DEFAULT '',
  position  INT NOT NULL DEFAULT 0,
  is_active INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question  VARCHAR(500) NOT NULL DEFAULT '',
  answer    TEXT NULL,
  position  INT NOT NULL DEFAULT 0,
  is_active INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enquiries (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(190) NOT NULL DEFAULT '',
  company    VARCHAR(190) NOT NULL DEFAULT '',
  email      VARCHAR(190) NOT NULL DEFAULT '',
  phone      VARCHAR(60)  NOT NULL DEFAULT '',
  interest   VARCHAR(120) NOT NULL DEFAULT '',
  option_key VARCHAR(120) NOT NULL DEFAULT '',
  message    TEXT NULL,
  status     VARCHAR(20)  NOT NULL DEFAULT 'new',
  ip         VARCHAR(45)  NOT NULL DEFAULT '',
  created_at VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_enquiries_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename      VARCHAR(255) NOT NULL DEFAULT '',
  original_name VARCHAR(190) NOT NULL DEFAULT '',
  mime          VARCHAR(100) NOT NULL DEFAULT '',
  size          INT NOT NULL DEFAULT 0,
  created_at    VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_name  VARCHAR(190) NOT NULL DEFAULT '',
  action     VARCHAR(190) NOT NULL DEFAULT '',
  detail     VARCHAR(500) NOT NULL DEFAULT '',
  created_at VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_hits (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`      VARCHAR(190) NOT NULL DEFAULT '',
  created_at VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_rate_hits (`key`, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- the EFT booking platform

CREATE TABLE IF NOT EXISTS clients (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(191) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  full_name         VARCHAR(190) NOT NULL DEFAULT '',
  phone             VARCHAR(60)  NOT NULL DEFAULT '',
  company           VARCHAR(190) NOT NULL DEFAULT '',
  address           VARCHAR(300) NOT NULL DEFAULT '',
  city              VARCHAR(120) NOT NULL DEFAULT '',
  country           VARCHAR(120) NOT NULL DEFAULT '',
  status            VARCHAR(20)  NOT NULL DEFAULT 'active',
  email_verified_at VARCHAR(25)  NOT NULL DEFAULT '',
  verify_hash       VARCHAR(64)  NOT NULL DEFAULT '',
  verify_expires    VARCHAR(25)  NOT NULL DEFAULT '',
  reset_hash        VARCHAR(64)  NOT NULL DEFAULT '',
  reset_expires     VARCHAR(25)  NOT NULL DEFAULT '',
  admin_notes       TEXT NULL,
  last_login        VARCHAR(25)  NOT NULL DEFAULT '',
  last_ip           VARCHAR(45)  NOT NULL DEFAULT '',
  created_at        VARCHAR(25)  NOT NULL DEFAULT '',
  updated_at        VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                   VARCHAR(190) NOT NULL DEFAULT '',
  slug                   VARCHAR(191) NOT NULL DEFAULT '',
  summary                VARCHAR(255) NOT NULL DEFAULT '',
  description            TEXT NULL,
  location               VARCHAR(190) NOT NULL DEFAULT '',
  image                  VARCHAR(255) NOT NULL DEFAULT '',
  price                  DECIMAL(12,2) NOT NULL DEFAULT 0,
  price_mode             VARCHAR(20)  NOT NULL DEFAULT 'booking',
  duration_minutes       INT NOT NULL DEFAULT 60,
  buffer_minutes         INT NOT NULL DEFAULT 0,
  slot_capacity          INT NOT NULL DEFAULT 1,
  capacity_unit          VARCHAR(20)  NOT NULL DEFAULT 'booking',
  min_guests             INT NOT NULL DEFAULT 1,
  max_guests             INT NOT NULL DEFAULT 1,
  collect_guest_names    INT NOT NULL DEFAULT 0,
  lead_time_hours        INT NOT NULL DEFAULT 24,
  max_advance_days       INT NOT NULL DEFAULT 180,
  payment_deadline_hours INT NOT NULL DEFAULT 48,
  extra_field_label      VARCHAR(190) NOT NULL DEFAULT '',
  extra_field_help       VARCHAR(255) NOT NULL DEFAULT '',
  extra_field_required   INT NOT NULL DEFAULT 0,
  instructions           TEXT NULL,
  position               INT NOT NULL DEFAULT 0,
  is_active              INT NOT NULL DEFAULT 1,
  created_at             VARCHAR(25)  NOT NULL DEFAULT '',
  updated_at             VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_services_pos (position, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_hours (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id    INT NOT NULL DEFAULT 0,
  weekday       INT NOT NULL DEFAULT 1,
  start_time    VARCHAR(5) NOT NULL DEFAULT '09:00',
  end_time      VARCHAR(5) NOT NULL DEFAULT '17:00',
  slot_interval INT NOT NULL DEFAULT 0,
  capacity      INT NOT NULL DEFAULT 0,
  position      INT NOT NULL DEFAULT 0,
  is_active     INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_hours_svc (service_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_dates (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id INT NOT NULL DEFAULT 0,
  start_date VARCHAR(10) NOT NULL DEFAULT '',
  end_date   VARCHAR(10) NOT NULL DEFAULT '',
  start_time VARCHAR(5)  NOT NULL DEFAULT '',
  end_time   VARCHAR(5)  NOT NULL DEFAULT '',
  reason     VARCHAR(255) NOT NULL DEFAULT '',
  created_at VARCHAR(25) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_blocked_svc (service_id, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_bookings (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference        VARCHAR(191) NOT NULL,
  access_token     VARCHAR(64)  NOT NULL DEFAULT '',
  client_id        INT NOT NULL DEFAULT 0,
  service_id       INT NOT NULL DEFAULT 0,
  service_name     VARCHAR(190) NOT NULL DEFAULT '',
  booking_date     VARCHAR(10)  NOT NULL DEFAULT '',
  start_time       VARCHAR(5)   NOT NULL DEFAULT '',
  end_time         VARCHAR(5)   NOT NULL DEFAULT '',
  guests           INT NOT NULL DEFAULT 1,
  seats            INT NOT NULL DEFAULT 1,
  location         VARCHAR(190) NOT NULL DEFAULT '',
  unit_price       DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_due       DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid      DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency         VARCHAR(10)  NOT NULL DEFAULT 'N$',
  status           VARCHAR(30)  NOT NULL DEFAULT 'awaiting_eft',
  contact_name     VARCHAR(190) NOT NULL DEFAULT '',
  contact_email    VARCHAR(190) NOT NULL DEFAULT '',
  contact_phone    VARCHAR(60)  NOT NULL DEFAULT '',
  company          VARCHAR(190) NOT NULL DEFAULT '',
  extra_details    TEXT NULL,
  client_notes     TEXT NULL,
  admin_notes      TEXT NULL,
  decline_reason   VARCHAR(500) NOT NULL DEFAULT '',
  payment_deadline VARCHAR(25)  NOT NULL DEFAULT '',
  confirmed_at     VARCHAR(25)  NOT NULL DEFAULT '',
  cancelled_at     VARCHAR(25)  NOT NULL DEFAULT '',
  completed_at     VARCHAR(25)  NOT NULL DEFAULT '',
  reschedules      INT NOT NULL DEFAULT 0,
  source           VARCHAR(20)  NOT NULL DEFAULT 'online',
  ip               VARCHAR(45)  NOT NULL DEFAULT '',
  created_at       VARCHAR(25)  NOT NULL DEFAULT '',
  updated_at       VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_sb_reference (reference),
  KEY idx_sb_date (booking_date, start_time),
  KEY idx_sb_client (client_id, booking_date),
  KEY idx_sb_status (status, booking_date),
  KEY idx_sb_service (service_id, booking_date),
  KEY idx_sb_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_guests (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL DEFAULT 0,
  full_name  VARCHAR(190) NOT NULL DEFAULT '',
  email      VARCHAR(190) NOT NULL DEFAULT '',
  phone      VARCHAR(60)  NOT NULL DEFAULT '',
  notes      VARCHAR(500) NOT NULL DEFAULT '',
  position   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_guests_bkg (booking_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eft_payments (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id           INT NOT NULL DEFAULT 0,
  reference            VARCHAR(190) NOT NULL DEFAULT '',
  amount_due           DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_declared      DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_received      DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency             VARCHAR(10)  NOT NULL DEFAULT 'N$',
  bank_reference       VARCHAR(120) NOT NULL DEFAULT '',
  admin_bank_reference VARCHAR(120) NOT NULL DEFAULT '',
  paid_on              VARCHAR(10)  NOT NULL DEFAULT '',
  status               VARCHAR(30)  NOT NULL DEFAULT 'awaiting',
  client_notes         TEXT NULL,
  admin_notes          TEXT NULL,
  decline_reason       VARCHAR(500) NOT NULL DEFAULT '',
  verified_by          INT NOT NULL DEFAULT 0,
  verified_by_name     VARCHAR(190) NOT NULL DEFAULT '',
  verified_at          VARCHAR(25)  NOT NULL DEFAULT '',
  refunded_at          VARCHAR(25)  NOT NULL DEFAULT '',
  refund_reference     VARCHAR(120) NOT NULL DEFAULT '',
  created_at           VARCHAR(25)  NOT NULL DEFAULT '',
  updated_at           VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_pay_booking (booking_id),
  KEY idx_pay_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_proofs (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id       INT NOT NULL DEFAULT 0,
  payment_id       INT NOT NULL DEFAULT 0,
  stored_name      VARCHAR(190) NOT NULL DEFAULT '',
  original_name    VARCHAR(190) NOT NULL DEFAULT '',
  mime             VARCHAR(100) NOT NULL DEFAULT '',
  extension        VARCHAR(10)  NOT NULL DEFAULT '',
  size             INT NOT NULL DEFAULT 0,
  checksum         VARCHAR(64)  NOT NULL DEFAULT '',
  amount           DECIMAL(12,2) NOT NULL DEFAULT 0,
  bank_reference   VARCHAR(120) NOT NULL DEFAULT '',
  paid_on          VARCHAR(10)  NOT NULL DEFAULT '',
  client_notes     TEXT NULL,
  status           VARCHAR(20)  NOT NULL DEFAULT 'submitted',
  uploaded_by      VARCHAR(20)  NOT NULL DEFAULT 'client',
  uploaded_by_name VARCHAR(120) NOT NULL DEFAULT '',
  uploaded_ip      VARCHAR(45)  NOT NULL DEFAULT '',
  reviewed_by_name VARCHAR(120) NOT NULL DEFAULT '',
  reviewed_at      VARCHAR(25)  NOT NULL DEFAULT '',
  review_reason    VARCHAR(500) NOT NULL DEFAULT '',
  created_at       VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_proof_bkg (booking_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_tickets (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id        INT NOT NULL DEFAULT 0,
  ticket_number     VARCHAR(191) NOT NULL,
  verification_code VARCHAR(30)  NOT NULL DEFAULT '',
  file_name         VARCHAR(190) NOT NULL DEFAULT '',
  status            VARCHAR(20)  NOT NULL DEFAULT 'valid',
  issued_at         VARCHAR(25)  NOT NULL DEFAULT '',
  checked_in_at     VARCHAR(25)  NOT NULL DEFAULT '',
  checked_in_by     VARCHAR(190) NOT NULL DEFAULT '',
  created_at        VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_number (ticket_number),
  KEY idx_ticket_bkg (booking_id),
  KEY idx_ticket_code (verification_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_receipts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id     INT NOT NULL DEFAULT 0,
  payment_id     INT NOT NULL DEFAULT 0,
  receipt_number VARCHAR(191) NOT NULL,
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency       VARCHAR(10)  NOT NULL DEFAULT 'N$',
  file_name      VARCHAR(190) NOT NULL DEFAULT '',
  issued_at      VARCHAR(25)  NOT NULL DEFAULT '',
  created_at     VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipt_number (receipt_number),
  KEY idx_receipt_bkg (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_requests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id     INT NOT NULL DEFAULT 0,
  client_id      INT NOT NULL DEFAULT 0,
  type           VARCHAR(20)  NOT NULL DEFAULT 'cancel',
  requested_date VARCHAR(10)  NOT NULL DEFAULT '',
  requested_time VARCHAR(5)   NOT NULL DEFAULT '',
  reason         TEXT NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'pending',
  admin_response VARCHAR(500) NOT NULL DEFAULT '',
  handled_by     VARCHAR(190) NOT NULL DEFAULT '',
  handled_at     VARCHAR(25)  NOT NULL DEFAULT '',
  created_at     VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_request_bkg (booking_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  INT NOT NULL DEFAULT 0,
  client_id   INT NOT NULL DEFAULT 0,
  to_address  VARCHAR(400) NOT NULL DEFAULT '',
  subject     VARCHAR(250) NOT NULL DEFAULT '',
  template    VARCHAR(60)  NOT NULL DEFAULT '',
  transport   VARCHAR(20)  NOT NULL DEFAULT '',
  attachments VARCHAR(400) NOT NULL DEFAULT '',
  status      VARCHAR(20)  NOT NULL DEFAULT 'queued',
  error       VARCHAR(500) NOT NULL DEFAULT '',
  created_at  VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_email_bkg (booking_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_audit (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL DEFAULT 0,
  actor_type VARCHAR(20)  NOT NULL DEFAULT 'system',
  actor_id   INT NOT NULL DEFAULT 0,
  actor_name VARCHAR(120) NOT NULL DEFAULT '',
  action     VARCHAR(120) NOT NULL DEFAULT '',
  detail     VARCHAR(500) NOT NULL DEFAULT '',
  ip         VARCHAR(45)  NOT NULL DEFAULT '',
  created_at VARCHAR(25)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_audit_bkg (booking_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
