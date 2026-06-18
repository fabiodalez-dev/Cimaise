<?php

declare(strict_types=1);

use App\Services\Imaging\ImageEngine;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the capability-detected image engine (#109): the
 * capability map contract, the encode() fast-path/fallback contract, the
 * JPEG-XL-via-cjxl route, and the libvips dimension reader. Capability-gated
 * assertions skip cleanly so the suite stays portable across CI hosts.
 */
final class ImageEngineTest extends TestCase
{
    /** @var string[] */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        $this->tmp = [];
    }

    // ── capabilities() contract ───────────────────────────────────────────

    public function testCapabilitiesExposesEveryExpectedKey(): void
    {
        $caps = ImageEngine::capabilities();
        foreach (['vips', 'imagick', 'gd', 'heif_read', 'avif_write', 'jxl_write', 'opt_jpegoptim'] as $key) {
            self::assertArrayHasKey($key, $caps, "capabilities() must expose '$key'");
            self::assertIsBool($caps[$key], "capabilities()['$key'] must be a bool");
        }
    }

    public function testCapabilitiesAreCachedAcrossCalls(): void
    {
        // Same content on repeat (per-process memoization) — cheap and stable.
        self::assertSame(ImageEngine::capabilities(), ImageEngine::capabilities());
    }

    public function testJxlWriteImpliesAnAvailableEncoder(): void
    {
        $caps = ImageEngine::capabilities();
        if (!$caps['jxl_write']) {
            self::assertFalse($caps['jxl_write']); // nothing to prove on a no-jxl host
            return;
        }
        // jxl_write is true ⇒ libvips can write jxl, OR Imagick+cjxl are both present.
        $viaVips    = $caps['vips'];
        $viaCjxl    = $caps['imagick'] && $this->onPath('cjxl');
        self::assertTrue($viaVips || $viaCjxl, 'jxl_write=true must be backed by vips or Imagick+cjxl');
    }

    public function testHeifReadImpliesVipsOrImagick(): void
    {
        $caps = ImageEngine::capabilities();
        if ($caps['heif_read']) {
            self::assertTrue($caps['vips'] || $caps['imagick'], 'heif_read=true must be backed by vips or Imagick');
        } else {
            self::assertFalse($caps['heif_read']);
        }
    }

    // ── encode() contract ─────────────────────────────────────────────────

    public function testEncodeReturnsFalseForJpegWhenVipsUnavailable(): void
    {
        if (ImageEngine::capabilities()['vips']) {
            self::markTestSkipped('vips present — the no-vips fallback contract is not exercised here.');
        }
        $dest = $this->tmpPath('.jpg');
        // No libvips → encode() must report false for jpeg/webp/avif so the
        // caller falls back to its own Imagick/GD path (no file written).
        self::assertFalse(ImageEngine::encode($this->makePng(), $dest, 32, 'jpeg', 80));
        self::assertFileDoesNotExist($dest);
    }

    public function testEncodeProducesValidJxlWhenSupported(): void
    {
        if (!ImageEngine::capabilities()['jxl_write']) {
            self::markTestSkipped('No JPEG-XL encoder (libvips+libjxl or cjxl) on this host.');
        }
        $dest = $this->tmpPath('.jxl');
        self::assertTrue(ImageEngine::encode($this->makePng(128, 96), $dest, 64, 'jxl', 80));
        self::assertTrue($this->isJxl($dest), 'output must carry a JPEG-XL signature');
    }

    public function testEncodedJxlNeverUpscalesBeyondSource(): void
    {
        $caps = ImageEngine::capabilities();
        if (!$caps['jxl_write']) {
            self::markTestSkipped('No JPEG-XL encoder on this host.');
        }
        if (!$caps['vips']) {
            self::markTestSkipped('Reading JPEG-XL dimensions to verify no-upscale requires libvips.');
        }
        // Source is 40px wide; requesting 1000 must NOT upscale (the 'size'=>'down'
        // guard). Read the encoded output's real dimensions and assert the width
        // never exceeds the source — a regression that upscales would now fail.
        $dest = $this->tmpPath('.jxl');
        self::assertTrue(ImageEngine::encode($this->makePng(40, 30), $dest, 1000, 'jxl', 70));
        $dims = ImageEngine::dimensions($dest);
        self::assertNotNull($dims, 'should read dimensions of the encoded jxl');
        self::assertLessThanOrEqual(40, $dims[0], 'must not upscale beyond the 40px source width');
    }

    public function testEncodeClampsDegenerateQualityAndWidth(): void
    {
        if (!ImageEngine::capabilities()['jxl_write']) {
            self::markTestSkipped('No JPEG-XL encoder on this host.');
        }
        // Degenerate inputs (0 width, out-of-range quality) must be clamped, not
        // crash, and still yield a valid file.
        $dest = $this->tmpPath('.jxl');
        self::assertTrue(ImageEngine::encode($this->makePng(64, 64), $dest, 0, 'jxl', 100000));
        self::assertTrue($this->isJxl($dest));
    }

    // ── dimensions() contract ─────────────────────────────────────────────

    public function testDimensionsReturnsNullWhenVipsUnavailable(): void
    {
        if (ImageEngine::capabilities()['vips']) {
            self::markTestSkipped('vips present — the null fallback contract is not exercised here.');
        }
        // No libvips → dimensions() returns null so callers fall back to their
        // own Imagick ping / getimagesize path.
        self::assertNull(ImageEngine::dimensions($this->makePng(20, 10)));
    }

    public function testDimensionsReadsSizeWhenVipsAvailable(): void
    {
        if (!ImageEngine::capabilities()['vips']) {
            self::markTestSkipped('vips absent — header dimension read not exercised here.');
        }
        $dims = ImageEngine::dimensions($this->makePng(48, 24));
        self::assertSame([48, 24], $dims);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function makePng(int $w = 64, int $h = 64): string
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            self::markTestSkipped('GD is required to synthesize the source image.');
        }
        $path = $this->tmpPath('.png');
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 180, 40, 90));
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    private function isJxl(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 12);
        return str_starts_with($head, "\xFF\x0A")
            || $head === "\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87\x0A";
    }

    private function onPath(string $bin): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("$dir/$bin") && is_executable("$dir/$bin")) {
                return true;
            }
        }
        return false;
    }

    private function tmpPath(string $ext): string
    {
        $p = tempnam(sys_get_temp_dir(), 'cimaise_iet_');
        self::assertNotFalse($p, 'tempnam() failed creating a temporary path');
        @unlink($p);
        $p .= $ext;
        $this->tmp[] = $p;
        return $p;
    }
}
