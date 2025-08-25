-- Minimal seed data (SQLite version)

-- Categories
INSERT OR IGNORE INTO categories (id, name, slug, sort_order, created_at) VALUES
(1, 'Photo', 'photo', 1, datetime('now'));