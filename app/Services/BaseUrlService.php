<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface;

class BaseUrlService
{
    /**
     * Get the current base URL automatically from request environment
     */
    public static function getCurrentBaseUrl(): string
    {
        // Try to get from environment first
        if (isset($_ENV['APP_URL']) && !empty($_ENV['APP_URL'])) {
            return rtrim((string) $_ENV['APP_URL'], '/');
        }

        // Fallback to automatic detection. TrustedProxyMiddleware has already
        // normalised $_SERVER (HTTPS / SERVER_PORT / REQUEST_SCHEME) from the
        // X-Forwarded-* headers when the request came through a trusted proxy, so
        // reading $_SERVER here yields the PUBLIC scheme + host. We deliberately
        // do NOT read X-Forwarded-* directly (that would trust forged headers).
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        $isHttps = ($https !== '' && strtolower($https) !== 'off')
            || (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $protocol = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Keep a non-default public port. A trusted proxy may set SERVER_PORT
        // (from X-Forwarded-Port) without the port appearing in HTTP_HOST; without
        // this, sitemap/feed URLs would point at a different authority than the
        // request-derived canonical. Skip when the host already carries a port
        // (host:port, or an IPv6 literal which legitimately contains colons).
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        $isDefaultPort = $port === 0
            || ($isHttps && $port === 443)
            || (!$isHttps && $port === 80);
        $hostHasPort = preg_match('/:\d+$/', (string) $host) === 1;
        if (!$isDefaultPort && !$hostHasPort) {
            $host .= ':' . $port;
        }

        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname((string) $scriptPath);

        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }

        // Remove /public from the path if present (since document root should be public/)
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7); // Remove '/public'
        }

        return $protocol . '://' . $host . $basePath;
    }

    /**
     * Get base URL with fallback to APP_URL or manual URL
     */
    public static function getBaseUrl(?string $manualUrl = null): string
    {
        if (!empty($manualUrl)) {
            return rtrim($manualUrl, '/');
        }

        return self::getCurrentBaseUrl();
    }

    /**
     * Return the canonical origin and application base for an HTTP request.
     *
     * The configured URL is deliberately reduced to its origin: request paths
     * and generated media paths already contain the installation subdirectory.
     * Keeping a configured path here would therefore duplicate it.
     *
     * @return array{root:string,base:string}
     */
    public static function canonicalRoots(
        ServerRequestInterface $request,
        string $basePath = '',
        string $configuredUrl = ''
    ): array {
        $uri = $request->getUri();
        $authority = $uri->getAuthority() !== '' ? $uri->getAuthority() : $uri->getHost();
        $requestRoot = rtrim($uri->getScheme() . '://' . $authority, '/');
        $configuredRoot = self::origin($configuredUrl);
        $root = $configuredRoot !== '' ? $configuredRoot : $requestRoot;

        return [
            'root' => $root,
            'base' => rtrim($root . ($basePath !== '' ? '/' . ltrim($basePath, '/') : ''), '/'),
        ];
    }

    /** Reduce an absolute URL to scheme://authority, preserving a custom port. */
    public static function origin(string $url): string
    {
        if (trim($url) === '') {
            return '';
        }

        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $host = (string) $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return strtolower((string) $parts['scheme']) . '://' . $host
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /**
     * Detect if we're in a subdirectory installation
     */
    public static function isSubdirectoryInstallation(): bool
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname((string) $scriptPath);

        return !empty($basePath) && $basePath !== '/' && $basePath !== '\\';
    }

    /**
     * Get the installation path (for subdirectory installations)
     */
    public static function getInstallationPath(): string
    {
        // Try to get from APP_URL first (for CLI commands)
        if (isset($_ENV['APP_URL']) && !empty($_ENV['APP_URL'])) {
            $parsedUrl = parse_url((string) $_ENV['APP_URL']);
            if (isset($parsedUrl['path']) && !empty($parsedUrl['path'])) {
                return rtrim($parsedUrl['path'], '/');
            }
            return '';
        }

        // Fallback to server variables for web requests
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname((string) $scriptPath);

        if ($basePath === '/' || $basePath === '\\') {
            return '';
        }

        // Remove /public from the path if present
        if (str_ends_with($basePath, '/public')) {
            return substr($basePath, 0, -7);
        }

        return $basePath;
    }
}
