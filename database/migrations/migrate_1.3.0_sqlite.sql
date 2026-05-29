-- Migration 1.3.0 (SQLite) — Full-text search
--
-- The FTS infrastructure (FTS5 virtual tables albums_fts / images_fts plus the
-- AFTER INSERT/UPDATE/DELETE triggers that keep them in sync) is created and
-- self-healed at runtime by App\Services\SearchIndexer::ensureReady().
--
-- It is intentionally NOT created here: trigger bodies contain semicolons, and
-- the migration runner (Updater::runMigrations) splits files on ';', which would
-- shred a CREATE TRIGGER ... BEGIN ... END. SearchIndexer runs the whole block
-- through one semicolon-safe PDO::exec() instead, and degrades gracefully to a
-- LIKE search when the SQLite build lacks FTS5.
--
-- This marker records that the 1.3.0 search schema has been reached.

INSERT OR IGNORE INTO settings (key, value, type) VALUES
  ('search_fts_schema', '1', 'string');
