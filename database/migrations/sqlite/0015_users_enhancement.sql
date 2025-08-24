-- Enhance users table for multi-user system with roles and additional fields (SQLite)
ALTER TABLE users ADD COLUMN first_name TEXT NULL;
ALTER TABLE users ADD COLUMN last_name TEXT NULL;
ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1;
ALTER TABLE users ADD COLUMN last_login TEXT NULL;
ALTER TABLE users ADD COLUMN updated_at TEXT NULL;