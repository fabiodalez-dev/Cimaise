<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Support\LazySession;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Applies LazySession::finalize() to the finished response. Registered as one
 * of the outermost middlewares so it sees error pages too.
 */
class LazySessionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        return LazySession::finalize($handler->handle($request));
    }
}
