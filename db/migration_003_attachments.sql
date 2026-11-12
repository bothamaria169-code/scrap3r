-- Email attachments (e.g. an event brochure) stored per template.
ALTER TABLE templates
  ADD COLUMN attachment_path VARCHAR(255) NULL AFTER body_html,
  ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path;
