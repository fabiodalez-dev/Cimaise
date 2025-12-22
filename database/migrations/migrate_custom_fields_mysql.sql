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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_cft_system ON custom_field_types(is_system);

-- Custom field values (for select/multi_select types)
CREATE TABLE IF NOT EXISTS custom_field_values (
  id INT AUTO_INCREMENT PRIMARY KEY,
  field_type_id INT NOT NULL,
  value VARCHAR(255) NOT NULL,
  extra_data TEXT,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
  UNIQUE KEY unique_type_value (field_type_id, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_cfv_type ON custom_field_values(field_type_id);

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
  FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_icf_image ON image_custom_fields(image_id);
CREATE INDEX IF NOT EXISTS idx_icf_type ON image_custom_fields(field_type_id);

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
  FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_acf_album ON album_custom_fields(album_id);
CREATE INDEX IF NOT EXISTS idx_acf_type ON album_custom_fields(field_type_id);

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
  UNIQUE KEY unique_entity_extension (entity_type, entity_id, extension_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_meta_ext_entity ON metadata_extensions(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_meta_ext_plugin ON metadata_extensions(plugin_id);
