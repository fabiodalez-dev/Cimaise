<?php

declare(strict_types=1);

namespace App\Services\Imaging;

use App\Support\Logger;

/**
 * ImageEngine — modern, capability-detected variant encoder (#109).
 *
 * Foundation for the image-variant pipeline modernization. It provides:
 *   - a fast, low-memory libvips path (jcupitt/vips) when the extension is
 *     available, with automatic fallback to the caller's existing Imagick/GD
 *     code when it is not (zero behaviour change on hosts without vips);
 *   - capability detection (vips, HEIC/HEIF read, AVIF/JPEG-XL write,
 *     post-encode optimizer binaries) surfaced for diagnostics;
 *   - an optional post-encode optimization pass via CLI optimizers.
 *
 * Dependency-optional by design: vips classes are referenced only behind
 * class_exists()/extension_loaded() guards, optimizer binaries only behind
 * PATH detection, and every external command runs through proc_open() with an
 * argv ARRAY (no shell, so no command injection). The package builds and runs
 * unchanged before `composer require jcupitt/vips` and before any optimizer
 * binary is installed. Heavy work runs from the CLI variant generator
 * (`images:generate`), never inside a web request.
 */
final class ImageEngine
{
    /** @var array<string,bool>|null Cached capability map. */
    private static ?array $caps = null;

    /**
     * Detect available imaging capabilities. Cached per-process.
     *
     * @return array<string,bool>
     */
    public static function capabilities(): array
    {
        if (self::$caps !== null) {
            return self::$caps;
        }

        $vips = self::vipsAvailable();

        self::$caps = [
            'vips'          => $vips,
            'imagick'       => class_exists(\Imagick::class),
            'gd'            => \function_exists('imagecreatetruecolor'),
            // Read support for Apple HEIC/HEIF (iPhone): vips (libheif) or the
            // Imagick HEIC delegate.
            'heif_read'     => ($vips && self::vipsCanLoad('probe.heic')) || self::imagickSupports('HEIC'),
            'avif_write'    => ($vips && self::vipsCanWrite('.avif')) || self::imagickSupports('AVIF'),
            'jxl_write'     => $vips && self::vipsCanWrite('.jxl'),
            // Post-encode optimizer (only jpegoptim is actually invoked, for
            // jpeg variants; webp/avif/jxl are emitted at target quality and
            // PNG is never produced, so no other optimizer is detected).
            'opt_jpegoptim' => self::binaryExists('jpegoptim'),
        ];

        return self::$caps;
    }

    /**
     * Encode a resized variant via libvips. Returns true on success, false
     * when this engine cannot handle the request (caller should fall back to
     * its existing Imagick/GD path).
     *
     * @param string $src     Source path (any vips-readable format, incl. HEIC).
     * @param string $dest    Destination path; only where the file is written
     *                        (it does not influence the output format).
     * @param int    $targetW Target width in px (height auto, aspect kept).
     * @param string $format  'jpeg' | 'webp' | 'avif' | 'jxl' — selects the
     *                        encoder (passed to writeOptions); this argument,
     *                        not the $dest extension, decides the output format.
     * @param int    $quality 1-100.
     * @param bool   $strip   Strip metadata from the variant (privacy).
     */
    public static function encode(
        string $src,
        string $dest,
        int $targetW,
        string $format,
        int $quality,
        bool $strip = true
    ): bool {
        if (!self::vipsAvailable()) {
            return false;
        }

        $targetW = max(1, $targetW);
        $quality = max(1, min(100, $quality));

        try {
            // thumbnail() shrinks on load (decodes only what's needed) — the
            // big memory/CPU win over decode-then-resize.
            $img = \Jcupitt\Vips\Image::thumbnail($src, $targetW, [
                'height' => 10_000_000, // unbounded → width drives the resize
                'size'   => 'down',     // never upscale beyond the source
            ]);

            if ($format === 'jpeg' && $img->hasAlpha()) {
                $img = $img->flatten(['background' => [255, 255, 255]]);
            }

            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0775, true);
            }
            $img->writeToFile($dest, self::writeOptions($format, $quality, $strip));

            if (!is_file($dest) || (int) filesize($dest) === 0) {
                return false;
            }

            self::optimize($dest, $format);
            return true;
        } catch (\Throwable $e) {
            Logger::warning('ImageEngine: vips encode failed, falling back', [
                'src'    => $src,
                'format' => $format,
                'error'  => $e->getMessage(),
            ], 'imaging');
            return false;
        }
    }

    /**
     * Read [width, height] for a source image via libvips, header/sequential
     * only (no full decode → preserves decompression-bomb safety). Returns null
     * when vips is unavailable or cannot read the input, so callers fall back to
     * their existing Imagick ping path.
     *
     * @return array{0:int,1:int}|null
     */
    public static function dimensions(string $src): ?array
    {
        if (!self::vipsAvailable()) {
            return null;
        }
        try {
            // 'sequential' access reads only what's needed off the header/stream
            // rather than buffering the whole raster — no full decode.
            $img = \Jcupitt\Vips\Image::newFromFile($src, ['access' => 'sequential']);
            $w = (int) $img->width;
            $h = (int) $img->height;
            return ($w > 0 && $h > 0) ? [$w, $h] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Best-effort post-encode optimization via an installed CLI optimizer.
     * Silent no-op when no suitable binary is present.
     */
    private static function optimize(string $path, string $format): void
    {
        if (!is_file($path)) {
            return;
        }
        $caps = self::capabilities();

        if (($format === 'jpeg' || $format === 'jpg') && $caps['opt_jpegoptim']) {
            self::run(['jpegoptim', '--strip-all', '--quiet', $path]);
        }
        // webp/avif are emitted at target quality by the encoder; re-optimizing
        // risks double-compression artefacts, so they are intentionally skipped.
        // ImageEngine never encodes PNG, so no PNG optimizer is wired in.
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private static function writeOptions(string $format, int $quality, bool $strip): array
    {
        return match ($format) {
            'jpeg', 'jpg' => ['Q' => $quality, 'strip' => $strip, 'interlace' => true, 'optimize_coding' => true],
            'webp'        => ['Q' => $quality, 'strip' => $strip, 'effort' => 4],
            'avif'        => ['Q' => $quality, 'strip' => $strip],
            'jxl'         => ['Q' => $quality, 'strip' => $strip],
            default       => ['strip' => $strip],
        };
    }

    private static function vipsAvailable(): bool
    {
        return \extension_loaded('vips') && class_exists(\Jcupitt\Vips\Image::class);
    }

    /** Can vips write the given file extension (e.g. '.avif')? Probed once. */
    private static function vipsCanWrite(string $ext): bool
    {
        try {
            // 1×1 black pixel; writeToBuffer throws if the saver isn't built in.
            $img = \Jcupitt\Vips\Image::black(1, 1);
            $img->writeToBuffer($ext, ['Q' => 50]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Can vips load the given (representative) filename / format? */
    private static function vipsCanLoad(string $filename): bool
    {
        try {
            // findLoad() returns the loader operation name or throws/returns null
            // when no loader is built in for that suffix.
            return (string) \Jcupitt\Vips\Image::findLoad($filename) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function imagickSupports(string $format): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }
        try {
            $found = \Imagick::queryFormats(strtoupper($format));
            return $found !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /** PATH scan + is_executable — no shell, so no command-injection surface. */
    private static function binaryExists(string $bin): bool
    {
        $path = (string) getenv('PATH');
        if ($path === '') {
            return false;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $bin;
            if (@is_file($candidate) && @is_executable($candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run an external command via proc_open with an argv ARRAY (no shell
     * interpretation → no command injection). Output is discarded; failures
     * are swallowed (optimization is opportunistic).
     *
     * @param array<int,string> $argv
     */
    private static function run(array $argv): void
    {
        try {
            $descriptors = [
                1 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
                2 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            ];
            // nosemgrep: argv-array form bypasses the shell entirely; binary
            // names are hardcoded constants and the only dynamic element is a
            // filesystem path passed as a discrete argv element (never
            // interpolated), so command injection is not possible.
            $proc = @proc_open($argv, $descriptors, $pipes); // nosemgrep
            if (is_resource($proc)) {
                @proc_close($proc);
            }
        } catch (\Throwable) {
            // ignore — never fail a variant over optimization
        }
    }
}
