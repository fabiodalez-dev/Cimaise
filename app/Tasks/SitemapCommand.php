<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Services\BaseUrlService;
use App\Services\SettingsService;
use App\Services\SitemapService;
use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sitemap:build')]
class SitemapCommand extends Command
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Generate sitemap.xml, sitemap-images.xml and update robots.txt')
             ->addOption('base-url', 'u', InputOption::VALUE_OPTIONAL, 'Base URL for the site (uses SEO settings or APP_URL if not provided)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get base URL from: CLI option > SEO settings > BaseUrlService (APP_URL or auto-detect)
        $cliBaseUrl = $input->getOption('base-url');

        $settingsService = new SettingsService($this->db);
        $settingsService->clearCache();
        $seoBaseUrl = $settingsService->get('seo.canonical_base_url', '');

        $baseUrl = $cliBaseUrl ?: ($seoBaseUrl ?: BaseUrlService::getCurrentBaseUrl());
        $baseUrl = rtrim((string) $baseUrl, '/');

        $publicDir = dirname(__DIR__, 2) . '/public';

        $output->writeln('Building sitemap...');

        // Delegate to SitemapService so the CLI and the admin UI emit exactly the
        // same URLs, filters and robots.txt handling (single source of truth).
        $result = (new SitemapService($this->db, $baseUrl, $publicDir))->generate();

        if (!empty($result['success'])) {
            $output->writeln('<info>' . ($result['message'] ?? 'Sitemap generated successfully') . '</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>' . ($result['error'] ?? 'Failed to generate sitemap') . '</error>');
        return Command::FAILURE;
    }
}
