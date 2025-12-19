<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use Icamys\SitemapGenerator\SitemapGenerator;

/**
 * SitemapService
 * Generates XML sitemap for search engine indexing using icamys/php-sitemap-generator
 */
class SitemapService
{
    private Database $db;
    private string $baseUrl;
    private string $publicPath;

    public function __construct(Database $db, string $baseUrl, string $publicPath)
    {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->publicPath = rtrim($publicPath, '/');
    }

    /**
     * Generate sitemap.xml file
     *
     * @return array Result with success status and message
     */
    public function generate(): array
    {
        try {
            $sitemap = new SitemapGenerator($this->baseUrl);

            // Set the path where sitemap files will be saved
            $sitemap->setPath($this->publicPath . '/');

            // Set filename
            $sitemap->setFilename('sitemap');

            // Add homepage (highest priority)
            $sitemap->addURL('/', new \DateTime(), 'daily', 1.0);

            // Add static pages
            $settingsService = new SettingsService($this->db);
            $aboutSlug = (string)($settingsService->get('about.slug', 'about') ?? 'about');

            $sitemap->addURL('/' . $aboutSlug, new \DateTime(), 'monthly', 0.8);
            $sitemap->addURL('/galleries', new \DateTime(), 'weekly', 0.9);

            // Add categories
            $stmt = $this->db->query('SELECT slug, updated_at FROM categories WHERE slug IS NOT NULL ORDER BY slug');
            $categories = $stmt->fetchAll() ?: [];

            foreach ($categories as $category) {
                $updatedAt = !empty($category['updated_at'])
                    ? new \DateTime($category['updated_at'])
                    : new \DateTime();

                $sitemap->addURL('/category/' . $category['slug'], $updatedAt, 'weekly', 0.7);
            }

            // Add tags
            $stmt = $this->db->query('SELECT slug FROM tags WHERE slug IS NOT NULL ORDER BY slug');
            $tags = $stmt->fetchAll() ?: [];

            foreach ($tags as $tag) {
                $sitemap->addURL('/tag/' . $tag['slug'], new \DateTime(), 'weekly', 0.6);
            }

            // Add published albums (exclude NSFW and password-protected for privacy/SEO)
            $stmt = $this->db->query('
                SELECT slug, published_at, updated_at
                FROM albums
                WHERE is_published = 1
                  AND slug IS NOT NULL
                  AND (is_nsfw = 0 OR is_nsfw IS NULL)
                  AND (password_hash IS NULL OR password_hash = "")
                ORDER BY published_at DESC
            ');
            $albums = $stmt->fetchAll() ?: [];

            foreach ($albums as $album) {
                $updatedAt = !empty($album['updated_at'])
                    ? new \DateTime($album['updated_at'])
                    : (!empty($album['published_at']) ? new \DateTime($album['published_at']) : new \DateTime());

                $sitemap->addURL('/album/' . $album['slug'], $updatedAt, 'monthly', 0.8);
            }

            // Generate the sitemap files
            $sitemap->createSitemap();

            // Write sitemap index (if multiple sitemap files were created)
            $sitemap->writeSitemap();

            // Update robots.txt to include sitemap reference (if writable)
            $this->updateRobotsTxt();

            return [
                'success' => true,
                'message' => 'Sitemap generated successfully at ' . $this->baseUrl . '/sitemap.xml',
                'file' => $this->publicPath . '/sitemap.xml'
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate sitemap: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update robots.txt to include sitemap URL
     */
    private function updateRobotsTxt(): void
    {
        $robotsPath = $this->publicPath . '/robots.txt';

        // Read existing robots.txt or create default content
        $content = '';
        if (file_exists($robotsPath) && is_readable($robotsPath)) {
            $content = file_get_contents($robotsPath);
            if ($content === false) {
                $content = '';
            }
        }

        // Check if Sitemap directive already exists
        if (stripos($content, 'Sitemap:') === false) {
            // Add sitemap reference
            $sitemapUrl = $this->baseUrl . '/sitemap.xml';
            $content = trim($content) . "\n\nSitemap: " . $sitemapUrl . "\n";

            // Try to write (might fail if not writable, which is OK)
            @file_put_contents($robotsPath, $content);
        }
    }

    /**
     * Check if sitemap exists
     */
    public function exists(): bool
    {
        return file_exists($this->publicPath . '/sitemap.xml');
    }

    /**
     * Get sitemap last modification time
     */
    public function getLastModified(): ?int
    {
        $path = $this->publicPath . '/sitemap.xml';
        if (file_exists($path)) {
            $mtime = filemtime($path);
            return $mtime !== false ? $mtime : null;
        }
        return null;
    }
}
