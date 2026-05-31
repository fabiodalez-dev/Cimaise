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

    /**
     * Build a redirect Location value confined to this site (defense-in-depth, CWE-601).
     *
     * Every caller passes an internal, literal-prefixed path (e.g. "/album/$slug"),
     * so this is normally already same-origin. This method additionally guarantees it:
     * it strips CR/LF/NUL (header-injection), forces a leading slash, and collapses any
     * run of leading slashes to one — so the result can never become a protocol-relative
     * ("//evil.com") or absolute ("https://evil.com") destination regardless of input.
     */
    protected function redirect(string $path): string
    {
        $path = str_replace(["\r", "\n", "\0"], '', $path);
        // Reject absolute URLs (scheme://...) — keep only the path-and-query portion.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path)) {
            $path = '/';
        }
        // Force exactly one leading slash (blocks "//host" protocol-relative redirects).
        $path = '/' . ltrim($path, '/');
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
        return $this->jsonResponse($response, ['ok' => false, 'error' => 'Invalid CSRF token'], 403);
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
     * - Blur variants are always allowed (for preview/cover purposes)
     * - Password-protected albums: non-blur variants require session access
     * - NSFW albums: non-blur variants require consent
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

        // Password-protected albums: require session access for non-blur variants
        if ($isPasswordProtected && !$this->hasAlbumPasswordAccess($albumId)) {
            if ($log) {
                error_log("[MediaAccess] DENY password album={$albumId} variant={$variant}");
            }
            return 'password';
        }

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
        $placeholderUrl = $this->blurPlaceholderUrl();

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
            } else {
                // No blur variant: substitute with a static placeholder so the template never
                // falls back to the full-resolution preview/original for NSFW covers.
                $album['cover']['variants'] = [[
                    'variant' => 'blur',
                    'format' => 'jpg',
                    'path' => $placeholderUrl,
                    'width' => null,
                    'height' => null,
                ]];
                unset($album['cover']['original_path']);
                $album['nsfw_needs_css_blur'] = false;
            }
        }

        if (!empty($album['cover_image']) && is_array($album['cover_image'])) {
            if (empty($album['cover_image']['blur_path'])) {
                // Prefer a real blur if we have one; otherwise fall back to the static placeholder
                // (never leak preview_path/original_path for NSFW covers).
                $album['cover_image']['blur_path'] = $placeholderUrl;
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
            // Even if cover_image already exists, password-protected albums without a
            // blur_path must still get a placeholder so _album_card.twig always has a
            // renderable URL when should_blur_cover is true.
            if (!empty($album['cover_image']) && is_array($album['cover_image'])) {
                $needsPlaceholder = !empty($album['is_password_protected']) || !empty($album['is_locked']);
                if ($needsPlaceholder && empty($album['cover_image']['blur_path'])) {
                    $album['cover_image']['blur_path'] = $this->blurPlaceholderUrl();
                }
            }
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

        // For password-protected (or locked) albums without a blur variant, fall back to a
        // static placeholder so listings never expose the full-resolution cover via the
        // _album_card.twig blur branch.
        $needsPlaceholder = !empty($album['is_password_protected']) || !empty($album['is_locked']);
        if ($needsPlaceholder && empty($coverImage['blur_path'])) {
            $coverImage['blur_path'] = $this->blurPlaceholderUrl();
        }

        $album['cover_image'] = $coverImage;
        return $album;
    }

    /**
     * URL path for the static blur placeholder used when a protected album lacks a blur variant.
     *
     * Lazily creates an 8x8 gray jpeg at public/media/blur-placeholder.jpg the first time
     * it is requested (CR-2): without this, the very first page render with a protected cover
     * would emit a `<img src="/media/blur-placeholder.jpg">` for an asset that doesn't exist
     * on disk yet (MediaController::generateBlurOnDemand() materialises it only on a /media
     * request), producing a 404 from serveStaticFile().
     *
     * Returned as an absolute server path (starting with `/`) so _album_card.twig will prepend
     * base_path itself, consistent with how other variant.path values flow through the template.
     *
     * If GD is unavailable and the file cannot be created, we still return the canonical URL —
     * any resulting 404 is preferable to leaking the original cover. Callers/templates should
     * treat this as a best-effort fallback.
     */
    protected function blurPlaceholderUrl(): string
    {
        $this->ensureBlurPlaceholderExists();
        return '/media/blur-placeholder.jpg';
    }

    /**
     * Ensure the blur placeholder file exists on disk (CR-2).
     *
     * Performs a cheap is_file() check first; only when the file is missing does it
     * attempt to materialise the 8x8 gray jpeg directly. This mirrors the logic of
     * UploadService::ensureBlurPlaceholder() but is inlined here because BaseController
     * does not have any service dependencies injected — and instantiating UploadService
     * requires a Database, which is owned by the concrete child controllers.
     *
     * If GD is unavailable (CR-12), or any write step fails, we degrade silently:
     * blurPlaceholderUrl() still returns the canonical URL and the caller's template
     * may emit a 404 image — strictly better than leaking the un-blurred cover.
     *
     * The static guard avoids retrying creation on every cover render within a single
     * request once a successful or failed attempt has been made.
     */
    private function ensureBlurPlaceholderExists(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        $placeholderPath = $mediaDir . '/blur-placeholder.jpg';
        if (is_file($placeholderPath)) {
            $checked = true;
            return;
        }

        // Runtime GD guard: if GD is missing we cannot generate a JPEG. Bail out
        // gracefully so a misconfigured environment doesn't fatal on a page render.
        if (!extension_loaded('gd')
            || !function_exists('imagecreatetruecolor')
            || !function_exists('imagecolorallocate')
            || !function_exists('imagefilledrectangle')
            || !function_exists('imagejpeg')
            || !function_exists('imagedestroy')
        ) {
            Logger::warning('BaseController: GD extension unavailable, cannot lazily create blur placeholder', [
                'path' => $placeholderPath,
            ], 'frontend');
            $checked = true;
            return;
        }

        try {
            if (!is_dir($mediaDir) && !@mkdir($mediaDir, 0755, true) && !is_dir($mediaDir)) {
                Logger::warning('BaseController: failed to create media dir for blur placeholder', [
                    'dir' => $mediaDir,
                ], 'frontend');
                $checked = true;
                return;
            }

            $image = imagecreatetruecolor(8, 8);
            if ($image === false) {
                Logger::warning('BaseController: failed to allocate blur placeholder image', [], 'frontend');
                $checked = true;
                return;
            }

            $color = imagecolorallocate($image, 120, 120, 120);
            if ($color !== false) {
                imagefilledrectangle($image, 0, 0, 7, 7, $color);
            }

            $saved = imagejpeg($image, $placeholderPath, 60);
            imagedestroy($image);

            if (!$saved) {
                Logger::warning('BaseController: failed to write blur placeholder image', [
                    'path' => $placeholderPath,
                ], 'frontend');
            }
        } catch (\Throwable $e) {
            Logger::warning('BaseController: exception while lazily creating blur placeholder', [
                'error' => $e->getMessage(),
            ], 'frontend');
        }

        // Mark checked regardless of outcome — avoid retrying on every cover render
        // for this request. A subsequent request will see the file via is_file().
        $checked = true;
    }

    /**
     * Get a safe redirect URL from HTTP_REFERER, validating it belongs to the same host.
     * Falls back to the provided default path if REFERER is missing or external.
     */
    protected function safeReferer(string $fallbackPath): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer === '') {
            return $this->redirect($fallbackPath);
        }
        $refererScheme = parse_url($referer, PHP_URL_SCHEME);
        $refererHost = parse_url($referer, PHP_URL_HOST);
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        // Strip port from server host for comparison
        $serverHost = preg_replace('/:\d+$/', '', $serverHost);
        $schemeOk = is_string($refererScheme) && in_array(strtolower($refererScheme), ['http', 'https'], true);
        if ($schemeOk && is_string($refererHost) && $serverHost !== '' && strcasecmp($refererHost, $serverHost) === 0) {
            return $referer;
        }
        return $this->redirect($fallbackPath);
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

    /**
     * Write a JSON response with proper error handling.
     * Returns the response with Content-Type header set.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @param mixed $data Data to encode as JSON
     * @param int $status HTTP status code (default: 200)
     * @param int $flags JSON encoding flags (default: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function jsonResponse(
        \Psr\Http\Message\ResponseInterface $response,
        mixed $data,
        int $status = 200,
        int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ): \Psr\Http\Message\ResponseInterface {
        try {
            $json = json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::error('JSON encoding failed', ['error' => $e->getMessage()], 'api');
            $json = json_encode(['ok' => false, 'error' => 'Internal error: failed to encode response']);
            $status = 500;
        }
        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
