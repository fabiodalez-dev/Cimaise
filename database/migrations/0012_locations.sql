-- Locations table and relationships

CREATE TABLE IF NOT EXISTS locations (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) UNIQUE NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Add location_id to albums table
ALTER TABLE albums 
ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER category_id,
ADD FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL;

-- Add location_id to images table
ALTER TABLE images 
ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER lab_id,
ADD FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL;
