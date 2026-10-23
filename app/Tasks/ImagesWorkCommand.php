<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Services\ImageJobQueue;
use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drains the image job queue: generates the variants, LQIP placeholder and
 * blurred preview of every uploaded image that is still waiting for them.
 *
 * The Docker image runs it automatically. Elsewhere it is only needed when
 * PHP does not run as PHP-FPM (which processes uploads by itself):
 *   * * * * * cd /path/to/cimaise && php bin/console images:work --quiet-mode
 */
#[AsCommand(name: 'images:work')]
class ImagesWorkCommand extends Command
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Process pending image jobs (variants, LQIP, blur); failed jobs stay queued for retry')
             ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop starting new jobs after this many seconds (0 = no limit)', '0')
             ->addOption('watch', null, InputOption::VALUE_NONE, 'Retry queued jobs for up to five minutes (compatibility option)')
             ->addOption('quiet-mode', null, InputOption::VALUE_NONE, 'Suppress all output (for cron)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quiet = (bool) $input->getOption('quiet-mode');
        try {
            $queue = new ImageJobQueue($this->db);
            $watch = (bool)$input->getOption('watch');
            $timeLimit = max(0, (int)$input->getOption('time-limit'));
            $deadline = time() + ($timeLimit > 0 ? $timeLimit : 300);
            $result = ['processed' => 0, 'failed' => 0, 'remaining' => 0];
            do {
                $pass = $queue->drain($watch ? max(1, $deadline - time()) : $timeLimit);
                $result['processed'] += $pass['processed'];
                $result['failed'] += $pass['failed'];
                $result['remaining'] = $pass['remaining'];
                if (!$watch || !$pass['remaining'] || time() >= $deadline) {
                    break;
                }
                sleep(2);
            } while (true);
        } catch (\Throwable $e) {
            if (!$quiet) {
                $output->writeln('<error>Image worker failed: ' . $e->getMessage() . '</error>');
            }
            return Command::FAILURE;
        }
        if (!$quiet) {
            $output->writeln(sprintf(
                'Image jobs: %d processed, %d failed, %d still queued',
                $result['processed'],
                $result['failed'],
                $result['remaining']
            ));
        }
        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
