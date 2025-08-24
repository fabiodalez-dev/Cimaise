<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:migrate', description: 'Run SQL migrations from database/migrations')] 
class MigrateCommand extends Command
{
    public function __construct(private Database $db, private string $migrationsDir)
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
        
        // Choose migration set based on actual Database driver
        $migrationsPath = $this->migrationsDir;
        $connection = 'mysql';
        try {
            if ($this->db->isSqlite()) {
                $migrationsPath = $this->migrationsDir . '/sqlite';
                $connection = 'sqlite';
            }
        } catch (\Throwable) {
            // Fallback to env if db unavailable
            $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'mysql');
            if ($connection === 'sqlite') {
                $migrationsPath = $this->migrationsDir . '/sqlite';
            }
        }
        
        if (!is_dir($migrationsPath)) {
            $output->writeln('<comment>No migrations directory found: ' . $migrationsPath . '</comment>');
            return Command::SUCCESS;
        }

        $files = glob(rtrim($migrationsPath, '/'). '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        if ($only) {
            $files = array_filter($files, fn($f) => basename($f) === $only);
        }

        if (!$files) {
            $output->writeln('<comment>No migration files to run.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Using ' . $connection . ' migrations from: ' . $migrationsPath . '</info>');

        foreach ($files as $file) {
            $base = basename($file);
            // Idempotency checks for SQLite ALTER statements
            if ($connection === 'sqlite') {
                if ($base === '0005_categories_hierarchy.sql') {
                    $exists = $this->columnExists('categories', 'parent_id');
                    if ($exists) { $output->writeln('Skipping (exists): ' . $base); continue; }
                }
                if ($base === '0007_album_show_date.sql') {
                    $exists = $this->columnExists('albums', 'show_date');
                    if ($exists) { $output->writeln('Skipping (exists): ' . $base); continue; }
                }
                if ($base === '0008_templates.sql') {
                    $exists = $this->columnExists('albums', 'template_id');
                    if ($exists) { $output->writeln('Skipping (exists): ' . $base); continue; }
                }
                if ($base === '0010_album_equipment.sql') {
                    if ($this->tableExists('album_camera')) { $output->writeln('Skipping (exists): ' . $base); continue; }
                }
                if ($base === '0011_album_protection.sql') {
                    if ($this->columnExists('albums','password_hash')) { $output->writeln('Skipping (exists): ' . $base); continue; }
                }
                if ($base === '0012_locations.sql') {
                    if ($this->tableExists('locations')) { $output->writeln('Skipping (exists): ' . $base); continue; }
                }
                if ($base === '0013_location_relationships.sql') {
                    if ($this->tableExists('album_location') && $this->tableExists('image_location')) { $output->writeln('Skipping (exists): ' . $base); continue; }
                }
            }

            $output->writeln('Running: ' . $base);
            try {
                $this->db->execSqlFile($file);
                $output->writeln('  ✅ Success');
            } catch (\Throwable $e) {
                $output->writeln('  ❌ Error: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $output->writeln('<info>Migrations completed.</info>');
        return Command::SUCCESS;
    }

    // Helper to check column existence (SQLite only)
    private function columnExists(string $table, string $column): bool
    {
        try {
            $pdo = $this->db->pdo();
            $stmt = $pdo->query("PRAGMA table_info('" . str_replace("'","''", $table) . "')");
            $cols = $stmt ? $stmt->fetchAll() : [];
            foreach ($cols as $c) {
                if (isset($c['name']) && strtolower((string)$c['name']) === strtolower($column)) return true;
            }
        } catch (\Throwable) { /* ignore */ }
        return false;
    }

    // Helper to check table existence (SQLite only)
    private function tableExists(string $table): bool
    {
        try {
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :t LIMIT 1");
            $stmt->execute([':t' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }
}
