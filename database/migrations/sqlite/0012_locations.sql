-- Locations table and relationships (SQLite)

CREATE TABLE IF NOT EXISTS locations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT UNIQUE NOT NULL,
  description TEXT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_locations_slug ON locations(slug);

-- Add location_id to albums table (SQLite does not support IF NOT EXISTS on columns)
-- This file assumes a fresh run where the column doesn't exist yet.
ALTER TABLE albums ADD COLUMN location_id INTEGER NULL;

-- Add location_id to images table
ALTER TABLE images ADD COLUMN location_id INTEGER NULL;
