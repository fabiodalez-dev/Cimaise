-- ============================================
-- Migration: Add Custom Fields System (MySQL)
-- Run this on existing installations
-- ============================================

-- Custom field types (both built-in references and user-defined)
CREATE TABLE IF NOT EXISTS custom_field_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  label VARCHAR(255) NOT NULL,
  icon VARCHAR(50) DEFAULT 'fa-tag',
  description TEXT,
  field_type ENUM('text', 'select', 'multi_select') DEFAULT 'select',
  is_system TINYINT(1) DEFAULT 0,
  show_in_lightbox TINYINT(1) DEFAULT 1,
  show_in_gallery TINYINT(1) DEFAULT 1,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cft_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom field values (for select/multi_select types)
CREATE TABLE IF NOT EXISTS custom_field_values (
  id INT AUTO_INCREMENT PRIMARY KEY,
  field_type_id INT NOT NULL,
  value VARCHAR(255) NOT NULL,
  extra_data TEXT,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
  UNIQUE KEY unique_type_value (field_type_id, value),
  INDEX idx_cfv_type (field_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Image custom fields (junction table)
CREATE TABLE IF NOT EXISTS image_custom_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  image_id INT NOT NULL,
  field_type_id INT NOT NULL,
  field_value_id INT,
  custom_value TEXT,
  is_override TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
  FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
  FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL,
  INDEX idx_icf_image (image_id),
  INDEX idx_icf_type (field_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Album custom fields (junction table, supports multiple values)
CREATE TABLE IF NOT EXISTS album_custom_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  album_id INT NOT NULL,
  field_type_id INT NOT NULL,
  field_value_id INT,
  custom_value TEXT,
  auto_added TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
  FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL,
  INDEX idx_acf_album (album_id),
  INDEX idx_acf_type (field_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metadata extensions (plugin data for built-in metadata)
CREATE TABLE IF NOT EXISTS metadata_extensions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT NOT NULL,
  extension_key VARCHAR(100) NOT NULL,
  extension_value TEXT,
  plugin_id VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_entity_extension (entity_type, entity_id, extension_key),
  INDEX idx_meta_ext_entity (entity_type, entity_id),
  INDEX idx_meta_ext_plugin (plugin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
