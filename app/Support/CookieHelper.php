<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helper class for cookie-related security functions
 */
class CookieHelper
{
    /**
     * Check if currently running over HTTPS.
     *
     * X-Forwarded-Proto is honoured only when the immediate peer is a trusted
     * proxy (TRUSTED_PROXIES, same policy as ClientIp / TrustedProxyMiddleware):
     * any client can send the header, so trusting it blindly lets a request
     * claim a scheme it does not have. This runs at bootstrap, before
     * TrustedProxyMiddleware, so it applies the check itself.
     */
    public static function isHttps(): bool
    {
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
            return true;
        }
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!is_string($forwarded) || $forwarded === '') {
            return false;
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || !ClientIp::isTrustedProxy($remote)) {
            return false;
        }
        // Nearest hop: the value our trusted proxy appended last.
        $hops = explode(',', $forwarded);
        return strtolower(trim((string) end($hops))) === 'https';
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
