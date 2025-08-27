<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\SettingsService;
use App\Support\Database;
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
        if (!$imagickOk) {
            $output->writeln('<comment>Imagick not available, generating only JPEG via GD (if any).</comment>');
        }

        foreach ($images as $img) {
            $imageId = (int)$img['id'];
            $src = dirname(__DIR__, 2) . $img['original_path'];
            if (!is_file($src)) continue;
            foreach ($breakpoints as $variant => $width) {
                foreach (['avif','webp','jpg'] as $fmt) {
                    if (empty($formats[$fmt])) continue;
                    $destRelUrl = "/media/{$imageId}_{$variant}.{$fmt}";
                    $dest = dirname(__DIR__, 2) . '/public/media/' . "{$imageId}_{$variant}.{$fmt}";
                    if ($missingOnly && is_file($dest)) continue;
                    $ok = false;
                    if ($fmt === 'jpg') {
                        $ok = $this->resizeWithImagickOrGd($src, $dest, (int)$width, 'jpeg', (int)$quality['jpg']);
                    } elseif ($fmt === 'webp') {
                        $ok = $imagickOk && $this->resizeWithImagick($src, $dest, (int)$width, 'webp', (int)$quality['webp']);
                    } elseif ($fmt === 'avif') {
                        $ok = $imagickOk && $this->resizeWithImagick($src, $dest, (int)$width, 'avif', (int)$quality['avif']);
                    }
                    if ($ok) {
                        $size = (int)filesize($dest);
                        [$w, $h] = getimagesize($dest) ?: [(int)$width, 0];
                        $stmt = $pdo->prepare('REPLACE INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)');
                        $stmt->execute([$imageId, $variant, $fmt, $destRelUrl, $w, $h, $size]);
                    }
                }
            }
            $output->writeln("Generated variants for image #{$imageId}");
        }

        $output->writeln('<info>Done.</info>');
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
        // GD fallback JPEG only
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW; $newH = (int)round($targetW / $ratio);
        $srcImg = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            default => null,
        };
        if (!$srcImg) return false;
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $srcImg, 0,0,0,0, $newW,$newH, $w,$h);
        @mkdir(dirname($dest), 0775, true);
        $ok = imagejpeg($dst, $dest, $quality);
        imagedestroy($srcImg); imagedestroy($dst);
        return (bool)$ok;
    }
}
