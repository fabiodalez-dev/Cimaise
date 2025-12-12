<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\BaseUrlService;
use App\Services\SettingsService;
use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sitemap:build')]
class SitemapCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Generate sitemap.xml and robots.txt files')
             ->addOption('base-url', 'u', InputOption::VALUE_OPTIONAL, 'Base URL for the site (uses SEO settings or APP_URL if not provided)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get base URL from: CLI option > SEO settings > BaseUrlService (APP_URL or auto-detect)
        $cliBaseUrl = $input->getOption('base-url');

        $settingsService = new SettingsService($this->db);
        $seoBaseUrl = $settingsService->get('seo.canonical_base_url', '');

        $baseUrl = $cliBaseUrl ?: ($seoBaseUrl ?: BaseUrlService::getCurrentBaseUrl());
        $baseUrl = rtrim($baseUrl, '/');

        $publicDir = dirname(__DIR__, 2) . '/public';
        
        $output->writeln('Building sitemap...');
        
        try {
            $this->generateSitemap($baseUrl, $publicDir);
            $this->generateRobotsTxt($baseUrl, $publicDir);
            
            $output->writeln('<info>✓ Sitemap generated successfully</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to generate sitemap: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function generateSitemap(string $baseUrl, string $publicDir): void
    {
        $pdo = $this->db->pdo();
        $urls = [];
        
        // Homepage
        $urls[] = [
            'loc' => $baseUrl,
            'lastmod' => date('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => '1.0'
        ];
        
        // Categories
        $stmt = $pdo->prepare('
            SELECT c.*, MAX(a.updated_at) as last_updated
            FROM categories c
            LEFT JOIN albums a ON a.category_id = c.id AND a.is_published = 1
            GROUP BY c.id
            ORDER BY c.name
        ');
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        foreach ($categories as $category) {
            $urls[] = [
                'loc' => $baseUrl . '/category/' . $category['slug'],
                'lastmod' => $category['last_updated'] ? date('Y-m-d', strtotime($category['last_updated'])) : date('Y-m-d'),
                'changefreq' => 'weekly',
                'priority' => '0.8'
            ];
        }
        
        // Tags
        $stmt = $pdo->prepare('
            SELECT t.*, MAX(a.updated_at) as last_updated
            FROM tags t
            JOIN album_tag at ON at.tag_id = t.id
            JOIN albums a ON a.id = at.album_id AND a.is_published = 1
            GROUP BY t.id
            ORDER BY t.name
        ');
        $stmt->execute();
        $tags = $stmt->fetchAll();
        
        foreach ($tags as $tag) {
            $urls[] = [
                'loc' => $baseUrl . '/tag/' . $tag['slug'],
                'lastmod' => $tag['last_updated'] ? date('Y-m-d', strtotime($tag['last_updated'])) : date('Y-m-d'),
                'changefreq' => 'weekly',
                'priority' => '0.6'
            ];
        }
        
        // Albums
        $stmt = $pdo->prepare('
            SELECT slug, updated_at, published_at
            FROM albums
            WHERE is_published = 1
            ORDER BY published_at DESC
        ');
        $stmt->execute();
        $albums = $stmt->fetchAll();
        
        foreach ($albums as $album) {
            $lastmod = $album['updated_at'] ?: $album['published_at'];
            $urls[] = [
                'loc' => $baseUrl . '/album/' . $album['slug'],
                'lastmod' => date('Y-m-d', strtotime($lastmod)),
                'changefreq' => 'monthly',
                'priority' => '0.9'
            ];
        }
        
        // Generate XML
        $xml = $this->generateSitemapXml($urls);
        
        // Write to file
        $sitemapPath = $publicDir . '/sitemap.xml';
        if (file_put_contents($sitemapPath, $xml) === false) {
            throw new \RuntimeException('Failed to write sitemap.xml');
        }
        
        // Generate sitemap index if needed (for future use with multiple sitemaps)
        $indexXml = $this->generateSitemapIndex($baseUrl);
        $indexPath = $publicDir . '/sitemap_index.xml';
        file_put_contents($indexPath, $indexXml);
    }

    private function generateSitemapXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc']) . '</loc>' . "\n";
            $xml .= '    <lastmod>' . $url['lastmod'] . '</lastmod>' . "\n";
            $xml .= '    <changefreq>' . $url['changefreq'] . '</changefreq>' . "\n";
            $xml .= '    <priority>' . $url['priority'] . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        
        $xml .= '</urlset>' . "\n";
        return $xml;
    }

    private function generateSitemapIndex(string $baseUrl): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= '  <sitemap>' . "\n";
        $xml .= '    <loc>' . $baseUrl . '/sitemap.xml</loc>' . "\n";
        $xml .= '    <lastmod>' . date('Y-m-d') . '</lastmod>' . "\n";
        $xml .= '  </sitemap>' . "\n";
        $xml .= '</sitemapindex>' . "\n";
        return $xml;
    }

    private function generateRobotsTxt(string $baseUrl, string $publicDir): void
    {
        $robots = "User-agent: *\n";
        $robots .= "Allow: /\n";
        $robots .= "Disallow: /admin/\n";
        $robots .= "Disallow: /api/\n";
        $robots .= "\n";
        $robots .= "# Sitemaps\n";
        $robots .= "Sitemap: {$baseUrl}/sitemap.xml\n";
        $robots .= "Sitemap: {$baseUrl}/sitemap_index.xml\n";
        
        $robotsPath = $publicDir . '/robots.txt';
        if (file_put_contents($robotsPath, $robots) === false) {
            throw new \RuntimeException('Failed to write robots.txt');
        }
    }
}