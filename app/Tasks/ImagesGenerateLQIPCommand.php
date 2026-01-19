<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\UploadService;
use App\Support\Database;
use App\Support\Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
#[AsCommand(name: 'images:generate-lqip')]
class ImagesGenerateLQIPCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Generate LQIP (Low Quality Image Placeholders) for instant perceived loading')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Regenerate even if LQIP already exists')
            ->addOption('album', 'a', InputOption::VALUE_REQUIRED, 'Only process images from specific album ID')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit number of images to process')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Show what would be done without actually generating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $albumId = $input->getOption('album') ? (int) $input->getOption('album') : null;
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $uploadService = new UploadService($this->db);

        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════════════════════════════════════╗</info>');
        $output->writeln('<info>║  LQIP Generator - Low Quality Image Placeholders                    ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════════════════════════════════════╝</info>');
        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN MODE - No files will be generated</comment>');
            $output->writeln('');
        }

        // Get images to process
        $images = $this->getImagesToProcess($force, $albumId, $limit);

        if (empty($images)) {
            $output->writeln('<info>No images found to process.</info>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $total = count($images);
        $generated = 0;
        $skipped = 0;
        $errors = 0;

        $output->writeln("Found <info>{$total}</info> images to process");
        if ($force) {
            $output->writeln('<comment>Force mode: Will regenerate existing LQIPs</comment>');
        }
        $output->writeln('');

        $startTime = microtime(true);

        foreach ($images as $index => $image) {
            $imageId = (int) $image['id'];
            $albumTitle = $image['album_title'] ?? 'Unknown';
            $progress = $index + 1;

            $output->write(sprintf(
                "[%d/%d] Processing image #%d (%s)... ",
                $progress,
                $total,
                $imageId,
                $albumTitle
            ));

            if ($dryRun) {
                $output->writeln('<comment>DRY RUN</comment>');
                continue;
            }

            try {
                $result = $uploadService->generateLQIP($imageId, $force);

                if ($result === null) {
                    // Skipped (likely protected album or source not found)
                    $output->writeln('<comment>SKIPPED</comment>');
                    $skipped++;
                } else {
                    $output->writeln('<info>GENERATED</info>');
                    $generated++;
                }
            } catch (\Throwable $e) {
                $output->writeln('<error>ERROR: ' . $e->getMessage() . '</error>');
                Logger::error('LQIP generation failed', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage()
                ], 'cli');
                $errors++;
            }
        }

        $elapsed = microtime(true) - $startTime;

        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════════════════════════════════════╗</info>');
        $output->writeln('<info>║  Summary                                                             ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════════════════════════════════════╝</info>');
        $output->writeln('');
        $output->writeln("Total images:     {$total}");
        $output->writeln("<info>Generated:        {$generated}</info>");
        $output->writeln("<comment>Skipped:          {$skipped}</comment>");
        $output->writeln("<error>Errors:           {$errors}</error>");
        $output->writeln("Time elapsed:     " . number_format($elapsed, 2) . "s");

        if ($generated > 0) {
            $avgTime = $elapsed / $generated;
            $output->writeln("Avg per image:    " . number_format($avgTime, 3) . "s");
        }

        $output->writeln('');

        if ($skipped > 0) {
            $output->writeln('<comment>Note: Skipped images are likely from protected albums (password/NSFW)</comment>');
            $output->writeln('<comment>or source files could not be found.</comment>');
            $output->writeln('');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Get images to process based on filters
     */
    private function getImagesToProcess(bool $force, ?int $albumId, ?int $limit): array
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
        $sql .= ' AND (a.password_hash IS NULL OR a.password_hash = \'\')';
        $sql .= ' AND (a.is_nsfw IS NULL OR a.is_nsfw = 0)';

        // Filter: Specific album
        if ($albumId !== null) {
            $sql .= ' AND a.id = ?';
            $params[] = $albumId;
        }

        // Filter: Skip if LQIP already exists (unless force mode)
        if (!$force) {
            $sql .= ' AND NOT EXISTS (
                SELECT 1 FROM image_variants
                WHERE image_id = i.id AND variant = \'lqip\'
            )';
        }

        $sql .= ' ORDER BY i.id ASC';

        // Limit
        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
