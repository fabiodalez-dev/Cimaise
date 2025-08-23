-- Add password protection and downloads toggle to albums (SQLite)
ALTER TABLE albums ADD COLUMN password_hash TEXT NULL;
ALTER TABLE albums ADD COLUMN allow_downloads INTEGER NOT NULL DEFAULT 0;

