-- scrap3r: ZoomInfo lead -> outreach -> appointment pipeline
-- MariaDB 10.4+ / MySQL 8+

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(190)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('admin','rep') NOT NULL DEFAULT 'rep',
  timezone      VARCHAR(64)   NOT NULL DEFAULT 'UTC',
  slot_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  booking_slug  VARCHAR(64)   NULL UNIQUE,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings: SMTP credentials, sender identity, compliance footer.
CREATE TABLE IF NOT EXISTS settings (
  skey       VARCHAR(64) PRIMARY KEY,
  svalue     TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per scraper run or CSV upload, so imports are traceable.
CREATE TABLE IF NOT EXISTS lead_imports (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source       ENUM('scraper','csv','manual') NOT NULL,
  filename     VARCHAR(255) NULL,
  rows_seen    INT UNSIGNED NOT NULL DEFAULT 0,
  rows_created INT UNSIGNED NOT NULL DEFAULT 0,
  rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
  rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  notes        TEXT NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leads (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_id      VARCHAR(64)  NULL,
  source_url       VARCHAR(512) NULL,
  record_type      ENUM('person','company','unknown') NOT NULL DEFAULT 'unknown',
  name             VARCHAR(190) NULL,
  title            VARCHAR(190) NULL,
  company          VARCHAR(190) NULL,
  description      TEXT NULL,
  revenue          VARCHAR(64)  NULL,
  revenue_currency VARCHAR(8)   NULL,
  email            VARCHAR(190) NULL,
  phone            VARCHAR(64)  NULL,
  status           ENUM('new','contacted','replied','booked','won','lost','unqualified')
                     NOT NULL DEFAULT 'new',
  owner_id         INT UNSIGNED NULL,
  import_id        INT UNSIGNED NULL,
  unsubscribed_at  DATETIME NULL,
  bounced_at       DATETIME NULL,
  last_emailed_at  DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leads_external (external_id),
  KEY ix_leads_email (email),
  KEY ix_leads_status (status),
  KEY ix_leads_owner (owner_id),
  FOREIGN KEY (owner_id)  REFERENCES users(id)        ON DELETE SET NULL,
  FOREIGN KEY (import_id) REFERENCES lead_imports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS templates (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  subject    VARCHAR(255) NOT NULL,
  body_html  MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(160) NOT NULL,
  status            ENUM('draft','running','paused','done') NOT NULL DEFAULT 'draft',
  from_name         VARCHAR(120) NULL,
  from_email        VARCHAR(190) NULL,
  reply_to          VARCHAR(190) NULL,
  owner_id          INT UNSIGNED NULL,
  throttle_per_hour SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  send_window_start TIME NOT NULL DEFAULT '08:00:00',
  send_window_end   TIME NOT NULL DEFAULT '18:00:00',
  skip_weekends     TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_steps (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  step_no     SMALLINT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NOT NULL,
  delay_hours INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_step (campaign_id, step_no),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enrollments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id   INT UNSIGNED NOT NULL,
  lead_id       INT UNSIGNED NOT NULL,
  status        ENUM('active','finished','stopped') NOT NULL DEFAULT 'active',
  current_step  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_send_at  DATETIME NULL,
  stop_reason   VARCHAR(120) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enroll (campaign_id, lead_id),
  KEY ix_due (status, next_send_at),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id)     REFERENCES leads(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id     INT UNSIGNED NOT NULL,
  campaign_id INT UNSIGNED NULL,
  step_no     SMALLINT UNSIGNED NULL,
  to_email    VARCHAR(190) NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body_html   MEDIUMTEXT NOT NULL,
  status      ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  error       TEXT NULL,
  sent_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_msg_lead (lead_id),
  KEY ix_msg_sent (sent_at),
  FOREIGN KEY (lead_id)     REFERENCES leads(id)     ON DELETE CASCADE,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hard block list. Checked before every send, independent of lead state.
CREATE TABLE IF NOT EXISTS suppressions (
  email      VARCHAR(190) PRIMARY KEY,
  reason     ENUM('unsubscribed','bounced','complaint','manual') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS availability (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  weekday    TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  UNIQUE KEY uq_avail (user_id, weekday, start_time),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-purpose random tokens for public pages (booking, unsubscribe).
CREATE TABLE IF NOT EXISTS tokens (
  token      CHAR(43) PRIMARY KEY,
  purpose    ENUM('book','unsubscribe') NOT NULL,
  lead_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  expires_at DATETIME NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_token_lead (lead_id, purpose),
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
