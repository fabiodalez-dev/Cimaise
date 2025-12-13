-- Add show_date column to albums table (MySQL)
ALTER TABLE albums ADD COLUMN show_date TINYINT(1) NOT NULL DEFAULT 1 AFTER shoot_date;
