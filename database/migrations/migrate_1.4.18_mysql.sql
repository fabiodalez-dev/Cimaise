-- Migration 1.4.18 (MySQL) — retire the removed bundled plugins
--
-- analytics-logger and image-rating were removed from the distribution (inert:
-- they registered hooks the core never emitted). Fresh installs no longer seed
-- them, but EXISTING installs still have their plugin_status rows AND their
-- files on disk — the updater's orphan cleanup only runs on app/ and
-- public/assets/, never on plugins/ (so it can't delete a user's custom
-- plugins). Left alone, PluginManager would keep loading them from disk.
--
-- Deactivate (do NOT delete) the rows: PluginManager auto-installs+activates any
-- plugin dir that has NO status row, so deleting the row while the files remain
-- would silently reinstate the plugin on the next boot. With is_active=0 AND
-- is_installed=0 the row is kept, isPluginActive() returns false, and the plugin
-- stays off. The (empty, orphaned) plugin tables are left in place — no code
-- reads them and dropping data automatically on a production upgrade is unsafe.
UPDATE plugin_status SET is_active = 0, is_installed = 0
WHERE slug IN ('analytics-logger', 'image-rating');

DELETE FROM settings
WHERE `key` IN ('plugin_analytics_logger_schema', 'plugin_image_ratings_schema');
