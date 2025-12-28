-- Migration: Add frontend settings for dark mode and custom CSS
-- Run this on existing SQLite installations to add the new frontend settings

-- Add frontend.dark_mode if not exists (uses INSERT OR IGNORE with unique key constraint)
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('frontend.dark_mode', 'false', 'boolean');

-- Add frontend.custom_css if not exists
INSERT OR IGNORE INTO settings (`key`, `value`, `type`) VALUES ('frontend.custom_css', '', 'string');
