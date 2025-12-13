-- Templates table and album->template association (MySQL)
CREATE TABLE IF NOT EXISTS templates (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  settings JSON NULL,
  libs JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_templates_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add template_id to albums (separate statements for error handling)
ALTER TABLE albums ADD COLUMN template_id BIGINT UNSIGNED NULL AFTER cover_image_id;
ALTER TABLE albums ADD INDEX idx_albums_template (template_id);
ALTER TABLE albums ADD CONSTRAINT fk_albums_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL;
