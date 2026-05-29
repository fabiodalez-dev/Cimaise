-- Migration 1.3.0 (MySQL / MariaDB) — Full-text search
--
-- For symmetry with the SQLite side, the FULLTEXT indexes on albums and images
-- are created and guarded at runtime by App\Services\SearchIndexer::ensureReady()
-- (it checks information_schema before issuing ALTER ... ADD FULLTEXT, so it runs
-- once and is safe to call on every request). FULLTEXT on InnoDB is engine-
-- maintained, so there are no triggers to worry about.
--
-- This marker records that the 1.3.0 search schema has been reached.

INSERT IGNORE INTO `settings` (`key`, `value`, `type`) VALUES
  ('search_fts_schema', '1', 'string');
