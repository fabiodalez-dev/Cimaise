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
-- EQUIPMENT LOOKUP TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS cameras (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  make TEXT NOT NULL,
  model TEXT NOT NULL,
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
  format TEXT DEFAULT '35mm' CHECK(format IN ('35mm', '120', '4x5', '8x10', 'other')),
  type TEXT NOT NULL DEFAULT 'color_negative' CHECK(type IN ('color_negative', 'color_reversal', 'bw')),
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
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_albums_slug ON albums(slug);
CREATE INDEX IF NOT EXISTS idx_albums_category ON albums(category_id);
CREATE INDEX IF NOT EXISTS idx_albums_location ON albums(location_id);
CREATE INDEX IF NOT EXISTS idx_albums_template ON albums(template_id);
CREATE INDEX IF NOT EXISTS idx_albums_published ON albums(is_published);
CREATE INDEX IF NOT EXISTS idx_albums_published_at ON albums(published_at);
CREATE INDEX IF NOT EXISTS idx_albums_sort ON albums(sort_order);
CREATE INDEX IF NOT EXISTS idx_albums_seo_title ON albums(seo_title);
CREATE INDEX IF NOT EXISTS idx_albums_robots ON albums(robots_index, robots_follow);
CREATE INDEX IF NOT EXISTS idx_albums_published_date ON albums(is_published, published_at);
CREATE INDEX IF NOT EXISTS idx_albums_published_shoot ON albums(is_published, shoot_date);
CREATE INDEX IF NOT EXISTS idx_albums_nsfw ON albums(is_nsfw);
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

CREATE INDEX IF NOT EXISTS idx_plugin_status_slug ON plugin_status(slug);
CREATE INDEX IF NOT EXISTS idx_plugin_status_active ON plugin_status(is_active);

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
-- DEFAULT DATA
-- ============================================

-- Default category
INSERT INTO categories (name, slug, sort_order) VALUES ('Photo', 'photo', 1);

-- Default templates
INSERT INTO templates (name, slug, description, settings, libs) VALUES
('Grid Classica', 'grid-classica', 'Layout a griglia responsivo - desktop 3 colonne, tablet 2, mobile 1', '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}', '["photoswipe"]'),
('Masonry Portfolio', 'masonry-portfolio', 'Layout masonry responsivo per portfolio - desktop 4 colonne, tablet 3, mobile 2', '{"layout":"grid","columns":{"desktop":4,"tablet":3,"mobile":2},"masonry":true,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.1,"allowPanToNext":true}}', '["photoswipe"]'),
('Magazine Split', 'magazine-split', 'Slideshow minimalista con controlli essenziali', '{"layout":"magazine","columns":{"desktop":1,"tablet":1,"mobile":1},"masonry":1,"magazine":{"autoplay":true,"delay":4000,"showThumbs":true,"showProgress":false,"external_navigation":true},"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":false,"arrowKeys":true,"escKey":true,"bgOpacity":0.95,"spacing":0.05,"allowPanToNext":false}}', '["photoswipe"]'),
('Gallery Fullscreen', 'gallery-fullscreen', 'Layout fullscreen responsivo - desktop 2 colonne, tablet 1, mobile 1', '{"layout":"fullscreen","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":1.0,"spacing":0,"allowPanToNext":true}}', '["photoswipe"]'),
('Grid Compatta', 'grid-compatta', 'Layout compatto con molte colonne - desktop 5 colonne, tablet 3, mobile 2', '{"layout":"grid","columns":{"desktop":5,"tablet":3,"mobile":2},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}', '["photoswipe"]'),
('Grid Ampia', 'grid-ampia', 'Layout con poche colonne per immagini grandi - desktop 2 colonne, tablet 1, mobile 1', '{"layout":"grid","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.85,"spacing":0.15,"allowPanToNext":true}}', '["photoswipe"]');

-- Default settings
INSERT INTO settings (key, value, type) VALUES
('image.formats', '{"avif":true,"webp":true,"jpg":true}', 'string'),
('image.quality', '{"avif":50,"webp":75,"jpg":85}', 'string'),
('image.preview', '{"width":480,"height":null}', 'string'),
('image.breakpoints', '{"sm":768,"md":1200,"lg":1920,"xl":2560,"xxl":3840}', 'string'),
('gallery.default_template_id', '1', 'number'),
('performance.compression', 'true', 'boolean'),
('pagination.limit', '12', 'number'),
('cache.ttl', '24', 'number'),
('site.logo', 'null', 'null'),
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
('seo.structured_data_format', 'json-ld', 'string');

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

-- Default frontend texts (English)
INSERT INTO frontend_texts (text_key, text_value, context, description) VALUES
-- Navigation
('nav.home', 'Home', 'navigation', 'Home link text'),
('nav.about', 'About', 'navigation', 'About link text'),
('nav.contact', 'Contact', 'navigation', 'Contact link text'),
('nav.gallery', 'Gallery', 'navigation', 'Gallery link text'),
('nav.albums', 'Albums', 'navigation', 'Albums link text'),
('nav.categories', 'Categories', 'navigation', 'Categories link text'),
('nav.galleries', 'Galleries', 'navigation', 'Galleries link text'),
('nav.back', 'Back', 'navigation', 'Back link text'),
('nav.menu', 'Menu', 'navigation', 'Menu text'),
('nav.close', 'Close', 'navigation', 'Close text'),
-- Filters
('filter.all', 'All', 'filters', 'All filter option'),
('filter.all_photos', 'All Photos', 'filters', 'All photos filter option'),
('filter.categories', 'Categories', 'filters', 'Categories filter label'),
('filter.category', 'Category', 'filters', 'Category filter label'),
('filter.tags', 'Tags', 'filters', 'Tags filter label'),
('filter.tag', 'Tag', 'filters', 'Tag filter label'),
('filter.cameras', 'Cameras', 'filters', 'Cameras filter label'),
('filter.lenses', 'Lenses', 'filters', 'Lenses filter label'),
('filter.films', 'Films', 'filters', 'Films filter label'),
('filter.film', 'Film', 'filters', 'Film filter label'),
('filter.developers', 'Developers', 'filters', 'Developers filter label'),
('filter.labs', 'Labs', 'filters', 'Labs filter label'),
('filter.locations', 'Locations', 'filters', 'Locations filter label'),
('filter.location', 'Location', 'filters', 'Location filter label'),
('filter.year', 'Year', 'filters', 'Year filter label'),
('filter.date', 'Date', 'filters', 'Date filter label'),
('filter.search', 'Search', 'filters', 'Search filter label'),
('filter.clear', 'Clear filters', 'filters', 'Clear all filters button'),
('filter.apply', 'Apply', 'filters', 'Apply filters button'),
('filter.no_results', 'No results found', 'filters', 'No results message'),
('filter.results_count', '{count} results', 'filters', 'Results count with placeholder'),
('filter.sort', 'Sort', 'filters', 'Sort label'),
('filter.process', 'Process', 'filters', 'Process filter label'),
('filter.filter_images', 'Filter images:', 'filters', 'Filter images label'),
('filter.sort_latest', 'Latest First', 'filters', 'Sort by latest'),
('filter.sort_oldest', 'Oldest First', 'filters', 'Sort by oldest'),
('filter.sort_title_asc', 'Title A-Z', 'filters', 'Sort by title ascending'),
('filter.sort_title_desc', 'Title Z-A', 'filters', 'Sort by title descending'),
('filter.sort_date_new', 'Shoot Date (New)', 'filters', 'Sort by shoot date new'),
('filter.sort_date_old', 'Shoot Date (Old)', 'filters', 'Sort by shoot date old'),
-- Album
('album.photos', 'photos', 'album', 'Photos count label'),
('album.photo', 'photo', 'album', 'Single photo label'),
('album.photo_count', '{count} photos', 'album', 'Photo count with placeholder'),
('album.view', 'View album', 'album', 'View album button'),
('album.view_gallery', 'View Gallery', 'album', 'View gallery button'),
('album.view_all', 'View All', 'album', 'View all button'),
('album.download', 'Download', 'album', 'Download button'),
('album.share', 'Share', 'album', 'Share button'),
('album.info', 'Info', 'album', 'Info button'),
('album.details', 'Details', 'album', 'Details label'),
('album.description', 'Description', 'album', 'Description label'),
('album.date', 'Date', 'album', 'Date label'),
('album.location', 'Location', 'album', 'Location label'),
('album.camera', 'Camera', 'album', 'Camera label'),
('album.lens', 'Lens', 'album', 'Lens label'),
('album.settings', 'Settings', 'album', 'Settings label'),
('album.equipment', 'Equipment', 'album', 'Equipment section title'),
('album.more_from', 'More from {category}', 'album', 'More from category section title'),
('album.related', 'Related albums', 'album', 'Related albums section title'),
('album.empty', 'This gallery is empty', 'album', 'Empty gallery message'),
('album.empty_message', 'Images will appear here once uploaded.', 'album', 'Empty gallery description'),
('album.private', 'Private Gallery', 'album', 'Private gallery label'),
('album.password_protected', 'Password Protected', 'album', 'Password protected message'),
('album.enter_password', 'Enter Password', 'album', 'Password field label'),
('album.wrong_password', 'Wrong password', 'album', 'Wrong password error'),
('album.unlock', 'Unlock', 'album', 'Unlock button'),
-- Pagination
('pagination.previous', 'Previous', 'pagination', 'Previous page button'),
('pagination.next', 'Next', 'pagination', 'Next page button'),
('pagination.first', 'First', 'pagination', 'First page button'),
('pagination.last', 'Last', 'pagination', 'Last page button'),
('pagination.page', 'Page', 'pagination', 'Page label'),
('pagination.of', 'of', 'pagination', 'Of separator'),
('pagination.showing', 'Showing {from} to {to} of {total}', 'pagination', 'Showing range'),
('pagination.load_more', 'Load more', 'pagination', 'Load more button'),
-- Dates
('date.published', 'Published', 'dates', 'Published date label'),
('date.updated', 'Updated', 'dates', 'Updated date label'),
('date.taken', 'Taken', 'dates', 'Photo taken date label'),
('date.january', 'January', 'dates', 'Month name'),
('date.february', 'February', 'dates', 'Month name'),
('date.march', 'March', 'dates', 'Month name'),
('date.april', 'April', 'dates', 'Month name'),
('date.may', 'May', 'dates', 'Month name'),
('date.june', 'June', 'dates', 'Month name'),
('date.july', 'July', 'dates', 'Month name'),
('date.august', 'August', 'dates', 'Month name'),
('date.september', 'September', 'dates', 'Month name'),
('date.october', 'October', 'dates', 'Month name'),
('date.november', 'November', 'dates', 'Month name'),
('date.december', 'December', 'dates', 'Month name'),
('date.today', 'Today', 'dates', 'Today label'),
('date.yesterday', 'Yesterday', 'dates', 'Yesterday label'),
('date.days_ago', '{count} days ago', 'dates', 'Days ago with placeholder'),
-- Footer
('footer.copyright', '© {year} All rights reserved', 'footer', 'Copyright text with year placeholder'),
('footer.powered_by', 'Powered by Cimaise', 'footer', 'Powered by text'),
('footer.privacy', 'Privacy Policy', 'footer', 'Privacy policy link'),
('footer.terms', 'Terms of Service', 'footer', 'Terms of service link'),
-- Lightbox
('lightbox.close', 'Close (Esc)', 'lightbox', 'Close button title'),
('lightbox.previous', 'Previous', 'lightbox', 'Previous image title'),
('lightbox.next', 'Next', 'lightbox', 'Next image title'),
('lightbox.zoom', 'Zoom', 'lightbox', 'Zoom button title'),
('lightbox.zoom_in', 'Zoom In', 'lightbox', 'Zoom in button'),
('lightbox.zoom_out', 'Zoom Out', 'lightbox', 'Zoom out button'),
('lightbox.fullscreen', 'Fullscreen', 'lightbox', 'Fullscreen button'),
('lightbox.exit_fullscreen', 'Exit Fullscreen', 'lightbox', 'Exit fullscreen button'),
('lightbox.download', 'Download image', 'lightbox', 'Download button title'),
('lightbox.slideshow', 'Slideshow', 'lightbox', 'Slideshow button'),
('lightbox.stop_slideshow', 'Stop Slideshow', 'lightbox', 'Stop slideshow button'),
('lightbox.image_count', 'Image {current} of {total}', 'lightbox', 'Image counter'),
('lightbox.of', 'of', 'lightbox', 'Image counter separator'),
('lightbox.error', 'Unable to load image', 'lightbox', 'Error loading image'),
-- Social Share
('share.title', 'Share', 'social', 'Share section title'),
('share.facebook', 'Share on Facebook', 'social', 'Facebook share button'),
('share.twitter', 'Share on X', 'social', 'X/Twitter share button'),
('share.pinterest', 'Pin on Pinterest', 'social', 'Pinterest share button'),
('share.linkedin', 'Share on LinkedIn', 'social', 'LinkedIn share button'),
('share.whatsapp', 'Share on WhatsApp', 'social', 'WhatsApp share button'),
('share.telegram', 'Share on Telegram', 'social', 'Telegram share button'),
('share.email', 'Share via Email', 'social', 'Email share button'),
('share.copy', 'Copy link', 'social', 'Copy link button'),
('share.copy_link', 'Copy Link', 'social', 'Copy link button alt'),
('share.copied', 'Link copied!', 'social', 'Link copied confirmation'),
('share.link_copied', 'Link copied!', 'social', 'Link copied confirmation alt'),
-- Search
('search.placeholder', 'Search...', 'search', 'Search input placeholder'),
('search.button', 'Search', 'search', 'Search button'),
('search.no_results', 'No results found for "{query}"', 'search', 'No search results'),
('search.results_for', 'Results for "{query}"', 'search', 'Search results title prefix'),
('search.clear', 'Clear search', 'search', 'Clear search button'),
-- Errors
('error.404', 'Page not found', 'errors', '404 error title'),
('error.404_message', 'The page you are looking for does not exist.', 'errors', '404 error message'),
('error.500', 'Server error', 'errors', '500 error title'),
('error.500_message', 'Something went wrong. Please try again later.', 'errors', '500 error message'),
('error.generic', 'An error occurred', 'errors', 'Generic error'),
('error.go_home', 'Go to Homepage', 'errors', 'Go home button'),
('error.back_home', 'Back to Home', 'errors', 'Back to home button'),
-- Contact Form
('contact.title', 'Contact', 'contact', 'Contact form title'),
('contact.name', 'Name', 'contact', 'Name field label'),
('contact.email', 'Email', 'contact', 'Email field label'),
('contact.subject', 'Subject', 'contact', 'Subject field label'),
('contact.message', 'Message', 'contact', 'Message field label'),
('contact.send', 'Send Message', 'contact', 'Send button'),
('contact.sending', 'Sending...', 'contact', 'Sending state'),
('contact.success', 'Message sent successfully!', 'contact', 'Success message'),
('contact.error', 'Failed to send message. Please try again.', 'contact', 'Error message'),
('contact.required', 'This field is required', 'contact', 'Required field error'),
('contact.invalid_email', 'Please enter a valid email address', 'contact', 'Invalid email error'),
('contact.intro', 'For collaborations and commissions, contact me via email or social.', 'contact', 'Contact intro text'),
-- Metadata
('meta.camera', 'Camera', 'metadata', 'Camera metadata label'),
('meta.lens', 'Lens', 'metadata', 'Lens metadata label'),
('meta.film', 'Film', 'metadata', 'Film metadata label'),
('meta.developer', 'Developer', 'metadata', 'Developer metadata label'),
('meta.lab', 'Lab', 'metadata', 'Lab metadata label'),
('meta.location', 'Location', 'metadata', 'Location metadata label'),
('meta.date', 'Date', 'metadata', 'Date metadata label'),
('meta.home_title', 'Photography Portfolio', 'metadata', 'Home page title'),
('meta.home_description', 'Professional photography portfolio showcasing creative work', 'metadata', 'Home page description'),
('meta.gallery_title', '{name} - Gallery', 'metadata', 'Gallery page title'),
-- General
('general.loading', 'Loading...', 'general', 'Loading indicator'),
('general.load_more', 'Load More', 'general', 'Load more button'),
('general.show_less', 'Show Less', 'general', 'Show less button'),
('general.read_more', 'Read more', 'general', 'Read more link'),
('general.see_all', 'See all', 'general', 'See all link'),
('general.back', 'Back', 'general', 'Back button'),
('general.close', 'Close', 'general', 'Close button'),
('general.yes', 'Yes', 'general', 'Yes button'),
('general.no', 'No', 'general', 'No button'),
('general.ok', 'OK', 'general', 'OK button'),
('general.cancel', 'Cancel', 'general', 'Cancel button'),
('general.save', 'Save', 'general', 'Save button'),
('general.delete', 'Delete', 'general', 'Delete button'),
('general.edit', 'Edit', 'general', 'Edit button'),
('general.submit', 'Submit', 'general', 'Submit button'),
('general.reset', 'Reset', 'general', 'Reset button');
