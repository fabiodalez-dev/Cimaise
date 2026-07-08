<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class CsrfMiddleware implements MiddlewareInterface
{
    /** @var array<string> */
    private array $validateMethods = ['POST','PUT','PATCH','DELETE'];

    public function process(Request $request, Handler $handler): Response
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // Skip CSRF validation for installer and public analytics tracking routes
        // Note: /admin/login now requires CSRF for security hardening
        if ((str_starts_with($path, '/install/') && $method === 'POST') ||
            ($path === '/api/analytics/track' && $method === 'POST')) {
            return $handler->handle($request);
        }

        if (in_array($method, $this->validateMethods, true)) {
            $parsed = (array)($request->getParsedBody() ?? []);
            $token = $parsed['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');

            if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
                $response = new \Slim\Psr7\Response(400);
                $accept = $request->getHeaderLine('Accept');
                if (stripos($accept, 'application/json') !== false) {
                    $response->getBody()->write(json_encode(['ok' => false,'error' => 'Invalid CSRF token']));
                    return $response->withHeader('Content-Type', 'application/json');
                }
                $response->getBody()->write('Invalid CSRF token');
                return $response;
            }
            // Do not rotate token to avoid breaking parallel forms/AJAX
        }
        // Pass down the chain
        $response = $handler->handle($request);
        // Expose the current CSRF token so clients can update their token after
        // POSTs — but never on image responses (/media/ variants and the like):
        // those are long-cacheable (public, immutable), and a shared cache/CDN
        // would store one visitor's session-bound token and replay it to every
        // other visitor. Content-Type is used instead of the path so the guard
        // also holds for subdirectory installs (prefixed request paths).
        $isImage = str_starts_with($response->getHeaderLine('Content-Type'), 'image/');
        if (isset($_SESSION['csrf']) && !$isImage) {
            return $response->withHeader('X-CSRF-Token', $_SESSION['csrf']);
        }
        return $response;
    }
}
