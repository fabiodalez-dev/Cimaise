-- Templates table and album->template association (SQLite)
CREATE TABLE IF NOT EXISTS templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  settings TEXT NULL, -- JSON as TEXT in SQLite
  libs TEXT NULL,     -- JSON as TEXT in SQLite
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Add template_id column to albums table
ALTER TABLE albums ADD COLUMN template_id INTEGER NULL;

-- Create index for template_id (foreign key constraint not enforced in SQLite by default)
CREATE INDEX IF NOT EXISTS idx_albums_template ON albums(template_id);