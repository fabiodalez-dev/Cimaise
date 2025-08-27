-- Essential default settings for MySQL
INSERT INTO settings (`key`, `value`, type, created_at, updated_at) VALUES
('image.formats', '{"avif":true,"webp":true,"jpg":true}', 'string', NOW(), NOW()),
('image.quality', '{"avif":50,"webp":75,"jpg":85}', 'string', NOW(), NOW()),
('image.preview', '{"width":480,"height":null}', 'string', NOW(), NOW()),
('image.breakpoints', '{"sm":768,"md":1200,"lg":1920,"xl":2560,"xxl":3840}', 'string', NOW(), NOW()),
('gallery.default_template', 'grid-classica', 'string', NOW(), NOW()),
('gallery.images_per_page', '24', 'number', NOW(), NOW()),
('site.maintenance_mode', 'false', 'boolean', NOW(), NOW()),
('site.allow_registration', 'false', 'boolean', NOW(), NOW()),
('upload.max_file_size', '10485760', 'number', NOW(), NOW()),
('upload.allowed_types', '["jpg","jpeg","png","gif","webp","avif"]', 'string', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  updated_at = NOW();