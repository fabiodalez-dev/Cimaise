-- Migration 1.4.19 (SQLite) — indexes for path-based media lookups
--
-- UploadService::deleteOriginalIfUnreferenced() probes images.original_path
-- once per deleted file, and ProtectedMediaStorage::deleteVariantCopies() /
-- hasPublicReference() probe image_variants.path (per variant, also from the
-- daily protected-storage reconciliation). Both columns were unindexed, so
-- every probe was a full table scan — O(deleted × library) on bulk deletes.

CREATE INDEX IF NOT EXISTS idx_images_original_path ON images(original_path);
CREATE INDEX IF NOT EXISTS idx_image_variants_path ON image_variants(path);
