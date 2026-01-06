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

#[AsCommand(name: 'images:generate-blur')]
class BlurGenerateImageCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Generate blur variant for a single image')
            ->addOption('image', 'i', InputOption::VALUE_REQUIRED, 'Image ID to process')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force regeneration of existing blur variant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $imageId = (int)$input->getOption('image');
        if ($imageId <= 0) {
            $output->writeln('<error>Missing or invalid image ID.</error>');
            return Command::INVALID;
        }

        try {
            $settings = new SettingsService($this->db);
            $settings->clearCache();
            $uploadService = new UploadService($this->db);
            $result = $uploadService->generateBlurredVariant($imageId, (bool)$input->getOption('force'));
            if ($result === null) {
                $output->writeln('<error>Blur generation failed or source image missing.</error>');
                return Command::FAILURE;
            }

            $output->writeln('<info>Blur variant generated.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Fatal error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
