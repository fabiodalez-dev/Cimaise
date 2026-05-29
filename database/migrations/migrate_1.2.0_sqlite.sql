-- Migration 1.2.0 (SQLite)
-- Brings installations created on 1.1.0 up to the current schema.
-- All statements are idempotent: re-running is harmless.
--
-- Changes accumulated since 1.1.0:
--   * Composite analytics indexes (frontend audit + query optimizations)
--   * Schema-version markers for the bundled plugins
--
-- Note: the seed default for performance.html_cache_max_age was raised from
-- 300 to 3600 for fresh installs. We intentionally do NOT overwrite it here:
-- existing installations keep whatever value the operator configured.

CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_type_viewed ON analytics_pageviews(page_type, viewed_at);
CREATE INDEX IF NOT EXISTS idx_analytics_events_type_occurred ON analytics_events(event_type, occurred_at);

INSERT OR IGNORE INTO settings (key, value, type) VALUES
  ('plugin_image_ratings_schema', '2', 'string'),
  ('plugin_analytics_logger_schema', '1', 'string'),
  ('plugin_analytics_pro_schema', '1', 'string');
