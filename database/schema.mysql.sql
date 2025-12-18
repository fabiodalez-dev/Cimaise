-- ============================================
-- Cimaise - Complete MySQL Schema
-- Single file installation schema
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- CORE TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'user') DEFAULT 'admin',
  `first_name` VARCHAR(100) NULL,
  `last_name` VARCHAR(100) NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_login` DATETIME NULL,
  `remember_token` VARCHAR(64) NULL,
  `remember_token_expires_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_users_email` (`email`),
  KEY `idx_users_remember_token` (`remember_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(140) NOT NULL,
  `slug` VARCHAR(160) NOT NULL,
  `sort_order` INT DEFAULT 0,
  `parent_id` INT UNSIGNED NULL,
  `image_path` VARCHAR(255) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_categories_slug` (`slug`),
  KEY `idx_categories_sort` (`sort_order`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_image_path` (`image_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(140) NOT NULL,
  `slug` VARCHAR(160) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_tags_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `locations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_locations_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(180) NOT NULL,
  `description` VARCHAR(255) NULL,
  `settings` JSON NULL,
  `libs` JSON NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_templates_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EQUIPMENT LOOKUP TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS `cameras` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `make` VARCHAR(120) NOT NULL,
  `model` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_cameras_make_model` (`make`, `model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand` VARCHAR(120) NOT NULL,
  `model` VARCHAR(160) NOT NULL,
  `focal_min` DECIMAL(6,2) NULL,
  `focal_max` DECIMAL(6,2) NULL,
  `aperture_min` DECIMAL(4,2) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_lenses_brand_model` (`brand`, `model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `films` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand` VARCHAR(120) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `iso` INT NULL,
  `format` ENUM('35mm', '120', '4x5', '8x10', 'other') DEFAULT '35mm',
  `type` ENUM('color_negative', 'color_reversal', 'bw') NOT NULL DEFAULT 'color_negative',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_films_brand_name_iso_format` (`brand`, `name`, `iso`, `format`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `developers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `process` ENUM('C-41', 'E-6', 'BW', 'Hybrid', 'Other') DEFAULT 'BW',
  `notes` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_developers_name_process` (`name`, `process`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `labs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `city` VARCHAR(120) NULL,
  `country` VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_labs_name_city` (`name`, `city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ALBUMS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `albums` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NULL,
  `template_id` INT UNSIGNED NULL,
  `excerpt` TEXT NULL,
  `body` MEDIUMTEXT NULL,
  `cover_image_id` INT UNSIGNED NULL,
  `shoot_date` DATE NULL,
  `show_date` TINYINT(1) NOT NULL DEFAULT 1,
  `published_at` DATETIME NULL,
  `is_published` TINYINT(1) DEFAULT 0,
  `sort_order` INT DEFAULT 0,
  `password_hash` VARCHAR(255) NULL,
  `allow_downloads` TINYINT(1) NOT NULL DEFAULT 0,
  `is_nsfw` TINYINT(1) NOT NULL DEFAULT 0,
  `seo_title` VARCHAR(255) NULL,
  `seo_description` TEXT NULL,
  `seo_keywords` TEXT NULL,
  `og_title` VARCHAR(255) NULL,
  `og_description` TEXT NULL,
  `og_image_path` VARCHAR(500) NULL,
  `schema_type` VARCHAR(100) DEFAULT 'ImageGallery',
  `schema_data` TEXT NULL,
  `canonical_url` VARCHAR(500) NULL,
  `robots_index` TINYINT(1) DEFAULT 1,
  `robots_follow` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_albums_slug` (`slug`),
  KEY `idx_albums_category` (`category_id`),
  KEY `idx_albums_location` (`location_id`),
  KEY `idx_albums_template` (`template_id`),
  KEY `idx_albums_published` (`is_published`),
  KEY `idx_albums_published_at` (`published_at`),
  KEY `idx_albums_sort` (`sort_order`),
  KEY `idx_albums_seo_title` (`seo_title`),
  KEY `idx_albums_robots` (`robots_index`, `robots_follow`),
  KEY `idx_albums_published_date` (`is_published`, `published_at`),
  KEY `idx_albums_published_shoot` (`is_published`, `shoot_date`),
  KEY `idx_albums_published_nsfw` (`is_published`, `is_nsfw`),
  CONSTRAINT `fk_albums_category` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_albums_location` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_albums_template` FOREIGN KEY (`template_id`) REFERENCES `templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- IMAGES TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `album_id` INT UNSIGNED NOT NULL,
  `original_path` VARCHAR(255) NOT NULL,
  `file_hash` CHAR(40) NOT NULL,
  `width` INT NOT NULL,
  `height` INT NOT NULL,
  `mime` VARCHAR(60) NOT NULL,
  `alt_text` VARCHAR(200) NULL,
  `caption` VARCHAR(300) NULL,
  `exif` JSON NULL,
  `camera_id` INT UNSIGNED NULL,
  `lens_id` INT UNSIGNED NULL,
  `film_id` INT UNSIGNED NULL,
  `developer_id` INT UNSIGNED NULL,
  `lab_id` INT UNSIGNED NULL,
  `location_id` INT UNSIGNED NULL,
  `custom_camera` VARCHAR(160) NULL,
  `custom_lens` VARCHAR(160) NULL,
  `custom_film` VARCHAR(160) NULL,
  `custom_development` VARCHAR(160) NULL,
  `custom_lab` VARCHAR(160) NULL,
  `custom_scanner` VARCHAR(160) NULL,
  `scan_resolution_dpi` INT NULL,
  `scan_bit_depth` INT NULL,
  `process` ENUM('digital', 'analog', 'hybrid') DEFAULT 'digital',
  `development_date` DATE NULL,
  `iso` INT NULL,
  `shutter_speed` VARCHAR(40) NULL,
  `aperture` DECIMAL(4,2) NULL,
  `sort_order` INT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_images_album` (`album_id`),
  KEY `idx_images_sort` (`sort_order`),
  KEY `idx_images_hash` (`file_hash`),
  KEY `idx_images_camera` (`camera_id`),
  KEY `idx_images_lens` (`lens_id`),
  KEY `idx_images_film` (`film_id`),
  KEY `idx_images_developer` (`developer_id`),
  KEY `idx_images_lab` (`lab_id`),
  KEY `idx_images_location` (`location_id`),
  KEY `idx_images_process` (`process`),
  KEY `idx_images_iso` (`iso`),
  KEY `idx_images_album_sort` (`album_id`, `sort_order`, `id`),
  CONSTRAINT `fk_images_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_images_camera` FOREIGN KEY (`camera_id`) REFERENCES `cameras`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_lens` FOREIGN KEY (`lens_id`) REFERENCES `lenses`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_film` FOREIGN KEY (`film_id`) REFERENCES `films`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_developer` FOREIGN KEY (`developer_id`) REFERENCES `developers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_lab` FOREIGN KEY (`lab_id`) REFERENCES `labs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_location` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `image_variants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `image_id` INT UNSIGNED NOT NULL,
  `variant` VARCHAR(50) NOT NULL,
  `format` ENUM('avif', 'webp', 'jpg') NOT NULL,
  `path` VARCHAR(255) NOT NULL,
  `width` INT NOT NULL,
  `height` INT NOT NULL,
  `size_bytes` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_image_variants_unique` (`image_id`, `variant`, `format`),
  CONSTRAINT `fk_image_variants_image` FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- JUNCTION TABLES (Many-to-Many)
-- ============================================

CREATE TABLE IF NOT EXISTS `album_tag` (
  `album_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `tag_id`),
  KEY `idx_album_tag_tag` (`tag_id`),
  CONSTRAINT `fk_album_tag_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_tag_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `album_category` (
  `album_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `category_id`),
  KEY `idx_album_category_category` (`category_id`),
  CONSTRAINT `fk_album_category_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_category_category` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `album_camera` (
  `album_id` INT UNSIGNED NOT NULL,
  `camera_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `camera_id`),
  KEY `idx_album_camera_camera` (`camera_id`),
  CONSTRAINT `fk_album_camera_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_camera_camera` FOREIGN KEY (`camera_id`) REFERENCES `cameras`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `album_lens` (
  `album_id` INT UNSIGNED NOT NULL,
  `lens_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `lens_id`),
  KEY `idx_album_lens_lens` (`lens_id`),
  CONSTRAINT `fk_album_lens_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_lens_lens` FOREIGN KEY (`lens_id`) REFERENCES `lenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `album_film` (
  `album_id` INT UNSIGNED NOT NULL,
  `film_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `film_id`),
  KEY `idx_album_film_film` (`film_id`),
  CONSTRAINT `fk_album_film_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_film_film` FOREIGN KEY (`film_id`) REFERENCES `films`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `album_developer` (
  `album_id` INT UNSIGNED NOT NULL,
  `developer_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `developer_id`),
  KEY `idx_album_developer_developer` (`developer_id`),
  CONSTRAINT `fk_album_developer_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_developer_developer` FOREIGN KEY (`developer_id`) REFERENCES `developers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `album_lab` (
  `album_id` INT UNSIGNED NOT NULL,
  `lab_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `lab_id`),
  KEY `idx_album_lab_lab` (`lab_id`),
  CONSTRAINT `fk_album_lab_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_lab_lab` FOREIGN KEY (`lab_id`) REFERENCES `labs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `album_location` (
  `album_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`album_id`, `location_id`),
  KEY `idx_album_location_location` (`location_id`),
  CONSTRAINT `fk_album_location_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_location_location` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `image_location` (
  `image_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`image_id`, `location_id`),
  KEY `idx_image_location_location` (`location_id`),
  CONSTRAINT `fk_image_location_image` FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_image_location_location` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SETTINGS TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(190) NOT NULL,
  `value` TEXT NULL,
  `type` VARCHAR(50) DEFAULT 'string',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `filter_settings` (
  `setting_key` VARCHAR(255) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `description` TEXT NULL,
  `sort_order` INT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ANALYTICS TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS `analytics_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` VARCHAR(64) NOT NULL,
  `ip_hash` VARCHAR(64) NOT NULL,
  `user_agent` TEXT NULL,
  `browser` VARCHAR(100) NULL,
  `browser_version` VARCHAR(50) NULL,
  `platform` VARCHAR(100) NULL,
  `device_type` VARCHAR(50) NULL,
  `screen_resolution` VARCHAR(20) NULL,
  `country_code` VARCHAR(2) NULL,
  `region` VARCHAR(100) NULL,
  `city` VARCHAR(100) NULL,
  `referrer_domain` VARCHAR(255) NULL,
  `referrer_url` TEXT NULL,
  `landing_page` TEXT NULL,
  `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_activity` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `page_views` INT DEFAULT 0,
  `duration` INT DEFAULT 0,
  `is_bot` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_analytics_sessions_session_id` (`session_id`),
  KEY `idx_analytics_sessions_started_at` (`started_at`),
  KEY `idx_analytics_sessions_country` (`country_code`),
  KEY `idx_analytics_sessions_device` (`device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analytics_pageviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` VARCHAR(64) NOT NULL,
  `page_url` TEXT NOT NULL,
  `page_title` VARCHAR(255) NULL,
  `page_type` VARCHAR(50) NULL,
  `album_id` INT UNSIGNED NULL,
  `category_id` INT UNSIGNED NULL,
  `tag_id` INT UNSIGNED NULL,
  `load_time` INT NULL,
  `viewport_width` INT NULL,
  `viewport_height` INT NULL,
  `scroll_depth` INT DEFAULT 0,
  `time_on_page` INT DEFAULT 0,
  `viewed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_analytics_pageviews_session_id` (`session_id`),
  KEY `idx_analytics_pageviews_viewed_at` (`viewed_at`),
  KEY `idx_analytics_pageviews_page_type` (`page_type`),
  KEY `idx_analytics_pageviews_album_id` (`album_id`),
  CONSTRAINT `fk_analytics_pageviews_session` FOREIGN KEY (`session_id`) REFERENCES `analytics_sessions`(`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analytics_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `event_category` VARCHAR(100) NULL,
  `event_action` VARCHAR(100) NULL,
  `event_label` VARCHAR(255) NULL,
  `event_value` INT NULL,
  `page_url` TEXT NULL,
  `album_id` INT UNSIGNED NULL,
  `image_id` INT UNSIGNED NULL,
  `occurred_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_analytics_events_session_id` (`session_id`),
  KEY `idx_analytics_events_type` (`event_type`),
  KEY `idx_analytics_events_occurred_at` (`occurred_at`),
  CONSTRAINT `fk_analytics_events_session` FOREIGN KEY (`session_id`) REFERENCES `analytics_sessions`(`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analytics_daily_summary` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `total_sessions` INT DEFAULT 0,
  `total_pageviews` INT DEFAULT 0,
  `unique_visitors` INT DEFAULT 0,
  `bounce_rate` DECIMAL(5,2) DEFAULT 0.00,
  `avg_session_duration` INT DEFAULT 0,
  `top_pages` JSON NULL,
  `top_countries` JSON NULL,
  `top_browsers` JSON NULL,
  `top_albums` JSON NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_analytics_daily_summary_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analytics_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(255) NOT NULL,
  `setting_value` TEXT NULL,
  `description` TEXT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_analytics_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PLUGIN STATUS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `plugin_status` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(190) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `version` VARCHAR(50) NOT NULL,
  `description` TEXT NULL,
  `author` VARCHAR(120) NULL,
  `path` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `is_installed` TINYINT(1) DEFAULT 1,
  `installed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_plugin_status_slug` (`slug`),
  KEY `idx_plugin_status_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LOGS TABLE (Structured Logging System)
-- ============================================

CREATE TABLE IF NOT EXISTS `logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` INT NOT NULL,
  `level_name` VARCHAR(20) NOT NULL,
  `category` VARCHAR(50) DEFAULT 'app',
  `message` TEXT NOT NULL,
  `context` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_level` (`level`),
  KEY `idx_logs_category` (`category`),
  KEY `idx_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Default category
INSERT INTO `categories` (`name`, `slug`, `sort_order`) VALUES ('Photo', 'photo', 1);

-- Default templates (IDs 1-6, Magazine Split = ID 3)
INSERT INTO `templates` (`id`, `name`, `slug`, `description`, `settings`, `libs`) VALUES
(1, 'Grid Classica', 'grid-classica', 'Layout a griglia responsivo - desktop 3 colonne, tablet 2, mobile 1', '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}', '["photoswipe"]'),
(2, 'Masonry Portfolio', 'masonry-portfolio', 'Layout masonry responsivo per portfolio - desktop 4 colonne, tablet 3, mobile 2', '{"layout":"grid","columns":{"desktop":4,"tablet":3,"mobile":2},"masonry":true,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.1,"allowPanToNext":true}}', '["photoswipe"]'),
(3, 'Magazine Split', 'magazine-split', 'Galleria a colonne con scorrimento infinito/masonry in stile magazine', '{"layout":"magazine","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":true,"magazine":{"durations":[60,72,84],"gap":20},"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.10,"allowPanToNext":true}}', '["photoswipe"]'),
(4, 'Gallery Fullscreen', 'gallery-fullscreen', 'Layout fullscreen responsivo - desktop 2 colonne, tablet 1, mobile 1', '{"layout":"fullscreen","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":1.0,"spacing":0,"allowPanToNext":true}}', '["photoswipe"]'),
(5, 'Grid Compatta', 'grid-compatta', 'Layout compatto con molte colonne - desktop 5 colonne, tablet 3, mobile 2', '{"layout":"grid","columns":{"desktop":5,"tablet":3,"mobile":2},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}', '["photoswipe"]'),
(6, 'Grid Ampia', 'grid-ampia', 'Layout con poche colonne per immagini grandi - desktop 2 colonne, tablet 1, mobile 1', '{"layout":"grid","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.85,"spacing":0.15,"allowPanToNext":true}}', '["photoswipe"]');

-- Default settings
INSERT INTO `settings` (`key`, `value`, `type`) VALUES
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
-- SEO Settings
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
('recaptcha.enabled', 'false', 'boolean'),
('recaptcha.site_key', '', 'string'),
('recaptcha.secret_key', '', 'string');

-- Default filter settings
INSERT INTO `filter_settings` (`setting_key`, `setting_value`, `description`, `sort_order`) VALUES
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
INSERT INTO `analytics_settings` (`setting_key`, `setting_value`, `description`) VALUES
('analytics_enabled', 'true', 'Enable/disable analytics tracking'),
('ip_anonymization', 'true', 'Anonymize IP addresses for privacy'),
('data_retention_days', '365', 'Number of days to keep detailed analytics data'),
('real_time_enabled', 'true', 'Enable real-time visitor tracking'),
('geolocation_enabled', 'true', 'Enable geographic data collection'),
('bot_detection_enabled', 'true', 'Filter out bot traffic'),
('session_timeout_minutes', '30', 'Session timeout in minutes'),
('export_enabled', 'true', 'Allow data export functionality');

-- ============================================
-- FRONTEND TEXTS TABLE (Translation System)
-- ============================================

CREATE TABLE IF NOT EXISTS `frontend_texts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `text_key` VARCHAR(190) NOT NULL,
  `text_value` TEXT NOT NULL,
  `context` VARCHAR(100) DEFAULT 'general',
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_frontend_texts_key` (`text_key`),
  KEY `idx_frontend_texts_context` (`context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: Frontend texts are loaded from JSON files in storage/translations/
-- The frontend_texts table is for user-customized translations only
