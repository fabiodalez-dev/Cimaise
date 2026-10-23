<?php

declare(strict_types=1);

use App\Services\Imaging\ImageEngine;
use App\Services\SettingsService;
use App\Services\UploadService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

/** GD fallbacks, the JPEG-XL route and per-image locking in batch generation. */
final class ImageGenerationFallbacksTest extends TestCase
{
    private Database $db;
    private mixed $settingsSnapshot;
    /** @var array<string, string|false> */
    private array $env = [];
    /** @var string[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $this->settingsSnapshot = $property->getValue();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $this->db->pdo()->exec("INSERT INTO categories(id,name,slug) VALUES(765430,'Fixture','fixture');
            INSERT INTO albums(id,title,slug,category_id,is_published) VALUES(765430,'Fixture','fixture',765430,1)");
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->settingsSnapshot);
        foreach ($this->env as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->env)) {
            $this->env[$key] = $_ENV[$key] ?? getenv($key);
        }
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function tempFile(string $extension): string
    {
        $path = sys_get_temp_dir() . '/cimaise_fallback_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $this->cleanup[] = $path;
        return $path;
    }

    private function source(string $mime, int $width = 120, int $height = 80): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, imagecolorallocate($im, 40, 120, 200));
        $path = $this->tempFile(explode('/', $mime)[1]);
        match ($mime) {
            'image/webp' => imagewebp($im, $path),
            'image/gif' => imagegif($im, $path),
            'image/png' => imagepng($im, $path),
        };
        return $path;
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(UploadService::class, $method))->invoke(new UploadService($this->db), ...$args);
    }

    public function testGdWebpEncoderAcceptsWebpAndGifOriginals(): void
    {
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromwebp')) {
            self::markTestSkipped('GD WebP support is required.');
        }
        foreach (['image/webp', 'image/gif'] as $mime) {
            $dest = $this->tempFile('webp');
            self::assertTrue($this->invoke('resizeWithGd', $this->source($mime), $dest, 60, 80, 'webp'), $mime);
            self::assertSame([60, 40], array_slice((array) getimagesize($dest), 0, 2), $mime);
        }
    }

    public function testGdJpegFallbackAcceptsWebpOriginalsWhenImagickIsDisabled(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            self::markTestSkipped('GD WebP decoding is required.');
        }
        $this->setEnv('CIMAISE_DISABLE_IMAGICK', 'true');
        $dest = $this->tempFile('jpg');
        self::assertTrue($this->invoke('resizeWithImagickOrGd', $this->source('image/webp'), $dest, 60, 'jpeg', 80));
        self::assertSame('image/jpeg', getimagesize($dest)['mime'] ?? null);
    }

    public function testCjxlRouteHonoursTheImagickOptOutAndStaysOffWebRequests(): void
    {
        $cjxl = new ReflectionMethod(ImageEngine::class, 'cjxlUsable');
        $nativeVips = (new ReflectionMethod(ImageEngine::class, 'vipsAvailable'))->invoke(null)
            && (new ReflectionMethod(ImageEngine::class, 'vipsCanWrite'))->invoke(null, '.jxl');

        $this->setEnv('CIMAISE_DISABLE_IMAGICK', 'true');
        self::assertFalse($cjxl->invoke(null), 'the opt-out must also cover the Imagick intermediate of cjxl');
        if (!$nativeVips) {
            self::assertFalse(ImageEngine::jxlWritable(true));
        }

        $this->setEnv('CIMAISE_DISABLE_IMAGICK', 'false');
        if (!$nativeVips) {
            // Without libvips+libjxl a web request never takes the cjxl route.
            self::assertFalse(ImageEngine::jxlWritable(false));
            self::assertSame($cjxl->invoke(null), ImageEngine::jxlWritable(true));
        }
    }

    public function testJxlVariantDimensionsAreNeverUpscaledPastTheOriginal(): void
    {
        if (!ImageEngine::jxlWritable(true)) {
            self::markTestSkipped('No JPEG-XL encoder available in this CLI.');
        }
        $root = dirname(__DIR__, 2);
        $name = 'jxl-dims-' . bin2hex(random_bytes(6)) . '.png';
        $original = $root . '/storage/originals/' . $name;
        if (!is_dir(dirname($original))) {
            mkdir(dirname($original), 0775, true);
        }
        copy($this->source('image/png', 100, 50), $original);
        $this->cleanup[] = $original;
        $imageId = 765431;
        foreach (['sm', 'md'] as $variant) {
            $this->cleanup[] = $root . "/public/media/{$imageId}_{$variant}.jxl";
        }
        $this->db->pdo()->prepare("INSERT INTO images(id,album_id,original_path,file_hash,width,height,mime) VALUES(?,765430,?,?,100,50,'image/png')")
            ->execute([$imageId, '/storage/originals/' . $name, bin2hex(random_bytes(20))]);
        $settings = new SettingsService($this->db);
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, array_replace($settings->defaults(), [
            'image.formats' => ['jxl' => true, 'jpg' => false, 'webp' => false, 'avif' => false],
            'image.breakpoints' => ['sm' => 768, 'md' => 1200],
        ]));

        $stats = (new UploadService($this->db))->generateVariantsForImage($imageId, true);

        self::assertSame(2, $stats['generated'], json_encode($stats));
        $rows = $this->db->pdo()->query("SELECT variant, width, height FROM image_variants WHERE image_id = {$imageId} ORDER BY variant")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['variant' => 'md', 'width' => 100, 'height' => 50], ['variant' => 'sm', 'width' => 100, 'height' => 50]],
            array_map(static fn (array $r): array => ['variant' => $r['variant'], 'width' => (int) $r['width'], 'height' => (int) $r['height']], $rows));
    }

    public function testBlurBatchContinuesPastAnImageHeldByTheWorker(): void
    {
        $this->db->pdo()->exec("INSERT INTO images(id,album_id,original_path,file_hash,width,height,mime) VALUES
            (765432,765430,'/storage/originals/missing-a.jpg','blur-a',10,10,'image/jpeg'),
            (765433,765430,'/storage/originals/missing-b.jpg','blur-b',10,10,'image/jpeg')");
        $dir = dirname(__DIR__, 2) . '/storage/image-jobs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $lockPath = $dir . '/765432.lock';
        $this->cleanup[] = $lockPath;
        $held = fopen($lockPath, 'c');
        self::assertTrue(flock($held, LOCK_EX));
        try {
            $stats = (new UploadService($this->db))->generateBlurredVariantsForAlbum(765430);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        // Both are counted (the held one and the one without an original):
        // the held image no longer aborts the whole album.
        self::assertSame(2, $stats['failed'] + $stats['generated'] + $stats['skipped']);
    }
}
