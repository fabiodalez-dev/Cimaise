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
        // Expose admin-session presence + name so the frontend admin top bar can render
        // on every page for a logged-in admin (independent of per-render is_admin).
        $env->addGlobal('admin_logged_in', !empty($_SESSION['admin_id']));
        $env->addGlobal('admin_name', $_SESSION['admin_name'] ?? '');
        // "View as visitor" mode: admin session is present but the admin is browsing as a guest.
        $env->addGlobal('view_as_guest', !empty($_SESSION['view_as_guest']));
        unset($_SESSION['flash']);
        return $handler->handle($request);
    }
}
