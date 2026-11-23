-- Optional visual album layouts: one row per album, draft and published JSON,
-- optimistic locking through `revision`.
CREATE TABLE IF NOT EXISTS `album_layouts` (
  `album_id` INT UNSIGNED NOT NULL,
  `draft_json` MEDIUMTEXT NULL,
  `published_json` MEDIUMTEXT NULL,
  `revision` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`album_id`),
  CONSTRAINT `fk_album_layouts_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
