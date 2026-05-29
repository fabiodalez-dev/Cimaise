-- Migration 1.2.0 (MySQL / MariaDB)
-- Brings installations created on 1.1.0 up to the current schema.
--
-- Changes accumulated since 1.1.0:
--   * Composite analytics indexes (frontend audit + query optimizations)
--   * Schema-version markers for the bundled plugins
--
-- Note: CREATE INDEX has no portable "IF NOT EXISTS" across MySQL 8 and MariaDB.
-- The migration runner records each version once, so these run a single time on
-- a 1.1.0 install (which does not yet have the indexes). The seed default for
-- performance.html_cache_max_age (300 -> 3600) is intentionally NOT forced here.

CREATE INDEX `idx_analytics_pageviews_type_viewed` ON `analytics_pageviews` (`page_type`, `viewed_at`);
CREATE INDEX `idx_analytics_events_type_occurred` ON `analytics_events` (`event_type`, `occurred_at`);

INSERT IGNORE INTO `settings` (`key`, `value`, `type`) VALUES
  ('plugin_image_ratings_schema', '2', 'string'),
  ('plugin_analytics_logger_schema', '1', 'string'),
  ('plugin_analytics_pro_schema', '1', 'string');
