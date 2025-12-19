<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\CookieHelper;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class BaseController
{
    protected const ALBUM_ACCESS_WINDOW_SECONDS = 86400;
    protected string $basePath;

    public function __construct()
    {
        $this->basePath = $this->getBasePath();
    }

    protected function getBasePath(): string
    {
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = $basePath === '/' ? '' : $basePath;

        // Remove /public from the path if present (since document root should be public/)
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7); // Remove '/public'
        }

        return $basePath;
    }

    protected function redirect(string $path): string
    {
        return $this->basePath . $path;
    }

    /**
     * Validate CSRF token from request body or header.
     * Uses timing-safe comparison to prevent timing attacks.
     */
    protected function validateCsrf(Request $request): bool
    {
        $data = (array)$request->getParsedBody();
        $token = $data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        return \is_string($token) && isset($_SESSION['csrf']) && \hash_equals($_SESSION['csrf'], $token);
    }

    /**
     * Return JSON error response for invalid CSRF token.
     * For use in AJAX/API endpoints.
     */
    protected function csrfErrorJson(\Psr\Http\Message\ResponseInterface $response): \Psr\Http\Message\ResponseInterface
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Check if current user is an authenticated admin.
     */
    protected function isAdmin(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    /**
     * Check if user has valid password access for a specific album (24h window).
     */
    protected function hasAlbumPasswordAccess(int $albumId): bool
    {
        if ($albumId <= 0) {
            return false;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $accessTime = $_SESSION['album_access'][$albumId] ?? null;
        if (!\is_int($accessTime)) {
            return false;
        }
        if ((time() - $accessTime) >= self::ALBUM_ACCESS_WINDOW_SECONDS) {
            unset($_SESSION['album_access'][$albumId]);
            return false;
        }
        return true;
    }

    /**
     * Grant password access for a specific album (stored in session).
     */
    protected function grantAlbumPasswordAccess(int $albumId): void
    {
        if ($albumId <= 0) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['album_access'])) {
            $_SESSION['album_access'] = [];
        }
        $_SESSION['album_access'][$albumId] = time();
    }

    /**
     * Check if user has global NSFW consent (session or cookie).
     */
    protected function hasNsfwConsent(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION['nsfw_confirmed_global'])) {
            return true;
        }
        if (!empty($_COOKIE['nsfw_consent']) && $_COOKIE['nsfw_consent'] === '1') {
            $_SESSION['nsfw_confirmed_global'] = true;
            return true;
        }
        return false;
    }

    /**
     * Check NSFW consent for a specific album (global or per-album).
     */
    protected function hasNsfwAlbumConsent(int $albumId): bool
    {
        if ($this->hasNsfwConsent()) {
            return true;
        }
        if ($albumId <= 0) {
            return false;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['nsfw_confirmed'][$albumId]) && $_SESSION['nsfw_confirmed'][$albumId] === true;
    }

    /**
     * Grant NSFW consent globally (cookie + session) and optionally per-album.
     */
    protected function grantNsfwConsent(?int $albumId = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['nsfw_confirmed_global'] = true;
        if ($albumId !== null && $albumId > 0) {
            if (!isset($_SESSION['nsfw_confirmed'])) {
                $_SESSION['nsfw_confirmed'] = [];
            }
            $_SESSION['nsfw_confirmed'][$albumId] = true;
        }

        setcookie('nsfw_consent', '1', [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => !CookieHelper::allowInsecureCookies(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Check if the current request is an AJAX/JSON request.
     */
    protected function isAjaxRequest(Request $request): bool
    {
        try {
            $hdr = $request->getHeaderLine('X-Requested-With');
            $acc = $request->getHeaderLine('Accept');
            return (stripos($hdr, 'XMLHttpRequest') !== false) || (stripos($acc, 'application/json') !== false);
        } catch (\Throwable) {
            return false;
        }
    }
}
