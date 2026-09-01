<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Support\CookieHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * WARNING: process-wide state, safe ONLY under one-request-per-process runtimes
     * (Apache/mod_php, PHP-FPM). Under persistent runtimes handling concurrent
     * requests in one process (Swoole, RoadRunner), this value would leak between
     * requests. It exists solely because SecurityTwigExtension::getCspNonce() is
     * registered at bootstrap as a plain Twig function and has no access to the
     * PSR-7 request. Anything that CAN access the request must read the
     * 'csp_nonce' request attribute instead of calling getNonce().
     */
    private static ?string $nonce = null;

    public function process(Request $request, Handler $handler): Response
    {
        // Generate a unique nonce for this request
        $nonce = base64_encode(random_bytes(16));
        self::$nonce = $nonce;

        // Store nonce in request attribute for use by templates
        $request = $request->withAttribute('csp_nonce', $nonce);

        $response = $handler->handle($request);

        // Check if this is an admin route
        $path = $request->getUri()->getPath();
        $isAdminRoute = str_starts_with($path, '/admin') || str_starts_with($path, '/cimaise/admin');

        // Admin routes: unsafe-inline for scripts because TinyMCE/SortableJS inject inline scripts dynamically.
        // Nonce-based CSP can't work here: CSP2+ ignores 'unsafe-inline' when a nonce is present,
        // but dynamically-created scripts by libraries don't carry the nonce.
        // Admin is behind authentication, so the XSS risk from unsafe-inline is acceptable.
        // Frontend routes: strict nonce-based CSP with external image sources for galleries
        if ($isAdminRoute) {
            $csp = "upgrade-insecure-requests; default-src 'self'; "
                 . "img-src 'self' data: blob:; "
                 . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                 . "style-src 'self' 'unsafe-inline'; "
                 . "font-src 'self' data:; "
                 . "connect-src 'self'; "
                 . "frame-src 'self'; "
                 . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
        } else {
            // Frontend: allow external images (https:) for PhotoSwipe and galleries
            // SECURITY: Allow 'unsafe-inline' for styles because JavaScript libraries (PhotoSwipe, GSAP, Lenis)
            // dynamically set inline styles via element.style.property = value, which cannot use nonces.
            // This is standard practice - script-src remains strict with nonce-based protection.
            // GA4 / Google Tag Manager: the analytics snippet (rendered only when
            // seo.analytics_gtag/gtm is configured) injects scripts from
            // googletagmanager.com and beacons to the analytics collection hosts.
            // Allowlisted here the same way reCAPTCHA is — always present so the
            // opt-in tags are never CSP-blocked; the tags themselves stay gated by
            // the setting. Host-source + nonce coexist (no 'strict-dynamic').
            $gaScript = 'https://www.googletagmanager.com https://www.google-analytics.com';
            $gaConnect = 'https://www.google-analytics.com https://*.google-analytics.com '
                . 'https://*.analytics.google.com https://www.googletagmanager.com';

            $csp = "upgrade-insecure-requests; default-src 'self'; "
                 . "img-src 'self' data: blob: https:; "
                 . "script-src 'self' 'nonce-{$nonce}' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ {$gaScript}; "
                 . "style-src 'self' 'unsafe-inline'; "
                 . "font-src 'self' data:; "
                 . "connect-src 'self' {$gaConnect}; "
                 . "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; "
                 . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
        }

        // SECURITY: Only add HSTS if connection is HTTPS (avoid breaking HTTP dev environments)
        $isHttps = CookieHelper::isHttps();

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=(), midi=(), fullscreen=(self)')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none');

        // Staging/demo guard: when CIMAISE_NOINDEX is set, force every response to
        // noindex via an authoritative X-Robots-Tag header. Unlike a <meta robots>,
        // this covers non-HTML responses (feeds, sitemap) and cannot be undone by a
        // per-page meta override, keeping a staging copy out of the index entirely.
        $noindex = (string) ($_ENV['CIMAISE_NOINDEX'] ?? (getenv('CIMAISE_NOINDEX') ?: ''));
        if (!in_array(strtolower($noindex), ['', '0', 'false', 'off', 'no'], true)) {
            $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        // Skip CSP on 304 responses: the browser keeps the cached body (with the original nonce)
        // but would update headers. A new CSP nonce would mismatch the cached body's nonce.
        if ($response->getStatusCode() === 304) {
            $response = $response->withoutHeader('Content-Security-Policy');
        } else {
            $response = $response->withHeader('Content-Security-Policy', $csp);
        }

        // Add HSTS only on HTTPS connections
        if ($isHttps) {
            return $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Get the current request's CSP nonce.
     *
     * NOTE: only for consumers without request access (SecurityTwigExtension).
     * Prefer the 'csp_nonce' request attribute wherever the request is available —
     * this static accessor is not safe under concurrent persistent runtimes
     * (see the warning on self::$nonce).
     */
    public static function getNonce(): ?string
    {
        return self::$nonce;
    }
}
