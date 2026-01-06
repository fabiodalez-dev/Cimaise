-- Migration: Add performance and cache settings
-- Run this on existing SQLite installations to add the new performance settings

-- Compression settings
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('performance.compression_enabled', 'true', 'boolean');
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('performance.compression_type', 'auto', 'string');
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('performance.compression_level', '6', 'integer');

-- Cache settings
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('performance.cache_enabled', 'true', 'boolean');
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('performance.static_cache_max_age', '31536000', 'integer');
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('performance.media_cache_max_age', '86400', 'integer');
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('performance.html_cache_max_age', '300', 'integer');
