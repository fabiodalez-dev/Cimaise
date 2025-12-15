<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private int $maxAttempts = 5, private int $windowSec = 600) {}

    public function process(Request $request, Handler $handler): Response
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $path = $request->getUri()->getPath();

        // Use different keys for different endpoints to track separately
        $keyIdentifier = 'generic';
        if (str_contains($path, '/unlock')) {
            $keyIdentifier = 'album_unlock:' . $path;
        } elseif (str_contains($path, '/login')) {
            $keyIdentifier = 'login';
        }

        $key = 'rl_' . sha1($keyIdentifier . ':' . $ip);
        $now = time();

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        // Purge old attempts outside the window
        $_SESSION[$key] = array_filter((array)$_SESSION[$key], fn($ts) => ($now - (int)$ts) < $this->windowSec);

        if (count($_SESSION[$key]) >= $this->maxAttempts) {
            $remaining = $this->windowSec - ($now - min($_SESSION[$key]));
            $resp = new \Slim\Psr7\Response(429);
            $resp->getBody()->write('Too Many Attempts. Please try again in ' . ceil($remaining / 60) . ' minutes.');
            return $resp->withHeader('Retry-After', (string)$remaining);
        }

        $response = $handler->handle($request);

        // Detect failed attempts based on response type
        $isFailedAttempt = false;
        $statusCode = $response->getStatusCode();

        // For login pages: check response body for error message
        if ($statusCode === 200 && str_contains((string)$response->getBody(), 'Credenziali non valide')) {
            $isFailedAttempt = true;
        }

        // For album unlock: check for redirect with error parameter
        if ($statusCode === 302) {
            $location = $response->getHeaderLine('Location');
            if (str_contains($location, 'error=1') || str_contains($location, 'error=nsfw')) {
                $isFailedAttempt = true;
            }
        }

        if ($isFailedAttempt) {
            $_SESSION[$key][] = $now;
        } else {
            // On successful login/unlock, reset window for this endpoint
            if (isset($_SESSION['admin_id']) ||
                ($statusCode === 302 && !str_contains($response->getHeaderLine('Location'), 'error='))) {
                $_SESSION[$key] = [];
            }
        }

        return $response;
    }
}

