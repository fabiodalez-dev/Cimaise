<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Helper class for cookie-related security functions
 */
class CookieHelper
{
    /**
     * Check if currently running over HTTPS
     */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Check if insecure cookies are allowed (not using HTTPS)
     * Returns true when running over HTTP, allowing cookies to work on localhost
     */
    public static function allowInsecureCookies(): bool
    {
        return !self::isHttps();
    }
}
