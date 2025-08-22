<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Views\Twig;

class FlashMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $twig = Twig::fromRequest($request);
        $messages = $_SESSION['flash'] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        // Expose and clear
        $env = $twig->getEnvironment();
        $env->addGlobal('flash', $messages);
        $env->addGlobal('csrf', $_SESSION['csrf'] ?? '');
        unset($_SESSION['flash']);
        return $handler->handle($request);
    }
}
