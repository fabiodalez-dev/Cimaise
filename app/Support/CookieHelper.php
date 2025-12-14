<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Helper class for cookie-related security functions
 */
class CookieHelper
{
    /**
     * Check if insecure cookies are allowed (localhost + debug mode)
     * Only allow insecure cookies on localhost in debug mode for development
     */
    public static function allowInsecureCookies(): bool
    {
        $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost';
        return $isLocalhost && filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
