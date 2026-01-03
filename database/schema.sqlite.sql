-- ============================================
-- Cimaise - Complete SQLite Schema
-- Template database for clean installations
-- ============================================

PRAGMA foreign_keys = ON;

-- ============================================
-- CORE TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT DEFAULT 'admin' CHECK(role IN ('admin', 'user')),
  first_name TEXT,
  last_name TEXT,
  is_active INTEGER DEFAULT 1,
  last_login TEXT,
  remember_token TEXT,
  remember_token_expires_at TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_users_remember_token ON users(remember_token);

CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  sort_order INTEGER DEFAULT 0,
  parent_id INTEGER,
  image_path TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_categories_slug ON categories(slug);
CREATE INDEX IF NOT EXISTS idx_categories_sort ON categories(sort_order);
CREATE INDEX IF NOT EXISTS idx_categories_parent ON categories(parent_id);
CREATE INDEX IF NOT EXISTS idx_categories_image_path ON categories(image_path);

CREATE TABLE IF NOT EXISTS tags (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tags_slug ON tags(slug);

CREATE TABLE IF NOT EXISTS locations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_locations_slug ON locations(slug);

CREATE TABLE IF NOT EXISTS templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT,
  settings TEXT,
  libs TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_templates_slug ON templates(slug);

-- ============================================
-- CUSTOM TEMPLATES (Plugin)
-- ============================================

CREATE TABLE IF NOT EXISTS custom_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL CHECK(type IN ('gallery', 'album_page', 'homepage')),
  name TEXT NOT NULL,
  slug TEXT UNIQUE NOT NULL,
  description TEXT,
  version TEXT NOT NULL,
  author TEXT,
  metadata TEXT,
  twig_path TEXT NOT NULL,
  css_paths TEXT,
  js_paths TEXT,
  preview_path TEXT,
  is_active INTEGER DEFAULT 1 CHECK(is_active IN (0, 1)),
  installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_custom_templates_type ON custom_templates(type);
CREATE INDEX IF NOT EXISTS idx_custom_templates_slug ON custom_templates(slug);
CREATE INDEX IF NOT EXISTS idx_custom_templates_active ON custom_templates(is_active);

-- ============================================
-- EQUIPMENT LOOKUP TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS cameras (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  make TEXT NOT NULL,
  model TEXT NOT NULL,
  type TEXT DEFAULT NULL,
  UNIQUE(make, model)
);

CREATE TABLE IF NOT EXISTS lenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  brand TEXT NOT NULL,
  model TEXT NOT NULL,
  focal_min REAL,
  focal_max REAL,
  aperture_min REAL,
  UNIQUE(brand, model)
);

CREATE TABLE IF NOT EXISTS films (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  brand TEXT NOT NULL,
  name TEXT NOT NULL,
  iso INTEGER,
  format TEXT DEFAULT '35mm',
  type TEXT NOT NULL DEFAULT 'color_negative',
  UNIQUE(brand, name, iso, format)
);

CREATE TABLE IF NOT EXISTS developers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  process TEXT DEFAULT 'BW' CHECK(process IN ('C-41', 'E-6', 'BW', 'Hybrid', 'Other')),
  notes TEXT,
  UNIQUE(name, process)
);

CREATE TABLE IF NOT EXISTS labs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  city TEXT,
  country TEXT,
  UNIQUE(name, city)
);

-- ============================================
-- ALBUMS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS albums (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  category_id INTEGER NOT NULL,
  location_id INTEGER,
  template_id INTEGER,
  custom_template_id INTEGER,
  album_page_template TEXT,
  excerpt TEXT,
  body TEXT,
  cover_image_id INTEGER,
  shoot_date TEXT,
  show_date INTEGER NOT NULL DEFAULT 1,
  published_at TEXT,
  is_published INTEGER DEFAULT 0,
  sort_order INTEGER DEFAULT 0,
  password_hash TEXT,
  allow_downloads INTEGER NOT NULL DEFAULT 0,
  custom_cameras TEXT,
  custom_lenses TEXT,
  custom_films TEXT,
  custom_developers TEXT,
  custom_labs TEXT,
  seo_title TEXT,
  seo_description TEXT,
  seo_keywords TEXT,
  og_title TEXT,
  og_description TEXT,
  og_image_path TEXT,
  schema_type TEXT DEFAULT 'ImageGallery',
  schema_data TEXT,
  canonical_url TEXT,
  robots_index INTEGER DEFAULT 1,
  robots_follow INTEGER DEFAULT 1,
  is_nsfw INTEGER NOT NULL DEFAULT 0,
  allow_template_switch INTEGER NOT NULL DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL,
  FOREIGN KEY (custom_template_id) REFERENCES custom_templates(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_albums_slug ON albums(slug);
CREATE INDEX IF NOT EXISTS idx_albums_category ON albums(category_id);
CREATE INDEX IF NOT EXISTS idx_albums_location ON albums(location_id);
CREATE INDEX IF NOT EXISTS idx_albums_template ON albums(template_id);
CREATE INDEX IF NOT EXISTS idx_albums_custom_template ON albums(custom_template_id);
CREATE INDEX IF NOT EXISTS idx_albums_published ON albums(is_published);
CREATE INDEX IF NOT EXISTS idx_albums_published_at ON albums(published_at);
CREATE INDEX IF NOT EXISTS idx_albums_sort ON albums(sort_order);
CREATE INDEX IF NOT EXISTS idx_albums_seo_title ON albums(seo_title);
CREATE INDEX IF NOT EXISTS idx_albums_robots ON albums(robots_index, robots_follow);
CREATE INDEX IF NOT EXISTS idx_albums_published_date ON albums(is_published, published_at);
CREATE INDEX IF NOT EXISTS idx_albums_published_shoot ON albums(is_published, shoot_date);
CREATE INDEX IF NOT EXISTS idx_albums_published_nsfw ON albums(is_published, is_nsfw);

-- ============================================
-- IMAGES TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS images (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  album_id INTEGER NOT NULL,
  original_path TEXT NOT NULL,
  file_hash TEXT NOT NULL,
  width INTEGER NOT NULL,
  height INTEGER NOT NULL,
  mime TEXT NOT NULL,
  alt_text TEXT,
  caption TEXT,
  exif TEXT,
  camera_id INTEGER,
  lens_id INTEGER,
  film_id INTEGER,
  developer_id INTEGER,
  lab_id INTEGER,
  location_id INTEGER,
  custom_camera TEXT,
  custom_lens TEXT,
  custom_film TEXT,
  custom_development TEXT,
  custom_lab TEXT,
  custom_scanner TEXT,
  scan_resolution_dpi INTEGER,
  scan_bit_depth INTEGER,
  process TEXT DEFAULT 'digital' CHECK(process IN ('digital', 'analog', 'hybrid')),
  development_date TEXT,
  iso INTEGER,
  shutter_speed TEXT,
  aperture REAL,
  -- Extended EXIF fields
  focal_length REAL DEFAULT NULL,
  exposure_bias REAL DEFAULT NULL,
  flash INTEGER DEFAULT NULL,
  white_balance INTEGER DEFAULT NULL,
  exposure_program INTEGER DEFAULT NULL,
  metering_mode INTEGER DEFAULT NULL,
  exposure_mode INTEGER DEFAULT NULL,
  date_original TEXT DEFAULT NULL,
  color_space INTEGER DEFAULT NULL,
  contrast INTEGER DEFAULT NULL,
  saturation INTEGER DEFAULT NULL,
  sharpness INTEGER DEFAULT NULL,
  scene_capture_type INTEGER DEFAULT NULL,
  light_source INTEGER DEFAULT NULL,
  gps_lat REAL DEFAULT NULL,
  gps_lng REAL DEFAULT NULL,
  artist TEXT DEFAULT NULL,
  copyright TEXT DEFAULT NULL,
  exif_make TEXT DEFAULT NULL,
  exif_model TEXT DEFAULT NULL,
  exif_lens_maker TEXT DEFAULT NULL,
  exif_lens_model TEXT DEFAULT NULL,
  software TEXT DEFAULT NULL,
  exif_extended TEXT DEFAULT NULL,
  sort_order INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE SET NULL,
  FOREIGN KEY (lens_id) REFERENCES lenses(id) ON DELETE SET NULL,
  FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE SET NULL,
  FOREIGN KEY (developer_id) REFERENCES developers(id) ON DELETE SET NULL,
  FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE SET NULL,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_images_album ON images(album_id);
CREATE INDEX IF NOT EXISTS idx_images_sort ON images(sort_order);
CREATE INDEX IF NOT EXISTS idx_images_hash ON images(file_hash);
CREATE INDEX IF NOT EXISTS idx_images_camera ON images(camera_id);
CREATE INDEX IF NOT EXISTS idx_images_lens ON images(lens_id);
CREATE INDEX IF NOT EXISTS idx_images_film ON images(film_id);
CREATE INDEX IF NOT EXISTS idx_images_developer ON images(developer_id);
CREATE INDEX IF NOT EXISTS idx_images_lab ON images(lab_id);
CREATE INDEX IF NOT EXISTS idx_images_location ON images(location_id);
CREATE INDEX IF NOT EXISTS idx_images_process ON images(process);
CREATE INDEX IF NOT EXISTS idx_images_iso ON images(iso);
CREATE INDEX IF NOT EXISTS idx_images_album_sort ON images(album_id, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_images_date_original ON images(date_original);
CREATE INDEX IF NOT EXISTS idx_images_gps ON images(gps_lat, gps_lng);
CREATE INDEX IF NOT EXISTS idx_images_artist ON images(artist);

CREATE TABLE IF NOT EXISTS image_variants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  image_id INTEGER NOT NULL,
  variant TEXT NOT NULL,
  format TEXT NOT NULL CHECK(format IN ('avif', 'webp', 'jpg')),
  path TEXT NOT NULL,
  width INTEGER NOT NULL,
  height INTEGER NOT NULL,
  size_bytes INTEGER NOT NULL,
  UNIQUE(image_id, variant, format),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
);

-- ============================================
-- JUNCTION TABLES (Many-to-Many)
-- ============================================

CREATE TABLE IF NOT EXISTS album_tag (
  album_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, tag_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_tag_tag ON album_tag(tag_id);

CREATE TABLE IF NOT EXISTS album_category (
  album_id INTEGER NOT NULL,
  category_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, category_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_category_category ON album_category(category_id);

CREATE TABLE IF NOT EXISTS album_camera (
  album_id INTEGER NOT NULL,
  camera_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, camera_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_camera_camera ON album_camera(camera_id);

CREATE TABLE IF NOT EXISTS album_lens (
  album_id INTEGER NOT NULL,
  lens_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, lens_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (lens_id) REFERENCES lenses(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_lens_lens ON album_lens(lens_id);

CREATE TABLE IF NOT EXISTS album_film (
  album_id INTEGER NOT NULL,
  film_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, film_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_film_film ON album_film(film_id);

CREATE TABLE IF NOT EXISTS album_developer (
  album_id INTEGER NOT NULL,
  developer_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, developer_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (developer_id) REFERENCES developers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_developer_developer ON album_developer(developer_id);

CREATE TABLE IF NOT EXISTS album_lab (
  album_id INTEGER NOT NULL,
  lab_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, lab_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_lab_lab ON album_lab(lab_id);

CREATE TABLE IF NOT EXISTS album_location (
  album_id INTEGER NOT NULL,
  location_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, location_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_album_location_location ON album_location(location_id);

CREATE TABLE IF NOT EXISTS image_location (
  image_id INTEGER NOT NULL,
  location_id INTEGER NOT NULL,
  PRIMARY KEY (image_id, location_id),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_image_location_location ON image_location(location_id);

-- ============================================
-- SETTINGS TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key TEXT NOT NULL UNIQUE,
  value TEXT,
  type TEXT DEFAULT 'string',
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_settings_key ON settings(key);

CREATE TABLE IF NOT EXISTS filter_settings (
  setting_key TEXT PRIMARY KEY NOT NULL,
  setting_value TEXT NOT NULL,
  description TEXT,
  sort_order INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- ANALYTICS TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS analytics_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL UNIQUE,
  ip_hash TEXT NOT NULL,
  user_agent TEXT,
  browser TEXT,
  browser_version TEXT,
  platform TEXT,
  device_type TEXT,
  screen_resolution TEXT,
  country_code TEXT,
  region TEXT,
  city TEXT,
  referrer_domain TEXT,
  referrer_url TEXT,
  landing_page TEXT,
  started_at TEXT DEFAULT CURRENT_TIMESTAMP,
  last_activity TEXT DEFAULT CURRENT_TIMESTAMP,
  page_views INTEGER DEFAULT 0,
  duration INTEGER DEFAULT 0,
  is_bot INTEGER DEFAULT 0
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_analytics_sessions_session_id ON analytics_sessions(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_started_at ON analytics_sessions(started_at);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_country ON analytics_sessions(country_code);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_device ON analytics_sessions(device_type);

CREATE TABLE IF NOT EXISTS analytics_pageviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL,
  page_url TEXT NOT NULL,
  page_title TEXT,
  page_type TEXT,
  album_id INTEGER,
  category_id INTEGER,
  tag_id INTEGER,
  load_time INTEGER,
  viewport_width INTEGER,
  viewport_height INTEGER,
  scroll_depth INTEGER DEFAULT 0,
  time_on_page INTEGER DEFAULT 0,
  viewed_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_session_id ON analytics_pageviews(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_viewed_at ON analytics_pageviews(viewed_at);
CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_page_type ON analytics_pageviews(page_type);
CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_album_id ON analytics_pageviews(album_id);

CREATE TABLE IF NOT EXISTS analytics_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL,
  event_type TEXT NOT NULL,
  event_category TEXT,
  event_action TEXT,
  event_label TEXT,
  event_value INTEGER,
  page_url TEXT,
  album_id INTEGER,
  image_id INTEGER,
  occurred_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_analytics_events_session_id ON analytics_events(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_events_type ON analytics_events(event_type);
CREATE INDEX IF NOT EXISTS idx_analytics_events_occurred_at ON analytics_events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_analytics_events_album_id ON analytics_events(album_id);

CREATE TABLE IF NOT EXISTS analytics_daily_summary (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT NOT NULL UNIQUE,
  total_sessions INTEGER DEFAULT 0,
  total_pageviews INTEGER DEFAULT 0,
  unique_visitors INTEGER DEFAULT 0,
  bounce_rate REAL DEFAULT 0.00,
  avg_session_duration INTEGER DEFAULT 0,
  top_pages TEXT,
  top_countries TEXT,
  top_browsers TEXT,
  top_albums TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_analytics_daily_summary_date ON analytics_daily_summary(date);

CREATE TABLE IF NOT EXISTS analytics_settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  setting_key TEXT NOT NULL UNIQUE,
  setting_value TEXT,
  description TEXT,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_analytics_settings_key ON analytics_settings(setting_key);

-- ============================================
-- FRONTEND TEXTS TABLE (Translation System)
-- ============================================

CREATE TABLE IF NOT EXISTS frontend_texts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  text_key TEXT NOT NULL UNIQUE,
  text_value TEXT NOT NULL,
  context TEXT DEFAULT 'general',
  description TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_frontend_texts_key ON frontend_texts(text_key);
CREATE INDEX IF NOT EXISTS idx_frontend_texts_context ON frontend_texts(context);

-- ============================================
-- PLUGIN STATUS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS plugin_status (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  version TEXT NOT NULL,
  description TEXT,
  author TEXT,
  path TEXT NOT NULL,
  is_active INTEGER DEFAULT 1,
  is_installed INTEGER DEFAULT 1,
  installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Note: slug already has UNIQUE constraint which creates an implicit index
CREATE INDEX IF NOT EXISTS idx_plugin_status_active ON plugin_status(is_active);

-- ============================================
-- PLUGIN TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS plugin_analytics_custom_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT,
  event_type TEXT NOT NULL,
  event_category TEXT,
  event_action TEXT,
  event_label TEXT,
  event_value INTEGER,
  user_id INTEGER,
  metadata TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_plugin_analytics_session ON plugin_analytics_custom_events(session_id);
CREATE INDEX IF NOT EXISTS idx_plugin_analytics_type ON plugin_analytics_custom_events(event_type);
CREATE INDEX IF NOT EXISTS idx_plugin_analytics_user ON plugin_analytics_custom_events(user_id);

CREATE TABLE IF NOT EXISTS plugin_image_ratings (
  image_id INTEGER PRIMARY KEY,
  rating INTEGER NOT NULL CHECK(rating >= 0 AND rating <= 5),
  rated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  rated_by INTEGER NULL,
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
  FOREIGN KEY (rated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_plugin_image_ratings_rated_by ON plugin_image_ratings(rated_by);

-- ============================================
-- ANALYTICS PRO TABLES (Plugin)
-- ============================================

CREATE TABLE IF NOT EXISTS analytics_pro_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL UNIQUE,
  user_id INTEGER,
  ip_address TEXT,
  user_agent TEXT,
  device_type TEXT,
  browser TEXT,
  country TEXT,
  started_at TEXT DEFAULT CURRENT_TIMESTAMP,
  last_activity TEXT DEFAULT CURRENT_TIMESTAMP,
  ended_at TEXT,
  duration INTEGER DEFAULT 0,
  pageviews INTEGER DEFAULT 0,
  events_count INTEGER DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_analytics_pro_sessions_user_id ON analytics_pro_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_sessions_started_at ON analytics_pro_sessions(started_at);

CREATE TABLE IF NOT EXISTS analytics_pro_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_name TEXT NOT NULL,
  category TEXT,
  action TEXT,
  label TEXT,
  value INTEGER,
  user_id INTEGER,
  session_id TEXT,
  ip_address TEXT,
  user_agent TEXT,
  referrer TEXT,
  device_type TEXT,
  browser TEXT,
  country TEXT,
  metadata TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (session_id) REFERENCES analytics_pro_sessions(session_id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_analytics_pro_event_name ON analytics_pro_events(event_name);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_category ON analytics_pro_events(category);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_created_at ON analytics_pro_events(created_at);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_user_id ON analytics_pro_events(user_id);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_session_id ON analytics_pro_events(session_id);

CREATE TABLE IF NOT EXISTS analytics_pro_funnels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT,
  steps TEXT NOT NULL,
  is_active INTEGER DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS analytics_pro_dimensions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  dimension_name TEXT NOT NULL,
  dimension_value TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES analytics_pro_events(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_analytics_pro_dimensions_event_id ON analytics_pro_dimensions(event_id);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_dimensions_name ON analytics_pro_dimensions(dimension_name);

-- ============================================
-- LOGS TABLE (Structured Logging System)
-- ============================================

CREATE TABLE IF NOT EXISTS logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  level INTEGER NOT NULL,
  level_name TEXT NOT NULL,
  category TEXT DEFAULT 'app',
  message TEXT NOT NULL,
  context TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level);
CREATE INDEX IF NOT EXISTS idx_logs_category ON logs(category);
CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at);

-- ============================================
-- CUSTOM FIELDS SYSTEM
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
CREATE INDEX IF NOT EXISTS idx_icf_image_type ON image_custom_fields(image_id, field_type_id);

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

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Default category
INSERT INTO categories (name, slug, sort_order) VALUES ('Photo', 'photo', 1);

-- Default templates (IDs 1-7)
INSERT INTO templates (id, name, slug, description, settings, libs) VALUES
(1, 'Grid Classica', 'grid-classica', 'Layout a griglia responsivo - desktop 3 colonne, tablet 2, mobile 1', '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"gap":{"horizontal":16,"vertical":16},"aspect_ratio":"1:1","style":{"rounded":true,"shadow":true,"hover_scale":true},"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.12,"allowPanToNext":false}}', '["photoswipe"]'),
(2, 'Masonry Portfolio', 'masonry-portfolio', 'Layout masonry responsivo per portfolio - desktop 4 colonne, tablet 3, mobile 1', '{"layout":"masonry","columns":{"desktop":4,"tablet":3,"mobile":1},"gap":{"horizontal":16,"vertical":16},"aspect_ratio":"1:1","style":{"rounded":true,"shadow":false,"hover_scale":false,"hover_fade":false},"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.1,"allowPanToNext":true},"masonry_portfolio":{"columns":{"desktop":4,"tablet":3,"mobile":1},"gap_h":16,"gap_v":16,"layout_mode":"fullwidth","type":"balanced"}}', '["photoswipe"]'),
(3, 'Magazine Split', 'magazine-split', 'Galleria a colonne con scorrimento infinito/masonry in stile magazine', '{"layout":"magazine","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":true,"magazine":{"durations":[60,72,84],"gap":20},"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.10,"allowPanToNext":true}}', '["photoswipe"]'),
(4, 'Masonry Full', 'masonry-full', 'Layout masonry a immagini intere con CSS columns - desktop 4 colonne, tablet 2, mobile 1', '{"layout":"masonry","columns":{"desktop":4,"tablet":2,"mobile":1},"gap":{"horizontal":0,"vertical":0},"aspect_ratio":"1:1","style":{"rounded":false,"shadow":false,"hover_scale":false,"hover_fade":true},"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.1,"allowPanToNext":true},"masonry":{"type":"balanced"}}', '["photoswipe","masonry-grid"]'),
(5, 'Grid Compatta', 'grid-compatta', 'Layout compatto con molte colonne - desktop 3 colonne, tablet 2, mobile 2', '{"layout":"dense_grid","columns":{"desktop":3,"tablet":2,"mobile":2},"gap":{"horizontal":10,"vertical":10},"style":{"rounded":true,"shadow":false,"hover_scale":true},"dense_grid":{"minCellDesktop":250,"rowHeight":200,"gap":10},"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.12,"allowPanToNext":true}}', '["photoswipe"]'),
(6, 'Grid Ampia', 'grid-ampia', 'Layout Dense Grid con CSS Grid auto-flow dense - celle adattive in base all''aspect ratio', '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"gap":{"horizontal":16,"vertical":16},"aspect_ratio":"1:1","style":{"rounded":false,"shadow":false,"hover_scale":true,"hover_fade":true},"creative_layout":{"gap":15,"hover_tooltip":true},"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.15,"allowPanToNext":true}}', '["photoswipe"]'),
(7, 'Gallery Wall Scroll', 'gallery-wall-scroll', 'Galleria orizzontale a scorrimento con parete immagini e lightbox', '{"layout":"gallery_wall","columns":{"desktop":3,"tablet":2,"mobile":1},"gallery_wall":{"desktop":{"horizontal_ratio":1.5,"vertical_ratio":0.67},"tablet":{"horizontal_ratio":1.3,"vertical_ratio":0.6},"divider":2,"mobile":{"columns":2,"gap":8,"wide_every":5}},"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.12,"allowPanToNext":true}}', '["photoswipe"]');

-- Default settings
INSERT INTO settings (key, value, type) VALUES
('image.formats', '{"avif":true,"webp":true,"jpg":true}', 'string'),
('image.quality', '{"avif":50,"webp":75,"jpg":85}', 'string'),
('image.preview', '{"width":480,"height":null}', 'string'),
('image.breakpoints', '{"sm":768,"md":1200,"lg":1920,"xl":2560,"xxl":3840}', 'string'),
('gallery.default_template_id', '4', 'number'),
('performance.compression', 'true', 'boolean'),
('pagination.limit', '12', 'number'),
('cache.ttl', '24', 'number'),
('site.logo', 'null', 'null'),
('site.logo_type', 'text', 'string'),
('site.favicon_source', 'null', 'null'),
('social.enabled', '["bluesky","facebook","pinterest","telegram","threads","whatsapp","x"]', 'string'),
('social.order', '["bluesky","facebook","pinterest","telegram","threads","whatsapp","x"]', 'string'),
('seo.site_title', 'Photography Portfolio', 'string'),
('seo.site_description', 'Professional photography portfolio showcasing creative work and artistic vision', 'string'),
('seo.site_keywords', 'photography, portfolio, professional photographer, creative photography', 'string'),
('seo.author_name', '', 'string'),
('seo.author_url', '', 'string'),
('seo.organization_name', '', 'string'),
('seo.organization_url', '', 'string'),
('seo.og_site_name', 'Photography Portfolio', 'string'),
('seo.og_type', 'website', 'string'),
('seo.og_locale', 'en_US', 'string'),
('seo.twitter_card', 'summary_large_image', 'string'),
('seo.twitter_site', '', 'string'),
('seo.twitter_creator', '', 'string'),
('seo.schema_enabled', 'true', 'boolean'),
('seo.breadcrumbs_enabled', 'true', 'boolean'),
('seo.local_business_enabled', 'false', 'boolean'),
('seo.local_business_name', '', 'string'),
('seo.local_business_type', 'ProfessionalService', 'string'),
('seo.local_business_address', '', 'string'),
('seo.local_business_city', '', 'string'),
('seo.local_business_postal_code', '', 'string'),
('seo.local_business_country', '', 'string'),
('seo.local_business_phone', '', 'string'),
('seo.local_business_geo_lat', '', 'string'),
('seo.local_business_geo_lng', '', 'string'),
('seo.local_business_opening_hours', '', 'string'),
('seo.photographer_job_title', 'Professional Photographer', 'string'),
('seo.photographer_services', 'Professional Photography Services', 'string'),
('seo.photographer_area_served', '', 'string'),
('seo.photographer_same_as', '', 'string'),
('seo.robots_default', 'index,follow', 'string'),
('seo.canonical_base_url', '', 'string'),
('seo.sitemap_enabled', 'true', 'boolean'),
('seo.analytics_gtag', '', 'string'),
('seo.analytics_gtm', '', 'string'),
('seo.image_alt_auto', 'true', 'boolean'),
('seo.image_copyright_notice', '', 'string'),
('seo.image_license_url', '', 'string'),
('seo.image_acquire_license_page', '', 'string'),
('seo.preload_critical_images', 'true', 'boolean'),
('seo.lazy_load_images', 'true', 'boolean'),
('seo.structured_data_format', 'json-ld', 'string'),
('lightbox.show_exif', 'true', 'boolean'),
('maintenance.enabled', 'false', 'boolean'),
('maintenance.title', '', 'string'),
('maintenance.message', '', 'string'),
('maintenance.show_logo', 'true', 'boolean'),
('maintenance.show_countdown', 'true', 'boolean'),
('recaptcha.enabled', 'false', 'boolean'),
('recaptcha.site_key', '', 'string'),
('recaptcha.secret_key', '', 'string'),
-- Frontend settings
('frontend.dark_mode', 'false', 'boolean'),
('frontend.custom_css', '', 'string');

-- Default filter settings
INSERT INTO filter_settings (setting_key, setting_value, description, sort_order) VALUES
('enabled', '1', 'Enable/disable filter functionality', 1),
('show_categories', '1', 'Show categories filter', 2),
('show_tags', '1', 'Show tags filter', 3),
('show_cameras', '1', 'Show cameras filter', 4),
('show_lenses', '1', 'Show lenses filter', 5),
('show_films', '1', 'Show films filter', 6),
('show_developers', '0', 'Show developers filter', 7),
('show_labs', '0', 'Show labs filter', 8),
('show_locations', '1', 'Show locations filter', 9),
('show_year', '1', 'Show year filter', 10),
('grid_columns_desktop', '3', 'Grid columns on desktop', 11),
('grid_columns_tablet', '2', 'Grid columns on tablet', 12),
('grid_columns_mobile', '1', 'Grid columns on mobile', 13),
('grid_gap', 'normal', 'Grid gap size (small, normal, large)', 14),
('animation_enabled', '1', 'Enable GSAP animations', 15),
('animation_duration', '0.6', 'Animation duration in seconds', 16);

-- Default analytics settings
INSERT INTO analytics_settings (setting_key, setting_value, description) VALUES
('analytics_enabled', 'true', 'Enable/disable analytics tracking'),
('ip_anonymization', 'true', 'Anonymize IP addresses for privacy'),
('data_retention_days', '365', 'Number of days to keep detailed analytics data'),
('real_time_enabled', 'true', 'Enable real-time visitor tracking'),
('geolocation_enabled', 'true', 'Enable geographic data collection'),
('bot_detection_enabled', 'true', 'Filter out bot traffic'),
('session_timeout_minutes', '30', 'Session timeout in minutes'),
('export_enabled', 'true', 'Allow data export functionality');

-- Default plugin status (pre-installed plugins)
INSERT OR IGNORE INTO plugin_status (slug, name, version, description, author, path, is_active, is_installed) VALUES
('analytics-logger', 'Analytics Logger', '1.0.0', 'Advanced analytics logging with custom events and detailed tracking', 'Cimaise Team', 'plugins/analytics-logger', 1, 1),
('cimaise-analytics-pro', 'Cimaise Analytics Pro', '1.0.0', 'Sistema di analytics professionale con tracking avanzato, dashboard interattiva, report personalizzabili, funnel analysis, heatmap, export dati e real-time monitoring per Cimaise', 'Cimaise Team', 'plugins/cimaise-analytics-pro', 1, 1),
('custom-templates-pro', 'Custom Templates Pro', '1.0.0', 'Carica template personalizzati per gallerie, album e homepage con guide e prompt personalizzabili', 'Cimaise Team', 'plugins/custom-templates-pro', 1, 1),
('hello-cimaise', 'Hello Cimaise', '1.0.0', 'Simple example plugin demonstrating the hooks system', 'Cimaise Team', 'plugins/hello-cimaise', 1, 1),
('image-rating', 'Image Rating', '1.0.0', 'Add star rating system to images (1-5 stars) with sorting and filtering', 'Cimaise Team', 'plugins/image-rating', 1, 1),
('maintenance-mode', 'Maintenance Mode', '1.0.0', 'Put your site under construction with a beautiful maintenance page. Only admins can access the site.', 'Cimaise Team', 'plugins/maintenance-mode', 1, 1);

-- Default custom templates (plugin)
INSERT OR IGNORE INTO custom_templates
(id, type, name, slug, description, version, author, metadata, twig_path, css_paths, js_paths, preview_path, is_active) VALUES
(1, 'gallery', 'Polaroid Gallery', 'polaroid-gallery', 'Griglia fotografica con effetto polaroid e rotazioni casuali', '1.0.0', 'Cimaise Team',
 '{"type":"gallery","name":"Polaroid Gallery","slug":"polaroid-gallery","description":"Griglia fotografica con effetto polaroid e rotazioni casuali","version":"1.0.0","author":"Cimaise Team","requires":{"cimaise":">=1.0.0"},"settings":{"layout":"grid","columns":{"desktop":4,"tablet":3,"mobile":1},"gap":30,"aspect_ratio":"1:1","style":["shadow","hover_scale"]},"libraries":{"masonry":false,"photoswipe":true},"assets":{"css":["styles.css"]}}',
 'uploads/galleries/polaroid-gallery/template.twig',
 '["uploads/galleries/polaroid-gallery/styles.css"]',
 NULL,
 NULL,
 1),
(2, 'gallery', 'Prism Weave', 'prism-weave', 'Gallery moderna a colonne fluide con reveal animato e hover luminoso', '1.0.0', 'Cimaise Team',
'{"type":"gallery","name":"Prism Weave","slug":"prism-weave","description":"Gallery moderna a colonne fluide con reveal animato e hover luminoso","version":"1.0.0","author":"Cimaise Team","requires":{"cimaise":">=1.0.0"},"settings":{"layout":"masonry","columns":{"desktop":4,"tablet":3,"mobile":1},"gap":{"horizontal":18,"vertical":18},"style":{"rounded":true,"hover_scale":true},"masonry":{"type":"balanced"}},"libraries":{"photoswipe":true,"masonry":true},"assets":{"css":["styles.css"],"js":["script.js"]}}',
 'uploads/galleries/prism-weave/template.twig',
 '["uploads/galleries/prism-weave/styles.css"]',
 '["uploads/galleries/prism-weave/script.js"]',
 NULL,
 1),
(3, 'gallery', 'Mono Grid', 'mono-grid', 'Minimal grid with strong whitespace.', '1.0.0', 'Cimaise',
 '{"type":"gallery","name":"Mono Grid","slug":"mono-grid","description":"Minimal grid with strong whitespace.","version":"1.0.0","author":"Cimaise","settings":{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"gap":{"horizontal":16,"vertical":16},"aspect_ratio":"4:3","style":{"rounded":false,"shadow":false,"hover_scale":true}},"assets":{"css":["styles.css"]}}',
 'uploads/galleries/mono-grid/template.twig',
 '["uploads/galleries/mono-grid/styles.css"]',
 NULL,
 NULL,
 1),
(4, 'gallery', 'Caption Rail', 'caption-rail', 'Grid with subtle caption rail.', '1.0.0', 'Cimaise',
 '{"type":"gallery","name":"Caption Rail","slug":"caption-rail","description":"Grid with subtle caption rail.","version":"1.0.0","author":"Cimaise","settings":{"layout":"grid","columns":{"desktop":4,"tablet":2,"mobile":1},"gap":{"horizontal":12,"vertical":12},"aspect_ratio":"3:2","style":{"rounded":true,"shadow":false,"hover_scale":true}},"assets":{"css":["styles.css"]}}',
 'uploads/galleries/caption-rail/template.twig',
 '["uploads/galleries/caption-rail/styles.css"]',
 NULL,
 NULL,
 1),
(5, 'gallery', 'Edge Tiles', 'edge-tiles', 'Edge-to-edge tiles with clean borders.', '1.0.0', 'Cimaise',
 '{"type":"gallery","name":"Edge Tiles","slug":"edge-tiles","description":"Edge-to-edge tiles with clean borders.","version":"1.0.0","author":"Cimaise","settings":{"layout":"grid","columns":{"desktop":5,"tablet":3,"mobile":1},"gap":{"horizontal":6,"vertical":6},"aspect_ratio":"1:1","style":{"rounded":false,"shadow":false,"hover_scale":false}},"assets":{"css":["styles.css"]}}',
 'uploads/galleries/edge-tiles/template.twig',
 '["uploads/galleries/edge-tiles/styles.css"]',
 NULL,
 NULL,
 1),
(6, 'gallery', 'Quiet Masonry', 'quiet-masonry', 'Soft masonry rhythm with subtle hover.', '1.0.0', 'Cimaise',
'{"type":"gallery","name":"Quiet Masonry","slug":"quiet-masonry","description":"Soft masonry rhythm with subtle hover.","version":"1.0.0","author":"Cimaise","settings":{"layout":"masonry","columns":{"desktop":3,"tablet":2,"mobile":1},"gap":{"horizontal":18,"vertical":18},"style":{"rounded":true,"shadow":false,"hover_scale":true},"masonry":{"type":"balanced"}},"assets":{"css":["styles.css"]}}',
 'uploads/galleries/quiet-masonry/template.twig',
 '["uploads/galleries/quiet-masonry/styles.css"]',
 NULL,
 NULL,
 1),
(7, 'gallery', 'Strip Grid', 'strip-grid', 'Wide strips with tight rhythm.', '1.0.0', 'Cimaise',
 '{"type":"gallery","name":"Strip Grid","slug":"strip-grid","description":"Wide strips with tight rhythm.","version":"1.0.0","author":"Cimaise","settings":{"layout":"grid","columns":{"desktop":2,"tablet":2,"mobile":1},"gap":{"horizontal":20,"vertical":20},"aspect_ratio":"16:9","style":{"rounded":false,"shadow":false,"hover_scale":true}},"assets":{"css":["styles.css"]}}',
 'uploads/galleries/strip-grid/template.twig',
 '["uploads/galleries/strip-grid/styles.css"]',
 NULL,
 NULL,
 1),
(8, 'album_page', 'Minimal Album Page', 'minimal-album-page', 'Complete album page with minimalist and clean design', '1.0.0', 'Cimaise Team',
 '{"type":"album_page","name":"Minimal Album Page","slug":"minimal-album-page","description":"Complete album page with minimalist and clean design","version":"1.0.0","author":"Cimaise Team","requires":{"cimaise":">=1.0.0"},"settings":{"gallery_layout":"grid","show_breadcrumbs":false,"show_social_sharing":false,"show_equipment":true,"header_style":"centered"},"assets":{"css":["styles.css"]}}',
 'uploads/albums/minimal-album-page/page.twig',
 '["uploads/albums/minimal-album-page/styles.css"]',
 NULL,
 NULL,
 1),
(9, 'album_page', 'Signal Stack Album', 'signal-stack', 'Editorial album page with typographic hero and modular grid', '1.0.0', 'Cimaise Team',
 '{"type":"album_page","name":"Signal Stack Album","slug":"signal-stack","description":"Editorial album page with typographic hero and modular grid","version":"1.0.0","author":"Cimaise Team","requires":{"cimaise":">=1.0.0"},"settings":{"uses_gallery_template":false,"columns":{"desktop":3,"tablet":2,"mobile":1},"gap":20},"libraries":{"photoswipe":true},"assets":{"css":["styles.css"],"js":["script.js"]}}',
 'uploads/albums/signal-stack/page.twig',
 '["uploads/albums/signal-stack/styles.css"]',
 '["uploads/albums/signal-stack/script.js"]',
 NULL,
 1),
(10, 'album_page', 'Atlas Editorial', 'atlas-editorial', 'Editorial layout with cover, body, and sidebar equipment.', '1.0.0', 'Cimaise',
 '{"type":"album_page","name":"Atlas Editorial","slug":"atlas-editorial","description":"Editorial layout with cover, body, and sidebar equipment.","version":"1.0.0","author":"Cimaise","requires":{"cimaise":">=1.0.0"},"settings":{"gallery_layout":"grid","show_equipment":true,"header_style":"editorial"},"assets":{"css":["styles.css"]}}',
 'uploads/albums/atlas-editorial/page.twig',
 '["uploads/albums/atlas-editorial/styles.css"]',
 NULL,
 NULL,
 1),
(11, 'album_page', 'Split Story', 'split-story', 'Split layout with sticky meta and wide gallery.', '1.0.0', 'Cimaise',
 '{"type":"album_page","name":"Split Story","slug":"split-story","description":"Split layout with sticky meta and wide gallery.","version":"1.0.0","author":"Cimaise","requires":{"cimaise":">=1.0.0"},"settings":{"gallery_layout":"masonry","show_equipment":true,"header_style":"split"},"assets":{"css":["styles.css"]}}',
 'uploads/albums/split-story/page.twig',
 '["uploads/albums/split-story/styles.css"]',
 NULL,
 NULL,
 1),
(12, 'album_page', 'Quiet Stack', 'quiet-stack', 'Minimal stacked layout with subtle dividers.', '1.0.0', 'Cimaise',
 '{"type":"album_page","name":"Quiet Stack","slug":"quiet-stack","description":"Minimal stacked layout with subtle dividers.","version":"1.0.0","author":"Cimaise","requires":{"cimaise":">=1.0.0"},"settings":{"gallery_layout":"grid","show_equipment":true,"header_style":"minimal"},"assets":{"css":["styles.css"]}}',
 'uploads/albums/quiet-stack/page.twig',
 '["uploads/albums/quiet-stack/styles.css"]',
 NULL,
 NULL,
 1),
(13, 'album_page', 'Cover Rail', 'cover-rail', 'Hero cover with rail meta blocks and gallery.', '1.0.0', 'Cimaise',
 '{"type":"album_page","name":"Cover Rail","slug":"cover-rail","description":"Hero cover with rail meta blocks and gallery.","version":"1.0.0","author":"Cimaise","requires":{"cimaise":">=1.0.0"},"settings":{"gallery_layout":"grid","show_equipment":true,"header_style":"cover"},"assets":{"css":["styles.css"]}}',
 'uploads/albums/cover-rail/page.twig',
 '["uploads/albums/cover-rail/styles.css"]',
 NULL,
 NULL,
 1),
(14, 'album_page', 'Panel Notes', 'panel-notes', 'Blocky panels with notes and a clean gallery.', '1.0.0', 'Cimaise',
 '{"type":"album_page","name":"Panel Notes","slug":"panel-notes","description":"Blocky panels with notes and a clean gallery.","version":"1.0.0","author":"Cimaise","requires":{"cimaise":">=1.0.0"},"settings":{"gallery_layout":"grid","show_equipment":true,"header_style":"blocks"},"assets":{"css":["styles.css"]}}',
 'uploads/albums/panel-notes/page.twig',
 '["uploads/albums/panel-notes/styles.css"]',
 NULL,
 NULL,
 1);

-- NOTE: Frontend texts are loaded from JSON files in storage/translations/
-- The frontend_texts table is for user-customized translations only
