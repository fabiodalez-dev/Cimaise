<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Sessions for cookie-less visitors that are only kept when they are needed.
 *
 * Every page used to start a session, so each anonymous visit and every
 * crawler hit created a session file and got a Set-Cookie plus a per-visitor
 * X-CSRF-Token header, which also made the HTML unstorable for shared caches.
 *
 * For a GET/HEAD request without a session cookie the session is started
 * without sending the cookie. Once the response is built it is kept (cookie
 * sent, data written) only if the page embeds the CSRF token (a form or a
 * script that will POST it back) or the request stored real session data.
 * Otherwise the session is destroyed and the token header dropped: nobody
 * could ever send that token back, so no visitor-visible behavior changes.
 */
final class LazySession
{
    /** Bodies above this size are not scanned (the token lives in HTML/JSON pages). */
    private const MAX_SCAN_BYTES = 8 * 1024 * 1024;

    private static bool $active = false;

    /**
     * @param array<string, mixed> $cookies
     */
    public static function isCandidate(string $method, array $cookies, string $sessionName): bool
    {
        return in_array(strtoupper($method), ['GET', 'HEAD'], true)
            && empty($cookies[$sessionName]);
    }

    public static function start(): void
    {
        self::$active = session_start(['use_cookies' => false]);
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * @param array<string, mixed> $session
     */
    public static function shouldKeep(array $session, ?string $body): bool
    {
        foreach ($session as $key => $value) {
            if ($key !== 'csrf' && $value !== null && $value !== [] && $value !== '' && $value !== false) {
                return true;
            }
        }

        $token = $session['csrf'] ?? null;
        if (!is_string($token) || $token === '' || $body === null) {
            return false;
        }

        return str_contains($body, $token);
    }

    public static function isScannable(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        return $type === ''
            || str_starts_with($type, 'text/')
            || in_array($type, ['application/json', 'application/javascript', 'application/xhtml+xml', 'application/manifest+json'], true);
    }

    /**
     * Decide the fate of a lazily started session. Must run after the whole
     * app has produced the response and before it is emitted.
     */
    public static function finalize(ResponseInterface $response): ResponseInterface
    {
        if (!self::$active || session_status() !== PHP_SESSION_ACTIVE) {
            return $response;
        }
        self::$active = false;

        $contentType = $response->getHeaderLine('Content-Type');
        $body = null;
        if (self::isScannable($contentType)) {
            $stream = $response->getBody();
            $size = $stream->getSize();
            if ($stream->isSeekable() && ($size === null || $size <= self::MAX_SCAN_BYTES)) {
                $stream->rewind();
                $body = $stream->getContents();
                $stream->rewind();
            }
        }

        if (self::shouldKeep($_SESSION, $body)) {
            if (!headers_sent()) {
                $params = session_get_cookie_params();
                setcookie(session_name(), (string) session_id(), [
                    'expires' => $params['lifetime'] > 0 ? time() + $params['lifetime'] : 0,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]);
            }
            session_write_close();
            return $response;
        }

        $_SESSION = [];
        session_destroy();
        return $response->withoutHeader('X-CSRF-Token');
    }

    /** @internal for tests */
    public static function reset(): void
    {
        self::$active = false;
    }
}
