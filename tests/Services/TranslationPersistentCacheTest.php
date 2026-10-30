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
