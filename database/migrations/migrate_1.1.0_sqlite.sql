-- Migration: 1.1.0
-- Database: SQLite
-- Description: Add update system tables (migrations and update_logs)

-- Migrations table to track executed migrations
CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT NOT NULL UNIQUE,
    filename TEXT NOT NULL,
    batch INTEGER NOT NULL DEFAULT 1,
    executed_at TEXT DEFAULT (datetime('now'))
);

-- Update logs table to track update history
CREATE TABLE IF NOT EXISTS update_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_version TEXT NOT NULL,
    to_version TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'started',
    backup_path TEXT,
    error_message TEXT,
    started_at TEXT DEFAULT (datetime('now')),
    completed_at TEXT,
    executed_by INTEGER,
    FOREIGN KEY (executed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Index for faster history lookups
CREATE INDEX IF NOT EXISTS idx_update_logs_started_at ON update_logs(started_at DESC);
