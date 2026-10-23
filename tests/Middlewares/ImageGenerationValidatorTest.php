<?php

declare(strict_types=1);

use App\Middlewares\AuthMiddleware;
use App\Middlewares\CacheMiddleware;
use App\Services\ImageGenerationRevision;
use App\Services\SettingsService;
use App\Services\TwigGlobalsCache;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

/** The image-generation state the public layout embeds, and who reacts to it. */
final class ImageGenerationValidatorTest extends TestCase
{
    private string $job;
    private bool $jobExisted;

    protected function setUp(): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/image-jobs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->job = $dir . '/987654321.job';
        $this->jobExisted = is_file($this->job);
        self::assertFalse($this->jobExisted, 'fixture job id already in use');
    }

    protected function tearDown(): void
    {
        if (!$this->jobExisted) {
            @unlink($this->job);
        }
    }

    public function testValidatorChangesWithTheGenerationStateTheLayoutEmbeds(): void
    {
        $db = new Database(database: ':memory:', isSqlite: true);
        $db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $contentId = new ReflectionMethod(CacheMiddleware::class, 'htmlContentId');
        $middleware = new CacheMiddleware(new SettingsService($db), $db);

        $idle = $contentId->invoke($middleware, 'entry-hash');
        touch($this->job);
        self::assertTrue(ImageGenerationRevision::pending());
        $pending = $contentId->invoke($middleware, 'entry-hash');
        unlink($this->job);

        self::assertNotSame($idle, $pending, 'a 304 would keep a stale "pending" flag in the visitor\'s page');
        self::assertSame($idle, $contentId->invoke($middleware, 'entry-hash'), 'the validator must stay stable otherwise');
    }

    public function testOnlyNumericJobFilesCountAsPending(): void
    {
        $other = dirname($this->job) . '/not-an-image.job';
        if (!ImageGenerationRevision::pending()) {
            touch($other);
            try {
                self::assertFalse(ImageGenerationRevision::pending());
            } finally {
                @unlink($other);
            }
        }
        touch($this->job);
        self::assertTrue(ImageGenerationRevision::pending());
    }

    public function testProgressPollingNeverResumesTheQueue(): void
    {
        self::assertFalse(AuthMiddleware::resumesImageJobs('/admin/api/image-jobs'));
        self::assertFalse(AuthMiddleware::resumesImageJobs('/sub/dir/admin/api/image-jobs/'));
        self::assertTrue(AuthMiddleware::resumesImageJobs('/admin/media'));
        self::assertTrue(AuthMiddleware::resumesImageJobs('/admin/albums/5/upload'));
    }

    public function testDarkLogoIsPartOfTheVersionedGlobalsShape(): void
    {
        self::assertArrayHasKey('site_logo_dark', TwigGlobalsCache::getDefaults(''));
        $prefix = (new ReflectionClassConstant(TwigGlobalsCache::class, 'CACHE_KEY_PREFIX'))->getValue();
        self::assertNotSame('twig_globals:v5:', $prefix, 'new keys require a prefix bump so v5 caches are never served');
    }
}
