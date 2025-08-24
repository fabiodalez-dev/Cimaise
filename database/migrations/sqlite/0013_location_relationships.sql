-- Album and image locations relationships (SQLite)

-- Create album_location table
CREATE TABLE IF NOT EXISTS album_location (
  album_id INTEGER NOT NULL,
  location_id INTEGER NOT NULL,
  PRIMARY KEY (album_id, location_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
);

-- Create image_location table
CREATE TABLE IF NOT EXISTS image_location (
  image_id INTEGER NOT NULL,
  location_id INTEGER NOT NULL,
  PRIMARY KEY (image_id, location_id),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
);
