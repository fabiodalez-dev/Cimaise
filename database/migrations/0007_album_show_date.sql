-- Add show_date flag to albums (MySQL)
ALTER TABLE albums ADD COLUMN IF NOT EXISTS show_date TINYINT(1) NOT NULL DEFAULT 1 AFTER shoot_date;
