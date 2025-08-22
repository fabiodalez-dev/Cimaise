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
        $key = 'rl_' . sha1('login:' . $ip);
        $now = time();
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        // purge old
        $_SESSION[$key] = array_filter((array)$_SESSION[$key], fn($ts) => ($now - (int)$ts) < $this->windowSec);

        if (count($_SESSION[$key]) >= $this->maxAttempts) {
            $resp = new \Slim\Psr7\Response(429);
            $resp->getBody()->write('Too Many Attempts. Please try later.');
            return $resp->withHeader('Retry-After', (string)$this->windowSec);
        }

        $response = $handler->handle($request);
        if ($response->getStatusCode() === 200 && strpos((string)$response->getBody(), 'Credenziali non valide') !== false) {
            // count only failed login pages
            $_SESSION[$key][] = $now;
        } else {
            // on successful login, reset window
            if (isset($_SESSION['admin_id'])) {
                $_SESSION[$key] = [];
            }
        }
        return $response;
    }
}

