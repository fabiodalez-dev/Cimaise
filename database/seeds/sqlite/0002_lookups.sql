-- Lookup seed data (SQLite version)

-- Cameras
INSERT OR IGNORE INTO cameras (make, model) VALUES
('Nikon', 'F3'),
('Canon', 'AE-1'),
('Leica', 'M6'),
('Pentax', 'K1000'),
('Olympus', 'OM-1'),
('Canon', '5D Mark IV'),
('Nikon', 'D850'),
('Sony', 'A7R IV');

-- Lenses  
INSERT OR IGNORE INTO lenses (brand, model, focal_min, focal_max, aperture_min) VALUES
('Nikon', '50mm f/1.4', 50, 50, 1.4),
('Canon', '85mm f/1.8', 85, 85, 1.8),
('Leica', '35mm f/2', 35, 35, 2.0),
('Pentax', '28mm f/2.8', 28, 28, 2.8),
('Canon', '24-70mm f/2.8', 24, 70, 2.8),
('Sony', '55mm f/1.8', 55, 55, 1.8);

-- Films
INSERT OR IGNORE INTO films (brand, name, iso, format, type) VALUES
('Kodak', 'Portra 400', 400, '35mm', 'color_negative'),
('Kodak', 'Tri-X 400', 400, '35mm', 'bw'),
('Fuji', 'Pro 400H', 400, '120', 'color_negative'),
('Ilford', 'HP5+', 400, '35mm', 'bw'),
('Kodak', 'Ektar 100', 100, '35mm', 'color_negative'),
('Fuji', 'Velvia 50', 50, '35mm', 'color_reversal');

-- Developers
INSERT OR IGNORE INTO developers (name, process, notes) VALUES
('Kodak D-76', 'BW', 'Standard black and white developer'),
('Rodinal', 'BW', 'High acutance developer'),
('Cinestill C-41', 'C-41', 'Color negative processing'),
('Kodak E-6', 'E-6', 'Color reversal processing'),
('HC-110', 'BW', 'Economical liquid concentrate');

-- Labs
INSERT OR IGNORE INTO labs (name, city, country) VALUES  
('Carmencita Film Lab', 'Valencia', 'Spain'),
('The FPPF', 'Englewood', 'USA'),
('Mori Film Lab', 'Tokyo', 'Japan'),
('Lab Central', 'London', 'UK'),
('Processo Colore', 'Milano', 'Italy');