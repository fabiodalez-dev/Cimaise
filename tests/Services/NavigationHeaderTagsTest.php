<?php

declare(strict_types=1);

use App\Services\NavigationService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/TempQueryCache.php';

final class NavigationHeaderTagsTest extends TestCase
{
    private Closure $restoreCache;

    protected function setUp(): void
    {
        $this->restoreCache = TempQueryCache::install();
        NavigationService::invalidateTagsCache();
    }

    protected function tearDown(): void
    {
        NavigationService::invalidateTagsCache();
        ($this->restoreCache)();
    }

    public function testHeaderTagsAreSiteWideCachedAndOnlyCountPublicAlbums(): void
    {
        $db = new Database(database: ':memory:', isSqlite: true);
        $db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $db->pdo()->exec("INSERT INTO tags (id,name,slug) VALUES (1,'Street','street'),(2,'Secret','secret'),(3,'Late','late');
            INSERT INTO categories (id,name,slug) VALUES (77777,'Tagtest','tagtest');
            INSERT INTO albums (id,title,slug,category_id,is_published,is_nsfw,password_hash) VALUES
                (1,'A','a',77777,1,0,NULL),(2,'B','b',77777,1,0,''),(3,'Hidden','hidden',77777,0,0,NULL),(4,'Nsfw','nsfw',77777,1,1,NULL),(5,'Locked','locked',77777,1,0,'hash');
            INSERT INTO album_tag (album_id,tag_id) VALUES (1,1),(2,1),(3,2),(4,2),(5,2);");

        $tags = (new NavigationService($db))->getHeaderTags();
        self::assertSame(['street'], array_column($tags, 'slug'));
        self::assertSame(2, (int) $tags[0]['albums_count']);
        self::assertArrayNotHasKey('nav_tags_cache', $_SESSION ?? [], 'No longer stored per visitor');

        $db->pdo()->exec('INSERT INTO album_tag (album_id,tag_id) VALUES (1,3)');
        NavigationService::invalidateTagsCache();
        self::assertSame(['street', 'late'], array_column((new NavigationService($db))->getHeaderTags(), 'slug'));
    }

    public function testCacheIsScopedToTheConfiguredDatabase(): void
    {
        $dir = sys_get_temp_dir() . '/cimaise-navdb-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $make = function (string $file, string $tag) use ($dir): Database {
                $db = new Database(database: $dir . '/' . $file, isSqlite: true);
                $db->pdo()->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT, slug TEXT); CREATE TABLE album_tag (album_id INTEGER, tag_id INTEGER); CREATE TABLE albums (id INTEGER PRIMARY KEY, is_published INTEGER, is_nsfw INTEGER, password_hash TEXT)');
                $db->pdo()->exec("INSERT INTO tags VALUES (1,'$tag','$tag'); INSERT INTO albums VALUES (1,1,0,NULL); INSERT INTO album_tag VALUES (1,1)");
                return $db;
            };
            $first = $make('a.sqlite', 'alpha');
            $second = $make('b.sqlite', 'beta');
            self::assertSame(['alpha'], array_column((new NavigationService($first))->getHeaderTags(), 'slug'));
            $this->resetRequestCopy(); // next request: only the persistent cache is left
            self::assertSame(['beta'], array_column((new NavigationService($second))->getHeaderTags(), 'slug'), 'Switching DB_DATABASE never shows the previous site\'s tags');
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function resetRequestCopy(): void
    {
        (new ReflectionProperty(NavigationService::class, 'headerTagsCache'))->setValue(null, null);
    }
}
