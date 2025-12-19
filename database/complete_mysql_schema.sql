-- Cimaise Complete MySQL Schema with Data
-- Generated from template.sqlite database
-- This file creates the complete database structure and inserts all seed data

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Database structure

-- Users table
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'admin',
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `remember_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token_expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_remember_token` (`remember_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories table
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `image_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_categories_slug` (`slug`),
  KEY `idx_categories_sort` (`sort_order`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_image_path` (`image_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags table
CREATE TABLE `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_tags_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Templates table
CREATE TABLE `templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `libs` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Albums table
CREATE TABLE `albums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) NOT NULL,
  `excerpt` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_image_id` int(11) DEFAULT NULL,
  `shoot_date` date DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `show_date` tinyint(1) NOT NULL DEFAULT 1,
  `template_id` int(11) DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allow_downloads` tinyint(1) NOT NULL DEFAULT 0,
  `is_nsfw` tinyint(1) NOT NULL DEFAULT 0,
  `location_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_keywords` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `og_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `og_description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `og_image_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `schema_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'ImageGallery',
  `schema_data` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `robots_index` tinyint(1) DEFAULT 1,
  `robots_follow` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_albums_slug` (`slug`),
  KEY `idx_albums_category` (`category_id`),
  KEY `idx_albums_published` (`is_published`),
  KEY `idx_albums_published_at` (`published_at`),
  KEY `idx_albums_sort` (`sort_order`),
  KEY `idx_albums_template` (`template_id`),
  KEY `idx_albums_seo_title` (`seo_title`),
  KEY `idx_albums_robots` (`robots_index`, `robots_follow`),
  KEY `idx_albums_published_nsfw` (`is_published`, `is_nsfw`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cameras table
CREATE TABLE `cameras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `make` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `make_model` (`make`, `model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lenses table
CREATE TABLE `lenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `focal_min` decimal(5,1) DEFAULT NULL,
  `focal_max` decimal(5,1) DEFAULT NULL,
  `aperture_min` decimal(3,1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brand_model` (`brand`, `model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Films table
CREATE TABLE `films` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `iso` int(11) DEFAULT NULL,
  `format` ENUM('35mm', '120', '4x5', '8x10', 'other') DEFAULT '35mm',
  `type` ENUM('color_negative', 'color_reversal', 'bw') NOT NULL DEFAULT 'color_negative',
  PRIMARY KEY (`id`),
  UNIQUE KEY `brand_name_iso_format` (`brand`, `name`, `iso`, `format`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Developers table
CREATE TABLE `developers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `process` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'BW',
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_process` (`name`, `process`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Labs table
CREATE TABLE `labs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_city` (`name`, `city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Locations table
CREATE TABLE `locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_locations_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Images table
CREATE TABLE `images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `album_id` int(11) NOT NULL,
  `original_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `width` int(11) NOT NULL,
  `height` int(11) NOT NULL,
  `mime` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alt_text` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caption` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exif` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `camera_id` int(11) DEFAULT NULL,
  `lens_id` int(11) DEFAULT NULL,
  `film_id` int(11) DEFAULT NULL,
  `developer_id` int(11) DEFAULT NULL,
  `lab_id` int(11) DEFAULT NULL,
  `custom_camera` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_lens` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_film` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_development` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_lab` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_scanner` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scan_resolution_dpi` int(11) DEFAULT NULL,
  `scan_bit_depth` int(11) DEFAULT NULL,
  `process` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'digital',
  `development_date` date DEFAULT NULL,
  `iso` int(11) DEFAULT NULL,
  `shutter_speed` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aperture` decimal(3,1) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_images_album` (`album_id`),
  KEY `idx_images_sort` (`sort_order`),
  KEY `idx_images_hash` (`file_hash`),
  KEY `idx_images_camera` (`camera_id`),
  KEY `idx_images_lens` (`lens_id`),
  KEY `idx_images_film` (`film_id`),
  KEY `idx_images_developer` (`developer_id`),
  KEY `idx_images_lab` (`lab_id`),
  KEY `idx_images_process` (`process`),
  KEY `idx_images_iso` (`iso`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`camera_id`) REFERENCES `cameras`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`lens_id`) REFERENCES `lenses`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`film_id`) REFERENCES `films`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`developer_id`) REFERENCES `developers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`lab_id`) REFERENCES `labs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Image variants table
CREATE TABLE `image_variants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `image_id` int(11) NOT NULL,
  `variant` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `format` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `width` int(11) NOT NULL,
  `height` int(11) NOT NULL,
  `size_bytes` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `image_variant_format` (`image_id`, `variant`, `format`),
  FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings table
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'string',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `idx_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction tables
CREATE TABLE `album_tag` (
  `album_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `tag_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `album_category` (
  `album_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `category_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `album_camera` (
  `album_id` int(11) NOT NULL,
  `camera_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `camera_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`camera_id`) REFERENCES `cameras`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `album_lens` (
  `album_id` int(11) NOT NULL,
  `lens_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `lens_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lens_id`) REFERENCES `lenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `album_film` (
  `album_id` int(11) NOT NULL,
  `film_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `film_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`film_id`) REFERENCES `films`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `album_developer` (
  `album_id` int(11) NOT NULL,
  `developer_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `developer_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`developer_id`) REFERENCES `developers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `album_lab` (
  `album_id` int(11) NOT NULL,
  `lab_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `lab_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lab_id`) REFERENCES `labs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `album_location` (
  `album_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  PRIMARY KEY (`album_id`, `location_id`),
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `image_location` (
  `image_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  PRIMARY KEY (`image_id`, `location_id`),
  FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `filter_settings` (
  `setting_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert seed data
INSERT INTO `categories` (`id`, `name`, `slug`, `sort_order`, `created_at`, `parent_id`, `image_path`) VALUES
(9, 'Photo', 'photo', 1, '2025-08-25 14:33:29', NULL, NULL);

INSERT INTO `settings` (`id`, `key`, `value`, `type`, `created_at`, `updated_at`) VALUES
(2, 'test.setting', '123', 'number', '2025-08-24 23:18:27', '2025-08-24 23:18:27'),
(273, 'image.formats', '{"avif":true,"webp":true,"jpg":true}', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(274, 'image.quality', '{"avif":50,"webp":75,"jpg":85}', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(275, 'image.preview', '{"width":480,"height":null}', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(276, 'image.breakpoints', '{"sm":768,"md":1200,"lg":1920,"xl":2560,"xxl":3840}', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(277, 'gallery.default_template_id', '1', 'number', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(282, 'performance.compression', 'true', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(283, 'pagination.limit', '12', 'number', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(284, 'cache.ttl', '24', 'number', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(285, 'site.logo', 'null', 'null', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),

-- SEO Settings
(286, 'seo.site_title', 'Photography Portfolio', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(287, 'seo.site_description', 'Professional photography portfolio showcasing creative work and artistic vision', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(288, 'seo.site_keywords', 'photography, portfolio, professional photographer, creative photography', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(289, 'seo.author_name', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(290, 'seo.author_url', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(291, 'seo.organization_name', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(292, 'seo.organization_url', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(293, 'seo.og_site_name', 'Photography Portfolio', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(294, 'seo.og_type', 'website', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(295, 'seo.og_locale', 'en_US', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(296, 'seo.twitter_card', 'summary_large_image', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(297, 'seo.twitter_site', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(298, 'seo.twitter_creator', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(299, 'seo.schema_enabled', 'true', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(300, 'seo.breadcrumbs_enabled', 'true', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(301, 'seo.local_business_enabled', 'false', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(302, 'seo.local_business_name', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(303, 'seo.local_business_type', 'ProfessionalService', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(304, 'seo.local_business_address', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(305, 'seo.local_business_city', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(306, 'seo.local_business_postal_code', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(307, 'seo.local_business_country', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(308, 'seo.local_business_phone', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(309, 'seo.local_business_geo_lng', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(310, 'seo.local_business_opening_hours', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(311, 'seo.photographer_job_title', 'Professional Photographer', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(312, 'seo.photographer_services', 'Professional Photography Services', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(313, 'seo.photographer_area_served', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(314, 'seo.photographer_same_as', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(315, 'seo.robots_default', 'index,follow', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(316, 'seo.canonical_base_url', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(317, 'seo.sitemap_enabled', 'true', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(318, 'seo.analytics_gtag', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(319, 'seo.analytics_gtm', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(320, 'seo.image_alt_auto', 'true', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(321, 'seo.image_copyright_notice', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(322, 'seo.image_license_url', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(323, 'seo.image_acquire_license_page', '', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(324, 'seo.preload_critical_images', 'true', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(325, 'seo.lazy_load_images', 'true', 'boolean', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(326, 'seo.structured_data_format', 'json-ld', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),

-- Social sharing settings
(327, 'social.enabled', '["bluesky","facebook","pinterest","telegram","threads","whatsapp","x"]', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37'),
(328, 'social.order', '["bluesky","facebook","pinterest","telegram","threads","whatsapp","x"]', 'string', '2025-08-25 12:42:37', '2025-08-25 12:42:37');

-- Insert film types data (using standard ENUM values: color_negative, color_reversal, bw)
INSERT INTO `films` (`id`, `brand`, `name`, `iso`, `format`, `type`) VALUES
-- B/W Films
(1, 'Ilford', 'HP5 Plus', 400, '35mm', 'bw'),
(2, 'Kodak', 'Tri-X', 400, '35mm', 'bw'),
(3, 'Kodak', 'T-Max 100', 100, '35mm', 'bw'),
(4, 'Kodak', 'T-Max 400', 400, '35mm', 'bw'),
(5, 'Foma', 'Fomapan 100', 100, '35mm', 'bw'),
(6, 'Foma', 'Fomapan 200', 200, '35mm', 'bw'),
(7, 'Foma', 'Fomapan 400', 400, '35mm', 'bw'),
(8, 'Ilford', 'XP2 Super', 400, '35mm', 'bw'),
-- Color Negative Films
(9, 'Kodak', 'Portra 160', 160, '35mm', 'color_negative'),
(10, 'Kodak', 'Portra 400', 400, '35mm', 'color_negative'),
(11, 'Kodak', 'Ektar 100', 100, '35mm', 'color_negative'),
(12, 'Kodak', 'Gold 200', 200, '35mm', 'color_negative'),
(13, 'Kodak', 'Ultramax 400', 400, '35mm', 'color_negative'),
(14, 'Fujifilm', 'Superia 400', 400, '35mm', 'color_negative'),
(15, 'Lomography', 'Color Negative 100', 100, '35mm', 'color_negative'),
-- Color Reversal (Slide) Films
(16, 'Fujifilm', 'Provia 100F', 100, '35mm', 'color_reversal'),
(17, 'Fujifilm', 'Velvia 50', 50, '35mm', 'color_reversal'),
(18, 'Fujifilm', 'Velvia 100', 100, '35mm', 'color_reversal'),
(19, 'Kodak', 'Ektachrome E100', 100, '35mm', 'color_reversal');

INSERT INTO `templates` (`id`, `name`, `slug`, `description`, `settings`, `libs`, `created_at`) VALUES
(1, 'Grid Classica', 'grid-classica', 'Layout a griglia responsivo - desktop 3 colonne, tablet 2, mobile 1', '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}', '["photoswipe"]', '2025-08-25 14:21:20'),
(2, 'Masonry Portfolio', 'masonry-portfolio', 'Layout masonry responsivo per portfolio - desktop 4 colonne, tablet 3, mobile 2', '{"layout":"grid","columns":{"desktop":4,"tablet":3,"mobile":2},"masonry":true,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.1,"allowPanToNext":true}}', '["photoswipe","masonry"]', '2025-08-25 14:21:20'),
(3, 'Magazine Split', 'magazine-split', 'Galleria a colonne con scorrimento infinito/masonry in stile magazine', '{"layout":"magazine","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":true,"magazine":{"durations":[60,72,84],"gap":20},"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.9,"spacing":0.10,"allowPanToNext":true}}', '["photoswipe"]', '2025-08-25 14:21:20'),
(4, 'Gallery Fullscreen', 'gallery-fullscreen', 'Layout fullscreen responsivo - desktop 2 colonne, tablet 1, mobile 1', '{"layout":"fullscreen","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":1.0,"spacing":0,"allowPanToNext":true}}', '["photoswipe"]', '2025-08-25 14:21:20'),
(5, 'Grid Compatta', 'grid-compatta', 'Layout compatto con molte colonne - desktop 5 colonne, tablet 3, mobile 2', '{"layout":"grid","columns":{"desktop":5,"tablet":3,"mobile":2},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":false,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12,"allowPanToNext":false}}', '["photoswipe"]', '2025-08-25 14:21:20'),
(6, 'Grid Ampia', 'grid-ampia', 'Layout con poche colonne per immagini grandi - desktop 2 colonne, tablet 1, mobile 1', '{"layout":"grid","columns":{"desktop":2,"tablet":1,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"share":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.85,"spacing":0.15,"allowPanToNext":true}}', '["photoswipe"]', '2025-08-25 14:21:20');

-- Set AUTO_INCREMENT values to match the SQLite sequence
ALTER TABLE `categories` AUTO_INCREMENT = 10;
ALTER TABLE `settings` AUTO_INCREMENT = 329;
ALTER TABLE `templates` AUTO_INCREMENT = 7;
ALTER TABLE `films` AUTO_INCREMENT = 45;

-- Analytics system tables
-- Cimaise Analytics Migration

-- Analytics sessions table
CREATE TABLE IF NOT EXISTS `analytics_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `ip_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `user_agent` text COLLATE utf8mb4_unicode_ci,
    `browser` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `browser_version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `platform` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `device_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `screen_resolution` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `country_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `region` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `referrer_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `referrer_url` text COLLATE utf8mb4_unicode_ci,
    `landing_page` text COLLATE utf8mb4_unicode_ci,
    `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `last_activity` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `page_views` int(11) DEFAULT 0,
    `duration` int(11) DEFAULT 0,
    `is_bot` tinyint(1) DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `session_id` (`session_id`),
    KEY `idx_analytics_sessions_started_at` (`started_at`),
    KEY `idx_analytics_sessions_country` (`country_code`),
    KEY `idx_analytics_sessions_device` (`device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics page views table
CREATE TABLE IF NOT EXISTS `analytics_pageviews` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `page_url` text COLLATE utf8mb4_unicode_ci NOT NULL,
    `page_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `page_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `album_id` int(11) DEFAULT NULL,
    `category_id` int(11) DEFAULT NULL,
    `tag_id` int(11) DEFAULT NULL,
    `load_time` int(11) DEFAULT NULL,
    `viewport_width` int(11) DEFAULT NULL,
    `viewport_height` int(11) DEFAULT NULL,
    `scroll_depth` int(11) DEFAULT 0,
    `time_on_page` int(11) DEFAULT 0,
    `viewed_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_analytics_pageviews_session_id` (`session_id`),
    KEY `idx_analytics_pageviews_viewed_at` (`viewed_at`),
    KEY `idx_analytics_pageviews_page_type` (`page_type`),
    KEY `idx_analytics_pageviews_album_id` (`album_id`),
    FOREIGN KEY (`session_id`) REFERENCES `analytics_sessions`(`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics events table (downloads, searches, etc.)
CREATE TABLE IF NOT EXISTS `analytics_events` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `event_action` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `event_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `event_value` int(11) DEFAULT NULL,
    `page_url` text COLLATE utf8mb4_unicode_ci,
    `album_id` int(11) DEFAULT NULL,
    `image_id` int(11) DEFAULT NULL,
    `occurred_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_analytics_events_session_id` (`session_id`),
    KEY `idx_analytics_events_type` (`event_type`),
    KEY `idx_analytics_events_occurred_at` (`occurred_at`),
    KEY `idx_analytics_events_album_id` (`album_id`),
    FOREIGN KEY (`session_id`) REFERENCES `analytics_sessions`(`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics daily summaries (pre-computed stats)
CREATE TABLE IF NOT EXISTS `analytics_daily_summary` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `date` date NOT NULL,
    `total_sessions` int(11) DEFAULT 0,
    `total_pageviews` int(11) DEFAULT 0,
    `unique_visitors` int(11) DEFAULT 0,
    `bounce_rate` decimal(5,2) DEFAULT 0.00,
    `avg_session_duration` int(11) DEFAULT 0,
    `top_pages` text COLLATE utf8mb4_unicode_ci,
    `top_countries` text COLLATE utf8mb4_unicode_ci,
    `top_browsers` text COLLATE utf8mb4_unicode_ci,
    `top_albums` text COLLATE utf8mb4_unicode_ci,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `date` (`date`),
    KEY `idx_analytics_daily_summary_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics settings table
CREATE TABLE IF NOT EXISTS `analytics_settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `setting_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `setting_value` text COLLATE utf8mb4_unicode_ci,
    `description` text COLLATE utf8mb4_unicode_ci,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default analytics settings
INSERT IGNORE INTO `analytics_settings` (`setting_key`, `setting_value`, `description`) VALUES
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
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `text_key` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `text_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'general',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_frontend_texts_key` (`text_key`),
  KEY `idx_frontend_texts_context` (`context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PLUGIN STATUS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `plugin_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_installed` tinyint(1) DEFAULT 1,
  `installed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_plugin_status_slug` (`slug`),
  KEY `idx_plugin_status_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LOGS TABLE (Structured Logging System)
-- ============================================

CREATE TABLE IF NOT EXISTS `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `level` int(11) NOT NULL,
  `level_name` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'app',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_level` (`level`),
  KEY `idx_logs_category` (`category`),
  KEY `idx_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DEFAULT FILTER SETTINGS
-- ============================================

INSERT IGNORE INTO `filter_settings` (`setting_key`, `setting_value`, `description`, `sort_order`) VALUES
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

-- ============================================
-- DEFAULT FRONTEND TEXTS (English)
-- ============================================

INSERT IGNORE INTO `frontend_texts` (`text_key`, `text_value`, `context`, `description`) VALUES
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
('nav.breadcrumb', 'Breadcrumb', 'navigation', 'Breadcrumb aria-label'),
-- Filters
('filter.all', 'All', 'filters', 'All filter option'),
('filter.categories', 'Categories', 'filters', 'Categories filter label'),
('filter.tags', 'Tags', 'filters', 'Tags filter label'),
('filter.cameras', 'Cameras', 'filters', 'Cameras filter label'),
('filter.lenses', 'Lenses', 'filters', 'Lenses filter label'),
('filter.films', 'Films', 'filters', 'Films filter label'),
('filter.locations', 'Locations', 'filters', 'Locations filter label'),
('filter.year', 'Year', 'filters', 'Year filter label'),
('filter.clear', 'Clear filters', 'filters', 'Clear all filters button'),
('filter.apply', 'Apply', 'filters', 'Apply filters button'),
('filter.no_results', 'No results found', 'filters', 'No results message'),
-- Album
('album.photos', 'photos', 'album', 'Photos count label'),
('album.photo', 'photo', 'album', 'Single photo label'),
('album.view', 'View album', 'album', 'View album button'),
('album.more_from', 'More from', 'album', 'More from category section title'),
('album.password_protected', 'Password Protected', 'album', 'Password protected label'),
('album.enter_password', 'Enter Password', 'album', 'Password field label'),
('album.wrong_password', 'Wrong password', 'album', 'Wrong password error'),
('album.unlock', 'Unlock', 'album', 'Unlock button'),
('album.nsfw_warning_short', '18+', 'album', 'Short NSFW warning'),
-- Pagination
('pagination.previous', 'Previous', 'pagination', 'Previous page button'),
('pagination.next', 'Next', 'pagination', 'Next page button'),
('pagination.page', 'Page', 'pagination', 'Page label'),
('pagination.of', 'of', 'pagination', 'Of separator'),
-- Footer
('footer.copyright', '© {year} All rights reserved', 'footer', 'Copyright text'),
('footer.privacy', 'Privacy Policy', 'footer', 'Privacy policy link'),
('footer.terms', 'Terms of Service', 'footer', 'Terms of service link'),
-- Lightbox
('lightbox.close', 'Close', 'lightbox', 'Close button'),
('lightbox.zoom', 'Zoom', 'lightbox', 'Zoom button'),
('lightbox.previous', 'Previous', 'lightbox', 'Previous image'),
('lightbox.next', 'Next', 'lightbox', 'Next image'),
('lightbox.download', 'Download', 'lightbox', 'Download button'),
-- Social Share
('share.title', 'Share', 'social', 'Share section title'),
('share.facebook', 'Share on Facebook', 'social', 'Facebook share'),
('share.twitter', 'Share on X', 'social', 'X/Twitter share'),
('share.pinterest', 'Pin on Pinterest', 'social', 'Pinterest share'),
('share.copy_link', 'Copy Link', 'social', 'Copy link button'),
('share.link_copied', 'Link copied!', 'social', 'Link copied confirmation'),
('share.share_on', 'Share on {network}', 'social', 'Share on network template'),
-- Search
('search.placeholder', 'Search...', 'search', 'Search input placeholder'),
('search.no_results', 'No results found', 'search', 'No search results'),
-- Errors
('error.404', 'Page not found', 'errors', '404 error title'),
('error.404_message', 'The page you are looking for does not exist.', 'errors', '404 error message'),
('error.500', 'Server error', 'errors', '500 error title'),
('error.500_message', 'Something went wrong. Please try again later.', 'errors', '500 error message'),
('error.go_home', 'Go to Homepage', 'errors', 'Go home button'),
-- General
('general.loading', 'Loading...', 'general', 'Loading indicator'),
('general.read_more', 'Read more', 'general', 'Read more link'),
('general.see_all', 'See all', 'general', 'See all link'),
('general.close', 'Close', 'general', 'Close button'),
('general.previous', 'Previous', 'general', 'Previous button'),
('general.next', 'Next', 'general', 'Next button'),
('general.no_cover', 'No cover', 'general', 'No cover image text'),
('general.yes', 'Yes', 'general', 'Yes button'),
('general.no', 'No', 'general', 'No button'),
('general.cancel', 'Cancel', 'general', 'Cancel button'),
('general.save', 'Save', 'general', 'Save button');

COMMIT;
