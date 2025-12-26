-- Migration: EXIF Editor Extended Fields (MySQL)
-- Adds columns for full EXIF editing support
-- Run: php bin/console migrate

-- Equipment fields (stored separately for editing)
ALTER TABLE images ADD COLUMN exif_make VARCHAR(255) DEFAULT NULL;
ALTER TABLE images ADD COLUMN exif_model VARCHAR(255) DEFAULT NULL;
ALTER TABLE images ADD COLUMN exif_lens_model VARCHAR(255) DEFAULT NULL;
ALTER TABLE images ADD COLUMN software VARCHAR(255) DEFAULT NULL;

-- Exposure fields
ALTER TABLE images ADD COLUMN focal_length DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE images ADD COLUMN exposure_bias DECIMAL(5,2) DEFAULT NULL;

-- Mode fields
ALTER TABLE images ADD COLUMN flash SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN white_balance SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN exposure_program SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN metering_mode SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN exposure_mode SMALLINT DEFAULT NULL;

-- Details fields
ALTER TABLE images ADD COLUMN date_original VARCHAR(50) DEFAULT NULL;
ALTER TABLE images ADD COLUMN color_space SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN contrast SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN saturation SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN sharpness SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN scene_capture_type SMALLINT DEFAULT NULL;
ALTER TABLE images ADD COLUMN light_source SMALLINT DEFAULT NULL;

-- Location fields
ALTER TABLE images ADD COLUMN gps_lat DECIMAL(10,6) DEFAULT NULL;
ALTER TABLE images ADD COLUMN gps_lng DECIMAL(10,6) DEFAULT NULL;

-- Info fields
ALTER TABLE images ADD COLUMN artist VARCHAR(255) DEFAULT NULL;
ALTER TABLE images ADD COLUMN copyright VARCHAR(500) DEFAULT NULL;

-- Extended EXIF as JSON (for less common fields)
ALTER TABLE images ADD COLUMN exif_extended JSON DEFAULT NULL;

-- Indexes for common queries
CREATE INDEX idx_images_date_original ON images(date_original);
CREATE INDEX idx_images_gps ON images(gps_lat, gps_lng);
CREATE INDEX idx_images_artist ON images(artist);
