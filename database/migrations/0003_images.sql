-- Images and image variants tables

CREATE TABLE IF NOT EXISTS images (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  album_id BIGINT UNSIGNED NOT NULL,

  original_path VARCHAR(255) NOT NULL,
  file_hash CHAR(40) NOT NULL,
  width INT NOT NULL,
  height INT NOT NULL,
  mime VARCHAR(60) NOT NULL,

  alt_text VARCHAR(200) NULL,
  caption VARCHAR(300) NULL,

  exif JSON NULL,

  camera_id BIGINT UNSIGNED NULL,
  lens_id BIGINT UNSIGNED NULL,
  film_id BIGINT UNSIGNED NULL,
  developer_id BIGINT UNSIGNED NULL,
  lab_id BIGINT UNSIGNED NULL,

  custom_camera VARCHAR(160) NULL,
  custom_lens VARCHAR(160) NULL,
  custom_film VARCHAR(160) NULL,
  custom_development VARCHAR(160) NULL,
  custom_lab VARCHAR(160) NULL,
  custom_scanner VARCHAR(160) NULL,
  scan_resolution_dpi INT NULL,
  scan_bit_depth INT NULL,
  process ENUM('digital','analog','hybrid') DEFAULT 'digital',
  development_date DATE NULL,

  iso INT NULL,
  shutter_speed VARCHAR(40) NULL,
  aperture DECIMAL(4,2) NULL,

  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE SET NULL,
  FOREIGN KEY (lens_id) REFERENCES lenses(id) ON DELETE SET NULL,
  FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE SET NULL,
  FOREIGN KEY (developer_id) REFERENCES developers(id) ON DELETE SET NULL,
  FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE SET NULL,

  INDEX (album_id), INDEX (sort_order), INDEX (file_hash),
  INDEX (camera_id), INDEX (lens_id), INDEX (film_id),
  INDEX (developer_id), INDEX (lab_id), INDEX (process), INDEX (iso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS image_variants (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  image_id BIGINT UNSIGNED NOT NULL,
  variant VARCHAR(50) NOT NULL,
  format ENUM('avif','webp','jpg') NOT NULL,
  path VARCHAR(255) NOT NULL,
  width INT NOT NULL,
  height INT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  UNIQUE KEY uniq_image_variant_format (image_id, variant, format),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

