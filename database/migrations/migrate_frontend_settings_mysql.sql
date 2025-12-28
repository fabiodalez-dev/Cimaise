-- Migration: Add frontend settings for dark mode and custom CSS
-- Run this on existing MySQL installations to add the new frontend settings

-- Add frontend.dark_mode if not exists
INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'frontend.dark_mode', 'false', 'boolean'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'frontend.dark_mode');

-- Add frontend.custom_css if not exists
INSERT INTO `settings` (`key`, `value`, `type`)
SELECT 'frontend.custom_css', '', 'string'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'frontend.custom_css');
