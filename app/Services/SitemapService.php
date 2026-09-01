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
    private readonly string $baseUrl;
    private readonly string $publicPath;
    private ?SettingsService $settingsService = null;

    public function __construct(private readonly Database $db, string $baseUrl, string $publicPath)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->publicPath = rtrim($publicPath, '/');
    }

    /**
     * Get or create SettingsService instance
     */
    private function getSettingsService(): SettingsService
    {
        if (!$this->settingsService instanceof \App\Services\SettingsService) {
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

            // Add homepage (highest priority). No lastmod: the home page has no
            // single "modified" timestamp, so we omit it rather than fake now().
            $sitemap->addURL('/', null, 'daily', 1.0);

            // Static pages — use the configured slugs (the same settings the rest
            // of the app builds these URLs from). No real modified date exists for
            // them, so lastmod is intentionally omitted.
            $galleriesSlug = $this->slugSetting('galleries.slug', 'galleries');
            $aboutSlug = $this->slugSetting('about.slug', 'about');
            $sitemap->addURL('/' . $galleriesSlug, null, 'weekly', 0.9);
            $sitemap->addURL('/' . $aboutSlug, null, 'monthly', 0.8);

            // Legal / static content pages (public routes).
            $sitemap->addURL('/' . $this->slugSetting('license.slug', 'license'), null, 'yearly', 0.3);
            $sitemap->addURL('/' . $this->slugSetting('privacy.slug', 'privacy-policy'), null, 'yearly', 0.3);
            $sitemap->addURL('/' . $this->slugSetting('cookie.slug', 'cookie-policy'), null, 'yearly', 0.3);

            // Categories — only those that actually contain a publicly visible,
            // indexable album (mirrors the frontend's "empty categories are
            // hidden" rule). lastmod is the most recent album update in the
            // category (a real date), omitted when unavailable.
            $stmt = $this->db->query('
                SELECT c.slug AS slug, MAX(a.updated_at) AS last_updated
                FROM categories c
                JOIN albums a ON (
                    a.category_id = c.id
                    OR EXISTS (
                        SELECT 1 FROM album_category ac
                        WHERE ac.album_id = a.id AND ac.category_id = c.id
                    )
                )
                WHERE c.slug IS NOT NULL
                  AND a.is_published = 1
                  AND (a.is_nsfw = 0 OR a.is_nsfw IS NULL)
                  AND (a.password_hash IS NULL OR a.password_hash = "")
                  AND (a.robots_index = 1 OR a.robots_index IS NULL)
                GROUP BY c.id, c.slug
                ORDER BY c.slug
            ');
            foreach ($stmt->fetchAll() as $category) {
                $sitemap->addURL(
                    '/category/' . $category['slug'],
                    $this->toDateTime($category['last_updated'] ?? null),
                    'weekly',
                    0.7
                );
            }

            // Tags — only those with at least one publicly visible, indexable album.
            $stmt = $this->db->query('
                SELECT t.slug AS slug, MAX(a.updated_at) AS last_updated
                FROM tags t
                JOIN album_tag at ON at.tag_id = t.id
                JOIN albums a ON a.id = at.album_id
                WHERE t.slug IS NOT NULL
                  AND a.is_published = 1
                  AND (a.is_nsfw = 0 OR a.is_nsfw IS NULL)
                  AND (a.password_hash IS NULL OR a.password_hash = "")
                  AND (a.robots_index = 1 OR a.robots_index IS NULL)
                GROUP BY t.id, t.slug
                ORDER BY t.slug
            ');
            foreach ($stmt->fetchAll() as $tag) {
                $sitemap->addURL(
                    '/tag/' . $tag['slug'],
                    $this->toDateTime($tag['last_updated'] ?? null),
                    'weekly',
                    0.6
                );
            }

            // Curated collections (public). CollectionService centralises the
            // visibility rule (published collection containing >=1 visible photo).
            $collections = (new CollectionService($this->db))->publishedCollections();
            if ($collections !== []) {
                $sitemap->addURL('/collections', null, 'weekly', 0.7);
                foreach ($collections as $collection) {
                    if (empty($collection['slug'])) {
                        continue;
                    }
                    $sitemap->addURL(
                        '/collection/' . $collection['slug'],
                        $this->toDateTime($collection['updated_at'] ?? null),
                        'weekly',
                        0.6
                    );
                }
            }

            // Add published albums (exclude NSFW, password-protected and
            // non-indexable albums for privacy/SEO).
            $stmt = $this->db->query('
                SELECT slug, published_at, updated_at
                FROM albums
                WHERE is_published = 1
                  AND slug IS NOT NULL
                  AND (is_nsfw = 0 OR is_nsfw IS NULL)
                  AND (password_hash IS NULL OR password_hash = "")
                  AND (robots_index = 1 OR robots_index IS NULL)
                ORDER BY published_at DESC
            ');
            $albums = $stmt->fetchAll();

            foreach ($albums as $album) {
                $lastmod = $album['updated_at'] ?? $album['published_at'] ?? null;
                $sitemap->addURL('/album/' . $album['slug'], $this->toDateTime($lastmod), 'monthly', 0.8);
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

        // Get all public, indexable albums with their images
        $stmt = $this->db->query('
            SELECT a.id, a.slug, a.title, a.updated_at
            FROM albums a
            WHERE a.is_published = 1
              AND a.slug IS NOT NULL
              AND (a.is_nsfw = 0 OR a.is_nsfw IS NULL)
              AND (a.password_hash IS NULL OR a.password_hash = "")
              AND (a.robots_index = 1 OR a.robots_index IS NULL)
            ORDER BY a.published_at DESC
        ');
        $albums = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($albums)) {
            return;
        }

        // Collect album IDs for batch image query
        $albumIds = array_column($albums, 'id');
        $albumsById = [];
        foreach ($albums as $album) {
            $albumsById[$album['id']] = $album;
        }

        // Batch fetch image rows (metadata only). Variants are resolved
        // separately below so that a missing 'jpg/xl' variant — or a site with
        // jpg output disabled entirely — no longer silently drops the image.
        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $stmt = $this->db->query("
            SELECT
                i.id, i.album_id, i.alt_text, i.caption, i.width, i.height,
                i.camera_id, i.lens_id, i.film_id, i.location_id,
                i.custom_camera, i.custom_lens, i.custom_film
            FROM images i
            WHERE i.album_id IN ($placeholders)
            ORDER BY i.album_id, i.sort_order, i.id
        ", $albumIds);
        $imagesRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($imagesRaw === []) {
            return;
        }

        // Resolve the best variant per image: prefer JPEG (xl -> lg -> md) for
        // crawler compatibility, otherwise fall back to the widest available
        // variant in whatever format exists.
        $variantsByImage = $this->loadVariantsByImage(array_column($imagesRaw, 'id'));
        foreach ($imagesRaw as &$imgRow) {
            $best = $this->pickBestVariant($variantsByImage[(int) $imgRow['id']] ?? []);
            $imgRow['variant_path'] = $best['path'] ?? null;
        }
        unset($imgRow);

        // Enrich images with metadata names
        ImagesService::enrichWithMetadata($this->db->pdo(), $imagesRaw, 'sitemap');

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
            if ($images === []) {
                continue;
            }

            $xml->startElement('url');
            $xml->writeElement('loc', $this->baseUrl . '/album/' . $album['slug']);

            if (!empty($album['updated_at'])) {
                $xml->writeElement('lastmod', date('Y-m-d', strtotime((string) $album['updated_at'])));
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
            if (!str_starts_with((string) $path, '/')) {
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
            return strip_tags((string) $image['caption']);
        }
        if (!empty($image['alt_text'])) {
            return strip_tags((string) $image['alt_text']);
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
            $parts[] = strip_tags((string) $image['caption']);
        } elseif (!empty($image['alt_text'])) {
            $parts[] = strip_tags((string) $image['alt_text']);
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

        if ($parts === []) {
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
        if (mb_strlen((string) $text) > 200) {
            return mb_substr((string) $text, 0, 197) . '...';
        }
        return $text;
    }

    /**
     * Rewrite the Sitemap: directives in robots.txt from the current absolute
     * base URL.
     *
     * Every existing "Sitemap:" line is stripped first — the shipped defaults
     * point at http://localhost and any previously written line may reference a
     * stale host — then the two sitemaps this service actually generates are
     * appended. Only sitemap.xml and sitemap-images.xml are referenced (there is
     * no sitemap index file).
     */
    private function updateRobotsTxt(): void
    {
        $robotsPath = $this->publicPath . '/robots.txt';

        // Read existing robots.txt (keep the User-agent / Disallow rules).
        $content = '';
        if (file_exists($robotsPath) && is_readable($robotsPath)) {
            $existing = file_get_contents($robotsPath);
            if ($existing !== false) {
                $content = $existing;
            }
        }

        // Drop every existing Sitemap: line so stale/localhost URLs never linger.
        $content = (string) preg_replace('/^\s*Sitemap:.*$/mi', '', $content);
        $content = rtrim($content);

        // Re-add the sitemaps this service generates, from the current base URL.
        $content .= "\n\nSitemap: " . $this->baseUrl . "/sitemap.xml";
        $content .= "\nSitemap: " . $this->baseUrl . "/sitemap-images.xml\n";

        // Try to write (might fail if not writable, which is OK).
        @file_put_contents($robotsPath, ltrim($content, "\n"));
    }

    /**
     * Read a page-slug setting, guaranteeing a non-empty, path-safe value.
     */
    private function slugSetting(string $key, string $default): string
    {
        $slug = trim((string) ($this->getSettingsService()->get($key, $default) ?? $default));
        $slug = trim($slug, '/');
        return $slug !== '' ? $slug : $default;
    }

    /**
     * Convert a DB timestamp to a DateTime, or null when there is no real date
     * (so lastmod is omitted rather than faked as "now").
     */
    private function toDateTime(mixed $value): ?\DateTime
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        try {
            return new \DateTime((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Batch-load image variants keyed by image id.
     *
     * @param array<int, int|string> $imageIds
     * @return array<int, array<int, array{format:string, variant:string, path:string}>>
     */
    private function loadVariantsByImage(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
        $stmt = $this->db->query(
            "SELECT image_id, format, variant, path
             FROM image_variants
             WHERE image_id IN ($placeholders)",
            array_map(intval(...), array_values($imageIds))
        );

        $byImage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byImage[(int) $row['image_id']][] = [
                'format' => (string) $row['format'],
                'variant' => (string) $row['variant'],
                'path' => (string) $row['path'],
            ];
        }
        return $byImage;
    }

    /**
     * Choose the best variant for the image sitemap: JPEG (widest first) when
     * available, otherwise the widest available variant in any format.
     *
     * @param array<int, array{format:string, variant:string, path:string}> $variants
     * @return array{format:string, variant:string, path:string}|null
     */
    private function pickBestVariant(array $variants): ?array
    {
        if ($variants === []) {
            return null;
        }

        // 1) Prefer JPEG (most universally fetchable by crawlers), xl -> lg -> md.
        foreach (['xl', 'lg', 'md'] as $size) {
            foreach ($variants as $variant) {
                if ($variant['format'] === 'jpg' && $variant['variant'] === $size && $variant['path'] !== '') {
                    return $variant;
                }
            }
        }

        // 2) Otherwise the widest available variant, whatever the format
        //    (covers installs with jpg output disabled).
        $sizeRank = ['xxl' => 0, 'xl' => 1, 'lg' => 2, 'md' => 3, 'sm' => 4, 'xs' => 5];
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($variants as $variant) {
            if ($variant['path'] === '') {
                continue;
            }
            $rank = $sizeRank[$variant['variant']] ?? PHP_INT_MAX;
            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best = $variant;
            }
        }
        return $best;
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
