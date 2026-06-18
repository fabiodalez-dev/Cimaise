-- Migration 1.4.13 (SQLite) — allow 'jxl' in image_variants.format (#109)
--
-- SQLite cannot ALTER a CHECK constraint in place, so we rebuild the table with
-- the widened CHECK and copy the rows across. Each statement has NO inner
-- semicolons so it passes cleanly through the migration runner's splitter.
-- Column list + constraints mirror schema.sqlite.sql exactly (the table has no
-- secondary indexes beyond the inline PK / UNIQUE).

PRAGMA foreign_keys=OFF;

CREATE TABLE image_variants_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  image_id INTEGER NOT NULL,
  variant TEXT NOT NULL,
  format TEXT NOT NULL CHECK(format IN ('avif', 'webp', 'jpg', 'jxl')),
  path TEXT NOT NULL,
  width INTEGER NOT NULL,
  height INTEGER NOT NULL,
  size_bytes INTEGER NOT NULL,
  UNIQUE(image_id, variant, format),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
);

INSERT INTO image_variants_new (id, image_id, variant, format, path, width, height, size_bytes) SELECT id, image_id, variant, format, path, width, height, size_bytes FROM image_variants;

DROP TABLE image_variants;

ALTER TABLE image_variants_new RENAME TO image_variants;

PRAGMA foreign_keys=ON;
