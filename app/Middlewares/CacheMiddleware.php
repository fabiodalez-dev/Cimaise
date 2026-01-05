<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use App\Services\SettingsService;

class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SettingsService $settings
    ) {}

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

        // For HTML pages, use short cache with validation
        $contentType = $response->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'text/html')) {
            return $this->addHtmlCache($response);
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

        // Use ETag for validation
        $body = (string) $response->getBody();
        $etag = '"' . md5($body) . '"';

        return $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT')
            ->withHeader('ETag', $etag)
            ->withHeader('Pragma', 'public');
    }

    private function addProtectedMediaCache(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }

    private function addHtmlCache(Response $response): Response
    {
        $maxAge = $this->settings->get('performance.html_cache_max_age', 300); // 5 minutes default

        // For HTML, use shorter cache with must-revalidate
        return $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}, must-revalidate")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
    }

    private function addNoCacheHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }
}
