<?php

declare(strict_types=1);

use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

/**
 * A home rebuild that started before an admin change must not store its
 * (stale) page after the change invalidated the home: invalidateHome() waits
 * for the rebuild lock that every home rebuild holds.
 */
final class HomeInvalidationLockTest extends TestCase
{
    /** @var callable */
    private $restoreQueryCache;
    private string $marker;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Support/TempQueryCache.php';
        $this->restoreQueryCache = \TempQueryCache::install();
        $this->marker = sys_get_temp_dir() . '/cimaise-home-rebuild-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        ($this->restoreQueryCache)();
        foreach ([$this->marker . '.ready', $this->marker . '.done'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testInvalidationWaitsForARunningHomeRebuild(): void
    {
        $lockFile = dirname(__DIR__, 2) . '/storage/tmp/home_cache_warm.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0775, true);
        }
        // A separate process plays the running rebuild: it holds the lock,
        // and records when it is done just before releasing it.
        $script = '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); touch($argv[2] . ".ready");'
            . ' usleep(700000); touch($argv[2] . ".done"); flock($h, LOCK_UN);';
        $process = proc_open([PHP_BINARY, '-r', $script, $lockFile, $this->marker], [], $pipes);
        self::assertIsResource($process);
        $deadline = microtime(true) + 5;
        while (!is_file($this->marker . '.ready') && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFileExists($this->marker . '.ready', 'the rebuild process did not start');

        $db = new Database(database: ':memory:', isSqlite: true);
        $db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $cache = new PageCacheService(new SettingsService($db), $db);
        $cache->invalidateHome(false);

        self::assertFileExists($this->marker . '.done', 'invalidateHome() ran while the home rebuild still held its lock');
        proc_close($process);
    }
}
