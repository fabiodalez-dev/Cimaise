<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Services\SettingsService;
use App\Support\Database;
use App\Support\Logger;
use App\Traits\RegistersImageVariants;
use Imagick;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'images:generate', description: 'Generate image variants as per settings')]
class ImagesGenerateCommand extends Command
{
    use RegistersImageVariants;

    /** Memoised result of {@see imagickDisabled()} — env vars don't change per-run. */
    private ?bool $imagickDisabled = null;

    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    /**
     * Mirrors UploadService::imagickDisabled() but with a per-instance memo.
     * The CLI runs the same resize paths that segfault on macOS Apple
     * Silicon under ImageMagick 7.1.x, so the opt-out env flag must be
     * honored here too — and the flag is parsed once per command run
     * (the same env stays in effect for the whole CLI invocation).
     */
    private function imagickDisabled(): bool
    {
        if ($this->imagickDisabled !== null) {
            return $this->imagickDisabled;
        }
        $raw = function_exists('envv')
            ? envv('CIMAISE_DISABLE_IMAGICK', 'false')
            : ($_ENV['CIMAISE_DISABLE_IMAGICK'] ?? $_SERVER['CIMAISE_DISABLE_IMAGICK'] ?? 'false');
        return $this->imagickDisabled = filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN);
    }

    protected function configure(): void
    {
        $this->addOption('missing', null, InputOption::VALUE_NONE, 'Only generate missing variants');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Force regeneration even if file exists');
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of images', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->db->pdo();
        $settings = new SettingsService($this->db);
        $settings->clearCache();
        $formats = $settings->get('image.formats');
        $quality = $settings->get('image.quality');
        $breakpoints = $settings->get('image.breakpoints');
        $missingOnly = (bool)$input->getOption('missing');
        $forceRegenerate = (bool)$input->getOption('force');
        $limit = (int)$input->getOption('limit');

        $output->writeln('<info>Image Variant Generation Starting...</info>');
        $output->writeln(sprintf('Formats: %s', implode(', ', array_keys(array_filter($formats)))));
        $output->writeln(sprintf('Breakpoints: %s', implode(', ', array_map(fn ($k, $v) => "{$k}:{$v}px", array_keys($breakpoints), $breakpoints))));

        $q = 'SELECT id, original_path FROM images';
        if ($limit > 0) {
            $q .= ' LIMIT ' . $limit;
        }
        $images = $pdo->query($q)->fetchAll();
        if (!$images) {
            $output->writeln('<comment>No images found.</comment>');
            return Command::SUCCESS;
        }

        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        if (!is_dir($mediaDir) && !@mkdir($mediaDir, 0775, true)) {
            throw new RuntimeException('Cannot create media directory');
        }

        $imagickDisabled = $this->imagickDisabled();
        $imagickOk = class_exists(Imagick::class) && !$imagickDisabled;
        $gdWebpOk = function_exists('imagewebp');
        if (!$imagickOk) {
            $output->writeln('<comment>Imagick not available' . ($imagickDisabled ? ' (disabled via CIMAISE_DISABLE_IMAGICK)' : '') . ', using GD fallbacks.</comment>');
        }
        if (!$gdWebpOk) {
            $output->writeln('<comment>GD WebP support not available.</comment>');
        }

        $totalGenerated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($images as $img) {
            $imageId = (int)$img['id'];
            $originalPath = $img['original_path'];

            // Try multiple possible locations for the source file
            $possiblePaths = [
                dirname(__DIR__, 2) . $originalPath,           // /media/originals/...
                dirname(__DIR__, 2) . '/public' . $originalPath, // /public/media/originals/...
                dirname(__DIR__, 2) . '/storage/originals/' . basename((string) $originalPath), // /storage/originals/...
            ];

            $src = null;
            foreach ($possiblePaths as $path) {
                if (is_file($path)) {
                    $src = $path;
                    break;
                }
            }

            if (!$src) {
                $output->writeln("<error>Source file not found for image #{$imageId}: tried " . implode(', ', $possiblePaths) . "</error>");
                $totalErrors++;
                continue;
            }

            $existingStmt = $pdo->prepare('SELECT variant, format, path FROM image_variants WHERE image_id = ?');
            $existingStmt->execute([$imageId]);
            $existingVariants = [];
            foreach ($existingStmt->fetchAll() as $row) {
                $key = $row['variant'] . '|' . $row['format'];
                $existingVariants[$key] = (string)($row['path'] ?? '');
            }

            $variantsGenerated = 0;
            foreach ($breakpoints as $variant => $width) {
                // 'jxl' (JPEG-XL, #109) is included only when enabled in settings
                // AND the build can write it (libvips+libjxl); it is emitted by
                // the libvips engine. Browser support is still nascent, so the
                // frontend <picture> does not serve it yet — generation is
                // opt-in via settings.
                foreach (['avif','webp','jpg','jxl'] as $fmt) {
                    if (empty($formats[$fmt])) {
                        continue;
                    }
                    if ($fmt === 'jxl' && !\App\Services\Imaging\ImageEngine::capabilities()['jxl_write']) {
                        continue;
                    }
                    $destRelUrl = "/media/{$imageId}_{$variant}.{$fmt}";
                    $dest = dirname(__DIR__, 2) . '/public/media/' . "{$imageId}_{$variant}.{$fmt}";
                    $key = $variant . '|' . $fmt;
                    $existsInDb = isset($existingVariants[$key]);
                    $existsOnDisk = is_file($dest);

                    // Skip logic (unless force is used)
                    if (!$forceRegenerate && $missingOnly && $existsOnDisk && $existsInDb) {
                        $totalSkipped++;
                        continue;
                    }

                    // Delete orphan files (exist on disk but not in DB) before
                    // regenerating. Confine the deletion to public/media via
                    // realpath so a deletion can never escape that directory
                    // (defence-in-depth path-traversal guard).
                    if ($existsOnDisk && !$existsInDb) {
                        $mediaRoot = realpath(dirname(__DIR__, 2) . '/public/media');
                        $orphanReal = realpath($dest);
                        if ($mediaRoot !== false && $orphanReal !== false
                            && str_starts_with($orphanReal, $mediaRoot . DIRECTORY_SEPARATOR)) {
                            // nosemgrep: $orphanReal is realpath()-resolved and
                            // verified to live under public/media above, so this
                            // deletion cannot traverse outside that directory.
                            @unlink($orphanReal); // nosemgrep
                        }
                    }

                    $ok = false;
                    // Fast path (#109): libvips — shrink-on-load, low memory,
                    // supports AVIF/JPEG-XL/HEIC when the build provides them.
                    // Returns false when vips is unavailable or cannot handle
                    // the request; we then fall back to the existing Imagick/GD
                    // path below (zero behaviour change on hosts without vips).
                    $engineFmt = $fmt === 'jpg' ? 'jpeg' : $fmt;
                    // Honour STRIP_EXIF (default true) so vips doesn't always
                    // strip when the operator opted to retain metadata.
                    $stripExif = filter_var(getenv('STRIP_EXIF') ?: 'true', FILTER_VALIDATE_BOOL);
                    $ok = \App\Services\Imaging\ImageEngine::encode(
                        $src,
                        $dest,
                        (int)$width,
                        $engineFmt,
                        (int)($quality[$fmt] ?? 82),
                        $stripExif
                    );

                    if (!$ok) {
                        if ($fmt === 'jpg') {
                            $ok = $this->resizeWithImagickOrGd($src, $dest, (int)$width, 'jpeg', (int)$quality['jpg']);
                        } elseif ($fmt === 'webp') {
                            if ($imagickOk) {
                                $ok = $this->resizeWithImagick($src, $dest, (int)$width, 'webp', (int)$quality['webp']);
                            } elseif ($gdWebpOk) {
                                $ok = $this->resizeWithGdWebp($src, $dest, (int)$width, (int)$quality['webp']);
                            }
                        } elseif ($fmt === 'avif') {
                            $ok = $imagickOk && $this->resizeWithImagick($src, $dest, (int)$width, 'avif', (int)$quality['avif']);
                        }
                    }

                    if ($ok) {
                        // Defence-in-depth: a single constraint failure (e.g. a
                        // format the DB schema doesn't yet allow) must not abort
                        // the whole run — log and continue with the next variant.
                        try {
                            $size = (int)filesize($dest);
                            [$w, $h] = getimagesize($dest) ?: [(int)$width, 0];
                            $replaceKeyword = $this->db->replaceKeyword();
                            $stmt = $pdo->prepare(sprintf(
                                '%s INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)',
                                $replaceKeyword
                            ));
                            $stmt->execute([$imageId, $variant, $fmt, $destRelUrl, $w, $h, $size]);
                            $variantsGenerated++;
                            $totalGenerated++;
                        } catch (\Throwable $e) {
                            Logger::warning('ImagesGenerateCommand: failed to record image variant', [
                                'image_id' => $imageId,
                                'variant'  => $variant,
                                'format'   => $fmt,
                                'error'    => $e->getMessage(),
                            ], 'imaging');
                            $output->writeln("<error>Failed to record {$fmt} variant {$variant} for image #{$imageId}: {$e->getMessage()}</error>");
                            $totalErrors++;
                        }
                    } else {
                        $output->writeln("<error>Failed to generate {$fmt} variant {$variant} for image #{$imageId}</error>");
                        $totalErrors++;
                    }
                }
            }

            if ($variantsGenerated > 0) {
                $output->writeln("Generated {$variantsGenerated} variants for image #{$imageId}");
            }
        }

        $output->writeln('<info>Generation Complete!</info>');
        $output->writeln(sprintf('Generated: %d, Skipped: %d, Errors: %d', $totalGenerated, $totalSkipped, $totalErrors));
        return Command::SUCCESS;
    }

    private function resizeWithImagick(string $src, string $dest, int $targetW, string $format, int $quality): bool
    {
        try {
            $im = new Imagick($src);
            $im->setImageColorspace(Imagick::COLORSPACE_RGB);
            $im->setInterlaceScheme(Imagick::INTERLACE_JPEG);
            $im->thumbnailImage($targetW, 0);
            $im->setImageFormat($format);
            if ($format === 'webp' || $format === 'jpeg') {
                $im->setImageCompressionQuality($quality);
            } elseif ($format === 'avif') {
                $im->setOption('heic:quality', (string)$quality);
            }
            @mkdir(dirname($dest), 0775, true);
            $ok = $im->writeImage($dest);
            $im->clear();
            return (bool)$ok;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resizeWithImagickOrGd(string $src, string $dest, int $targetW, string $format, int $quality): bool
    {
        if (class_exists(Imagick::class) && !$this->imagickDisabled()) {
            return $this->resizeWithImagick($src, $dest, $targetW, $format, $quality);
        }

        // GD fallback for JPEG only
        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW;
        $newH = (int)round($targetW / $ratio);

        $srcImg = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/gif' => @imagecreatefromgif($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : null,
            default => null,
        };

        if (!$srcImg) {
            return false;
        }

        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG/GIF
        if ($info['mime'] === 'image/png' || $info['mime'] === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        }

        imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
        @mkdir(dirname($dest), 0775, true);

        $ok = imagejpeg($dst, $dest, $quality);

        imagedestroy($srcImg);
        imagedestroy($dst);
        return $ok;
    }

    private function resizeWithGdWebp(string $src, string $dest, int $targetW, int $quality): bool
    {
        if (!function_exists('imagewebp')) {
            return false;
        }

        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW;
        $newH = (int)round($targetW / $ratio);

        $srcImg = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/gif' => @imagecreatefromgif($src),
            'image/webp' => @imagecreatefromwebp($src),
            default => null,
        };

        if (!$srcImg) {
            return false;
        }

        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve transparency
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
        @mkdir(dirname($dest), 0775, true);

        $ok = imagewebp($dst, $dest, $quality);

        imagedestroy($srcImg);
        imagedestroy($dst);
        return $ok;
    }
}
