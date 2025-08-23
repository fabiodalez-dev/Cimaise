-- Add password protection and downloads toggle to albums (MySQL)
ALTER TABLE albums 
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER updated_at,
  ADD COLUMN allow_downloads TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

