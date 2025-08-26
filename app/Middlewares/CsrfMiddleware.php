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
        
        // Skip CSRF validation for login, installer, and public analytics tracking routes
        if (($path === '/admin/login' && $method === 'POST') || 
            (strpos($path, '/install/') === 0 && $method === 'POST') ||
            ($path === '/api/analytics/track' && $method === 'POST')) {
            return $handler->handle($request);
        }
        
        if (in_array($method, $this->validateMethods, true)) {
            $parsed = (array)($request->getParsedBody() ?? []);
            $token = $parsed['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
            if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
                $response = new \Slim\Psr7\Response(400);
                $response->getBody()->write('Invalid CSRF token');
                return $response;
            }
            // Do not rotate token to avoid breaking parallel forms/AJAX
        }
        // Pass down the chain
        $response = $handler->handle($request);
        // Expose the current CSRF token so clients can update their token after POSTs
        if (isset($_SESSION['csrf'])) {
            return $response->withHeader('X-CSRF-Token', $_SESSION['csrf']);
        }
        return $response;
    }
}