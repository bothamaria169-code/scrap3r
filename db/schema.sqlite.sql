-- scrap3r schema for SQLite (hosted deployment).
-- Mirrors db/schema.sql plus migrations 001-003. Built automatically the first
-- time the app opens an empty database file.
--
-- Times are stored as 'YYYY-MM-DD HH:MM:SS' text in the app's timezone.
-- NOW() is registered by the app (see Db::openSqlite); SQLite's own
-- CURRENT_TIMESTAMP would be UTC.
-- Email columns use COLLATE NOCASE so lookups match MySQL's case-insensitivity.

PRAGMA foreign_keys = ON;

CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT    NOT NULL,
  email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT    NOT NULL,
  role          TEXT    NOT NULL DEFAULT 'rep' CHECK (role IN ('admin','rep')),
  timezone      TEXT    NOT NULL DEFAULT 'UTC',
  slot_minutes  INTEGER NOT NULL DEFAULT 30,
  booking_slug  TEXT    UNIQUE,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT    NOT NULL DEFAULT (NOW())
);

CREATE TABLE settings (
  skey       TEXT PRIMARY KEY,
  svalue     TEXT,
  updated_at TEXT NOT NULL DEFAULT (NOW())
);

CREATE TABLE lead_imports (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  source       TEXT    NOT NULL CHECK (source IN ('scraper','csv','manual','hunter')),
  filename     TEXT,
  rows_seen    INTEGER NOT NULL DEFAULT 0,
  rows_created INTEGER NOT NULL DEFAULT 0,
  rows_updated INTEGER NOT NULL DEFAULT 0,
  rows_skipped INTEGER NOT NULL DEFAULT 0,
  rows_errored INTEGER NOT NULL DEFAULT 0,
  notes        TEXT,
  created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at   TEXT    NOT NULL DEFAULT (NOW())
);

CREATE TABLE leads (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  external_id      TEXT UNIQUE,
  source_url       TEXT,
  record_type      TEXT NOT NULL DEFAULT 'unknown' CHECK (record_type IN ('person','company','unknown')),
  name             TEXT,
  title            TEXT,
  company          TEXT,
  website          TEXT,
  industry         TEXT,
  headquarters     TEXT,
  employees        TEXT,
  description      TEXT,
  raw_json         TEXT,
  revenue          TEXT,
  revenue_currency TEXT,
  email            TEXT COLLATE NOCASE,
  email_confidence INTEGER,
  email_status     TEXT,
  email_source     TEXT,
  phone            TEXT,
  linkedin_url     TEXT,
  email_formats    TEXT,
  status           TEXT NOT NULL DEFAULT 'new'
                     CHECK (status IN ('new','contacted','replied','booked','won','lost','unqualified')),
  owner_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
  import_id        INTEGER REFERENCES lead_imports(id) ON DELETE SET NULL,
  unsubscribed_at  TEXT,
  bounced_at       TEXT,
  last_emailed_at  TEXT,
  created_at       TEXT NOT NULL DEFAULT (NOW()),
  updated_at       TEXT NOT NULL DEFAULT (NOW())
);
CREATE INDEX ix_leads_email  ON leads(email);
CREATE INDEX ix_leads_status ON leads(status);
CREATE INDEX ix_leads_owner  ON leads(owner_id);

CREATE TABLE templates (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  name            TEXT NOT NULL,
  subject         TEXT NOT NULL,
  body_html       TEXT NOT NULL,
  attachment_path TEXT,
  attachment_name TEXT,
  created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at      TEXT NOT NULL DEFAULT (NOW()),
  updated_at      TEXT NOT NULL DEFAULT (NOW())
);

CREATE TABLE campaigns (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  name              TEXT    NOT NULL,
  status            TEXT    NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','running','paused','done')),
  from_name         TEXT,
  from_email        TEXT,
  reply_to          TEXT,
  owner_id          INTEGER REFERENCES users(id) ON DELETE SET NULL,
  throttle_per_hour INTEGER NOT NULL DEFAULT 30,
  send_window_start TEXT    NOT NULL DEFAULT '08:00:00',
  send_window_end   TEXT    NOT NULL DEFAULT '18:00:00',
  skip_weekends     INTEGER NOT NULL DEFAULT 1,
  created_at        TEXT    NOT NULL DEFAULT (NOW())
);

CREATE TABLE campaign_steps (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  step_no     INTEGER NOT NULL,
  template_id INTEGER NOT NULL REFERENCES templates(id),
  delay_hours INTEGER NOT NULL DEFAULT 0,
  UNIQUE (campaign_id, step_no)
);

CREATE TABLE enrollments (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id  INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  lead_id      INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  status       TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','finished','stopped')),
  current_step INTEGER NOT NULL DEFAULT 0,
  next_send_at TEXT,
  stop_reason  TEXT,
  created_at   TEXT    NOT NULL DEFAULT (NOW()),
  UNIQUE (campaign_id, lead_id)
);
CREATE INDEX ix_due ON enrollments(status, next_send_at);

CREATE TABLE messages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  lead_id     INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
  step_no     INTEGER,
  to_email    TEXT NOT NULL,
  subject     TEXT NOT NULL,
  body_html   TEXT NOT NULL,
  status      TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sent','failed','skipped')),
  error       TEXT,
  sent_at     TEXT,
  created_at  TEXT NOT NULL DEFAULT (NOW())
);
CREATE INDEX ix_msg_lead ON messages(lead_id);
CREATE INDEX ix_msg_sent ON messages(sent_at);

CREATE TABLE suppressions (
  email      TEXT PRIMARY KEY COLLATE NOCASE,
  reason     TEXT NOT NULL CHECK (reason IN ('unsubscribed','bounced','complaint','manual')),
  created_at TEXT NOT NULL DEFAULT (NOW())
);

CREATE TABLE availability (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  weekday    INTEGER NOT NULL,
  start_time TEXT    NOT NULL,
  end_time   TEXT    NOT NULL,
  UNIQUE (user_id, weekday, start_time)
);

CREATE TABLE appointments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  lead_id    INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  starts_at  TEXT NOT NULL,
  ends_at    TEXT NOT NULL,
  status     TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','completed','cancelled','no_show')),
  source     TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('self','manual')),
  location   TEXT,
  notes      TEXT,
  created_at TEXT NOT NULL DEFAULT (NOW())
);
CREATE INDEX ix_appt_user_time ON appointments(user_id, starts_at);

CREATE TABLE tokens (
  token      TEXT PRIMARY KEY,
  purpose    TEXT NOT NULL CHECK (purpose IN ('book','unsubscribe')),
  lead_id    INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
  expires_at TEXT,
  used_at    TEXT,
  created_at TEXT NOT NULL DEFAULT (NOW())
);
CREATE INDEX ix_token_lead ON tokens(lead_id, purpose);

-- MySQL's "ON UPDATE CURRENT_TIMESTAMP", unless the update set it explicitly.
CREATE TRIGGER trg_settings_updated AFTER UPDATE ON settings
  FOR EACH ROW WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE settings SET updated_at = NOW() WHERE skey = NEW.skey;
END;

CREATE TRIGGER trg_leads_updated AFTER UPDATE ON leads
  FOR EACH ROW WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE leads SET updated_at = NOW() WHERE id = NEW.id;
END;

CREATE TRIGGER trg_templates_updated AFTER UPDATE ON templates
  FOR EACH ROW WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE templates SET updated_at = NOW() WHERE id = NEW.id;
END;
