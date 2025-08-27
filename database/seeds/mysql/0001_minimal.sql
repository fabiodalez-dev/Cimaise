-- Minimal seed data for MySQL
INSERT INTO categories (name, slug, sort_order, created_at) VALUES
('Photo', 'photo', 1, NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO tags (name, slug, created_at) VALUES
('35mm', '35mm', NOW()),
('Digital', 'digital', NOW()),
('B&W', 'bw', NOW()),
('Color', 'color', NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);