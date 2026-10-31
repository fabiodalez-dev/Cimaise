<?php

declare(strict_types=1);

namespace App\Support;

use Slim\Interfaces\RouteCollectorInterface;

/**
 * Enables FastRoute's compiled dispatcher cache for the Slim app.
 *
 * Without it every request rebuilds the regex tables for all ~270 routes.
 * The cache file name is a hash of the full route set (identifier, methods,
 * pattern, base path), because Slim maps compiled entries back to routes by
 * identifier ("route0", "route1", ...): if a plugin is toggled and the route
 * list changes, a new file is used instead of a stale one that would dispatch
 * to the wrong handler.
 */
final class RouteCache
{
    public static function configure(RouteCollectorInterface $collector, string $cacheDir): ?string
    {
        $signature = $collector->getBasePath() . "\n";
        foreach ($collector->getRoutes() as $route) {
            $signature .= $route->getIdentifier() . ' ' . implode(',', $route->getMethods()) . ' ' . $route->getPattern() . "\n";
        }

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }
        if (!is_writable($cacheDir)) {
            return null;
        }

        $file = rtrim($cacheDir, '/') . '/routes-' . hash('xxh128', $signature) . '.php';
        $collector->setCacheFile($file);
        return $file;
    }
}
