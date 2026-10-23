<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Support\Database;
use App\Support\QueryCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsPostWriteCacheTest extends TestCase
{
    public static function savedValues(): iterable
    {
        yield 'uploaded font registry' => ['typography.custom_fonts', ['custom-fixture' => ['name' => 'Uploaded Test', 'files' => [400 => 'fixture.woff2']]]];
        yield 'batch site title' => ['site.title', 'Batch title', true];
        yield 'batch async flag' => ['image.variants_async', false, true];
        yield 'site title' => ['site.title', 'New title'];
        yield 'asynchronous generation' => ['image.variants_async', false];
    }

    #[DataProvider('savedValues')]
    public function testConcurrentCacheFillCannotHideSuccessfullySavedSettings(string $key, mixed $value, bool $batch = false): void
    {
        $directory = sys_get_temp_dir() . '/cimaise-settings-cache-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $cacheProperty = new ReflectionProperty(SettingsService::class, 'cache');
        $instanceProperty = new ReflectionProperty(QueryCache::class, 'instance');
        $oldSettingsCache = $cacheProperty->getValue();
        $oldQueryCache = $instanceProperty->getValue();
        $queryCache = (new ReflectionClass(QueryCache::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(QueryCache::class, 'useApcu'))->setValue($queryCache, false);
        (new ReflectionProperty(QueryCache::class, 'cacheDir'))->setValue($queryCache, $directory);
        $instanceProperty->setValue(null, $queryCache);
        $cacheProperty->setValue(null, null);
        try {
            $db = new Database(database: ':memory:', isSqlite: true);
            $pdo = new class('sqlite::memory:') extends PDO {
                public ?Closure $beforeWrite = null;

                public function prepare(string $query, array $options = []): PDOStatement|false
                {
                    if (str_contains($query, 'INTO settings') && $this->beforeWrite !== null) {
                        $callback = $this->beforeWrite;
                        $this->beforeWrite = null;
                        $callback();
                    }
                    return parent::prepare($query, $options);
                }
            };
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
            (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
            $settings = new SettingsService($db);
            $oldValues = $settings->all();
            // Another request fills the shared cache with its pre-save snapshot.
            $pdo->beforeWrite = static fn () => $queryCache->set('all_settings', $oldValues);
            if ($batch) { $settings->setMany([$key => $value]); } else { $settings->set($key, $value); }
            self::assertSame($value, json_decode((string) $db->query('SELECT value FROM settings WHERE key=?', [$key])->fetchColumn(), true));
            self::assertSame($value, $settings->get($key));
            $cacheProperty->setValue(null, null); // A subsequent HTTP request.
            self::assertSame($value, (new SettingsService($db))->get($key));
        } finally {
            $cacheProperty->setValue(null, $oldSettingsCache);
            $instanceProperty->setValue(null, $oldQueryCache);
            foreach (glob($directory . '/*.cache') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
