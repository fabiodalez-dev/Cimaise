-- Default social sharing settings (MySQL compatible)
INSERT IGNORE INTO settings (`key`,`value`,`type`) VALUES
('social.enabled', '["behance","whatsapp","facebook","x","deviantart","instagram","pinterest","telegram","threads","bluesky"]', 'string'),
('social.order',   '["behance","whatsapp","facebook","x","deviantart","instagram","pinterest","telegram","threads","bluesky"]', 'string');

