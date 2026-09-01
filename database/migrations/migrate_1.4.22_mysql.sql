-- Migration 1.4.22 (MySQL) — settings introduced by the SEO layer rework
-- (see the SQLite migration for rationale). `key` is a reserved word (backticked)
-- and is UNIQUE, so INSERT IGNORE is idempotent and never overwrites an
-- admin-set value.

INSERT IGNORE INTO `settings` (`key`, `value`, `type`) VALUES
  ('seo.expose_gps', 'false', 'boolean'),
  ('seo.local_business_price_range', '$$', 'string'),
  ('seo.google_verification', '', 'string'),
  ('seo.bing_verification', '', 'string');
