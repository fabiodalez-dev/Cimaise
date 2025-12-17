<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\UploadService;
use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

#[AsCommand(name: 'nsfw:generate-blur')]
class NsfwBlurGenerateCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Generate blurred image variants for NSFW album covers')
             ->addOption('album', 'a', InputOption::VALUE_OPTIONAL, 'Process only specific album ID')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force regeneration of existing blur variants')
             ->addOption('all', null, InputOption::VALUE_NONE, 'Process all images in NSFW albums (not just covers)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $albumId = $input->getOption('album');
        $force = $input->getOption('force');
        $processAll = $input->getOption('all');

        $output->writeln('<info>Generating blurred variants for NSFW albums...</info>');
        $output->writeln('');

        try {
            $pdo = $this->db->pdo();
            $uploadService = new UploadService($this->db);

            // Get NSFW albums
            $query = 'SELECT id, title, cover_image_id FROM albums WHERE is_nsfw = 1';
            $params = [];

            if ($albumId) {
                $query .= ' AND id = ?';
                $params[] = (int)$albumId;
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $albums = $stmt->fetchAll();

            if (!$albums) {
                $output->writeln('<comment>No NSFW albums found.</comment>');
                return Command::SUCCESS;
            }

            $totalAlbums = count($albums);
            $output->writeln("<info>Found {$totalAlbums} NSFW album(s)</info>");
            $output->writeln('');

            $totalStats = ['generated' => 0, 'failed' => 0, 'skipped' => 0];
            $errors = [];

            foreach ($albums as $album) {
                $output->writeln("<info>Processing album #{$album['id']}: {$album['title']}</info>");

                if ($processAll) {
                    // Generate blur for all images in album
                    $stats = $uploadService->generateBlurredVariantsForAlbum((int)$album['id'], $force);
                    $totalStats['generated'] += $stats['generated'];
                    $totalStats['failed'] += $stats['failed'];
                    $totalStats['skipped'] += $stats['skipped'];
                } else {
                    // Only generate blur for cover image
                    if ($album['cover_image_id']) {
                        try {
                            $result = $uploadService->generateBlurredVariant((int)$album['cover_image_id'], $force);
                            if ($result !== null) {
                                $totalStats['generated']++;
                                $output->writeln("  <fg=green>Generated blur for cover image #{$album['cover_image_id']}</>");
                            } else {
                                $totalStats['failed']++;
                                $errors[] = "Album #{$album['id']}: Failed to generate blur for cover";
                            }
                        } catch (\Throwable $e) {
                            $totalStats['failed']++;
                            $errors[] = "Album #{$album['id']}: " . $e->getMessage();
                        }
                    } else {
                        // Try first image as cover
                        $imgStmt = $pdo->prepare('SELECT id FROM images WHERE album_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1');
                        $imgStmt->execute([$album['id']]);
                        $firstImage = $imgStmt->fetch();

                        if ($firstImage) {
                            try {
                                $result = $uploadService->generateBlurredVariant((int)$firstImage['id'], $force);
                                if ($result !== null) {
                                    $totalStats['generated']++;
                                    $output->writeln("  <fg=green>Generated blur for first image #{$firstImage['id']}</>");
                                } else {
                                    $totalStats['failed']++;
                                    $errors[] = "Album #{$album['id']}: Failed to generate blur for first image";
                                }
                            } catch (\Throwable $e) {
                                $totalStats['failed']++;
                                $errors[] = "Album #{$album['id']}: " . $e->getMessage();
                            }
                        } else {
                            $totalStats['skipped']++;
                            $output->writeln("  <fg=yellow>No images in album</>");
                        }
                    }
                }
            }

            // Print summary
            $output->writeln('');
            $output->writeln('<info>========================================</info>');
            $output->writeln('<info>         GENERATION SUMMARY</info>');
            $output->writeln('<info>========================================</info>');
            $output->writeln(sprintf('<fg=green>Generated: %d blur variants</>', $totalStats['generated']));
            $output->writeln(sprintf('<fg=yellow>Skipped:   %d (no images or already exist)</>', $totalStats['skipped']));
            $output->writeln(sprintf('<fg=red>Failed:    %d</>', $totalStats['failed']));
            $output->writeln('<info>========================================</info>');

            if ($errors) {
                $output->writeln('');
                $output->writeln('<error>Errors:</error>');
                foreach ($errors as $error) {
                    $output->writeln("<error>  {$error}</error>");
                }
            }

            $output->writeln('');
            $output->writeln('<info>Done!</info>');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<error>Fatal error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
