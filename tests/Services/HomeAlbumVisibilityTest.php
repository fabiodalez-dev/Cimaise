<?php

declare(strict_types=1);

use App\Services\HomeAlbumVisibility;
use App\Services\HomeImageService;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class HomeAlbumVisibilityTest extends TestCase
{
    public function testHiddenAndLockedAlbumsStayOutOfEveryHomeFeed(): void
    {
        $db = new Database(database: ':memory:', isSqlite: true);
        $db->pdo()->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $db->pdo()->exec("INSERT INTO albums(id, title, slug, category_id, is_published, password_hash)
            VALUES (91, 'Visible', 'visible', 1, 1, NULL),
                   (92, 'Hidden', 'hidden', 1, 1, NULL),
                   (93, 'Locked', 'locked', 1, 1, 'hash')");
        $db->pdo()->exec("INSERT INTO images(id, album_id, original_path, file_hash, width, height, mime)
            VALUES (91, 91, '/tmp/a.jpg', 'a', 800, 600, 'image/jpeg'),
                   (92, 92, '/tmp/b.jpg', 'b', 800, 600, 'image/jpeg'),
                   (93, 93, '/tmp/c.jpg', 'c', 800, 600, 'image/jpeg')");

        $visibility = new HomeAlbumVisibility($db);
        $visibility->save(92, true);
        self::assertTrue($visibility->isHidden(92));
        self::assertSame([91], $db->query('SELECT a.id FROM albums a WHERE a.is_published = 1' .
            $visibility->eligibleSql())->fetchAll(PDO::FETCH_COLUMN));
        // The admin's album list keeps protected albums, never hidden ones.
        self::assertSame([91, 93], array_map('intval', $db->query('SELECT a.id FROM albums a WHERE a.is_published = 1' .
            $visibility->eligibleSql(includeProtected: true) . ' ORDER BY a.id')->fetchAll(PDO::FETCH_COLUMN)));

        $images = new HomeImageService($db);
        foreach ([$images->getInitialImages(), $images->getAllImages(), $images->getMoreImages(), $images->getMoreMasonryImages([])] as $feed) {
            self::assertSame([91], array_values(array_unique(array_column($feed['images'], 'album_id'))));
        }

        $visibility->save(92, false);
        self::assertFalse($visibility->isHidden(92));
        self::assertSame(3, $db->query('SELECT COUNT(*) FROM albums WHERE is_published = 1')->fetchColumn());
    }

    public function testVisibilityEditCanDiscardStaleHomeData(): void
    {
        $db = new Database(database: ':memory:', isSqlite: true);
        $db->pdo()->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $cache = new PageCacheService(new SettingsService($db), $db);
        self::assertTrue($cache->set('home', ['albums' => [92]]));
        $cache->invalidateHome(false);
        self::assertNull($cache->get('home', allowStale: true));
    }
}
