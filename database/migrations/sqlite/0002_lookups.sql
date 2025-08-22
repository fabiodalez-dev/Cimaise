-- Lookup tables (SQLite version)

CREATE TABLE IF NOT EXISTS cameras (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  make TEXT NOT NULL,
  model TEXT NOT NULL,
  UNIQUE(make, model)
);

CREATE TABLE IF NOT EXISTS lenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  brand TEXT NOT NULL,
  model TEXT NOT NULL,
  focal_min REAL NULL,
  focal_max REAL NULL,
  aperture_min REAL NULL,
  UNIQUE(brand, model)
);

CREATE TABLE IF NOT EXISTS films (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  brand TEXT NOT NULL,
  name TEXT NOT NULL,
  iso INTEGER NULL,
  format TEXT DEFAULT '35mm',
  type TEXT NOT NULL DEFAULT 'color_negative',
  UNIQUE(brand, name, iso, format)
);

CREATE TABLE IF NOT EXISTS developers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  process TEXT DEFAULT 'BW',
  notes TEXT NULL,
  UNIQUE(name, process)
);

CREATE TABLE IF NOT EXISTS labs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  city TEXT NULL,
  country TEXT NULL,
  UNIQUE(name, city)
);