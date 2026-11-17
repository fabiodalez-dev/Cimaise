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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('missing')) {
            $input->setOption('force', true);
        }
        // One library-wide run at a time: a second click on "Generate" (or a
        // second admin) would redo the same work and truncate the shared log.
        $lockDir = dirname(__DIR__, 2) . '/storage/image-jobs/locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lock = @fopen($lockDir . '/images-generate.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $output->writeln('<comment>Another images:generate run is still in progress; not starting a second one.</comment>');
            return self::SUCCESS;
        }
        try {
            return parent::execute($input, $output);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
