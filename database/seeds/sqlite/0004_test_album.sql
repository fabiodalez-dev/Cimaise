-- Test album with complete metadata (SQLite)
INSERT OR IGNORE INTO albums (id, title, slug, category_id, excerpt, body, shoot_date, show_date, is_published, published_at, sort_order, template_id) VALUES
(100, 'Street Photography Portfolio', 'street-photography-portfolio', 1, 'A comprehensive street photography collection showcasing urban life and human stories captured in Milan, Rome, and Naples during spring 2024.', '<p>This portfolio represents three months of dedicated street photography work across Italy''s most vibrant urban centers. Each image tells a story of daily life, capturing candid moments that reveal the authentic character of Italian street culture.</p><p>The collection explores themes of human connection, architectural beauty, and the interplay between light and shadow in urban environments. Shot entirely on film to maintain the authentic, timeless quality that digital cannot replicate.</p><p>Technical approach focused on available light photography, minimal equipment, and patient observation to capture genuine moments without intrusion.</p>', '2024-05-15', 1, 1, '2024-06-01 10:00:00', 1, 1);

-- Add tags
INSERT OR IGNORE INTO tags (name, slug) VALUES
('Street Photography', 'street-photography'),
('Urban Life', 'urban-life'),
('Film Photography', 'film-photography'),
('Italy', 'italy'),
('Documentary', 'documentary'),
('Black and White', 'black-white'),
('Human Stories', 'human-stories'),
('Architecture', 'architecture');

-- Link album to tags
INSERT OR IGNORE INTO album_tag (album_id, tag_id) VALUES
(100, (SELECT id FROM tags WHERE slug = 'street-photography')),
(100, (SELECT id FROM tags WHERE slug = 'urban-life')),
(100, (SELECT id FROM tags WHERE slug = 'film-photography')),
(100, (SELECT id FROM tags WHERE slug = 'italy')),
(100, (SELECT id FROM tags WHERE slug = 'documentary')),
(100, (SELECT id FROM tags WHERE slug = 'black-white')),
(100, (SELECT id FROM tags WHERE slug = 'human-stories'));

-- Link album to additional categories  
INSERT OR IGNORE INTO album_category (album_id, category_id) VALUES
(100, 1),
(100, 2);

-- Link equipment to album
INSERT OR IGNORE INTO album_camera (album_id, camera_id) VALUES
(100, 2), -- Canon AE-1
(100, 3), -- Leica M6
(100, 1); -- Nikon F3

INSERT OR IGNORE INTO album_lens (album_id, lens_id) VALUES
(100, 1), -- Nikon 50mm f/1.4
(100, 3), -- Leica 35mm f/2
(100, 2); -- Canon 85mm f/1.8

INSERT OR IGNORE INTO album_film (album_id, film_id) VALUES
(100, 1), -- Kodak Portra 400
(100, 2), -- Kodak Tri-X 400
(100, 4); -- Ilford HP5+

INSERT OR IGNORE INTO album_developer (album_id, developer_id) VALUES
(100, 1), -- Kodak D-76
(100, 2); -- Rodinal

INSERT OR IGNORE INTO album_lab (album_id, lab_id) VALUES
(100, 1), -- Carmencita Film Lab
(100, 3); -- Mori Film Lab

-- Test images with detailed metadata
INSERT OR IGNORE INTO images (id, album_id, original_path, filename, caption, alt_text, custom_camera, custom_lens, process, sort_order) VALUES
(201, 100, '/media/test/street-001.jpg', 'street-001.jpg', 'Morning commuter rushing through Milan Central Station', 'Silhouette of person walking through sunlit train station', 'Leica M6', 'Leica 35mm f/2', 'BW', 1),
(202, 100, '/media/test/street-002.jpg', 'street-002.jpg', 'Elderly man reading newspaper at outdoor café in Rome', 'Man in cap reading newspaper at small round table', 'Canon AE-1', 'Canon 85mm f/1.8', 'Color', 2),
(203, 100, '/media/test/street-003.jpg', 'street-003.jpg', 'Children playing football in narrow Naples alley', 'Three kids kicking ball between old buildings', 'Nikon F3', 'Nikon 50mm f/1.4', 'Color', 3),
(204, 100, '/media/test/street-004.jpg', 'street-004.jpg', 'Vendor arranging fresh vegetables at market stall', 'Hands organizing colorful produce display', 'Leica M6', 'Leica 35mm f/2', 'Color', 4),
(205, 100, '/media/test/street-005.jpg', 'street-005.jpg', 'Dramatic shadows on ancient Roman architecture', 'Stone columns creating geometric shadow patterns', 'Canon AE-1', 'Canon 85mm f/1.8', 'BW', 5),
(206, 100, '/media/test/street-006.jpg', 'street-006.jpg', 'Street musician performing for evening crowd', 'Guitarist with case open, people gathered around', 'Nikon F3', 'Nikon 50mm f/1.4', 'BW', 6);

-- Set cover image
UPDATE albums SET cover_image_id = 201 WHERE id = 100;