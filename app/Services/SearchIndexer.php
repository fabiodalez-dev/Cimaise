<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;
use Throwable;

/**
 * Owns the full-text search infrastructure and keeps it healthy at runtime.
 *
 * Why runtime instead of a migration file: the SQLite side needs FTS5 virtual
 * tables plus AFTER INSERT/UPDATE/DELETE triggers, and trigger bodies contain
 * semicolons. The migration runner (Updater::runMigrations) splits files naively
 * on ';', which would shred a trigger. Database::execSqlFile() runs the whole
 * SQLite file through one PDO::exec() (semicolon-safe), and so does this class.
 *
 * The setup is idempotent: CREATE ... IF NOT EXISTS for tables, DROP TRIGGER
 * IF EXISTS before each CREATE TRIGGER, and an FTS5 'rebuild' to repopulate.
 * On MySQL the FULLTEXT indexes are engine-maintained, so we only add them once
 * (guarded by information_schema) and the engine keeps them in sync afterwards.
 */
final class SearchIndexer
{
    /** Albums: columns fed into the index. */
    private const ALBUM_COLUMNS = ['title', 'excerpt', 'body'];

    /** Images: columns fed into the index. */
    private const IMAGE_COLUMNS = ['caption', 'alt_text', 'custom_camera', 'custom_lens', 'custom_film'];

    /** Process-local guard so a single request does the existence check at most once. */
    private static ?bool $ready = null;

    /** Whether SQLite has FTS5 compiled in. Null until first probe. */
    private static ?bool $ftsAvailable = null;

    public function __construct(private Database $db)
    {
    }

    /** Clear the process-local guards. Test-only: each test uses a fresh database. */
    public static function resetForTesting(): void
    {
        self::$ready = null;
        self::$ftsAvailable = null;
    }

    /**
     * Ensure the search infrastructure exists. Cheap on the hot path: after the
     * first successful setup it returns immediately via the static guard.
     *
     * @return bool True when a usable index is available (FTS or, on SQLite
     *              without FTS5, the source tables for a LIKE fallback).
     */
    public function ensureReady(): bool
    {
        if (self::$ready === true) {
            return true;
        }

        try {
            if ($this->db->isSqlite()) {
                self::$ready = $this->ensureSqlite();
            } else {
                self::$ready = $this->ensureMysql();
            }
        } catch (Throwable $e) {
            error_log('[SearchIndexer] ensureReady failed: ' . $e->getMessage());
            self::$ready = false;
        }

        return self::$ready ?? false;
    }

    /** Whether SQLite FTS5 is usable. On MySQL this is always true (FULLTEXT). */
    public function ftsAvailable(): bool
    {
        if ($this->db->isMySQL()) {
            return true;
        }
        return self::$ftsAvailable === true;
    }

    /** Force a full rebuild of the FTS content (used by tests and a reindex command). */
    public function rebuild(): void
    {
        if ($this->db->isSqlite()) {
            if (!$this->probeFts5()) {
                return;
            }
            $this->db->pdo()->exec("INSERT INTO albums_fts(albums_fts) VALUES('rebuild');");
            $this->db->pdo()->exec("INSERT INTO images_fts(images_fts) VALUES('rebuild');");
        }
        // MySQL FULLTEXT is engine-maintained; nothing to rebuild.
    }

    // --- SQLite -------------------------------------------------------------

    private function ensureSqlite(): bool
    {
        if (!$this->probeFts5()) {
            // No FTS5 in this SQLite build: the LIKE fallback queries the source
            // tables directly, so there is nothing to create. Still "ready".
            return true;
        }

        $pdo = $this->db->pdo();
        $exists = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='albums_fts'"
        )->fetchColumn();

        if ($exists !== false) {
            return true; // tables + triggers already in place
        }

        // One semicolon-safe block: tables, triggers, initial populate.
        $pdo->exec($this->sqliteSetupSql());
        return true;
    }

    /** Detect FTS5 availability once and cache the result. */
    private function probeFts5(): bool
    {
        if (self::$ftsAvailable !== null) {
            return self::$ftsAvailable;
        }
        try {
            $probe = new PDO('sqlite::memory:');
            $probe->exec('CREATE VIRTUAL TABLE _fts_probe USING fts5(x)');
            self::$ftsAvailable = true;
        } catch (Throwable $e) {
            self::$ftsAvailable = false;
        }
        return self::$ftsAvailable;
    }

    private function sqliteSetupSql(): string
    {
        $albumCols = implode(', ', self::ALBUM_COLUMNS);
        $imageCols = implode(', ', self::IMAGE_COLUMNS);

        $albumNew = $this->columnList('new', self::ALBUM_COLUMNS);
        $albumOld = $this->columnList('old', self::ALBUM_COLUMNS);
        $imageNew = $this->columnList('new', self::IMAGE_COLUMNS);
        $imageOld = $this->columnList('old', self::IMAGE_COLUMNS);

        return <<<SQL
CREATE VIRTUAL TABLE IF NOT EXISTS albums_fts USING fts5(
  {$albumCols},
  content='albums', content_rowid='id',
  tokenize='unicode61 remove_diacritics 2'
);

DROP TRIGGER IF EXISTS albums_fts_ai;
CREATE TRIGGER albums_fts_ai AFTER INSERT ON albums BEGIN
  INSERT INTO albums_fts(rowid, {$albumCols}) VALUES (new.id, {$albumNew});
END;

DROP TRIGGER IF EXISTS albums_fts_ad;
CREATE TRIGGER albums_fts_ad AFTER DELETE ON albums BEGIN
  INSERT INTO albums_fts(albums_fts, rowid, {$albumCols}) VALUES ('delete', old.id, {$albumOld});
END;

DROP TRIGGER IF EXISTS albums_fts_au;
CREATE TRIGGER albums_fts_au AFTER UPDATE ON albums BEGIN
  INSERT INTO albums_fts(albums_fts, rowid, {$albumCols}) VALUES ('delete', old.id, {$albumOld});
  INSERT INTO albums_fts(rowid, {$albumCols}) VALUES (new.id, {$albumNew});
END;

CREATE VIRTUAL TABLE IF NOT EXISTS images_fts USING fts5(
  {$imageCols},
  content='images', content_rowid='id',
  tokenize='unicode61 remove_diacritics 2'
);

DROP TRIGGER IF EXISTS images_fts_ai;
CREATE TRIGGER images_fts_ai AFTER INSERT ON images BEGIN
  INSERT INTO images_fts(rowid, {$imageCols}) VALUES (new.id, {$imageNew});
END;

DROP TRIGGER IF EXISTS images_fts_ad;
CREATE TRIGGER images_fts_ad AFTER DELETE ON images BEGIN
  INSERT INTO images_fts(images_fts, rowid, {$imageCols}) VALUES ('delete', old.id, {$imageOld});
END;

DROP TRIGGER IF EXISTS images_fts_au;
CREATE TRIGGER images_fts_au AFTER UPDATE ON images BEGIN
  INSERT INTO images_fts(images_fts, rowid, {$imageCols}) VALUES ('delete', old.id, {$imageOld});
  INSERT INTO images_fts(rowid, {$imageCols}) VALUES (new.id, {$imageNew});
END;

INSERT INTO albums_fts(albums_fts) VALUES('rebuild');
INSERT INTO images_fts(images_fts) VALUES('rebuild');
SQL;
    }

    /**
     * "new.title, new.excerpt, ..." for trigger VALUES lists.
     *
     * @param string[] $columns
     */
    private function columnList(string $alias, array $columns): string
    {
        return implode(', ', array_map(static fn(string $c): string => "{$alias}.{$c}", $columns));
    }

    // --- MySQL --------------------------------------------------------------

    private function ensureMysql(): bool
    {
        $pdo = $this->db->pdo();

        if (!$this->mysqlFulltextExists('albums', 'ft_albums_search')) {
            $cols = '`' . implode('`, `', self::ALBUM_COLUMNS) . '`';
            $pdo->exec("ALTER TABLE `albums` ADD FULLTEXT `ft_albums_search` ({$cols})");
        }
        if (!$this->mysqlFulltextExists('images', 'ft_images_search')) {
            $cols = '`' . implode('`, `', self::IMAGE_COLUMNS) . '`';
            $pdo->exec("ALTER TABLE `images` ADD FULLTEXT `ft_images_search` ({$cols})");
        }
        return true;
    }

    private function mysqlFulltextExists(string $table, string $indexName): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $indexName]);
        return $stmt->fetchColumn() !== false;
    }
}
