-- Migration 1.4.0 (SQLite) — Curated collections
--
-- Hand-picked photos that can span multiple albums. CREATE TABLE / CREATE INDEX
-- have no inner semicolons, so they pass cleanly through the migration runner's
-- statement splitter (unlike the FTS triggers, which are runtime-managed).

CREATE TABLE IF NOT EXISTS collections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT,
  cover_image_id INTEGER,
  is_published INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cover_image_id) REFERENCES images(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_collections_published ON collections(is_published, sort_order);

CREATE TABLE IF NOT EXISTS collection_images (
  collection_id INTEGER NOT NULL,
  image_id INTEGER NOT NULL,
  sort_order INTEGER DEFAULT 0,
  PRIMARY KEY (collection_id, image_id),
  FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_collection_images_image ON collection_images(image_id);
CREATE INDEX IF NOT EXISTS idx_collection_images_order ON collection_images(collection_id, sort_order);
