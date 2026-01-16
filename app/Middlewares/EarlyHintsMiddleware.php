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
 */
class EarlyHintsMiddleware implements MiddlewareInterface
{
    private string $basePath;

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
                header(
                    sprintf(
                        'Link: <%s>; rel=%s%s%s',
                        $resource['href'],
                        $resource['rel'],
                        isset($resource['as']) ? '; as=' . $resource['as'] : '',
                        isset($resource['type']) ? '; type=' . $resource['type'] : ''
                    ),
                    false // Don't replace previous headers
                );
            }
        }

        $response = $handler->handle($request);

        // Add Link headers to response (for HTTP/2 Server Push)
        foreach ($criticalResources as $resource) {
            $linkValue = sprintf(
                '<%s>; rel=%s%s%s',
                $resource['href'],
                $resource['rel'],
                isset($resource['as']) ? '; as=' . $resource['as'] : '',
                isset($resource['type']) ? '; type=' . $resource['type'] : ''
            );

            $response = $response->withAddedHeader('Link', $linkValue);
        }

        return $response;
    }

    /**
     * Get critical resources to preload based on request
     * Only preloads stable paths (fonts, vendor libraries) - not Vite-hashed assets
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
            // Gallery pages - preload PhotoSwipe
            $resources[] = [
                'href' => $this->basePath . '/assets/photoswipe/dist/photoswipe.esm.min.js',
                'rel' => 'modulepreload'
            ];
            $resources[] = [
                'href' => $this->basePath . '/assets/photoswipe/dist/photoswipe-lightbox.esm.min.js',
                'rel' => 'modulepreload'
            ];
            $resources[] = [
                'href' => $this->basePath . '/assets/photoswipe/dist/photoswipe.css',
                'rel' => 'preload',
                'as' => 'style'
            ];
        }

        return $resources;
    }
}
