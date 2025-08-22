-- Pivot table for album <-> categories (SQLite)
CREATE TABLE IF NOT EXISTS album_category (
  album_id INTEGER NOT NULL,
  category_id INTEGER NOT NULL,
  PRIMARY KEY(album_id, category_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

