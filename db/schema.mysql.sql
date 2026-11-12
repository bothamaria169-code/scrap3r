-- scrap3r schema for MySQL / MariaDB (cPanel deployment).
-- The complete current schema: db/schema.sqlite.sql plus db/migrations/002.
-- The app builds it automatically the first time it connects to an empty
-- database, so there is nothing to import by hand. (Importing this file in
-- phpMyAdmin also works.)
--
-- MariaDB 10.3+ / MySQL 5.7+.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','rep') NOT NULL DEFAULT 'rep',
  timezone      VARCHAR(64)  NOT NULL DEFAULT 'UTC',
  slot_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  booking_slug  VARCHAR(190) NULL UNIQUE,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey       VARCHAR(64) PRIMARY KEY,
  svalue     MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_imports (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source       ENUM('scraper','csv','manual','hunter') NOT NULL,
  filename     VARCHAR(255) NULL,
  rows_seen    INT UNSIGNED NOT NULL DEFAULT 0,
  rows_created INT UNSIGNED NOT NULL DEFAULT 0,
  rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
  rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  rows_errored INT UNSIGNED NOT NULL DEFAULT 0,
  notes        TEXT NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_id      VARCHAR(190) NULL UNIQUE,
  source_url       VARCHAR(512) NULL,
  record_type      ENUM('person','company','unknown') NOT NULL DEFAULT 'unknown',
  name             VARCHAR(190) NULL,
  title            VARCHAR(255) NULL,
  company          VARCHAR(255) NULL,
  website          VARCHAR(255) NULL,
  industry         VARCHAR(190) NULL,
  headquarters     VARCHAR(255) NULL,
  employees        VARCHAR(64)  NULL,
  description      TEXT NULL,
  raw_json         LONGTEXT NULL,
  revenue          VARCHAR(64) NULL,
  revenue_currency VARCHAR(16) NULL,
  email            VARCHAR(190) NULL,
  email_confidence TINYINT UNSIGNED NULL,
  email_status     VARCHAR(20) NULL,
  email_source     VARCHAR(20) NULL,
  phone            VARCHAR(64) NULL,
  linkedin_url     VARCHAR(255) NULL,
  email_formats    TEXT NULL,
  status           ENUM('new','contacted','replied','booked','won','lost','unqualified') NOT NULL DEFAULT 'new',
  owner_id         INT UNSIGNED NULL,
  import_id        INT UNSIGNED NULL,
  unsubscribed_at  DATETIME NULL,
  bounced_at       DATETIME NULL,
  last_emailed_at  DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_leads_email (email),
  KEY ix_leads_status (status),
  KEY ix_leads_owner (owner_id),
  FOREIGN KEY (owner_id)  REFERENCES users(id)        ON DELETE SET NULL,
  FOREIGN KEY (import_id) REFERENCES lead_imports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(190) NOT NULL,
  subject         VARCHAR(255) NOT NULL,
  body_html       LONGTEXT NOT NULL,
  attachment_path VARCHAR(255) NULL,
  attachment_name VARCHAR(255) NULL,
  design_json     LONGTEXT NULL,
  preheader       VARCHAR(255) NULL,
  kind            VARCHAR(16) NOT NULL DEFAULT 'email',
  is_sample       TINYINT(1) NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(190) NOT NULL,
  description       TEXT NULL,
  audience_json     TEXT NULL,
  status            ENUM('draft','running','paused','done') NOT NULL DEFAULT 'draft',
  from_name         VARCHAR(190) NULL,
  from_email        VARCHAR(190) NULL,
  reply_to          VARCHAR(190) NULL,
  owner_id          INT UNSIGNED NULL,
  throttle_per_hour INT UNSIGNED NOT NULL DEFAULT 30,
  send_window_start TIME NOT NULL DEFAULT '08:00:00',
  send_window_end   TIME NOT NULL DEFAULT '18:00:00',
  skip_weekends     TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_steps (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  step_no     INT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NOT NULL,
  delay_hours INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_campaign_step (campaign_id, step_no),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id  INT UNSIGNED NOT NULL,
  lead_id      INT UNSIGNED NOT NULL,
  status       ENUM('active','finished','stopped') NOT NULL DEFAULT 'active',
  current_step INT UNSIGNED NOT NULL DEFAULT 0,
  next_send_at DATETIME NULL,
  stop_reason  VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enrollment (campaign_id, lead_id),
  KEY ix_due (status, next_send_at),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id)     REFERENCES leads(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id           INT UNSIGNED NOT NULL,
  campaign_id       INT UNSIGNED NULL,
  step_no           INT UNSIGNED NULL,
  to_email          VARCHAR(190) NOT NULL,
  subject           VARCHAR(255) NOT NULL,
  body_html         LONGTEXT NOT NULL,
  status            ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  error             TEXT NULL,
  sent_at           DATETIME NULL,
  message_id_header VARCHAR(255) NULL,
  bounced_at        DATETIME NULL,
  bounce_type       VARCHAR(16) NULL,
  bounce_reason     TEXT NULL,
  replied_at        DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_msg_lead (lead_id),
  KEY ix_msg_sent (sent_at),
  KEY ix_msg_message_id (message_id_header),
  FOREIGN KEY (lead_id)     REFERENCES leads(id)     ON DELETE CASCADE,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppressions (
  email      VARCHAR(190) PRIMARY KEY,
  reason     ENUM('unsubscribed','bounced','complaint','manual') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS availability (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  weekday    TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  UNIQUE KEY uq_avail (user_id, weekday, start_time),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  starts_at  DATETIME NOT NULL,
  ends_at    DATETIME NOT NULL,
  status     ENUM('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  source     ENUM('self','manual') NOT NULL DEFAULT 'manual',
  location   VARCHAR(255) NULL,
  notes      TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_appt_user_time (user_id, starts_at),
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tokens (
  token      VARCHAR(128) PRIMARY KEY,
  purpose    ENUM('book','unsubscribe') NOT NULL,
  lead_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  expires_at DATETIME NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_token_lead (lead_id, purpose),
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        ENUM('sent','failed','bounced','replied','unsubscribed','auto_replied') NOT NULL,
  message_id  INT UNSIGNED NULL,
  lead_id     INT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  detail      TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_events_type_time (type, created_at),
  KEY ix_events_campaign (campaign_id, type),
  FOREIGN KEY (message_id)  REFERENCES messages(id)  ON DELETE SET NULL,
  FOREIGN KEY (lead_id)     REFERENCES leads(id)     ON DELETE SET NULL,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
