-- Add image_path column to categories table for SQLite
-- Column already exists, skip
CREATE INDEX IF NOT EXISTS idx_categories_image_path ON categories(image_path);