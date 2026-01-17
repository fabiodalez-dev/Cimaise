<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\UploadService;
use App\Support\Database;
use App\Support\Logger;

/**
 * CLI Command: Generate LQIP (Low Quality Image Placeholders) for existing images
 *
 * Usage:
 *   php bin/console images:generate-lqip [--force] [--album=ID] [--limit=N]
 *
 * Options:
 *   --force        Regenerate even if LQIP already exists
 *   --album=ID     Only process images from specific album
 *   --limit=N      Limit number of images to process (default: no limit)
 *   --dry-run      Show what would be done without actually generating
 *
 * Examples:
 *   php bin/console images:generate-lqip
 *   php bin/console images:generate-lqip --force
 *   php bin/console images:generate-lqip --album=5
 *   php bin/console images:generate-lqip --limit=100
 *   php bin/console images:generate-lqip --dry-run
 */
class ImagesGenerateLQIPCommand
{
    private Database $db;
    private UploadService $uploadService;
    private bool $force = false;
    private ?int $albumId = null;
    private ?int $limit = null;
    private bool $dryRun = false;

    public function __construct()
    {
        $this->db = new Database();
        $this->uploadService = new UploadService($this->db);
    }

    public function execute(array $args = []): int
    {
        $this->parseArgs($args);

        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════╗\n";
        echo "║  LQIP Generator - Low Quality Image Placeholders                    ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════╝\n";
        echo "\n";

        if ($this->dryRun) {
            echo "🔍 DRY RUN MODE - No files will be generated\n\n";
        }

        // Get images to process
        $images = $this->getImagesToProcess();

        if (empty($images)) {
            echo "✅ No images found to process.\n\n";
            return 0;
        }

        $total = count($images);
        $processed = 0;
        $generated = 0;
        $skipped = 0;
        $errors = 0;

        echo "📊 Found {$total} images to process\n";
        if ($this->force) {
            echo "⚡ Force mode: Will regenerate existing LQIPs\n";
        }
        echo "\n";

        $startTime = microtime(true);

        foreach ($images as $index => $image) {
            $imageId = (int) $image['id'];
            $albumTitle = $image['album_title'] ?? 'Unknown';
            $progress = $index + 1;

            echo sprintf(
                "[%d/%d] Processing image #%d (%s)... ",
                $progress,
                $total,
                $imageId,
                $albumTitle
            );

            if ($this->dryRun) {
                echo "DRY RUN\n";
                $processed++;
                continue;
            }

            try {
                $result = $this->uploadService->generateLQIP($imageId, $this->force);

                if ($result === null) {
                    // Skipped (likely protected album or source not found)
                    echo "⏭️  SKIPPED\n";
                    $skipped++;
                } else {
                    echo "✅ GENERATED\n";
                    $generated++;
                }

                $processed++;
            } catch (\Throwable $e) {
                echo "❌ ERROR: " . $e->getMessage() . "\n";
                Logger::error('LQIP generation failed', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage()
                ], 'cli');
                $errors++;
            }
        }

        $elapsed = microtime(true) - $startTime;

        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════╗\n";
        echo "║  Summary                                                             ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo "Total images:     {$total}\n";
        echo "✅ Generated:     {$generated}\n";
        echo "⏭️  Skipped:       {$skipped}\n";
        echo "❌ Errors:        {$errors}\n";
        echo "⏱️  Time elapsed:  " . number_format($elapsed, 2) . "s\n";

        if ($generated > 0) {
            $avgTime = $elapsed / $generated;
            echo "⚡ Avg per image:  " . number_format($avgTime, 3) . "s\n";
        }

        echo "\n";

        if ($skipped > 0) {
            echo "ℹ️  Note: Skipped images are likely from protected albums (password/NSFW)\n";
            echo "   or source files could not be found.\n\n";
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Get images to process based on filters
     */
    private function getImagesToProcess(): array
    {
        $pdo = $this->db->pdo();

        // Base query: images from public albums only (no password, no NSFW)
        $sql = '
            SELECT i.id, i.album_id, a.title as album_title, a.password_hash, a.is_nsfw
            FROM images i
            JOIN albums a ON i.album_id = a.id
            WHERE 1=1
        ';

        $params = [];

        // Filter: Skip protected albums (security requirement)
        $sql .= ' AND (a.password_hash IS NULL OR a.password_hash = "")';
        $sql .= ' AND (a.is_nsfw IS NULL OR a.is_nsfw = 0)';

        // Filter: Specific album
        if ($this->albumId !== null) {
            $sql .= ' AND a.id = ?';
            $params[] = $this->albumId;
        }

        // Filter: Skip if LQIP already exists (unless force mode)
        if (!$this->force) {
            $sql .= ' AND NOT EXISTS (
                SELECT 1 FROM image_variants
                WHERE image_id = i.id AND variant = "lqip"
            )';
        }

        $sql .= ' ORDER BY i.id ASC';

        // Limit
        if ($this->limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $this->limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Parse command line arguments
     */
    private function parseArgs(array $args): void
    {
        foreach ($args as $arg) {
            if ($arg === '--force') {
                $this->force = true;
            } elseif ($arg === '--dry-run') {
                $this->dryRun = true;
            } elseif (str_starts_with($arg, '--album=')) {
                $this->albumId = (int) substr($arg, 8);
            } elseif (str_starts_with($arg, '--limit=')) {
                $this->limit = (int) substr($arg, 8);
            }
        }
    }

    /**
     * Show help text
     */
    public static function help(): string
    {
        return <<<'HELP'
Generate LQIP (Low Quality Image Placeholders) for instant perceived loading

USAGE:
  php bin/console images:generate-lqip [OPTIONS]

OPTIONS:
  --force        Regenerate even if LQIP already exists
  --album=ID     Only process images from specific album ID
  --limit=N      Limit number of images to process
  --dry-run      Show what would be done without actually generating

EXAMPLES:
  # Generate LQIP for all new images
  php bin/console images:generate-lqip

  # Force regenerate all LQIPs
  php bin/console images:generate-lqip --force

  # Generate LQIP for specific album
  php bin/console images:generate-lqip --album=5

  # Process first 100 images
  php bin/console images:generate-lqip --limit=100

  # Dry run to see what would happen
  php bin/console images:generate-lqip --dry-run

SECURITY:
  LQIP is only generated for public albums (no password, no NSFW).
  Protected albums continue using blur variants for privacy.

PERFORMANCE:
  Each LQIP is ~1-2KB (40x30px with light blur).
  Processing time: ~0.1-0.3s per image.
  LQIPs are inlined as base64 for instant rendering (zero HTTP requests).

HELP;
    }
}
