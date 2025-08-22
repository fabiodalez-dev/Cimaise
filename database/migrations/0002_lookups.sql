-- Lookup tables for photographic metadata

CREATE TABLE IF NOT EXISTS cameras (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  make VARCHAR(120) NOT NULL,
  model VARCHAR(160) NOT NULL,
  UNIQUE KEY uniq_make_model (make, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS lenses (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  brand VARCHAR(120) NOT NULL,
  model VARCHAR(160) NOT NULL,
  focal_min DECIMAL(6,2) NULL,
  focal_max DECIMAL(6,2) NULL,
  aperture_min DECIMAL(4,2) NULL,
  UNIQUE KEY uniq_brand_model (brand, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS films (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  brand VARCHAR(120) NOT NULL,
  name VARCHAR(160) NOT NULL,
  iso INT NULL,
  format ENUM('35mm','120','4x5','8x10','other') DEFAULT '35mm',
  type ENUM('color_negative','color_reversal','bw') NOT NULL,
  UNIQUE KEY uniq_brand_name_iso_format (brand, name, iso, format)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS developers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  process ENUM('C-41','E-6','BW','Hybrid','Other') DEFAULT 'BW',
  notes VARCHAR(255) NULL,
  UNIQUE KEY uniq_name_process (name, process)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS labs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  city VARCHAR(120) NULL,
  country VARCHAR(120) NULL,
  UNIQUE KEY uniq_name_city (name, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

