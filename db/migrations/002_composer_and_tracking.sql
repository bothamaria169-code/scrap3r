-- Visual composer, campaign audiences, and the identifiers needed to match
-- bounces and replies back to the message they belong to.

-- Email designs: the block layout the composer edits. body_html stays the
-- rendered, email-safe output that is actually sent.
ALTER TABLE templates ADD COLUMN design_json TEXT;
ALTER TABLE templates ADD COLUMN preheader   TEXT;
ALTER TABLE templates ADD COLUMN kind        TEXT NOT NULL DEFAULT 'email';   -- email | reply
ALTER TABLE templates ADD COLUMN is_sample   INTEGER NOT NULL DEFAULT 0;

ALTER TABLE campaigns ADD COLUMN description   TEXT;
ALTER TABLE campaigns ADD COLUMN audience_json TEXT;                          -- saved audience filter

-- The Message-ID header we generated, so a reply (In-Reply-To) or a bounce
-- (the returned original) can be tied to this exact send.
ALTER TABLE messages ADD COLUMN message_id_header TEXT;
ALTER TABLE messages ADD COLUMN bounced_at        TEXT;
ALTER TABLE messages ADD COLUMN bounce_type       TEXT;                       -- hard | soft
ALTER TABLE messages ADD COLUMN bounce_reason     TEXT;
ALTER TABLE messages ADD COLUMN replied_at        TEXT;
CREATE INDEX ix_msg_message_id ON messages(message_id_header);

-- One row per thing that happened to an email; the analytics screen reads this.
CREATE TABLE events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  type        TEXT NOT NULL CHECK (type IN ('sent','failed','bounced','replied','unsubscribed','auto_replied')),
  message_id  INTEGER REFERENCES messages(id) ON DELETE SET NULL,
  lead_id     INTEGER REFERENCES leads(id) ON DELETE SET NULL,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
  detail      TEXT,
  created_at  TEXT NOT NULL DEFAULT (NOW())
);
CREATE INDEX ix_events_type_time ON events(type, created_at);
CREATE INDEX ix_events_campaign  ON events(campaign_id, type);
