<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'diagnostics:report', description: 'Print environment diagnostics (DB, extensions, filesystem)')]
class DiagnosticsCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // DB
        try {
            $info = $this->db->testConnection();
            $output->writeln(sprintf('<info>DB:</info> %s %s @ %s:%d (db=%s)', $info['driver'], $info['version'] ?? 'n/a', $info['host'], $info['port'], $info['database']));
        } catch (\Throwable $e) {
            $output->writeln('<error>DB connection failed: ' . $e->getMessage() . '</error>');
        }

        // PHP extensions
        $exts = [
            'gd' => extension_loaded('gd'),
            'imagick' => class_exists('Imagick'),
            'json' => extension_loaded('json'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
        ];
        foreach ($exts as $name => $ok) {
            $output->writeln(sprintf('%-12s %s', $name . ':', $ok ? '<info>ENABLED</info>' : '<comment>MISSING</comment>'));
        }

        // FS permissions
        $root = dirname(__DIR__, 2);
        $dirs = [
            '/storage',
            '/storage/originals',
            '/public/media',
        ];
        foreach ($dirs as $d) {
            $path = $root . $d;
            $ok = is_dir($path) || @mkdir($path, 0775, true);
            $w = $ok && is_writable($path);
            $output->writeln(sprintf('%-16s %s', $d . ':', $ok && $w ? '<info>OK (writable)</info>' : ($ok ? '<comment>NOT WRITABLE</comment>' : '<error>MISSING</error>')));
        }

        return Command::SUCCESS;
    }
}

