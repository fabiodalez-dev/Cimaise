<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Services\PageCacheService;
use App\Support\BlurGenerationJob;
use App\Support\CsvSafe;
use App\Support\Database;
use App\Support\RequestRoute;
use PHPUnit\Framework\TestCase;

/** Regressions for audit findings C9, C10, C12, C15, C16. */
final class CacheFlagsExportTest extends TestCase
{
    public function testCsvCellsCannotCarryFormulas(): void
    {
        self::assertSame("'=HYPERLINK(\"x\")", CsvSafe::cell('=HYPERLINK("x")'));
        self::assertSame("'+1", CsvSafe::cell('+1'));
        self::assertSame("'-1+cmd", CsvSafe::cell('-1+cmd'));
        self::assertSame("'@SUM(1)", CsvSafe::cell('@SUM(1)'));
        self::assertSame('plain', CsvSafe::cell('plain'));
        self::assertSame(42, CsvSafe::cell(42));
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AnalyticsService.php');
        self::assertStringContainsString('CsvSafe::row($row), \',\', \'"\', \'\')', $src);
        // RFC 4180 quoting: a backslash before a quote must not let the value
        // close its field and start a new (formula) cell.
        $out = fopen('php://temp', 'r+');
        fputcsv($out, CsvSafe::row(['safe\\",=1+1,tail']), ',', '"', '');
        rewind($out);
        self::assertSame(['safe\\",=1+1,tail'], str_getcsv(rtrim((string) stream_get_contents($out)), ',', '"', ''));
    }

    public function testBlurDispatchIsDedupedPerImage(): void
    {
        $id = 900000000 + random_int(1, 99999);
        $marker = dirname(__DIR__, 2) . '/storage/tmp/blur-locks/' . $id . '.pending';
        $claimFile = dirname(__DIR__, 2) . '/storage/tmp/blur-locks/' . $id . '.claim';
        $claim = new \ReflectionMethod(BlurGenerationJob::class, 'claimPending');
        try {
            self::assertTrue($claim->invoke(null, $id));
            self::assertFalse($claim->invoke(null, $id), 'second dispatch within the TTL must be skipped');
            touch($marker, time() - 3600);
            self::assertTrue($claim->invoke(null, $id), 'stale claim is replaced');

            // A concurrent claimer holding the per-image lock wins (contention => skip).
            touch($marker, time() - 3600);
            $held = fopen($claimFile, 'c+');
            self::assertNotFalse($held);
            self::assertTrue(flock($held, LOCK_EX | LOCK_NB));
            $child = proc_open(
                [PHP_BINARY, '-r', 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; $m = new ReflectionMethod(App\\Support\\BlurGenerationJob::class, "claimPending"); echo $m->invoke(null, ' . $id . ') ? "dispatch" : "skip";'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($child);
            $childOut = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($child);
            flock($held, LOCK_UN);
            fclose($held);
            self::assertSame('skip', $childOut, 'contention on the claim lock must skip the dispatch');
        } finally {
            @unlink($marker);
            @unlink($claimFile);
        }
    }

    public function testProtectedAlbumNeverGetsASkipControllerValidator(): void
    {
        $file = sys_get_temp_dir() . '/cimaise_pcs_' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $db = new Database(database: $file, isSqlite: true);
            $db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
            $db->pdo()->exec("INSERT INTO albums (id, category_id, title, slug, is_published) VALUES (1, 1, 'P', 'pub', 1)");
            $db->pdo()->exec("INSERT INTO albums (id, category_id, title, slug, is_published, password_hash) VALUES (2, 1, 'L', 'locked', 1, 'h')");
            $db->pdo()->exec("INSERT INTO albums (id, category_id, title, slug, is_published, is_nsfw) VALUES (3, 1, 'N', 'nsfw', 1, 1)");
            $db->pdo()->exec("INSERT INTO albums (id, category_id, title, slug, is_published) VALUES (4, 1, 'D', 'draft', 0)");
            $svc = new PageCacheService(new \App\Services\SettingsService($db), $db);
            self::assertNotNull($svc->albumPageKeyForSlug('pub'));
            self::assertNull($svc->albumPageKeyForSlug('locked'));
            self::assertNull($svc->albumPageKeyForSlug('nsfw'));
            self::assertNull($svc->albumPageKeyForSlug('draft'));
        } finally {
            @unlink($file);
        }
    }

    public function testQueryStringNeverClassifiesTheRoute(): void
    {
        foreach (['/?x=/install', '/album/a?x=/install', '/album/a?x=installer.php', '/album/installation-art', '/album/admin'] as $uri) {
            $path = RequestRoute::relativePath($uri, '/index.php');
            self::assertFalse(RequestRoute::isInstaller($path), $uri);
            self::assertFalse(RequestRoute::isAdmin($path), $uri);
        }
        self::assertTrue(RequestRoute::isInstaller(RequestRoute::relativePath('/install/step', '/index.php')));
        self::assertTrue(RequestRoute::isInstaller(RequestRoute::relativePath('/public/installer.php', '/index.php')));
        self::assertTrue(RequestRoute::isAdmin(RequestRoute::relativePath('/portfolio/admin/albums?x=1', '/portfolio/index.php')));
        self::assertTrue(RequestRoute::isAdmin(RequestRoute::relativePath('/portfolio/public/admin', '/portfolio/public/index.php')));
        self::assertFalse(RequestRoute::isAdmin(RequestRoute::relativePath('/portfolio/album/x', '/portfolio/index.php')));
    }

    public function testHtmlCacheRequiresAnEmptyQuery(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Middlewares/CacheMiddleware.php');
        self::assertStringContainsString("getQuery() === ''", $src);
    }
}
