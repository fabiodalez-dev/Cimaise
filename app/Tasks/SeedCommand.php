<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:seed', description: 'Run seed SQL files from database/seeds')]
class SeedCommand extends Command
{
    public function __construct(private Database $db, private string $seedsDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('only', InputArgument::OPTIONAL, 'Run only a specific SQL file (basename)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getArgument('only');
        
        // Check if we're using SQLite and use appropriate seeds
        $connection = $_ENV['DB_CONNECTION'] ?? 'mysql';
        $seedsPath = $this->seedsDir;
        if ($connection === 'sqlite') {
            $seedsPath = $this->seedsDir . '/sqlite';
        }
        
        if (!is_dir($seedsPath)) {
            $output->writeln('<comment>No seeds directory found: ' . $seedsPath . '</comment>');
            return Command::SUCCESS;
        }

        $files = glob(rtrim($seedsPath, '/'). '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        if ($only) {
            $files = array_filter($files, fn($f) => basename($f) === $only);
        }
        if (!$files) {
            $output->writeln('<comment>No seed files to run.</comment>');
            return Command::SUCCESS;
        }
        
        $output->writeln('<info>Using ' . $connection . ' seeds from: ' . $seedsPath . '</info>');
        
        foreach ($files as $file) {
            $output->writeln('Seeding: ' . basename($file));
            try {
                $this->db->execSqlFile($file);
                $output->writeln('  ✅ Success');
            } catch (\Throwable $e) {
                $output->writeln('  ❌ Error: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }
        $output->writeln('<info>Seeding completed.</info>');
        return Command::SUCCESS;
    }
}

