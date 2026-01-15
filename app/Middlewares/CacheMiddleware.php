<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use App\Services\SettingsService;
use App\Services\PageCacheService;
use App\Support\Database;

class CacheMiddleware implements MiddlewareInterface
{
    private ?PageCacheService $pageCacheService = null;

    public function __construct(
        private SettingsService $settings,
        private ?Database $database = null
    ) {
        $this->pageCacheService = new PageCacheService($this->settings, $this->database);
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        // Get cache settings
        $cacheEnabled = $this->settings->get('performance.cache_enabled', true);
        if (!$cacheEnabled) {
            return $response;
        }

        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Only cache GET and HEAD requests
        if (!in_array($method, ['GET', 'HEAD'])) {
            return $response;
        }

        // Don't cache admin routes
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/cimaise/admin')) {
            return $this->addNoCacheHeaders($response);
        }

        // Don't cache API routes (except specific ones)
        if (str_starts_with($path, '/api/')) {
            return $this->addNoCacheHeaders($response);
        }

        // Check if it's a static asset
        if ($this->isStaticAsset($path)) {
            return $this->addStaticAssetCache($response);
        }

        // Check if it's a media file
        if (str_starts_with($path, '/media/protected/')) {
            return $this->addProtectedMediaCache($response);
        }
        if (str_starts_with($path, '/media/')) {
            return $this->addMediaCache($response);
        }

        // For HTML pages, use short cache with validation and ETag
        $contentType = $response->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'text/html')) {
            return $this->addHtmlCache($response, $request);
        }

        return $response;
    }

    private function isStaticAsset(string $path): bool
    {
        $staticExtensions = [
            '.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif',
            '.svg', '.ico', '.woff', '.woff2', '.ttf', '.otf', '.eot',
            '.map', '.json', '.xml'
        ];

        foreach ($staticExtensions as $ext) {
            if (str_ends_with(strtolower($path), $ext)) {
                return true;
            }
        }

        return false;
    }

    private function addStaticAssetCache(Response $response): Response
    {
        $maxAge = $this->settings->get('performance.static_cache_max_age', 31536000); // 1 year default

        return $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}, immutable")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT')
            ->withHeader('Pragma', 'public');
    }

    private function addMediaCache(Response $response): Response
    {
        $maxAge = $this->settings->get('performance.media_cache_max_age', 86400); // 1 day default

        // Don't compute ETag here - it would require reading entire body into memory
        // MediaController already sets ETag based on file stats (mtime + size)
        // which is much more efficient than MD5 hashing the entire response
        $result = $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT')
            ->withHeader('Pragma', 'public');

        // Only add ETag if not already set by controller
        if (!$response->hasHeader('ETag')) {
            // Use weak ETag based on content-length and last-modified if available
            $contentLength = $response->getHeaderLine('Content-Length');
            $lastModified = $response->getHeaderLine('Last-Modified');
            if ($contentLength && $lastModified) {
                $result = $result->withHeader('ETag', 'W/"' . md5($contentLength . $lastModified) . '"');
            }
        }

        return $result;
    }

    private function addProtectedMediaCache(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }

    private function addHtmlCache(Response $response, Request $request): Response
    {
        $maxAge = $this->settings->get('performance.html_cache_max_age', 300); // 5 minutes default

        // Try to generate ETag from page cache (database or file) if available
        $path = $request->getUri()->getPath();
        $etag = $this->generateHtmlEtag($path);
        if (!$etag) {
            // Only hash body for small responses to avoid O(size) CPU/memory overhead
            // For large pages without cache ETags, skip hashing entirely
            $maxHashSize = 256 * 1024; // 256 KB threshold
            $contentLength = $response->getHeaderLine('Content-Length');
            $shouldHash = $contentLength === '' || (int) $contentLength <= $maxHashSize;

            if ($shouldHash) {
                $stream = $response->getBody();
                $currentPos = $stream->tell();

                if ($stream->isSeekable()) {
                    // Seekable stream: rewind to start if needed before reading
                    if ($currentPos > 0) {
                        $stream->rewind();
                    }
                    $body = $stream->getContents();
                    $stream->rewind();
                } elseif ($currentPos === 0) {
                    // Non-seekable stream at position 0: safe to read entire content
                    $body = $stream->getContents();
                    $newStream = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
                    $newStream->write($body);
                    $newStream->rewind();
                    $response = $response->withBody($newStream);
                } else {
                    // Non-seekable stream already partially read: skip ETag generation
                    // to avoid hashing incomplete content
                    $body = '';
                }

                // Double-check size after reading (Content-Length may be missing)
                if ($body !== '' && strlen($body) <= $maxHashSize) {
                    $etag = '"' . sha1($body) . '"';
                }
            }
        }

        // Check If-None-Match header for 304 response
        if ($etag) {
            $ifNoneMatch = $request->getHeaderLine('If-None-Match');
            if ($ifNoneMatch && $this->matchesEtag($etag, $ifNoneMatch)) {
                // Create empty body for 304 response to avoid sending stale content
                $emptyBody = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
                return $response
                    ->withStatus(304)
                    ->withBody($emptyBody)
                    ->withHeader('ETag', $etag)
                    ->withHeader('Cache-Control', "public, max-age={$maxAge}, must-revalidate");
            }
        }

        // For HTML, use shorter cache with must-revalidate
        $result = $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}, must-revalidate")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');

        if ($etag) {
            $result = $result->withHeader('ETag', $etag);
        }

        return $result;
    }

    /**
     * Generate ETag for HTML pages based on cache (database or file).
     */
    private function generateHtmlEtag(string $path): ?string
    {
        if (!$this->pageCacheService) {
            return null;
        }

        // Map URL path to cache type
        $basePath = rtrim($this->settings->get('site.base_path', ''), '/');
        $path = preg_replace('#^' . preg_quote($basePath, '#') . '#', '', $path);

        // Get configurable galleries slug (default: /galleries)
        $galleriesPath = '/' . trim($this->settings->get('galleries.slug', 'galleries'), '/');

        $cacheType = null;
        if ($path === '/' || $path === '') {
            $cacheType = 'home';
        } elseif ($path === $galleriesPath || $path === $galleriesPath . '/') {
            $cacheType = 'galleries';
        } elseif (preg_match('#^/album/([^/]+)/?$#', $path, $matches)) {
            $cacheType = 'album:' . $matches[1];
        }

        if (!$cacheType) {
            return null;
        }

        // Use database hash when backend is database
        $backend = $this->settings->get('cache.storage_backend', 'database');
        if ($backend === 'database') {
            $hash = $this->pageCacheService->getHash($cacheType);
            if ($hash) {
                return '"' . $hash . '"';
            }
            return null;
        }

        // Fall back to file-based ETag
        $cacheFile = $this->pageCacheService->getCacheFilePath($cacheType);
        if ($cacheFile && file_exists($cacheFile)) {
            $mtime = filemtime($cacheFile);
            $size = filesize($cacheFile);
            return '"' . md5($mtime . '-' . $size) . '"';
        }

        return null;
    }

    /**
     * Check if an ETag matches the If-None-Match header value.
     * Handles weak ETags (W/"..."), multiple comma-separated values, and quote stripping.
     */
    private function matchesEtag(string $etag, string $ifNoneMatch): bool
    {
        // Wildcard match
        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        // Normalize our ETag (strip weak prefix and quotes for comparison)
        $normalizedEtag = $this->normalizeEtag($etag);

        // Parse If-None-Match header (can contain comma-separated values)
        $candidates = array_map('trim', explode(',', $ifNoneMatch));

        foreach ($candidates as $candidate) {
            if ($candidate === '' || $candidate === '*') {
                continue;
            }

            // Normalize candidate ETag
            $normalizedCandidate = $this->normalizeEtag($candidate);

            // Compare normalized values
            if ($normalizedEtag === $normalizedCandidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize an ETag by stripping W/ prefix and surrounding quotes.
     */
    private function normalizeEtag(string $etag): string
    {
        // Strip weak prefix
        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }

        // Strip surrounding quotes
        $etag = trim($etag, '"');

        return $etag;
    }

    private function addNoCacheHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }
}
