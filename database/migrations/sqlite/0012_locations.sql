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
-- Columns already exist, skip
-- location_id already added to both albums and images tables
