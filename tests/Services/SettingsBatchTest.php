<?php
declare(strict_types=1);

use App\Services\SettingsService;
use App\Support\Database;
use App\Support\QueryCache;
use PHPUnit\Framework\TestCase;

final class SettingsBatchTest extends TestCase
{
    public function testBatchSkipsUnchangedWritesAndRollsBackOnFailure(): void
    {
        $oldSettings = (new ReflectionProperty(SettingsService::class, 'cache'))->getValue();
        $oldQueryCache = (new ReflectionProperty(QueryCache::class, 'instance'))->getValue();
        $directory = sys_get_temp_dir() . '/cimaise-batch-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $cache = (new ReflectionClass(QueryCache::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(QueryCache::class, 'useApcu'))->setValue($cache, false);
        (new ReflectionProperty(QueryCache::class, 'cacheDir'))->setValue($cache, $directory);
        (new ReflectionProperty(QueryCache::class, 'instance'))->setValue(null, $cache);
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, null);
        try {
            $db = new Database(database: ':memory:', isSqlite: true);
            $pdo = new class('sqlite::memory:') extends PDO {
                public int $writes = 0;
                public int $reads = 0;
                public int $transactions = 0;
                public bool $busyOnce = false;
                public function beginTransaction(): bool
                {
                    $this->transactions++;
                    return parent::beginTransaction();
                }
                public function prepare(string $query, array $options = []): PDOStatement|false
                {
                    if (str_contains($query, 'INTO settings')) {
                        $this->writes++;
                        if ($this->busyOnce) {
                            $this->busyOnce = false;
                            $error = new PDOException('database is locked');
                            $error->errorInfo = ['HY000', 5, 'database is locked'];
                            throw $error;
                        }
                    }
                    if (str_starts_with($query, 'SELECT')) { $this->reads++; }
                    return parent::prepare($query, $options);
                }
            };
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT, type TEXT, updated_at TEXT)');
            (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
            $settings = new SettingsService($db);
            $values = ['site.title' => 'Batch', 'image.variants_async' => false] + array_fill_keys(array_map(static fn (int $i): string => 'fixture.' . $i, range(1, 42)), 'value');
            $settings->setMany($values);
            self::assertSame(44, $pdo->writes);
            self::assertSame(2, $pdo->reads, 'One snapshot and one post-commit cache reload');
            self::assertSame(1, $pdo->transactions);
            self::assertFalse($settings->get('image.variants_async'));
            $settings->setMany($values);
            self::assertSame(44, $pdo->writes, 'Unchanged values must not be rewritten');
            self::assertSame(3, $pdo->reads, 'No cache reload for an unchanged batch');

            $pdo->exec("CREATE TRIGGER reject_setting BEFORE INSERT ON settings WHEN NEW.key='blocked' BEGIN SELECT RAISE(ABORT, 'blocked'); END");
            try {
                $settings->setMany(['site.title' => 'Must roll back', 'blocked' => true]);
                self::fail('Expected batch failure');
            } catch (PDOException) {
                self::assertFalse($pdo->inTransaction());
                self::assertSame('Batch', $settings->get('site.title'));
                self::assertSame('"Batch"', $pdo->query("SELECT value FROM settings WHERE key='site.title'")->fetchColumn());
            }
            $transactions = $pdo->transactions;
            $pdo->busyOnce = true;
            $settings->setMany(['site.title' => 'Retried']);
            self::assertSame($transactions + 2, $pdo->transactions, 'Retry the complete rolled-back transaction');
            self::assertSame('Retried', $settings->get('site.title'));
        } finally {
            (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $oldSettings);
            (new ReflectionProperty(QueryCache::class, 'instance'))->setValue(null, $oldQueryCache);
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
