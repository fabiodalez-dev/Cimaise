<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);
        $csp = "default-src 'self'; img-src 'self' data: blob:; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com http://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; connect-src 'self'";
        return $response
            ->withHeader('X-Content-Type-Options','nosniff')
            ->withHeader('Referrer-Policy','strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy','geolocation=(), microphone=(), camera=()')
            ->withHeader('Content-Security-Policy', $csp);
    }
}
