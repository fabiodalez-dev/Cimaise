<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use Icamys\SitemapGenerator\SitemapGenerator;
use Icamys\SitemapGenerator\Config;

/**
 * SitemapService
 * Generates XML sitemap for search engine indexing using icamys/php-sitemap-generator
 * Includes Google Image Sitemap extension for better image SEO
 */
class SitemapService
{
    private Database $db;
    private string $baseUrl;
    private string $publicPath;
    private ?SettingsService $settingsService = null;

    public function __construct(Database $db, string $baseUrl, string $publicPath)
    {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->publicPath = rtrim($publicPath, '/');
    }

    /**
     * Get or create SettingsService instance
     */
    private function getSettingsService(): SettingsService
    {
        if ($this->settingsService === null) {
            $this->settingsService = new SettingsService($this->db);
        }
        return $this->settingsService;
    }

    /**
     * Generate sitemap.xml file
     *
     * @return array Result with success status and message
     */
    public function generate(): array
    {
        try {
            // Create config for SitemapGenerator v5.0
            $config = (new Config())
                ->setBaseURL($this->baseUrl)
                ->setSaveDirectory($this->publicPath)
                ->setSitemapIndexURL($this->baseUrl . '/sitemap.xml');

            $sitemap = new SitemapGenerator($config);
            $sitemap->setSitemapFilename('sitemap.xml');

            // Add homepage (highest priority)
            $sitemap->addURL('/', new \DateTime(), 'daily', 1.0);

            // Add static pages
            $settingsService = $this->getSettingsService();
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

            // Flush URLs to disk and finalize sitemap files
            $sitemap->flush();
            $sitemap->finalize();

            // Generate image sitemap separately (Google Image extension)
            $this->generateImageSitemap();

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
     * Generate image sitemap with Google Image extension
     * Creates sitemap-images.xml with <image:image> tags for each album's images
     */
    private function generateImageSitemap(): void
    {
        $settingsService = $this->getSettingsService();
        $licenseUrl = $settingsService->get('seo.image_license_url', '');
        $copyrightNotice = $settingsService->get('seo.image_copyright_notice', '');

        // Get all public albums with their images
        $stmt = $this->db->query('
            SELECT a.id, a.slug, a.title, a.updated_at
            FROM albums a
            WHERE a.is_published = 1
              AND a.slug IS NOT NULL
              AND (a.is_nsfw = 0 OR a.is_nsfw IS NULL)
              AND (a.password_hash IS NULL OR a.password_hash = "")
            ORDER BY a.published_at DESC
        ');
        $albums = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (empty($albums)) {
            return;
        }

        // Collect album IDs for batch image query
        $albumIds = array_column($albums, 'id');
        $albumsById = [];
        foreach ($albums as $album) {
            $albumsById[$album['id']] = $album;
        }

        // Batch fetch all images for these albums with metadata
        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $stmt = $this->db->prepare("
            SELECT
                i.id, i.album_id, i.alt_text, i.caption, i.width, i.height,
                i.camera_id, i.lens_id, i.film_id, i.location_id,
                i.custom_camera, i.custom_lens, i.custom_film,
                iv.path as variant_path, iv.format, iv.size_key
            FROM images i
            LEFT JOIN image_variants iv ON i.id = iv.image_id
                AND iv.format = 'jpg' AND iv.size_key = 'xl'
            WHERE i.album_id IN ($placeholders)
            ORDER BY i.album_id, i.sort_order, i.id
        ");
        $stmt->execute($albumIds);
        $imagesRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Enrich images with metadata names
        ImagesService::enrichWithMetadata($this->db->getPdo(), $imagesRaw, 'sitemap');

        // Group images by album
        $imagesByAlbum = [];
        foreach ($imagesRaw as $img) {
            $albumId = $img['album_id'];
            if (!isset($imagesByAlbum[$albumId])) {
                $imagesByAlbum[$albumId] = [];
            }
            $imagesByAlbum[$albumId][] = $img;
        }

        // Build XML with image namespace
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        foreach ($albumsById as $albumId => $album) {
            $images = $imagesByAlbum[$albumId] ?? [];
            if (empty($images)) {
                continue;
            }

            $xml->startElement('url');
            $xml->writeElement('loc', $this->baseUrl . '/album/' . $album['slug']);

            if (!empty($album['updated_at'])) {
                $xml->writeElement('lastmod', date('Y-m-d', strtotime($album['updated_at'])));
            }

            // Add each image
            foreach ($images as $image) {
                $imageUrl = $this->getImageUrl($image);
                if (!$imageUrl) {
                    continue;
                }

                $xml->startElement('image:image');
                $xml->writeElement('image:loc', $imageUrl);

                // Title (caption > alt_text > album title)
                $title = $this->getImageTitle($image, $album);
                if ($title) {
                    $xml->writeElement('image:title', $this->sanitizeForXml($title));
                }

                // Caption (smart alt with metadata)
                $caption = $this->getImageCaption($image, $album);
                if ($caption) {
                    $xml->writeElement('image:caption', $this->sanitizeForXml($caption));
                }

                // License URL if configured
                if ($licenseUrl) {
                    $xml->writeElement('image:license', $licenseUrl);
                }

                $xml->endElement(); // image:image
            }

            $xml->endElement(); // url
        }

        $xml->endElement(); // urlset
        $xml->endDocument();

        // Write to file
        $content = $xml->outputMemory();
        file_put_contents($this->publicPath . '/sitemap-images.xml', $content);
    }

    /**
     * Get the best available image URL for sitemap
     */
    private function getImageUrl(array $image): ?string
    {
        if (!empty($image['variant_path'])) {
            // Use XL variant if available
            $path = $image['variant_path'];
            if (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
            return $this->baseUrl . $path;
        }
        return null;
    }

    /**
     * Generate image title for sitemap
     */
    private function getImageTitle(array $image, array $album): string
    {
        if (!empty($image['caption'])) {
            return strip_tags($image['caption']);
        }
        if (!empty($image['alt_text'])) {
            return strip_tags($image['alt_text']);
        }
        return $album['title'] ?? 'Photo';
    }

    /**
     * Generate smart image caption with metadata for sitemap
     * Format: "[Title/Caption] | [Location] | [Camera]"
     */
    private function getImageCaption(array $image, array $album): string
    {
        $parts = [];

        // Base description
        if (!empty($image['caption'])) {
            $parts[] = strip_tags($image['caption']);
        } elseif (!empty($image['alt_text'])) {
            $parts[] = strip_tags($image['alt_text']);
        }

        // Location
        if (!empty($image['location_name'])) {
            $parts[] = $image['location_name'];
        }

        // Camera
        $camera = $image['custom_camera'] ?? $image['camera_name'] ?? '';
        if ($camera) {
            $parts[] = $camera;
        }

        // Film (for analog photography)
        $film = $image['custom_film'] ?? $image['film_name'] ?? '';
        if ($film) {
            $parts[] = $film;
        }

        if (empty($parts)) {
            $parts[] = $album['title'] ?? 'Photo';
        }

        return implode(' | ', $parts);
    }

    /**
     * Sanitize text for XML (remove control chars, limit length)
     */
    private function sanitizeForXml(string $text): string
    {
        // Remove control characters except tab, newline, carriage return
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        // Limit length for SEO (Google may truncate anyway)
        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 197) . '...';
        }
        return $text;
    }

    /**
     * Update robots.txt to include sitemap URLs
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

        $updated = false;

        // Add main sitemap if not present
        if (stripos($content, 'sitemap.xml') === false) {
            $content = trim($content) . "\n\nSitemap: " . $this->baseUrl . "/sitemap.xml";
            $updated = true;
        }

        // Add image sitemap if not present
        if (stripos($content, 'sitemap-images.xml') === false) {
            $content = trim($content) . "\nSitemap: " . $this->baseUrl . "/sitemap-images.xml\n";
            $updated = true;
        }

        // Try to write (might fail if not writable, which is OK)
        if ($updated) {
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
