<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Front-controller request classification. Uses the decoded PATH only,
 * relative to the install base path and on segment boundaries, so that a query
 * string (?x=/install) or a slug containing "admin"/"install" cannot switch
 * off the maintenance gate or change the Twig globals of a page that may end
 * up in the shared HTML cache.
 */
final class RequestRoute
{
    public static function relativePath(string $requestUri, string $scriptName): string
    {
        $path = rawurldecode((string) (parse_url($requestUri, PHP_URL_PATH) ?: '/'));
        $base = rtrim(str_replace('\\', '/', dirname($scriptName !== '' ? $scriptName : '/index.php')), '/');
        $candidates = [$base];
        if (str_ends_with($base, '/public')) {
            $candidates[] = substr($base, 0, -7);
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && ($path === $candidate || str_starts_with($path, $candidate . '/'))) {
                $path = substr($path, strlen($candidate));
                break;
            }
        }
        return $path === '' ? '/' : $path;
    }

    public static function isInstaller(string $relativePath): bool
    {
        return $relativePath === '/install'
            || str_starts_with($relativePath, '/install/')
            || str_ends_with($relativePath, '/installer.php');
    }

    public static function isAdmin(string $relativePath): bool
    {
        return $relativePath === '/admin'
            || str_starts_with($relativePath, '/admin/')
            || $relativePath === '/admin-login';
    }
}
