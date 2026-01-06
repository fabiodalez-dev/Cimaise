<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\CookieHelper;
use App\Support\Logger;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class BaseController
{
    protected const ALBUM_ACCESS_WINDOW_SECONDS = 86400;
    protected const NSFW_CONSENT_COOKIE_DURATION_SECONDS = 2592000;
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
     * Ensure session is started (call once per request).
     */
    protected function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Validate CSRF token from request body or header.
     * Uses timing-safe comparison to prevent timing attacks.
     */
    protected function validateCsrf(Request $request): bool
    {
        $this->ensureSession();
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
        $this->ensureSession();
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
        $this->ensureSession();

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
        $this->ensureSession();
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
        $this->ensureSession();
        if (!empty($_SESSION['nsfw_confirmed_global'])) {
            return true;
        }
        $cookieValue = $_COOKIE['nsfw_consent'] ?? '';
        if (\is_string($cookieValue) && $cookieValue !== '' && $this->verifyNsfwConsentCookie($cookieValue)) {
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
        $this->ensureSession();
        return isset($_SESSION['nsfw_confirmed'][$albumId]) && $_SESSION['nsfw_confirmed'][$albumId] === true;
    }

    /**
     * Grant NSFW consent globally (cookie + session) and optionally per-album.
     */
    protected function grantNsfwConsent(?int $albumId = null): void
    {
        $this->ensureSession();
        $_SESSION['nsfw_confirmed_global'] = true;
        if ($albumId !== null && $albumId > 0) {
            if (!isset($_SESSION['nsfw_confirmed'])) {
                $_SESSION['nsfw_confirmed'] = [];
            }
            $_SESSION['nsfw_confirmed'][$albumId] = true;
        }

        $cookieValue = $this->buildNsfwConsentCookieValue();
        if ($cookieValue !== '') {
            $cookieSet = setcookie('nsfw_consent', $cookieValue, [
                'expires' => time() + self::NSFW_CONSENT_COOKIE_DURATION_SECONDS,
                'path' => '/',
                'secure' => !CookieHelper::allowInsecureCookies(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            if (!$cookieSet) {
                Logger::warning('Failed to set NSFW consent cookie', [], 'security');
            }
        }
    }

    private function buildNsfwConsentCookieValue(): string
    {
        $secret = (string)($_ENV['SESSION_SECRET'] ?? '');
        if ($secret === '') {
            return '';
        }
        $timestamp = time();
        $payload = '1|' . $timestamp;
        $signature = hash_hmac('sha256', $payload, $secret);
        return $payload . '|' . $signature;
    }

    private function verifyNsfwConsentCookie(string $value): bool
    {
        $secret = (string)($_ENV['SESSION_SECRET'] ?? '');
        if ($secret === '') {
            return false;
        }
        $parts = explode('|', $value);
        if (count($parts) !== 3) {
            return false;
        }
        [$flag, $timestamp, $signature] = $parts;
        if ($flag !== '1' || !ctype_digit($timestamp)) {
            return false;
        }
        $age = time() - (int)$timestamp;
        if ($age < 0 || $age > self::NSFW_CONSENT_COOKIE_DURATION_SECONDS) {
            return false;
        }
        $payload = $flag . '|' . $timestamp;
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Centralized access validation for protected albums (password/NSFW).
     *
     * For media serving (individual images):
     * - Blur variants are always allowed (for preview purposes)
     * - Password-protected albums: Full images allowed (password protects album page, not images)
     * - NSFW albums: Full images require consent
     */
    protected function validateAlbumAccess(
        int $albumId,
        bool $isPasswordProtected,
        bool $isNsfw,
        ?string $variant = null,
        bool $log = false
    ): bool|string {
        if ($this->isAdmin()) {
            return true;
        }

        $variantName = $variant !== null ? strtolower($variant) : null;

        // Blur variants are always allowed for preview purposes
        if ($variantName === 'blur') {
            return true;
        }

        // Password-protected albums: images are allowed (password protects page view, not images)
        // This allows cover images to show in album listings
        // The album page itself will still require password entry

        // NSFW albums require consent for non-blur variants
        if ($isNsfw && !$this->hasNsfwAlbumConsent($albumId)) {
            if ($log) {
                $consentCount = isset($_SESSION['nsfw_confirmed']) && \is_array($_SESSION['nsfw_confirmed'])
                    ? count($_SESSION['nsfw_confirmed'])
                    : 0;
                error_log("[MediaAccess] DENY nsfw album={$albumId} variant={$variant} consent_count={$consentCount}");
            }
            return 'nsfw';
        }

        return true;
    }

    protected function sanitizeAlbumCoverForNsfw(array $album, bool $isAdmin, bool $nsfwConsent): array
    {
        if ($isAdmin || empty($album['is_nsfw']) || $nsfwConsent) {
            return $album;
        }

        // Mark as requiring CSS blur fallback if no blur variant exists
        $album['nsfw_needs_css_blur'] = true;

        if (!empty($album['cover']) && !empty($album['cover']['variants']) && is_array($album['cover']['variants'])) {
            $blurVariants = array_values(array_filter(
                $album['cover']['variants'],
                fn($variant) => isset($variant['variant']) && $variant['variant'] === 'blur'
            ));

            if ($blurVariants !== []) {
                // Use only blur variants for display
                $album['cover']['variants'] = $blurVariants;
                unset($album['cover']['original_path']);
                $album['nsfw_needs_css_blur'] = false;
            }
            // If no blur variants, keep the cover as-is for CSS blur fallback in template
        }

        if (!empty($album['cover_image']) && is_array($album['cover_image'])) {
            if (empty($album['cover_image']['blur_path']) && !empty($album['cover_image']['preview_path'])) {
                $album['cover_image']['blur_path'] = $album['cover_image']['preview_path'];
            }
            // Don't unset cover_image even without blur - template will apply CSS blur
            if (!empty($album['cover_image']['blur_path'])) {
                unset(
                    $album['cover_image']['preview_path'],
                    $album['cover_image']['original_path'],
                    $album['cover_image']['path']
                );
                $album['nsfw_needs_css_blur'] = false;
            }
        }

        return $album;
    }

    protected function ensureAlbumCoverImage(array $album): array
    {
        if (!empty($album['cover_image']) || empty($album['cover']) || !is_array($album['cover'])) {
            return $album;
        }

        $cover = $album['cover'];
        $coverImage = [
            'id' => $cover['id'] ?? null,
            'width' => isset($cover['width']) ? (int)$cover['width'] : null,
            'height' => isset($cover['height']) ? (int)$cover['height'] : null,
            'alt_text' => $cover['alt_text'] ?? '',
            'original_path' => $cover['original_path'] ?? null,
        ];

        if (!empty($cover['variants']) && is_array($cover['variants'])) {
            foreach ($cover['variants'] as $variant) {
                if (empty($variant['path'])) {
                    continue;
                }
                if (($variant['variant'] ?? '') === 'blur') {
                    $coverImage['blur_path'] = $variant['path'];
                    continue;
                }
                if (empty($coverImage['preview_path'])) {
                    $coverImage['preview_path'] = $variant['path'];
                }
            }
        }

        $album['cover_image'] = $coverImage;
        return $album;
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
