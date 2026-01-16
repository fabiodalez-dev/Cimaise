<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Early Hints Middleware (HTTP 103)
 * Sends Link headers for HTTP/2 Server Push or Early Hints
 * Allows browser to start downloading critical resources BEFORE HTML is ready
 *
 * Performance impact:
 * - Reduces time to first byte for critical resources
 * - Works with HTTP/2 push or HTTP 103 Early Hints
 * - Fallback: Link headers still help browser prioritization
 *
 * Security:
 * - Includes SRI (Subresource Integrity) hashes for verified resources
 * - Protects against CDN compromise and MITM attacks
 */
class EarlyHintsMiddleware implements MiddlewareInterface
{
    private string $basePath;
    private static ?array $integrityCache = null;

    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Get critical resources to preload
        $criticalResources = $this->getCriticalResources($request);

        // Send Early Hints (HTTP 103) if supported
        // Note: Most servers don't support this yet, but Link headers still work
        if (function_exists('header') && !headers_sent()) {
            foreach ($criticalResources as $resource) {
                $linkHeader = $this->buildLinkHeader($resource);
                header("Link: {$linkHeader}", false); // Don't replace previous headers
            }
        }

        $response = $handler->handle($request);

        // Add Link headers to response (for HTTP/2 Server Push)
        foreach ($criticalResources as $resource) {
            $linkValue = $this->buildLinkHeader($resource);
            $response = $response->withAddedHeader('Link', $linkValue);
        }

        return $response;
    }

    /**
     * Build Link header value with optional SRI integrity hash
     * PERFORMANCE: Integrity hashes are cached in static memory
     */
    private function buildLinkHeader(array $resource): string
    {
        $parts = [
            '<' . $resource['href'] . '>',
            'rel=' . $resource['rel']
        ];

        if (isset($resource['as'])) {
            $parts[] = 'as=' . $resource['as'];
        }

        if (isset($resource['type'])) {
            $parts[] = 'type=' . $resource['type'];
        }

        // Add SRI integrity hash if available
        if (isset($resource['integrity'])) {
            $parts[] = 'integrity="' . $resource['integrity'] . '"';
            $parts[] = 'crossorigin="anonymous"';
        }

        return implode('; ', $parts);
    }

    /**
     * Get critical resources to preload based on request
     * Only preloads stable paths (fonts, vendor libraries) - not Vite-hashed assets
     * SECURITY: Includes SRI hashes for external/vendor resources
     */
    private function getCriticalResources(ServerRequestInterface $request): array
    {
        $uri = $request->getUri()->getPath();

        // Skip admin routes - they have different resource needs
        if (str_contains($uri, '/admin')) {
            return [];
        }

        // Common resources for all frontend pages (stable paths only)
        $resources = [
            // Font stylesheets - stable paths
            [
                'href' => $this->basePath . '/fonts/typography.css',
                'rel' => 'preload',
                'as' => 'style'
            ],
            [
                'href' => $this->basePath . '/fonts/font-faces.css',
                'rel' => 'preload',
                'as' => 'style'
            ],
        ];

        // Add page-specific vendor resources (stable paths)
        if (str_contains($uri, '/album/') || str_contains($uri, '/galleries')) {
            // Gallery pages - preload PhotoSwipe with SRI
            $resources[] = [
                'href' => $this->basePath . '/assets/photoswipe/dist/photoswipe.esm.min.js',
                'rel' => 'modulepreload',
                'integrity' => $this->getIntegrityHash('/assets/photoswipe/dist/photoswipe.esm.min.js')
            ];
            $resources[] = [
                'href' => $this->basePath . '/assets/photoswipe/dist/photoswipe-lightbox.esm.min.js',
                'rel' => 'modulepreload',
                'integrity' => $this->getIntegrityHash('/assets/photoswipe/dist/photoswipe-lightbox.esm.min.js')
            ];
            $resources[] = [
                'href' => $this->basePath . '/assets/photoswipe/dist/photoswipe.css',
                'rel' => 'preload',
                'as' => 'style',
                'integrity' => $this->getIntegrityHash('/assets/photoswipe/dist/photoswipe.css')
            ];
        }

        // Filter out resources with null integrity (file not found)
        return array_filter($resources, fn($r) => !isset($r['integrity']) || $r['integrity'] !== null);
    }

    /**
     * Get SRI integrity hash for a file (SHA-384)
     * PERFORMANCE: Hashes are cached in static memory to avoid repeated file reads
     *
     * @param string $filePath Path relative to public directory
     * @return string|null Base64-encoded SHA-384 hash or null if file doesn't exist
     */
    private function getIntegrityHash(string $filePath): ?string
    {
        // Initialize cache on first call
        if (self::$integrityCache === null) {
            self::$integrityCache = [];
        }

        // Return cached hash if available
        if (isset(self::$integrityCache[$filePath])) {
            return self::$integrityCache[$filePath];
        }

        // Build absolute file path
        $root = dirname(__DIR__, 2);
        $absolutePath = $root . '/public' . $filePath;

        // Check if file exists
        if (!file_exists($absolutePath)) {
            self::$integrityCache[$filePath] = null;
            return null;
        }

        // Calculate SHA-384 hash
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            self::$integrityCache[$filePath] = null;
            return null;
        }

        $hash = hash('sha384', $content, true);
        $base64Hash = base64_encode($hash);
        $integrity = 'sha384-' . $base64Hash;

        // Cache the result
        self::$integrityCache[$filePath] = $integrity;

        return $integrity;
    }
}
