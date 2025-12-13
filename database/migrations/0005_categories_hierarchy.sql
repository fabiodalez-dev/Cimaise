-- Add parent_id to categories for hierarchy (MySQL)
-- Separate statements for duplicate column handling
ALTER TABLE categories ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER slug;
ALTER TABLE categories ADD INDEX idx_categories_parent (parent_id);
ALTER TABLE categories ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL;
