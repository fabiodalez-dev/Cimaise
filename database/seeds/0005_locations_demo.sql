-- Demo locations (MySQL)
INSERT INTO locations (name, slug, description) VALUES
('Roma', 'roma', 'Roma, Italia'),
('Milano', 'milano', 'Milano, Italia'),
('Londra', 'londra', 'London, UK'),
('Parigi', 'parigi', 'Paris, France')
ON DUPLICATE KEY UPDATE name = VALUES(name);

