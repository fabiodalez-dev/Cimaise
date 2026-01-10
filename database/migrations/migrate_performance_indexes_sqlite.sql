-- ============================================
-- Performance Optimization: Additional Indexes
-- SQLite Migration
-- ============================================

-- Composite index for image_variants: faster variant lookups
CREATE INDEX IF NOT EXISTS idx_image_variants_composite ON image_variants(image_id, variant, format);

-- Cover index for published albums listing (avoid table scan)
CREATE INDEX IF NOT EXISTS idx_albums_cover_published ON albums(is_published, published_at DESC, id) WHERE is_published = 1;

-- Index for album category lookups with published filter
CREATE INDEX IF NOT EXISTS idx_albums_category_published ON albums(category_id, is_published, published_at DESC);

-- Faster image counting by album
CREATE INDEX IF NOT EXISTS idx_images_album_count ON images(album_id) WHERE album_id IS NOT NULL;

-- Analytics optimization: session lookup by date range
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_date_range ON analytics_sessions(started_at, session_id);

-- Faster pageview aggregation
CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_date ON analytics_pageviews(viewed_at, page_type, album_id);

-- Settings cache hint (already has unique key, but add covering index for faster reads)
CREATE INDEX IF NOT EXISTS idx_settings_key_value ON settings(key, value) WHERE type = 'string';
