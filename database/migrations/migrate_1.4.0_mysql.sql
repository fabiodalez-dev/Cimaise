-- Migration 1.4.0 (MySQL / MariaDB) — Curated collections
--
-- Hand-picked photos that can span multiple albums.

CREATE TABLE IF NOT EXISTS `collections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(190) NOT NULL,
  `slug` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `cover_image_id` INT UNSIGNED NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_collections_slug` (`slug`),
  KEY `idx_collections_published` (`is_published`, `sort_order`),
  KEY `idx_collections_cover` (`cover_image_id`),
  CONSTRAINT `fk_collections_cover` FOREIGN KEY (`cover_image_id`) REFERENCES `images`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collection_images` (
  `collection_id` INT UNSIGNED NOT NULL,
  `image_id` INT UNSIGNED NOT NULL,
  `sort_order` INT DEFAULT 0,
  PRIMARY KEY (`collection_id`, `image_id`),
  KEY `idx_collection_images_image` (`image_id`),
  KEY `idx_collection_images_order` (`collection_id`, `sort_order`),
  CONSTRAINT `fk_collection_images_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_collection_images_image` FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
