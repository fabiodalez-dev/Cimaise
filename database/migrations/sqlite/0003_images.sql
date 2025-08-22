-- Images and variants (SQLite version)

CREATE TABLE IF NOT EXISTS images (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  album_id INTEGER NOT NULL,
  original_path TEXT NOT NULL,
  file_hash TEXT NOT NULL,
  width INTEGER NOT NULL,
  height INTEGER NOT NULL,
  mime TEXT NOT NULL,
  alt_text TEXT NULL,
  caption TEXT NULL,
  exif TEXT NULL,
  camera_id INTEGER NULL,
  lens_id INTEGER NULL,
  film_id INTEGER NULL,
  developer_id INTEGER NULL,
  lab_id INTEGER NULL,
  custom_camera TEXT NULL,
  custom_lens TEXT NULL,
  custom_film TEXT NULL,
  custom_development TEXT NULL,
  custom_lab TEXT NULL,
  custom_scanner TEXT NULL,
  scan_resolution_dpi INTEGER NULL,
  scan_bit_depth INTEGER NULL,
  process TEXT DEFAULT 'digital',
  development_date TEXT NULL,
  iso INTEGER NULL,
  shutter_speed TEXT NULL,
  aperture REAL NULL,
  sort_order INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE SET NULL,
  FOREIGN KEY (lens_id) REFERENCES lenses(id) ON DELETE SET NULL,
  FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE SET NULL,
  FOREIGN KEY (developer_id) REFERENCES developers(id) ON DELETE SET NULL,
  FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_images_album ON images(album_id);
CREATE INDEX IF NOT EXISTS idx_images_sort ON images(sort_order);
CREATE INDEX IF NOT EXISTS idx_images_hash ON images(file_hash);
CREATE INDEX IF NOT EXISTS idx_images_camera ON images(camera_id);
CREATE INDEX IF NOT EXISTS idx_images_lens ON images(lens_id);
CREATE INDEX IF NOT EXISTS idx_images_film ON images(film_id);
CREATE INDEX IF NOT EXISTS idx_images_developer ON images(developer_id);
CREATE INDEX IF NOT EXISTS idx_images_lab ON images(lab_id);
CREATE INDEX IF NOT EXISTS idx_images_process ON images(process);
CREATE INDEX IF NOT EXISTS idx_images_iso ON images(iso);

CREATE TABLE IF NOT EXISTS image_variants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  image_id INTEGER NOT NULL,
  variant TEXT NOT NULL,
  format TEXT NOT NULL,
  path TEXT NOT NULL,
  width INTEGER NOT NULL,
  height INTEGER NOT NULL,
  size_bytes INTEGER NOT NULL,
  UNIQUE(image_id, variant, format),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
);