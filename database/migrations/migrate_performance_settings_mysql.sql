-- Migration: Add performance and cache settings
-- Run this on existing MySQL installations to add the new performance settings

-- Compression settings
INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'performance.compression_enabled', 'true', 'boolean'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'performance.compression_enabled');

INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'performance.compression_type', 'auto', 'string'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'performance.compression_type');

INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'performance.compression_level', '6', 'integer'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'performance.compression_level');

-- Cache settings
INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'performance.cache_enabled', 'true', 'boolean'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'performance.cache_enabled');

INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'performance.static_cache_max_age', '31536000', 'integer'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'performance.static_cache_max_age');

INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'performance.media_cache_max_age', '86400', 'integer'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'performance.media_cache_max_age');

INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'performance.html_cache_max_age', '300', 'integer'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'performance.html_cache_max_age');
