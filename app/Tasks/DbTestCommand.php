<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:test', description: 'Test MySQL connection and print server version')]
class DbTestCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $info = $this->db->testConnection();
        
        if ($info['driver'] === 'sqlite') {
            $size = isset($info['file_size']) ? round($info['file_size'] / 1024, 2) . 'KB' : '0KB';
            $output->writeln(sprintf(
                '<info>Connected:</info> %s %s (db=%s, size=%s)',
                $info['driver'],
                $info['version'] ?? 'unknown',
                $info['database'],
                $size
            ));
        } else {
            $output->writeln(sprintf(
                '<info>Connected:</info> %s %s @ %s:%d (db=%s)',
                $info['driver'],
                $info['version'] ?? 'unknown',
                $info['host'] ?? 'unknown',
                $info['port'] ?? 0,
                $info['database']
            ));
        }
        
        return Command::SUCCESS;
    }
}

