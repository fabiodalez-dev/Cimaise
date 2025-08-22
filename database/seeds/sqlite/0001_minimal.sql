-- Minimal seed data (SQLite version)

-- Categories
INSERT OR IGNORE INTO categories (id, name, slug, sort_order) VALUES
(1, 'Ritratti', 'ritratti', 1),
(2, 'Street Photography', 'street', 2),
(3, 'Paesaggi', 'paesaggi', 3);

-- Tags
INSERT OR IGNORE INTO tags (id, name, slug) VALUES
(1, 'Analogico', 'analogico'),
(2, '35mm', '35mm'),
(3, '120', '120'),
(4, 'Bianco e Nero', 'bw'),
(5, 'C-41', 'c41'),
(6, 'E-6', 'e6'),
(7, 'Pellicola', 'pellicola'),
(8, 'Digitale', 'digitale');

-- Albums  
INSERT OR IGNORE INTO albums (id, title, slug, category_id, excerpt, is_published, published_at, sort_order) VALUES
(1, 'Volti della Città', 'volti-della-citta', 1, 'Ritratti urbani catturati nelle strade della città.', 1, datetime('now'), 1),
(2, 'Momenti di Strada', 'momenti-di-strada', 2, 'La vita quotidiana attraverso l''obiettivo.', 1, datetime('now'), 2),
(3, 'Panorami Naturali', 'panorami-naturali', 3, 'La bellezza della natura in ogni stagione.', 1, datetime('now'), 3);

-- Album-Tag relations
INSERT OR IGNORE INTO album_tag (album_id, tag_id) VALUES
(1, 1), (1, 2), (1, 4), -- Ritratti: analogico, 35mm, b/w
(2, 1), (2, 2), (2, 7), -- Street: analogico, 35mm, pellicola  
(3, 8), (3, 2);         -- Paesaggi: digitale, 35mm