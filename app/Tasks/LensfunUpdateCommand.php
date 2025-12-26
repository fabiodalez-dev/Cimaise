<?php
declare(strict_types=1);

namespace App\Tasks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to update the Lensfun camera/lens database.
 * Downloads XML files from https://github.com/lensfun/lensfun
 */
class LensfunUpdateCommand extends Command
{
    protected static $defaultName = 'lensfun:update';
    protected static $defaultDescription = 'Update Lensfun camera/lens database from GitHub';

    private string $dataDir;
    private string $cacheFile;

    // List of XML files to download
    private const XML_FILES = [
        '6x6', 'actioncams',
        'compact-canon', 'compact-casio', 'compact-fujifilm', 'compact-kodak',
        'compact-konica-minolta', 'compact-leica', 'compact-nikon', 'compact-olympus',
        'compact-panasonic', 'compact-pentax', 'compact-ricoh', 'compact-samsung',
        'compact-sigma', 'compact-sony',
        'contax', 'generic',
        'mil-canon', 'mil-fujifilm', 'mil-leica', 'mil-nikon', 'mil-olympus',
        'mil-panasonic', 'mil-pentax', 'mil-samsung', 'mil-sigma', 'mil-sony', 'mil-yongnuo',
        'misc', 'om-system',
        'rf-canon', 'rf-contax', 'rf-fujifilm', 'rf-leica', 'rf-minolta',
        'rf-nikon', 'rf-olympus', 'rf-voigtlander',
        'slr-canon', 'slr-contax', 'slr-fujifilm', 'slr-hasselblad', 'slr-konica-minolta',
        'slr-mamiya', 'slr-minolta', 'slr-nikon', 'slr-olympus', 'slr-panasonic',
        'slr-pentax', 'slr-samsung', 'slr-sigma', 'slr-sony'
    ];

    private const BASE_URL = 'https://raw.githubusercontent.com/lensfun/lensfun/master/data/db/';

    public function __construct()
    {
        parent::__construct();
        $this->dataDir = dirname(__DIR__, 2) . '/storage/lensfun';
        $this->cacheFile = dirname(__DIR__, 2) . '/storage/cache/lensfun.json';
    }

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription)
             ->setHelp('Downloads the latest Lensfun database from GitHub and rebuilds the cache.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Lensfun Database Update');

        // Ensure directory exists
        if (!is_dir($this->dataDir)) {
            if (!@mkdir($this->dataDir, 0775, true)) {
                $io->error("Failed to create directory: {$this->dataDir}");
                return Command::FAILURE;
            }
        }

        $io->section('Downloading XML files from GitHub...');
        $progressBar = $io->createProgressBar(count(self::XML_FILES));
        $progressBar->start();

        $downloaded = 0;
        $failed = [];

        foreach (self::XML_FILES as $file) {
            $url = self::BASE_URL . $file . '.xml';
            $localPath = $this->dataDir . '/' . $file . '.xml';

            $content = @file_get_contents($url);
            if ($content !== false) {
                if (@file_put_contents($localPath, $content)) {
                    $downloaded++;
                } else {
                    $failed[] = $file;
                }
            } else {
                $failed[] = $file;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        if (!empty($failed)) {
            $io->warning('Some files failed to download: ' . implode(', ', $failed));
        }

        $io->success("Downloaded {$downloaded}/" . count(self::XML_FILES) . " XML files");

        // Delete cache to force rebuild
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
            $io->info('Cache cleared - will be rebuilt on next access');
        }

        // Rebuild cache now
        $io->section('Rebuilding cache...');

        $lensfun = new \App\Services\LensfunService($this->dataDir);
        $stats = $lensfun->rebuildCache();

        $io->table(
            ['Metric', 'Count'],
            [
                ['Cameras', $stats['cameras']],
                ['Lenses', $stats['lenses']],
                ['Camera Makers', $stats['camera_makers']],
                ['Lens Makers', $stats['lens_makers']],
            ]
        );

        $io->success('Lensfun database updated successfully!');

        return Command::SUCCESS;
    }
}
