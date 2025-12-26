-- Migration: EXIF Editor Extended Fields
-- Adds columns for full EXIF editing support
-- Run: php bin/console migrate

-- Equipment fields (stored separately for editing)
ALTER TABLE images ADD COLUMN exif_make TEXT DEFAULT NULL;
ALTER TABLE images ADD COLUMN exif_model TEXT DEFAULT NULL;
ALTER TABLE images ADD COLUMN exif_lens_model TEXT DEFAULT NULL;
ALTER TABLE images ADD COLUMN software TEXT DEFAULT NULL;

-- Exposure fields
ALTER TABLE images ADD COLUMN focal_length REAL DEFAULT NULL;
ALTER TABLE images ADD COLUMN exposure_bias REAL DEFAULT NULL;

-- Mode fields
ALTER TABLE images ADD COLUMN flash INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN white_balance INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN exposure_program INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN metering_mode INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN exposure_mode INTEGER DEFAULT NULL;

-- Details fields
ALTER TABLE images ADD COLUMN date_original TEXT DEFAULT NULL;
ALTER TABLE images ADD COLUMN color_space INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN contrast INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN saturation INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN sharpness INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN scene_capture_type INTEGER DEFAULT NULL;
ALTER TABLE images ADD COLUMN light_source INTEGER DEFAULT NULL;

-- Location fields
ALTER TABLE images ADD COLUMN gps_lat REAL DEFAULT NULL;
ALTER TABLE images ADD COLUMN gps_lng REAL DEFAULT NULL;

-- Info fields
ALTER TABLE images ADD COLUMN artist TEXT DEFAULT NULL;
ALTER TABLE images ADD COLUMN copyright TEXT DEFAULT NULL;

-- Extended EXIF as JSON (for less common fields)
ALTER TABLE images ADD COLUMN exif_extended TEXT DEFAULT NULL;

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS idx_images_date_original ON images(date_original);
CREATE INDEX IF NOT EXISTS idx_images_gps ON images(gps_lat, gps_lng);
CREATE INDEX IF NOT EXISTS idx_images_artist ON images(artist);
