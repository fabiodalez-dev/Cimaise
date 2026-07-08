-- Migration 1.4.19 (MySQL / MariaDB) — indexes for path-based media lookups
--
-- UploadService::deleteOriginalIfUnreferenced() probes images.original_path
-- once per deleted file, and ProtectedMediaStorage::deleteVariantCopies() /
-- hasPublicReference() probe image_variants.path (per variant, also from the
-- daily protected-storage reconciliation). Both columns were unindexed, so
-- every probe was a full table scan — O(deleted × library) on bulk deletes.
--
-- Prefix length 191: keeps the key under the 767-byte cap of MySQL 5.7
-- installs still on the COMPACT row format (utf8mb4 = 4 bytes/char); equality
-- probes only need the prefix to be selective.
--
-- Note: CREATE INDEX has no portable "IF NOT EXISTS" across MySQL 8 and
-- MariaDB. The migration runner records each version once, so these run a
-- single time per install.

CREATE INDEX `idx_images_original_path` ON `images` (`original_path`(191));
CREATE INDEX `idx_image_variants_path` ON `image_variants` (`path`(191));
