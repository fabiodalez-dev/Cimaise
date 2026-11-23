CREATE TABLE IF NOT EXISTS album_layouts (
    album_id INTEGER NOT NULL PRIMARY KEY,
    draft_json TEXT NULL,
    published_json TEXT NULL,
    revision INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
);
