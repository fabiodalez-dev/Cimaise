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
    private static ?string $nonce = null;

    public function process(Request $request, Handler $handler): Response
    {
        // Generate a unique nonce for this request
        self::$nonce = base64_encode(random_bytes(16));

        // Store nonce in request attribute for use by templates
        $request = $request->withAttribute('csp_nonce', self::$nonce);

        $response = $handler->handle($request);

        // Check if this is an admin route
        $path = $request->getUri()->getPath();
        $isAdminRoute = str_starts_with($path, '/admin') || str_starts_with($path, '/cimaise/admin');

        $nonce = self::$nonce ?? '';

        // Admin routes: allow unsafe-inline for scripts (needed for SPA navigation)
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
            $csp = "upgrade-insecure-requests; default-src 'self'; "
                 . "img-src 'self' data: blob: https:; "
                 . "script-src 'self' 'nonce-{$nonce}' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; "
                 . "style-src 'self' 'unsafe-inline'; "
                 . "font-src 'self' data:; "
                 . "connect-src 'self'; "
                 . "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; "
                 . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
        }

        // SECURITY: Only add HSTS if connection is HTTPS (avoid breaking HTTP dev environments)
        $isHttps = CookieHelper::isHttps();

        $response = $response
            ->withHeader('X-Content-Type-Options','nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Referrer-Policy','strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy','geolocation=(), microphone=(), camera=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=(), midi=(), fullscreen=(self)')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('Content-Security-Policy', $csp);

        // Add HSTS only on HTTPS connections
        if ($isHttps) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Get the current request's CSP nonce
     */
    public static function getNonce(): ?string
    {
        return self::$nonce;
    }
}
