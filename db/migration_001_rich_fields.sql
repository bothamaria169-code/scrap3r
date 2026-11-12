-- The live Bright Data schema is 41 fields, not the 8 in the repo's sample.
-- Store the ones useful for sales targeting, plus the raw record so a future
-- schema change never silently loses data.
ALTER TABLE leads
  ADD COLUMN website       VARCHAR(255) NULL AFTER company,
  ADD COLUMN industry      VARCHAR(190) NULL AFTER website,
  ADD COLUMN headquarters  VARCHAR(255) NULL AFTER industry,
  ADD COLUMN employees     VARCHAR(64)  NULL AFTER headquarters,
  ADD COLUMN email_formats TEXT         NULL AFTER phone,
  ADD COLUMN raw_json      LONGTEXT     NULL AFTER description;

ALTER TABLE lead_imports
  ADD COLUMN rows_errored INT UNSIGNED NOT NULL DEFAULT 0 AFTER rows_skipped;
