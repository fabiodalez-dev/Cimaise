-- Minimal seed data for development
INSERT INTO categories (name, slug, sort_order) VALUES
('Ritratti', 'ritratti', 1),
('Street', 'street', 2),
('Paesaggi', 'paesaggi', 3)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO tags (name, slug) VALUES
('Analogico', 'analogico'),
('35mm', '35mm'),
('120', '120'),
('B/W', 'bw'),
('C-41', 'c-41'),
('E-6', 'e-6')
ON DUPLICATE KEY UPDATE name=VALUES(name);

