-- Migration 1.4.22 (SQLite) — settings introduced by the SEO layer rework
--
-- The SEO overhaul reads four new settings that fresh installs get from
-- schema.sqlite.sql; existing installs need them backfilled here:
--   seo.expose_gps                gate photo GPS in public HTML (default off, privacy-safe)
--   seo.local_business_price_range feeds the optional LocalBusiness JSON-LD
--   seo.google_verification / seo.bing_verification  search-engine ownership meta tags
-- Idempotent: settings.key is UNIQUE, so INSERT OR IGNORE never overwrites an
-- admin-set value and is safe to re-run.

INSERT OR IGNORE INTO settings (key, value, type) VALUES
  ('seo.expose_gps', 'false', 'boolean'),
  ('seo.local_business_price_range', '$$', 'string'),
  ('seo.google_verification', '', 'string'),
  ('seo.bing_verification', '', 'string');
