<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\SettingsService;
use App\Support\Database;
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

    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('missing', null, InputOption::VALUE_NONE, 'Only generate missing variants');
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of images', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->db->pdo();
        $settings = new SettingsService($this->db);
        $formats = $settings->get('image.formats');
        $quality = $settings->get('image.quality');
        $breakpoints = $settings->get('image.breakpoints');
        $missingOnly = (bool)$input->getOption('missing');
        $limit = (int)$input->getOption('limit');

        $output->writeln('<info>Image Variant Generation Starting...</info>');
        $output->writeln(sprintf('Formats: %s', implode(', ', array_keys(array_filter($formats)))));
        $output->writeln(sprintf('Breakpoints: %s', implode(', ', array_map(fn($k, $v) => "{$k}:{$v}px", array_keys($breakpoints), $breakpoints))));

        $q = 'SELECT id, original_path FROM images';
        if ($limit > 0) { $q .= ' LIMIT ' . (int)$limit; }
        $images = $pdo->query($q)->fetchAll();
        if (!$images) {
            $output->writeln('<comment>No images found.</comment>');
            return Command::SUCCESS;
        }

        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        if (!is_dir($mediaDir) && !@mkdir($mediaDir, 0775, true)) {
            throw new RuntimeException('Cannot create media directory');
        }

        $imagickOk = class_exists(Imagick::class);
        $gdWebpOk = function_exists('imagewebp');
        if (!$imagickOk) {
            $output->writeln('<comment>Imagick not available, using GD fallbacks.</comment>');
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
                dirname(__DIR__, 2) . '/storage/originals/' . basename($originalPath), // /storage/originals/...
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
                $key = (string)$row['variant'] . '|' . (string)$row['format'];
                $existingVariants[$key] = (string)($row['path'] ?? '');
            }
            
            $variantsGenerated = 0;
            foreach ($breakpoints as $variant => $width) {
                foreach (['avif','webp','jpg'] as $fmt) {
                    if (empty($formats[$fmt])) continue;
                    $destRelUrl = "/media/{$imageId}_{$variant}.{$fmt}";
                    $dest = dirname(__DIR__, 2) . '/public/media/' . "{$imageId}_{$variant}.{$fmt}";
                    
                    if ($missingOnly && is_file($dest)) {
                        $key = (string)$variant . '|' . (string)$fmt;
                        if (!isset($existingVariants[$key])) {
                            $this->registerVariantFromFile(
                                $pdo,
                                $imageId,
                                (string)$variant,
                                (string)$fmt,
                                $destRelUrl,
                                $dest,
                                (int)$width,
                                $this->db->replaceKeyword()
                            );
                            $existingVariants[$key] = $destRelUrl;
                            $totalGenerated++;
                        } else {
                            $totalSkipped++;
                        }
                        continue;
                    }
                    
                    $ok = false;
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
                    
                    if ($ok) {
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
        if (class_exists(Imagick::class)) {
            return $this->resizeWithImagick($src, $dest, $targetW, $format, $quality);
        }
        
        // GD fallback for JPEG only
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW; 
        $newH = (int)round($targetW / $ratio);
        
        $srcImg = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/gif' => @imagecreatefromgif($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : null,
            default => null,
        };
        
        if (!$srcImg) return false;
        
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
        return (bool)$ok;
    }

    private function resizeWithGdWebp(string $src, string $dest, int $targetW, int $quality): bool
    {
        if (!function_exists('imagewebp')) {
            return false;
        }
        
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW;
        $newH = (int)round($targetW / $ratio);
        
        $srcImg = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/gif' => @imagecreatefromgif($src),
            'image/webp' => @imagecreatefromwebp($src),
            default => null,
        };
        
        if (!$srcImg) return false;
        
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
        return (bool)$ok;
    }
}
