-- Demo lookup values
INSERT INTO cameras (make, model) VALUES
('Nikon','F3'),
('Leica','M6'),
('Canon','5D Mark IV')
ON DUPLICATE KEY UPDATE model=VALUES(model);

INSERT INTO lenses (brand, model, focal_min, focal_max, aperture_min) VALUES
('Nikon','50mm f/1.4',50,50,1.40),
('Leica','35mm f/2 ASPH',35,35,2.00),
('Canon','24-70mm f/2.8L',24,70,2.80)
ON DUPLICATE KEY UPDATE model=VALUES(model);

INSERT INTO films (brand, name, iso, format, type) VALUES
('Kodak','Portra 400',400,'120','color_negative'),
('Ilford','HP5+',400,'35mm','bw')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO developers (name, process, notes) VALUES
('Rodinal','BW','Standard 1+50'),
('Cinestill C-41','C-41','Color negative')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);

INSERT INTO labs (name, city, country) VALUES
('Carmencita Film Lab','Valencia','Spain'),
('Come And Print','Milano','Italia')
ON DUPLICATE KEY UPDATE city=VALUES(city), country=VALUES(country);

