-- Add image_path column to categories table for SQLite
ALTER TABLE categories ADD COLUMN image_path TEXT NULL;
CREATE INDEX IF NOT EXISTS idx_categories_image_path ON categories(image_path);