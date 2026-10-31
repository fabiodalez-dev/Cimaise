<?php

declare(strict_types=1);

use App\Services\TranslationService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/TempQueryCache.php';

final class TranslationPersistentCacheTest extends TestCase
{
    private Closure $restoreCache;
    private Database $db;

    protected function setUp(): void
    {
        $this->restoreCache = TempQueryCache::install();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec('CREATE TABLE frontend_texts (id INTEGER PRIMARY KEY AUTOINCREMENT, text_key TEXT UNIQUE, text_value TEXT, context TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->pdo()->exec("INSERT INTO frontend_texts (text_key, text_value, context) VALUES ('zz.perf_test', 'first', 'general')");
    }

    protected function tearDown(): void
    {
        ($this->restoreCache)();
    }

    public function testMergedTranslationsAreReusedAcrossRequests(): void
    {
        self::assertSame('first', (new TranslationService($this->db))->get('zz.perf_test'));

        // A raw write that bypasses the service is not seen: the next request
        // is served from the persistent cache, without re-reading the DB/JSON.
        $this->db->pdo()->exec("UPDATE frontend_texts SET text_value = 'raw' WHERE text_key = 'zz.perf_test'");
        $second = new TranslationService($this->db);
        self::assertSame('first', $second->get('zz.perf_test'));
        self::assertNotSame('nav.home', $second->get('nav.home'), 'JSON base translations are part of the cached set');
    }

    public function testASetBuiltWhileTheDatabaseFailedIsNotCached(): void
    {
        $this->db->pdo()->exec('ALTER TABLE frontend_texts RENAME TO frontend_texts_away');
        self::assertSame('zz.perf_test', (new TranslationService($this->db))->get('zz.perf_test'), 'Falls back to the key');

        $this->db->pdo()->exec('ALTER TABLE frontend_texts_away RENAME TO frontend_texts');
        self::assertSame('first', (new TranslationService($this->db))->get('zz.perf_test'), 'The JSON-only set was not frozen in the cache');
    }

    public function testCachedSetsAreScopedToTheConfiguredDatabase(): void
    {
        self::assertSame('first', (new TranslationService($this->db))->get('zz.perf_test'));

        // Another site's database (file-backed: every :memory: DB shares one identity).
        $file = sys_get_temp_dir() . '/cimaise-tr-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $onDisk = new Database(database: $file, isSqlite: true);
            $onDisk->pdo()->exec('CREATE TABLE frontend_texts (id INTEGER PRIMARY KEY AUTOINCREMENT, text_key TEXT UNIQUE, text_value TEXT, context TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
            $onDisk->pdo()->exec("INSERT INTO frontend_texts (text_key, text_value, context) VALUES ('zz.perf_test', 'other site', 'general')");
            self::assertSame('other site', (new TranslationService($onDisk))->get('zz.perf_test'));
        } finally {
            @unlink($file);
        }
    }

    public function testEditingATranslationFileChangesTheContentVersion(): void
    {
        $file = dirname(__DIR__, 2) . '/storage/translations/en.json';
        self::assertFileExists($file);
        $mtime = (int) filemtime($file);
        $before = TranslationService::cacheGeneration();
        try {
            touch($file, $mtime + 5);
            clearstatcache();
            self::assertNotSame($before, TranslationService::cacheGeneration(), 'A JSON edit must change the HTML validator');
        } finally {
            touch($file, $mtime);
            clearstatcache();
        }
    }

    public function testEveryServiceWriteInvalidatesAllCachedSets(): void
    {
        self::assertSame('first', (new TranslationService($this->db))->get('zz.perf_test'));

        (new TranslationService($this->db))->set('zz.perf_test', 'second');
        self::assertSame('second', (new TranslationService($this->db))->get('zz.perf_test'));

        $this->db->pdo()->exec("UPDATE frontend_texts SET text_value = 'imported' WHERE text_key = 'zz.perf_test'");
        TranslationService::invalidatePersistentCache();
        self::assertSame('imported', (new TranslationService($this->db))->get('zz.perf_test'));
    }
}
