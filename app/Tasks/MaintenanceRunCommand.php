<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\VariantMaintenanceService;
use App\Services\SettingsService;
use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Maintenance command for cron-based variant generation.
 *
 * Generates missing image variants and blur variants for protected albums
 * (NSFW and password-protected).
 *
 * Recommended cron setup (daily at 3 AM):
 *   0 3 * * * cd /path/to/cimaise && php bin/console maintenance:run --quiet-mode
 *
 * For high-traffic sites, run more frequently (every 6 hours):
 *   0 0,6,12,18 * * * cd /path/to/cimaise && php bin/console maintenance:run --quiet-mode
 */
#[AsCommand(name: 'maintenance:run')]
class MaintenanceRunCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Run daily maintenance tasks (variant generation, blur for protected albums)')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force run even if already run today')
             ->addOption('quiet-mode', null, InputOption::VALUE_NONE, 'Suppress all output (for cron)')
             ->setHelp(<<<'HELP'
The <info>maintenance:run</info> command runs daily maintenance tasks:

  <info>php bin/console maintenance:run</info>

This command:
  - Generates missing image variants for all enabled formats and breakpoints
  - Generates blur variants for NSFW and password-protected albums
  - Uses file-based locking to prevent concurrent execution
  - Tracks last run date to avoid duplicate runs

For cron usage, add the --quiet-mode flag:

  <info>0 3 * * * cd /path/to/cimaise && php bin/console maintenance:run --quiet-mode</info>

To force a run even if already run today:

  <info>php bin/console maintenance:run --force</info>
HELP
);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $quiet = $input->getOption('quiet-mode');

        if (!$quiet) {
            $output->writeln('<info>Running daily maintenance tasks...</info>');
            $output->writeln('');
        }

        try {
            $maintenanceService = new VariantMaintenanceService($this->db);

            if ($force) {
                // Reset the last run date to force execution
                $settings = new SettingsService($this->db);
                $settings->clearCache();
                $settings->set('maintenance.variants_daily_last_run', '');
            }

            $maintenanceService->runDaily();

            if (!$quiet) {
                $output->writeln('<info>Maintenance tasks completed successfully.</info>');
                $output->writeln('');
                $output->writeln('Tasks performed:');
                $output->writeln('  - Generated missing image variants');
                $output->writeln('  - Generated blur variants for NSFW/password-protected albums');
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            if (!$quiet) {
                $output->writeln('');
                $output->writeln('<error>Maintenance failed: ' . $e->getMessage() . '</error>');
            }
            return Command::FAILURE;
        }
    }
}
