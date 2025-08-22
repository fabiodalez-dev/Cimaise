<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        // Skip auth check for login/logout routes
        $path = $request->getUri()->getPath();
        if (in_array($path, ['/admin/login', '/admin/logout'])) {
            return $handler->handle($request);
        }
        
        if (empty($_SESSION['admin_id'])) {
            $response = new \Slim\Psr7\Response(302);
            return $response->withHeader('Location', '/admin/login');
        }
        return $handler->handle($request);
    }
}

