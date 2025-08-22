-- Templates table and album->template association (MySQL)
CREATE TABLE IF NOT EXISTS templates (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  settings JSON NULL,
  libs JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE albums
  ADD COLUMN IF NOT EXISTS template_id BIGINT UNSIGNED NULL AFTER cover_image_id,
  ADD INDEX idx_albums_template (template_id),
  ADD CONSTRAINT fk_albums_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL;

