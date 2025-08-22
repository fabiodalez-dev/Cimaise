-- Add parent_id to categories for hierarchy (MySQL)
ALTER TABLE categories
  ADD COLUMN IF NOT EXISTS parent_id BIGINT UNSIGNED NULL AFTER slug,
  ADD INDEX IF NOT EXISTS idx_categories_parent (parent_id),
  ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL;
