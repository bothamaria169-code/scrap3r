-- Hunter.io integration: track where an address came from and how sure we are
-- it is deliverable, so unverified or bouncing addresses can be kept out of
-- campaigns.
ALTER TABLE lead_imports
  MODIFY source ENUM('scraper','csv','manual','hunter') NOT NULL;

ALTER TABLE leads
  ADD COLUMN email_confidence TINYINT UNSIGNED NULL AFTER email,
  ADD COLUMN email_status     VARCHAR(20)      NULL AFTER email_confidence,
  ADD COLUMN email_source     VARCHAR(20)      NULL AFTER email_status,
  ADD COLUMN linkedin_url     VARCHAR(255)     NULL AFTER phone;
