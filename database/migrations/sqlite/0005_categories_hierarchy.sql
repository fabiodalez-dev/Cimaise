-- Add parent_id to categories for hierarchy (SQLite)
ALTER TABLE categories ADD COLUMN parent_id INTEGER NULL;
CREATE INDEX IF NOT EXISTS idx_categories_parent ON categories(parent_id);

