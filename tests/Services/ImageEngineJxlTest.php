<?php

declare(strict_types=1);

use App\Services\Imaging\ImageEngine;
use PHPUnit\Framework\TestCase;

/**
 * JPEG-XL generation path (#109). On hosts where jxl_write is true the engine
 * must actually produce a valid .jxl file — via libvips+libjxl when present,
 * otherwise the standalone cjxl (libjxl) encoder with an Imagick intermediate.
 * Skips on hosts with no JXL encoder so the suite stays portable.
 */
final class ImageEngineJxlTest extends TestCase
{
    public function testEncodeProducesRealJxlWhenSupported(): void
    {
        if (!ImageEngine::capabilities()['jxl_write']) {
            self::markTestSkipped('No JPEG-XL encoder available (neither libvips+libjxl nor cjxl).');
        }
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            self::markTestSkipped('GD is required to synthesize the source image.');
        }

        $src  = tempnam(sys_get_temp_dir(), 'jxlsrc_') . '.png';
        $dest = tempnam(sys_get_temp_dir(), 'jxlout_') . '.jxl';
        $im = imagecreatetruecolor(128, 96);
        imagefilledrectangle($im, 0, 0, 127, 95, imagecolorallocate($im, 180, 40, 90));
        imagepng($im, $src);
        imagedestroy($im);

        try {
            $ok = ImageEngine::encode($src, $dest, 64, 'jxl', 80);
            self::assertTrue($ok, 'encode() must report success for jxl on a jxl-capable host');
            self::assertFileExists($dest);
            self::assertGreaterThan(0, (int) filesize($dest), 'the .jxl must not be empty');

            // Real JXL: bare codestream (FF 0A) or the ISO-BMFF "JXL " box.
            $head = (string) file_get_contents($dest, false, null, 0, 12);
            $isCodestream = str_starts_with($head, "\xFF\x0A");
            $isContainer  = $head === "\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87\x0A";
            self::assertTrue($isCodestream || $isContainer, 'output must carry a valid JPEG-XL signature');
        } finally {
            @unlink($src);
            @unlink(substr($src, 0, -4)); // tempnam stub without .png
            @unlink($dest);
            @unlink(substr($dest, 0, -4));
        }
    }
}
