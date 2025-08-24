-- Add image_path column to categories table for MySQL
ALTER TABLE categories ADD COLUMN image_path VARCHAR(255) NULL AFTER parent_id;