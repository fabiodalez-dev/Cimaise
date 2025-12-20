<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use App\Services\SettingsService;
use App\Services\UploadService;

class VariantMaintenanceService
{
    private const SETTINGS_KEY = 'maintenance.variants_daily_last_run';
    private const LOCK_FILE = '/storage/tmp/variants_daily.lock';

    public function __construct(private Database $db)
    {
    }

    public function runDaily(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $settings = new SettingsService($this->db);
        $lastRun = (string)$settings->get(self::SETTINGS_KEY, '');
        if (!$this->shouldRun($settings, $today, $lastRun)) {
            return;
        }

        $lockHandle = $this->acquireLock();
        if ($lockHandle === null) {
            return;
        }

        try {
            $lastRun = (string)$settings->get(self::SETTINGS_KEY, '');
            if (!$this->shouldRun($settings, $today, $lastRun)) {
                return;
            }

            $stats = $this->generateMissingVariants($settings);
            $settings->set(self::SETTINGS_KEY, $today);
            Logger::info('Variant maintenance completed', $stats, 'maintenance');
        } catch (\Throwable $e) {
            Logger::warning('Variant maintenance failed', ['error' => $e->getMessage()], 'maintenance');
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    private function acquireLock(): mixed
    {
        $lockPath = dirname(__DIR__, 2) . self::LOCK_FILE;
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function releaseLock(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function generateMissingVariants(SettingsService $settings): array
    {
        $pdo = $this->db->pdo();
        $uploadService = new UploadService($this->db);
        [$enabledFormats, $variants] = $this->resolveEnabledFormatsAndVariants($settings);
        if ($enabledFormats === [] || $variants === []) {
            return [
                'images_checked' => 0,
                'variants_generated' => 0,
                'variants_skipped' => 0,
                'variants_failed' => 0,
                'blur_generated' => 0,
                'blur_failed' => 0,
            ];
        }

        $expected = count($enabledFormats) * count($variants);
        $formatPlaceholders = implode(',', array_fill(0, count($enabledFormats), '?'));
        $variantPlaceholders = implode(',', array_fill(0, count($variants), '?'));
        $sql = "
            SELECT i.id, COUNT(iv.id) as variant_count
            FROM images i
            LEFT JOIN image_variants iv
                ON iv.image_id = i.id
                AND iv.variant IN ({$variantPlaceholders})
                AND iv.format IN ({$formatPlaceholders})
            GROUP BY i.id
            HAVING variant_count < ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($variants, $enabledFormats, [$expected]));
        $images = $stmt->fetchAll() ?: [];

        $stats = [
            'images_checked' => count($images),
            'variants_generated' => 0,
            'variants_skipped' => 0,
            'variants_failed' => 0,
            'blur_generated' => 0,
            'blur_failed' => 0,
        ];

        foreach ($images as $image) {
            try {
                $result = $uploadService->generateVariantsForImage((int)$image['id'], false);
                $stats['variants_generated'] += (int)($result['generated'] ?? 0);
                $stats['variants_skipped'] += (int)($result['skipped'] ?? 0);
                $stats['variants_failed'] += (int)($result['failed'] ?? 0);
            } catch (\Throwable) {
                $stats['variants_failed']++;
            }
        }

        $blurStmt = $pdo->prepare("
            SELECT i.id
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'blur'
            WHERE a.is_nsfw = 1 AND iv.id IS NULL
        ");
        $blurStmt->execute();
        $blurImages = $blurStmt->fetchAll() ?: [];

        foreach ($blurImages as $image) {
            try {
                $blurPath = $uploadService->generateBlurredVariant((int)$image['id'], false);
                if ($blurPath !== null) {
                    $stats['blur_generated']++;
                }
            } catch (\Throwable) {
                $stats['blur_failed']++;
            }
        }

        return $stats;
    }

    private function shouldRun(SettingsService $settings, string $today, string $lastRun): bool
    {
        if ($lastRun !== $today) {
            return true;
        }

        return $this->hasMissingVariants($settings);
    }

    private function hasMissingVariants(SettingsService $settings): bool
    {
        [$enabledFormats, $variants] = $this->resolveEnabledFormatsAndVariants($settings);
        if ($enabledFormats === [] || $variants === []) {
            return false;
        }

        $pdo = $this->db->pdo();
        $expected = count($enabledFormats) * count($variants);
        $formatPlaceholders = implode(',', array_fill(0, count($enabledFormats), '?'));
        $variantPlaceholders = implode(',', array_fill(0, count($variants), '?'));
        $sql = "
            SELECT i.id
            FROM images i
            LEFT JOIN image_variants iv
                ON iv.image_id = i.id
                AND iv.variant IN ({$variantPlaceholders})
                AND iv.format IN ({$formatPlaceholders})
            GROUP BY i.id
            HAVING COUNT(iv.id) < ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($variants, $enabledFormats, [$expected]));
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        $blurStmt = $pdo->prepare("
            SELECT i.id
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'blur'
            WHERE a.is_nsfw = 1 AND iv.id IS NULL
            LIMIT 1
        ");
        $blurStmt->execute();
        return $blurStmt->fetchColumn() !== false;
    }

    private function resolveEnabledFormatsAndVariants(SettingsService $settings): array
    {
        $defaults = $settings->defaults();

        $formats = $settings->get('image.formats', $defaults['image.formats']);
        if (!is_array($formats) || !$formats) {
            $formats = $defaults['image.formats'];
        }
        $breakpoints = $settings->get('image.breakpoints', $defaults['image.breakpoints']);
        if (!is_array($breakpoints) || !$breakpoints) {
            $breakpoints = $defaults['image.breakpoints'];
        }

        $enabledFormats = [];
        foreach ($formats as $format => $enabled) {
            if (is_string($enabled)) {
                $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            }
            if ($enabled) {
                $enabledFormats[] = (string)$format;
            }
        }

        return [$enabledFormats, array_keys($breakpoints)];
    }
}
