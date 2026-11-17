<?php

declare(strict_types=1);

namespace App\Tasks;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Historical entry point, now sharing the safe, protection-aware encoder of
 * images:generate-variants. Its contract is kept: without options it
 * regenerates every variant; --missing only fills in what is absent.
 */
#[AsCommand(name: 'images:generate', description: 'Generate image variants as per settings')]
class ImagesGenerateCommand extends ImagesGenerateVariantsCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Generate image variants as per settings (all of them unless --missing)');
        $this->addOption('missing', null, InputOption::VALUE_NONE, 'Only generate missing variants');
        $this->addOption('reset-log', null, InputOption::VALUE_REQUIRED, 'Empty this log under storage/logs once the run holds the lock (used by the admin trigger)');
    }

    /** Lock shared by every library-wide run and checked by the admin trigger. */
    public static function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/image-jobs/locks/images-generate.lock';
    }

    /**
     * True while another process holds the library-wide lock. Used by the
     * admin endpoint to refuse a second run before it touches the shared log.
     */
    public static function isRunning(): bool
    {
        $path = self::lockPath();
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('missing')) {
            $input->setOption('force', true);
        }
        // One library-wide run at a time: a second click on "Generate" (or a
        // second admin) would redo the same work and race on the shared log.
        $lockPath = self::lockPath();
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            // Without the lock a run could overlap another one, so refuse
            // rather than silently skipping the guard.
            $output->writeln('<error>Cannot open the lock file ' . $lockPath . '; check the permissions of storage/image-jobs/locks.</error>');
            return self::FAILURE;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $output->writeln('<comment>Another images:generate run is still in progress; not starting a second one.</comment>');
            return self::SUCCESS;
        }
        $this->resetLog($input->getOption('reset-log'));
        try {
            return parent::execute($input, $output);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Empties the admin log once this run owns the lock. The admin trigger
     * opens the log in append mode, so emptying it here starts the run with
     * a clean log without ever wiping the log of a run still in progress.
     */
    private function resetLog(mixed $path): void
    {
        if (!is_string($path) || $path === '') {
            return;
        }
        $logsDir = realpath(dirname(__DIR__, 2) . '/storage/logs');
        $real = realpath($path);
        if ($logsDir === false || $real === false || dirname($real) !== $logsDir) {
            return;
        }
        $handle = @fopen($real, 'r+');
        if ($handle !== false) {
            ftruncate($handle, 0);
            fclose($handle);
        }
    }
}
