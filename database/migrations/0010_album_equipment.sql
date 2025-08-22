-- Album equipment relationships
CREATE TABLE album_camera (
    album_id INTEGER NOT NULL,
    camera_id INTEGER NOT NULL,
    PRIMARY KEY (album_id, camera_id),
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
);

CREATE TABLE album_lens (
    album_id INTEGER NOT NULL,
    lens_id INTEGER NOT NULL,
    PRIMARY KEY (album_id, lens_id),
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    FOREIGN KEY (lens_id) REFERENCES lenses(id) ON DELETE CASCADE
);

CREATE TABLE album_film (
    album_id INTEGER NOT NULL,
    film_id INTEGER NOT NULL,
    PRIMARY KEY (album_id, film_id),
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE CASCADE
);

CREATE TABLE album_developer (
    album_id INTEGER NOT NULL,
    developer_id INTEGER NOT NULL,
    PRIMARY KEY (album_id, developer_id),
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    FOREIGN KEY (developer_id) REFERENCES developers(id) ON DELETE CASCADE
);

CREATE TABLE album_lab (
    album_id INTEGER NOT NULL,
    lab_id INTEGER NOT NULL,
    PRIMARY KEY (album_id, lab_id),
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE CASCADE
);