-- Add show_date flag to albums (SQLite)
ALTER TABLE albums ADD COLUMN show_date INTEGER NOT NULL DEFAULT 1;

