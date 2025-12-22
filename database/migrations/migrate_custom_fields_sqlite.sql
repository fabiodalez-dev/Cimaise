-- ============================================
-- Migration: Add Custom Fields System (SQLite)
-- Run this on existing installations
-- ============================================

-- Custom field types (both built-in references and user-defined)
CREATE TABLE IF NOT EXISTS custom_field_types (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  icon TEXT DEFAULT 'fa-tag',
  description TEXT,
  field_type TEXT DEFAULT 'select' CHECK(field_type IN ('text', 'select', 'multi_select')),
  is_system INTEGER DEFAULT 0,
  show_in_lightbox INTEGER DEFAULT 1,
  show_in_gallery INTEGER DEFAULT 1,
  sort_order INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cft_system ON custom_field_types(is_system);

-- Custom field values (for select/multi_select types)
CREATE TABLE IF NOT EXISTS custom_field_values (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  field_type_id INTEGER NOT NULL,
  value TEXT NOT NULL,
  extra_data TEXT,
  sort_order INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
  UNIQUE(field_type_id, value)
);

CREATE INDEX IF NOT EXISTS idx_cfv_type ON custom_field_values(field_type_id);

-- Image custom fields (junction table)
CREATE TABLE IF NOT EXISTS image_custom_fields (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  image_id INTEGER NOT NULL,
  field_type_id INTEGER NOT NULL,
  field_value_id INTEGER,
  custom_value TEXT,
  is_override INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
  FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
  FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_icf_image ON image_custom_fields(image_id);
CREATE INDEX IF NOT EXISTS idx_icf_type ON image_custom_fields(field_type_id);

-- Album custom fields (junction table, supports multiple values)
CREATE TABLE IF NOT EXISTS album_custom_fields (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  album_id INTEGER NOT NULL,
  field_type_id INTEGER NOT NULL,
  field_value_id INTEGER,
  custom_value TEXT,
  auto_added INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
  FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_acf_album ON album_custom_fields(album_id);
CREATE INDEX IF NOT EXISTS idx_acf_type ON album_custom_fields(field_type_id);

-- Metadata extensions (plugin data for built-in metadata)
CREATE TABLE IF NOT EXISTS metadata_extensions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL,
  entity_id INTEGER NOT NULL,
  extension_key TEXT NOT NULL,
  extension_value TEXT,
  plugin_id TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(entity_type, entity_id, extension_key)
);

CREATE INDEX IF NOT EXISTS idx_meta_ext_entity ON metadata_extensions(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_meta_ext_plugin ON metadata_extensions(plugin_id);
