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

#[AsCommand(name: 'images:generate-variants')]
class ImagesGenerateVariantsCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Generate missing image variants (for fast upload mode)')
             ->addOption('album', 'a', InputOption::VALUE_OPTIONAL, 'Process only images from specific album ID')
             ->addOption('image', 'i', InputOption::VALUE_OPTIONAL, 'Process only specific image ID')
             ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of images to process', '0')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force regeneration of existing variants');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $albumId = $input->getOption('album');
        $imageId = $input->getOption('image');
        $limit = (int)$input->getOption('limit');
        $force = $input->getOption('force');

        $output->writeln('<info>🚀 Starting variant generation...</info>');
        $output->writeln('');

        try {
            $pdo = $this->db->pdo();

            // Build query to get images needing variants
            $query = 'SELECT id, album_id FROM images WHERE 1=1';
            $params = [];

            if ($imageId) {
                $query .= ' AND id = ?';
                $params[] = (int)$imageId;
            } elseif ($albumId) {
                $query .= ' AND album_id = ?';
                $params[] = (int)$albumId;
            }

            $query .= ' ORDER BY id ASC';

            if ($limit > 0) {
                $query .= ' LIMIT ' . $limit;
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $images = $stmt->fetchAll();

            if (!$images) {
                $output->writeln('<comment>No images found to process.</comment>');
                return Command::SUCCESS;
            }

            $totalImages = count($images);
            $output->writeln("<info>Found {$totalImages} image(s) to process</info>");
            $output->writeln('');

            // Create progress bar
            $progressBar = new ProgressBar($output, $totalImages);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            $progressBar->setMessage('Starting...');
            $progressBar->start();

            $uploadService = new UploadService($this->db);
            $totalStats = ['generated' => 0, 'failed' => 0, 'skipped' => 0];
            $errors = [];

            foreach ($images as $image) {
                $imgId = (int)$image['id'];
                $progressBar->setMessage("Processing image #{$imgId}");

                try {
                    $stats = $uploadService->generateVariantsForImage($imgId, $force);
                    $totalStats['generated'] += $stats['generated'];
                    $totalStats['failed'] += $stats['failed'];
                    $totalStats['skipped'] += $stats['skipped'];
                } catch (\Throwable $e) {
                    $errors[] = "Image #{$imgId}: " . $e->getMessage();
                    $totalStats['failed']++;
                }

                $progressBar->advance();
            }

            $progressBar->setMessage('Complete!');
            $progressBar->finish();
            $output->writeln('');
            $output->writeln('');

            // Print summary
            $forceIndicator = $force ? ' <fg=magenta>(FORCE MODE)</>' : '';
            $output->writeln('<info>════════════════════════════════════════</info>');
            $output->writeln("<info>         GENERATION SUMMARY</info>{$forceIndicator}");
            $output->writeln('<info>════════════════════════════════════════</info>');
            $output->writeln(sprintf('<fg=green>✓ Generated: %d variants</>', $totalStats['generated']));
            $output->writeln(sprintf('<fg=yellow>⊘ Skipped:   %d variants (already exist)</>', $totalStats['skipped']));
            $output->writeln(sprintf('<fg=red>✗ Failed:    %d variants</>', $totalStats['failed']));
            $output->writeln('<info>════════════════════════════════════════</info>');

            if ($errors) {
                $output->writeln('');
                $output->writeln('<error>Errors encountered:</error>');
                foreach ($errors as $error) {
                    $output->writeln("<error>  • {$error}</error>");
                }
            }

            $output->writeln('');
            $output->writeln('<info>✓ Variant generation complete!</info>');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<error>Fatal error: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>' . $e->getTraceAsString() . '</error>');
            return Command::FAILURE;
        }
    }
}
