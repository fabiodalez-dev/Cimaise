<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\SettingsService;
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
        $this->setDescription('Generate blurred image variants for NSFW and password-protected album covers')
             ->addOption('album', 'a', InputOption::VALUE_OPTIONAL, 'Process only specific album ID')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force regeneration of existing blur variants')
             ->addOption('all', null, InputOption::VALUE_NONE, 'Process all images in protected albums (not just covers)')
             ->addOption('nsfw-only', null, InputOption::VALUE_NONE, 'Process only NSFW albums')
             ->addOption('password-only', null, InputOption::VALUE_NONE, 'Process only password-protected albums');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $albumId = $input->getOption('album');
        $force = $input->getOption('force');
        $processAll = $input->getOption('all');
        $nsfwOnly = $input->getOption('nsfw-only');
        $passwordOnly = $input->getOption('password-only');

        $output->writeln('<info>Generating blurred variants for protected albums...</info>');
        $output->writeln('');

        try {
            $pdo = $this->db->pdo();
            $settings = new SettingsService($this->db);
            $settings->clearCache();
            $uploadService = new UploadService($this->db);

            // Build query for protected albums (NSFW and/or password-protected)
            $conditions = [];
            if ($nsfwOnly) {
                $conditions[] = 'is_nsfw = 1';
            } elseif ($passwordOnly) {
                $conditions[] = 'password_hash IS NOT NULL';
            } else {
                // Default: both NSFW and password-protected
                $conditions[] = '(is_nsfw = 1 OR password_hash IS NOT NULL)';
            }

            $query = 'SELECT id, title, cover_image_id, is_nsfw, (password_hash IS NOT NULL) as is_password_protected FROM albums WHERE ' . implode(' AND ', $conditions);
            $params = [];

            if ($albumId) {
                $query .= ' AND id = ?';
                $params[] = (int)$albumId;
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $albums = $stmt->fetchAll();

            if (!$albums) {
                $output->writeln('<comment>No protected albums found.</comment>');
                return Command::SUCCESS;
            }

            $totalAlbums = count($albums);
            $typeLabel = $nsfwOnly ? 'NSFW' : ($passwordOnly ? 'password-protected' : 'protected');
            $output->writeln("<info>Found {$totalAlbums} {$typeLabel} album(s)</info>");
            $output->writeln('');

            $totalStats = ['generated' => 0, 'failed' => 0, 'skipped' => 0];
            $errors = [];

            foreach ($albums as $album) {
                $protection = [];
                if (!empty($album['is_nsfw'])) {
                    $protection[] = 'NSFW';
                }
                if (!empty($album['is_password_protected'])) {
                    $protection[] = 'password';
                }
                $protectionLabel = implode('+', $protection);
                $output->writeln("<info>Processing album #{$album['id']}: {$album['title']} [{$protectionLabel}]</info>");

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
