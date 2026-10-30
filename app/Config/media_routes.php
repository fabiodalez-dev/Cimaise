<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use App\Middlewares\RateLimitMiddleware;

/**
 * /media/* routes. Registered by routes.php for the full app AND by the
 * media fast path in public/index.php, which serves image requests without
 * Twig, translations, plugins' views and the other ~265 routes.
 */
return function (App $app, array $container): void {
    // Protected media serving (for password-protected and NSFW albums)
    // Rate limited to prevent scraping/enumeration attacks
    $app->get('/media/protected/{id}/{variant}.{format}', function (Request $request, Response $response, array $args) use ($container) {
        $controller = new \App\Controllers\Frontend\MediaController(
            $container['db'],
            new \App\Services\UploadService($container['db'])
        );
        return $controller->serveProtected($request, $response, $args);
    })->add(new RateLimitMiddleware(100, 60)); // 100 requests per minute
    $app->get('/media/protected/{id}/original', function (Request $request, Response $response, array $args) use ($container) {
        $controller = new \App\Controllers\Frontend\MediaController(
            $container['db'],
            new \App\Services\UploadService($container['db'])
        );
        return $controller->serveOriginal($request, $response, $args);
    })->add(new RateLimitMiddleware(100, 60)); // 100 requests per minute

    // Public media serving with protection validation
    // All /media/* requests go through PHP to check if album is protected
    // This MUST be after /media/protected/* routes to not intercept them
    $app->get('/media/{path:.*}', function (Request $request, Response $response, array $args) use ($container) {
        // During installation, database isn't available - serve static files directly
        if ($container['db'] === null) {
            $path = $args['path'] ?? '';
            if (empty($path) || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
                return $response->withStatus(404);
            }

            // Without the database there is no way to prove that an album
            // variant is public. Fail closed instead of serving numeric image
            // files during a DB outage/bootstrap failure.
            if (preg_match('/^\d+_[a-z0-9_-]+\.(?:jpg|jpeg|webp|avif|png)$/i', basename($path))) {
                return $response
                    ->withStatus(503)
                    ->withHeader('Cache-Control', 'no-store, max-age=0')
                    ->withHeader('Retry-After', '60');
            }

            $root = dirname(__DIR__, 2);
            $filePath = "{$root}/public/media/{$path}";
            $realPath = realpath($filePath);
            $mediaRoot = realpath("{$root}/public/media/");

            if (!$realPath || !$mediaRoot || !is_file($realPath) || !str_starts_with($realPath, $mediaRoot . DIRECTORY_SEPARATOR)) {
                return $response->withStatus(404);
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $realPath);
            finfo_close($finfo);

            $allowedMimes = ['image/jpeg', 'image/webp', 'image/avif', 'image/png', 'image/gif'];
            if (!in_array($mime, $allowedMimes, true)) {
                return $response->withStatus(403);
            }

            $stream = fopen($realPath, 'rb');
            if (!$stream) {
                return $response->withStatus(500);
            }

            $body = $response->getBody();
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    break;
                }
                $body->write($chunk);
            }
            fclose($stream);

            return $response
                ->withHeader('Content-Type', $mime)
                ->withHeader('Content-Length', (string) filesize($realPath))
                ->withHeader('Cache-Control', 'public, max-age=86400');
        }

        $controller = new \App\Controllers\Frontend\MediaController(
            $container['db'],
            new \App\Services\UploadService($container['db'])
        );
        return $controller->servePublic($request, $response, $args);
    });
    // NOTE: intentionally NOT rate limited (M1). RateLimitMiddleware does a
    // flock()ed read-modify-write of a per-IP JSON file, which serialized every
    // image request from the same visitor. Public variants are cheap static
    // streams; the expensive path (on-demand variant generation) is protected by
    // a per-image lock in MediaController::ensureVariantGenerated() and only
    // generates the single requested variant. The /media/protected/* routes
    // above keep their 100 req/min limiter; note that AUTHORIZED protected
    // variants are also served through this un-limited route after the access
    // check (unauthenticated requests only ever get the blur placeholder).
    // If needed, rate-limit /media/ at the Apache level (mod_ratelimit).
};
