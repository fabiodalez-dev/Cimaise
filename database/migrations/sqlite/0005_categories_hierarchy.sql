-- Add parent_id to categories for hierarchy (SQLite)
-- Skip if column already exists
CREATE INDEX IF NOT EXISTS idx_categories_parent ON categories(parent_id);

