<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use App\Services\SettingsService;
use App\Services\PageCacheService;
use App\Services\HtmlPageCache;
use App\Support\LazySession;
use App\Support\Database;

class CacheMiddleware implements MiddlewareInterface
{
    private ?PageCacheService $pageCacheService = null;

    /**
     * Per-request memo for the parts of the HTML validator that cannot change
     * while one request is handled. generateHtmlEtag() runs before AND after
     * rendering (race detection), so without it each HTML request hashed
     * version.json and JSON-encoded all settings twice, and album pages ran
     * the slug lookup twice. The page_cache hash and the image-generation
     * state are still read fresh on both passes.
     */
    private ?string $staticContentId = null;
    /** @var array<string, string|null> */
    private array $albumKeyBySlug = [];

    private readonly HtmlPageCache $htmlCache;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ?Database $database = null,
        ?HtmlPageCache $htmlCache = null
    ) {
        $this->pageCacheService = new PageCacheService($this->settings, $this->database);
        $this->htmlCache = $htmlCache ?? HtmlPageCache::forApp();
    }

    public function process(Request $request, Handler $handler): Response
    {
        $cacheEnabled = $this->settings->get('performance.cache_enabled', true);
        $method = $request->getMethod();

        // Normalize path: strip basePath for subdirectory installations.
        // Boundary check ensures basePath matches a full segment
        // (e.g. /foo must not match /foobar).
        $path = $request->getUri()->getPath();
        $basePath = rtrim((string) $this->settings->get('site.base_path', ''), '/');
        if ($basePath === '') {
            $scriptDirectory = dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            if (str_ends_with($scriptDirectory, '/public')) { $scriptDirectory = substr($scriptDirectory, 0, -7); }
            $basePath = in_array($scriptDirectory, ['/', '.', '\\'], true) ? '' : $scriptDirectory;
        }
        if ($basePath !== '' && str_starts_with($path, $basePath) && (strlen($path) === strlen($basePath) || $path[strlen($basePath)] === '/')) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        // Early 304 fast path: for cacheable HTML routes (home/galleries/album),
        // resolve the ETag from the page_cache table BEFORE executing the controller.
        // When the client's If-None-Match matches, skip rendering entirely.
        // Only safe for anonymous, non-session-dependent GET/HEAD requests.
        $etagBeforeRendering = null;
        if (
            $cacheEnabled
            && in_array($method, ['GET', 'HEAD'], true)
            && !$this->isSessionDependent()
            && !str_starts_with($path, '/admin')
            && !str_starts_with($path, '/api/')
        ) {
            $etagBeforeRendering = $this->generateHtmlEtag($path, $request->getQueryParams());
            $ifNoneMatch = $request->getHeaderLine('If-None-Match');
            if ($ifNoneMatch !== '') {
                $earlyEtag = $etagBeforeRendering;
                if ($earlyEtag !== null && $this->matchesEtag($earlyEtag, $ifNoneMatch)) {
                    // Build a 304 from scratch — no handler invocation, no Twig render.
                    $emptyBody = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
                    $maxAge = $this->settings->get('performance.html_cache_max_age', 3600);
                    return (new \Slim\Psr7\Response())
                        ->withStatus(304)
                        ->withBody($emptyBody)
                        ->withHeader('ETag', $earlyEtag)
                        ->withHeader('Cache-Control', "public, no-cache, max-age={$maxAge}, must-revalidate")
                        ->withHeader('Vary', 'Accept-Encoding');
                }
            }
        }

        // Rendered-HTML cache for cookie-less visitors (see HtmlPageCache).
        $htmlCacheKey = null;
        $nonce = (string) $request->getAttribute('csp_nonce', '');
        if ($etagBeforeRendering !== null && $nonce !== '' && $this->canUseHtmlCache($request)) {
            $uri = $request->getUri();
            $htmlCacheKey = HtmlPageCache::key($uri->getScheme(), $uri->getHost(), $uri->getPort(), $uri->getPath());
            $hit = $this->htmlCache->get($htmlCacheKey, $etagBeforeRendering, $nonce);
            if ($hit !== null) {
                $cached = new \Slim\Psr7\Response(200);
                $cached->getBody()->write($hit['body']);
                foreach ($hit['headers'] as $name => $values) {
                    $cached = $cached->withHeader($name, $values);
                }
                return $this->addHtmlCache($cached->withHeader('X-Cimaise-Cache', 'hit'), $request, $path, $etagBeforeRendering);
            }
        }

        $response = $handler->handle($request);

        // These editors change shared layout or page content. Hard-purge after
        // the final write so the next visitor cannot receive a stale page.
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && !empty($_SESSION['admin_id']) && $response->getStatusCode() < 400
            && preg_match('#^/admin/(settings|pages|social|typography)(/|$)#', $path)) {
            $this->pageCacheService?->clearAll();
            \App\Services\TwigGlobalsCache::invalidate();
        }

        if (!$cacheEnabled) {
            return $response;
        }

        // Error responses are never shareable, whatever the path looks like.
        // A 403 from a gated download stamped 'public, max-age=3600' would be
        // stored by a shared cache/CDN and replayed to the visitor who has
        // since unlocked the album (together with the session-bound
        // X-CSRF-Token header of whoever triggered it); a 404 for a .jpg path
        // would otherwise fall into the static branch and get 'public,
        // immutable' for a year. Decide this BEFORE any strategy branch.
        if ($response->getStatusCode() >= 400) {
            return $this->addNoCacheHeaders($response);
        }

        // Only cache GET and HEAD requests
        if (!in_array($method, ['GET', 'HEAD'])) {
            return $response;
        }

        // Don't cache admin routes
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return $this->addNoCacheHeaders($response);
        }

        // API routes
        if (str_starts_with($path, '/api/')) {
            if ($path === '/api/image-generation-revision') {
                return $response->withHeader('Cache-Control', 'no-store');
            }
            // Admin API: no cache
            if ($path === '/api/admin' || str_starts_with($path, '/api/admin/')) {
                return $this->addNoCacheHeaders($response);
            }
            // Frontend API: short private cache for infinite scroll, template switching, EXIF
            return $this->addApiCache($response);
        }

        // Dynamic routes that look like static assets (e.g., /fonts/typography.css)
        // These are PHP-generated and should NOT get 1-year immutable cache
        if ($this->isDynamicRoute($path)) {
            return $this->addDynamicAssetCache($response);
        }

        // Check if it's a static asset
        if ($this->isStaticAsset($path)) {
            return $this->addStaticAssetCache($response);
        }

        // Check if it's a media file
        if (str_starts_with($path, '/media/protected/')) {
            return $this->addProtectedMediaCache($response);
        }
        if (str_starts_with($path, '/media/')) {
            return $this->addMediaCache($response);
        }

        // Pass through non-HTML content types (JSON, binary, etc.)
        // Empty Content-Type is treated as HTML: at this point admin, API, static, and media
        // routes are already filtered above, so remaining responses are Twig-rendered pages
        // which don't always set Content-Type explicitly
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $result = $this->addHtmlCache($response, $request, $path, $etagBeforeRendering);
        if ($htmlCacheKey !== null && $etagBeforeRendering !== null) {
            $this->storeRenderedHtml($htmlCacheKey, $etagBeforeRendering, $nonce, $result);
        }
        return $result;
    }

    /**
     * Only requests carrying no cookie at all: NSFW consent, language or admin
     * state can come from cookies, and a session cookie means per-visitor data.
     */
    private function canUseHtmlCache(Request $request): bool
    {
        return $request->getMethod() === 'GET'
            && $request->getCookieParams() === []
            && LazySession::isActive()
            && !$this->isSessionDependent();
    }

    private function storeRenderedHtml(string $key, string $etagBeforeRendering, string $nonce, Response $result): void
    {
        // Same validator before and after rendering (no concurrent rebuild),
        // shareable, and no session-bound token in the markup.
        if ($result->getStatusCode() !== 200
            || $result->getHeaderLine('ETag') !== $etagBeforeRendering
            || !str_starts_with($result->getHeaderLine('Cache-Control'), 'public')) {
            return;
        }
        $stream = $result->getBody();
        if (!$stream->isSeekable()) {
            return;
        }
        $stream->rewind();
        $html = $stream->getContents();
        $stream->rewind();
        $token = $_SESSION['csrf'] ?? null;
        if ($html === '' || (is_string($token) && $token !== '' && str_contains($html, $token))) {
            return;
        }
        $this->htmlCache->put($key, $etagBeforeRendering, $nonce, $html, $result->getHeaders());
    }

    private function isStaticAsset(string $path): bool
    {
        $staticExtensions = [
            '.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif',
            '.svg', '.ico', '.woff', '.woff2', '.ttf', '.otf', '.eot',
            '.map', '.json', '.xml'
        ];

        foreach ($staticExtensions as $ext) {
            if (str_ends_with(strtolower($path), $ext)) {
                return true;
            }
        }

        return false;
    }

    private function addStaticAssetCache(Response $response): Response
    {
        // Respect access-controlled responses (see hasRestrictedCacheControl()).
        // NOTE: /media/*.{jpg,webp,avif} matches isStaticAsset() before the /media/
        // branches in process(), so password-gated media marked 'private' by
        // MediaController lands HERE — without this guard it was flipped to
        // 'public, immutable' and could be stored by shared caches/CDNs.
        if ($this->hasRestrictedCacheControl($response)) {
            return $response;
        }

        $maxAge = $this->settings->get('performance.static_cache_max_age', 31536000); // 1 year default

        return $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}, immutable")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT')
            ->withHeader('Pragma', 'public');
    }

    /**
     * True when the controller already marked the response as not shareable
     * (Cache-Control containing 'private' or 'no-store'). MediaController uses
     * 'private' for password-gated album media and NSFW-gated content; cache
     * decoration middleware must never widen that to 'public'.
     */
    private function hasRestrictedCacheControl(Response $response): bool
    {
        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));
        return $cacheControl !== ''
            && (str_contains($cacheControl, 'private') || str_contains($cacheControl, 'no-store'));
    }

    private function addMediaCache(Response $response): Response
    {
        // Respect access-controlled media: MediaController marks password-gated album
        // content and NSFW placeholders with Cache-Control 'private' (or 'no-store').
        // Rewriting that to 'public' would let shared caches/CDNs serve protected
        // bytes to other users — leave such responses untouched.
        if ($this->hasRestrictedCacheControl($response)) {
            return $response;
        }

        $maxAge = $this->settings->get('performance.media_cache_max_age', 86400); // 1 day default

        // Don't compute ETag here - it would require reading entire body into memory
        // MediaController already sets ETag based on file stats (mtime + size)
        // which is much more efficient than MD5 hashing the entire response
        $result = $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT')
            ->withHeader('Pragma', 'public');

        // Only add ETag if not already set by controller
        if (!$response->hasHeader('ETag')) {
            // Use weak ETag based on content-length and last-modified if available
            $contentLength = $response->getHeaderLine('Content-Length');
            $lastModified = $response->getHeaderLine('Last-Modified');
            if ($contentLength && $lastModified) {
                $result = $result->withHeader('ETag', 'W/"' . md5($contentLength . $lastModified) . '"');
            }
        }

        return $result;
    }

    private function addProtectedMediaCache(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }

    private function addHtmlCache(Response $response, Request $request, string $normalizedPath, ?string $etagBeforeRendering): Response
    {
        // PW2: a controller may mark an HTML response as not shareable — e.g. the
        // password/NSFW gate pages, which embed a session-bound CSRF token. For a
        // first-time visitor isSessionDependent() is still false (no album_access
        // /nsfw/admin flag set yet), so without this guard the gate would be
        // stamped 'public' and a shared cache could replay one visitor's gate (and
        // CSRF token) to another. Respect an existing private/no-store just like
        // the static and media cache decorators already do.
        if ($this->hasRestrictedCacheControl($response)) {
            return $response;
        }

        $maxAge = $this->settings->get('performance.html_cache_max_age', 3600); // 1 hour default

        // Determine cache visibility: use 'private' when the session contains user-specific
        // access control data (password-protected albums, NSFW consent, admin login).
        // This prevents CDN/proxies from serving session-dependent content to other users.
        $isSessionDependent = $this->isSessionDependent();
        $visibility = $isSessionDependent ? 'private' : 'public';

        // Try to generate ETag from page cache (database or file) if available
        $etag = $this->generateHtmlEtag($normalizedPath, $request->getQueryParams());
        // A concurrent save/rebuild may replace the cache while Twig renders
        // the previous snapshot. Never label that body with the new validator.
        // Stale renders opt out of HTTP caching in their controllers; on a
        // fresh-cache race, fall back to hashing the body actually returned.
        // The same holds when there was no valid entry before rendering and
        // one exists now: this request may have built it, or another request
        // may have stored a newer one while this body was rendered from older
        // data. The two cases cannot be told apart here, so hash the body.
        if ($isSessionDependent || $etag !== $etagBeforeRendering) {
            $etag = null;
        }
        if (!$etag) {
            // Only hash body for small responses to avoid O(size) CPU/memory overhead
            // For large pages without cache ETags, skip hashing entirely
            $maxHashSize = 256 * 1024; // 256 KB threshold
            $contentLength = $response->getHeaderLine('Content-Length');
            $shouldHash = $contentLength === '' || (int) $contentLength <= $maxHashSize;

            if ($shouldHash) {
                $stream = $response->getBody();
                $currentPos = $stream->tell();

                if ($stream->isSeekable()) {
                    // Seekable stream: rewind to start if needed before reading
                    if ($currentPos > 0) {
                        $stream->rewind();
                    }
                    $body = $stream->getContents();
                    $stream->rewind();
                } elseif ($currentPos === 0) {
                    // Non-seekable stream at position 0: safe to read entire content
                    $body = $stream->getContents();
                    $newStream = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
                    $newStream->write($body);
                    $newStream->rewind();
                    $response = $response->withBody($newStream);
                } else {
                    // Non-seekable stream already partially read: skip ETag generation
                    // to avoid hashing incomplete content
                    $body = '';
                }

                // Double-check size after reading (Content-Length may be missing)
                if ($body !== '' && strlen($body) <= $maxHashSize) {
                    // Strip per-request tokens (CSP nonce, CSRF) before hashing
                    // so the ETag is stable for identical page content
                    // preg_replace can return null on error (e.g. backtrack limit)
                    $hashBody = preg_replace('/\s*nonce="[^"]*"/', '', $body) ?? $body;
                    $hashBody = preg_replace('/name="csrf"\s+value="[^"]*"/', 'name="csrf" value=""', $hashBody) ?? $hashBody;
                    $etag = '"' . sha1($hashBody) . '"';
                }
            }
        }

        // Check If-None-Match header for 304 response
        if ($etag) {
            $ifNoneMatch = $request->getHeaderLine('If-None-Match');
            if ($ifNoneMatch && $this->matchesEtag($etag, $ifNoneMatch)) {
                // Create empty body for 304 response to avoid sending stale content
                $emptyBody = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
                $notModifiedResponse = $response
                    ->withStatus(304)
                    ->withBody($emptyBody)
                    ->withHeader('ETag', $etag)
                    ->withHeader('Cache-Control', "{$visibility}, no-cache, max-age={$maxAge}, must-revalidate");
                $vary304 = 'Accept-Encoding';
                if ($isSessionDependent) {
                    $vary304 .= ', Cookie';
                }
                return $this->addVaryHeader($notModifiedResponse, $vary304);
            }
        }

        // Keep stored HTML, but validate its content ID before every reuse.
        $result = $response
            ->withHeader('Cache-Control', "{$visibility}, no-cache, max-age={$maxAge}, must-revalidate")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');

        if ($etag) {
            $result = $result->withHeader('ETag', $etag);
        }

        // M1: Include Vary: Cookie for session-dependent responses
        $varyValues = 'Accept-Encoding';
        if ($isSessionDependent) {
            $varyValues .= ', Cookie';
        }

        return $this->addVaryHeader($result, $varyValues);
    }

    /**
     * Generate ETag for HTML pages based on cache (database or file).
     *
     * @param array<string, mixed> $query Query parameters of the request
     */
    private function generateHtmlEtag(string $path, array $query = []): ?string
    {
        if (!$this->pageCacheService) {
            return null;
        }

        // Path is already normalized (basePath stripped) by process()

        // Get configurable galleries slug (default: /galleries)
        $galleriesPath = '/' . trim((string) $this->settings->get('galleries.slug', 'galleries'), '/');

        // The validator must describe the entry the page is rendered from. A
        // query string selects something else (home ?template= preview,
        // filtered galleries listing, album ?template= variant) that is not
        // rendered from these entries, so those requests hash the body.
        $cacheType = null;
        if ($path === '/' || $path === '') {
            if ($query !== []) {
                return null;
            }
            $cacheType = 'home';
        } elseif ($path === $galleriesPath || $path === $galleriesPath . '/') {
            if ($query !== []) {
                return null;
            }
            $cacheType = PageCacheService::galleriesPageKey(
                (string) ($this->settings->get('galleries.template', 'classic') ?? 'classic')
            );
        } elseif (preg_match('#^/album/([^/]+)/?$#', $path, $matches)) {
            // The ETag must come from the entry the page is rendered from
            // (PageCacheService::albumPageKey). A ?template=N request renders a
            // different variant, so it falls back to hashing the body.
            if (isset($query['template'])) {
                return null;
            }
            $slug = rawurldecode($matches[1]);
            if (!array_key_exists($slug, $this->albumKeyBySlug)) {
                $this->albumKeyBySlug[$slug] = $this->pageCacheService->albumPageKeyForSlug($slug);
            }
            $cacheType = $this->albumKeyBySlug[$slug];
        }

        if (!$cacheType) {
            return null;
        }

        // Use database hash when backend is database
        $backend = $this->settings->get('cache.storage_backend', 'database');
        if ($backend === 'database') {
            $hash = $this->pageCacheService->getHash($cacheType);
            if ($hash) {
                return $this->htmlContentId($hash);
            }
            return null;
        }

        // Fall back to file-based ETag
        if ($this->pageCacheService->isExpired($cacheType)) {
            return null;
        }
        $cacheFile = $this->pageCacheService->getCacheFilePath($cacheType);
        if ($cacheFile && file_exists($cacheFile)) {
            // Hash the content, not its stat: two rewrites within the same
            // second with the same length would otherwise share a validator.
            $hash = hash_file('sha256', $cacheFile);
            return $hash === false ? null : $this->htmlContentId($hash);
        }

        return null;
    }

    private function htmlContentId(string $hash): string
    {
        $this->staticContentId ??= json_encode($this->settings->all()) . hash_file('sha256', dirname(__DIR__, 2) . '/version.json')
            . $this->layoutContentId();
        // The layout embeds the image-generation state (the visitor's
        // enhanced-version watcher): a validator that ignored it would keep
        // serving 304 for a page whose watcher state is stale.
        $generation = \App\Services\ImageGenerationRevision::current()
            . (\App\Services\ImageGenerationRevision::pending() ? ':pending' : ':idle');
        return '"' . hash('sha256', $hash . $this->staticContentId . $generation) . '"';
    }

    /**
     * Shared-layout inputs that live outside settings and page_cache: the
     * frontend texts (any frontend_texts write bumps the generation) and the
     * header tag menu. Without them a renamed tag or an edited text kept
     * serving 304s and stored HTML with the old layout.
     */
    private function layoutContentId(): string
    {
        $id = \App\Services\TranslationService::cacheGeneration();
        if ($this->database instanceof Database && $this->settings->get('navigation.show_tags_in_header', false)) {
            $id .= md5((string) json_encode((new \App\Services\NavigationService($this->database))->getHeaderTags()));
        }
        return $id;
    }

    /**
     * Check if an ETag matches the If-None-Match header value.
     * Handles weak ETags (W/"..."), multiple comma-separated values, and quote stripping.
     */
    private function matchesEtag(string $etag, string $ifNoneMatch): bool
    {
        // Wildcard match
        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        // Normalize our ETag (strip weak prefix and quotes for comparison)
        $normalizedEtag = $this->normalizeEtag($etag);

        // Parse If-None-Match header (can contain comma-separated values)
        $candidates = array_map(trim(...), explode(',', $ifNoneMatch));

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($candidate === '*') {
                continue;
            }
            // Normalize candidate ETag
            $normalizedCandidate = $this->normalizeEtag($candidate);

            // Compare normalized values
            if ($normalizedEtag === $normalizedCandidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize an ETag by stripping W/ prefix, surrounding quotes and the
     * content-coding suffix a compressing web server may have appended.
     */
    private function normalizeEtag(string $etag): string
    {
        // Strip weak prefix
        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }

        // Strip surrounding quotes
        $etag = trim($etag, '"');

        // Apache appends the content coding to the validator when it compresses
        // the response (mod_deflate / mod_brotli, DeflateAlterETag AddSuffix):
        // the browser then sends back "<etag>-gzip" or "<etag>-br". Compare the
        // validator itself, or a compressed page could never revalidate to 304.
        return preg_replace('/-(gzip|br|zstd|deflate)$/', '', $etag) ?? $etag;
    }

    private function addNoCacheHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * Add Vary header values to existing ones instead of overwriting.
     * This prevents overwriting Vary headers set by other middleware (e.g., CompressionMiddleware).
     */
    private function addVaryHeader(Response $response, string $newVary): Response
    {
        $existing = $response->getHeaderLine('Vary');
        $existingValues = array_filter(array_map(trim(...), explode(',', $existing)));
        $newValues = array_filter(array_map(trim(...), explode(',', $newVary)));
        $merged = array_unique(array_merge($existingValues, $newValues), SORT_STRING);
        return $response->withHeader('Vary', implode(', ', $merged));
    }

    /**
     * Check if a path is a PHP-generated route that looks like a static asset.
     * These should NOT get 1-year immutable cache since their content can change.
     */
    private function isDynamicRoute(string $path): bool
    {
        $dynamicRoutes = [
            '/fonts/typography.css',
            '/site.webmanifest',
        ];
        return in_array($path, $dynamicRoutes, true);
    }

    private function addDynamicAssetCache(Response $response): Response
    {
        $maxAge = $this->settings->get('performance.dynamic_asset_max_age', 86400); // 1 day default

        return $response
            ->withHeader('Cache-Control', "public, max-age={$maxAge}, stale-while-revalidate=60")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT')
            ->withHeader('Pragma', 'public');
    }

    private function addApiCache(Response $response): Response
    {
        $maxAge = $this->settings->get('performance.api_cache_max_age', 60); // 1 minute default

        $result = $response
            ->withHeader('Cache-Control', "private, max-age={$maxAge}, stale-while-revalidate=30");

        // Include Vary: Cookie for session-dependent API responses (e.g. /api/album/* that
        // depend on password unlock, NSFW consent, or admin session). Without this, shared
        // caches or browser back/forward may serve another session's response.
        $varyValues = 'Accept-Encoding';
        if ($this->isSessionDependent()) {
            $varyValues .= ', Cookie';
        }

        return $this->addVaryHeader($result, $varyValues);
    }

    /**
     * Determine whether the current response is session-dependent.
     *
     * Shared by addHtmlCache() and addApiCache() to ensure consistent Vary: Cookie
     * and cache visibility decisions when the session holds user-specific access
     * state (password-protected album access, NSFW consent, admin login).
     */
    private function isSessionDependent(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE
            && (
                !empty($_SESSION['album_access'])
                || !empty($_SESSION['nsfw_confirmed'])
                || !empty($_SESSION['nsfw_confirmed_global'])
                || !empty($_SESSION['admin_id'])
            );
    }
}
