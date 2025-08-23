<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Middlewares\CsrfMiddleware;
use App\Middlewares\FlashMiddleware;
use App\Middlewares\SecurityHeadersMiddleware;
use Slim\Middleware\ErrorMiddleware;
use Slim\Exception\HttpNotFoundException;

// Bootstrap env and services
$container = require __DIR__ . '/../app/Config/bootstrap.php';

// Sessions with secure defaults
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ((bool)($_ENV['APP_DEBUG'] ?? false) === false) {
    ini_set('session.cookie_secure', '1');
}
session_start();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->add(new CsrfMiddleware());
$app->add(new FlashMiddleware());
$app->add(new SecurityHeadersMiddleware());

$twig = Twig::create(__DIR__ . '/../app/Views', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));

// Share globals
$twig->getEnvironment()->addGlobal('app_url', $_ENV['APP_URL'] ?? 'http://localhost:8000');
// Expose about URL from settings (dynamic permalink)
try {
    $settingsSvc = new \App\Services\SettingsService($container['db']);
    $aboutSlug = (string)($settingsSvc->get('about.slug', 'about') ?? 'about');
    $aboutSlug = $aboutSlug !== '' ? $aboutSlug : 'about';
    $twig->getEnvironment()->addGlobal('about_url', '/' . $aboutSlug);
} catch (\Throwable) {
    $twig->getEnvironment()->addGlobal('about_url', '/about');
}

// Routes (pass container and app)
$routes = require __DIR__ . '/../app/Config/routes.php';
if (is_callable($routes)) {
    $routes($app, $container);
}

$errorMiddleware = $app->addErrorMiddleware((bool)($_ENV['APP_DEBUG'] ?? true), true, true);
$errorMiddleware->setErrorHandler(HttpNotFoundException::class, function ($request, \Throwable $exception, bool $displayErrorDetails) use ($twig) {
    $response = new \Slim\Psr7\Response(404);
    return $twig->render($response, 'errors/404.twig');
});
$errorMiddleware->setDefaultErrorHandler(function ($request, \Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) use ($twig) {
    $response = new \Slim\Psr7\Response(500);
    return $twig->render($response, 'errors/500.twig', [
        'message' => $displayErrorDetails ? (string)$exception : ''
    ]);
});

$app->run();
