<?php
declare(strict_types=1);

// Track request start time for performance logging
$_SERVER['REQUEST_TIME_FLOAT'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Middlewares\CsrfMiddleware;
use App\Middlewares\FlashMiddleware;
use App\Middlewares\SecurityHeadersMiddleware;
use Slim\Middleware\ErrorMiddleware;
use Slim\Exception\HttpNotFoundException;

// Check if installer is being accessed
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$isInstallerRoute = strpos($requestUri, '/install') !== false || strpos($requestUri, 'installer.php') !== false;
$isAdminRoute = strpos($requestUri, '/admin') !== false;
$isLoginRoute = strpos($requestUri, '/login') !== false;

// Check if already installed (for all routes except installer itself)
if (!$isInstallerRoute) {
    // Check if installed by looking for .env file (not .env.example) and database
    $root = dirname(__DIR__);
    $installed = false;
    
    // Auto-repair: create .env file if missing but template database exists
    if (!file_exists($root . '/.env') && file_exists($root . '/database/template.sqlite')) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = $scriptDir === '/' ? '' : $scriptDir;
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7);
        }
        $appUrl = $protocol . '://' . $host . $basePath;
        
        $envContent = "APP_ENV=production\n";
        $envContent .= "APP_DEBUG=false\n";
        $envContent .= "APP_URL=$appUrl\n";
        $envContent .= "APP_TIMEZONE=Europe/Rome\n\n";
        $envContent .= "DB_CONNECTION=sqlite\n";
        $envContent .= "DB_DATABASE=database/database.sqlite\n\n";
        $envContent .= "SESSION_SECRET=" . bin2hex(random_bytes(32)) . "\n";
        
        if (is_writable($root)) {
            @file_put_contents($root . '/.env', $envContent);
        }
    }
    
    if (file_exists($root . '/.env')) {
        // Load environment variables from .env
        $envContent = file_get_contents($root . '/.env');
        if (!empty($envContent)) {
            // Check if database file exists and is not empty
            $dbPath = $root . '/database/database.sqlite';
            $templatePath = $root . '/database/template.sqlite';
            
            // Auto-repair: if database is empty but template exists, copy template
            if (file_exists($templatePath) && (!file_exists($dbPath) || filesize($dbPath) == 0)) {
                if (is_writable(dirname($dbPath))) {
                    @copy($templatePath, $dbPath);
                }
            }
            
            // Try to load database configuration
            try {
                if (file_exists($root . '/app/Installer/Installer.php')) {
                    require_once $root . '/app/Installer/Installer.php';
                    $installer = new \App\Installer\Installer($root);
                    $installed = $installer->isInstalled();
                }
            } catch (\Throwable $e) {
                // If there's an error, we assume it's not installed
                $installed = false;
            }
        }
    }
    
    // If not installed, redirect to installer
    if (!$installed) {
        // Avoid redirect loop - check if we're already on install page
        if (strpos($_SERVER['REQUEST_URI'], '/install') === false) {
            $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
            $scriptDir = dirname($scriptPath);
            $basePath = $scriptDir === '/' ? '' : $scriptDir;
            http_response_code(302);
            header('Location: ' . $basePath . '/install');
            exit;
        }
    }
}

// Bootstrap env and services
try {
    $container = require __DIR__ . '/../app/Config/bootstrap.php';
} catch (\Throwable $e) {
    // If bootstrap fails (e.g., no database), create minimal container
    $container = ['db' => null];
}

// Sessions with secure defaults
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ((bool)($_ENV['APP_DEBUG'] ?? false) === false) {
    ini_set('session.cookie_secure', '1');
}
session_start();

$app = AppFactory::create();

// Set base path for subdirectory installations
// Note: PHP built-in server sets SCRIPT_NAME to the requested URI when using a router,
// so we need to detect this and use an empty base path instead
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = dirname($scriptName);

// Detect PHP built-in server (no actual script like index.php in SCRIPT_NAME)
$isBuiltInServer = php_sapi_name() === 'cli-server';
if ($isBuiltInServer) {
    // Built-in server with router: base path is always empty
    $basePath = '';
} else {
    $basePath = $scriptDir === '/' ? '' : $scriptDir;
    // Remove /public from the base path if present (since document root should be public/)
    if (str_ends_with($basePath, '/public')) {
        $basePath = substr($basePath, 0, -7); // Remove '/public'
    }
}

if ($basePath) {
    $app->setBasePath($basePath);
}

$app->addBodyParsingMiddleware();
$app->add(new CsrfMiddleware());
$app->add(new FlashMiddleware());
$app->add(new SecurityHeadersMiddleware());

$twig = Twig::create(__DIR__ . '/../app/Views', ['cache' => false]);

// Add custom Twig extensions
$twig->getEnvironment()->addExtension(new \App\Extensions\AnalyticsTwigExtension());
$twig->getEnvironment()->addExtension(new \App\Extensions\SecurityTwigExtension());
$twig->getEnvironment()->addExtension(new \App\Extensions\HooksTwigExtension());

// Add translation extension (only if database is available)
$translationService = null;
if ($container['db'] !== null) {
    $translationService = new \App\Services\TranslationService($container['db']);
    $twig->getEnvironment()->addExtension(new \App\Extensions\TranslationTwigExtension($translationService));
}

$app->add(TwigMiddleware::create($app, $twig));

// Auto-detect app URL if not set in environment
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// For PHP built-in server, use the already computed basePath
$autoBasePath = $basePath;

$autoDetectedUrl = $protocol . '://' . $host . $autoBasePath;

// Share globals
$twig->getEnvironment()->addGlobal('app_url', $_ENV['APP_URL'] ?? $autoDetectedUrl);
$twig->getEnvironment()->addGlobal('base_path', $basePath);

// Expose about URL from settings (only if not installer route and database exists)
if (!$isInstallerRoute && $container['db'] !== null) {
    try {
        $settingsSvc = new \App\Services\SettingsService($container['db']);
        $aboutSlug = (string)($settingsSvc->get('about.slug', 'about') ?? 'about');
        $aboutSlug = $aboutSlug !== '' ? $aboutSlug : 'about';
        $twig->getEnvironment()->addGlobal('about_url', $basePath . '/' . $aboutSlug);
        // Expose site title and logo globally for layouts
        $siteTitle = (string)($settingsSvc->get('site.title', 'Cimaise') ?? 'Cimaise');
        $siteLogo = $settingsSvc->get('site.logo', null);
        $twig->getEnvironment()->addGlobal('site_title', $siteTitle);
        $twig->getEnvironment()->addGlobal('site_logo', $siteLogo);
        // Initialize date format from settings
        $dateFormat = $settingsSvc->get('date.format', 'Y-m-d');
        \App\Support\DateHelper::setDisplayFormat($dateFormat);
        $twig->getEnvironment()->addGlobal('date_format', $dateFormat);
        // Initialize language from settings
        $siteLanguage = (string)($settingsSvc->get('site.language', 'en') ?? 'en');
        $adminLanguage = (string)($settingsSvc->get('admin.language', 'en') ?? 'en');
        if ($translationService !== null) {
            $translationService->setLanguage($siteLanguage);
            $translationService->setAdminLanguage($adminLanguage);
            // Set scope based on current route
            if ($isAdminRoute) {
                $translationService->setScope('admin');
            }
        }
        $twig->getEnvironment()->addGlobal('site_language', $siteLanguage);
        $twig->getEnvironment()->addGlobal('admin_language', $adminLanguage);
        // Cookie banner settings
        $twig->getEnvironment()->addGlobal('cookie_banner_enabled', $settingsSvc->get('privacy.cookie_banner_enabled', true));
        $twig->getEnvironment()->addGlobal('custom_js_essential', $settingsSvc->get('privacy.custom_js_essential', ''));
        $twig->getEnvironment()->addGlobal('custom_js_analytics', $settingsSvc->get('privacy.custom_js_analytics', ''));
        $twig->getEnvironment()->addGlobal('custom_js_marketing', $settingsSvc->get('privacy.custom_js_marketing', ''));
        $twig->getEnvironment()->addGlobal('show_analytics', $settingsSvc->get('cookie_banner.show_analytics', false));
        $twig->getEnvironment()->addGlobal('show_marketing', $settingsSvc->get('cookie_banner.show_marketing', false));
        // Lightbox settings
        $twig->getEnvironment()->addGlobal('lightbox_show_exif', $settingsSvc->get('lightbox.show_exif', true));
        $twig->getEnvironment()->addGlobal('disable_right_click', (bool)$settingsSvc->get('frontend.disable_right_click', true));
    } catch (\Throwable) {
        $twig->getEnvironment()->addGlobal('about_url', $basePath . '/about');
        $twig->getEnvironment()->addGlobal('site_title', 'Cimaise');
        $twig->getEnvironment()->addGlobal('site_logo', null);
        \App\Support\DateHelper::setDisplayFormat('Y-m-d');
        $twig->getEnvironment()->addGlobal('date_format', 'Y-m-d');
        $twig->getEnvironment()->addGlobal('site_language', 'en');
        $twig->getEnvironment()->addGlobal('admin_language', 'en');
        // Cookie banner defaults on error
        $twig->getEnvironment()->addGlobal('cookie_banner_enabled', true);
        $twig->getEnvironment()->addGlobal('custom_js_essential', '');
        $twig->getEnvironment()->addGlobal('custom_js_analytics', '');
        $twig->getEnvironment()->addGlobal('custom_js_marketing', '');
        $twig->getEnvironment()->addGlobal('show_analytics', false);
        $twig->getEnvironment()->addGlobal('show_marketing', false);
        $twig->getEnvironment()->addGlobal('lightbox_show_exif', true);
        $twig->getEnvironment()->addGlobal('disable_right_click', true);
    }
} else {
    $twig->getEnvironment()->addGlobal('about_url', $basePath . '/about');
    $twig->getEnvironment()->addGlobal('site_title', 'Cimaise');
    $twig->getEnvironment()->addGlobal('site_logo', null);
    \App\Support\DateHelper::setDisplayFormat('Y-m-d');
    $twig->getEnvironment()->addGlobal('date_format', 'Y-m-d');
    $twig->getEnvironment()->addGlobal('site_language', 'en');
    $twig->getEnvironment()->addGlobal('admin_language', 'en');
    // Cookie banner defaults for installer
    $twig->getEnvironment()->addGlobal('cookie_banner_enabled', false);
    $twig->getEnvironment()->addGlobal('custom_js_essential', '');
    $twig->getEnvironment()->addGlobal('custom_js_analytics', '');
    $twig->getEnvironment()->addGlobal('custom_js_marketing', '');
    $twig->getEnvironment()->addGlobal('show_analytics', false);
    $twig->getEnvironment()->addGlobal('show_marketing', false);
    $twig->getEnvironment()->addGlobal('lightbox_show_exif', true);
    $twig->getEnvironment()->addGlobal('disable_right_click', true);
}

// Register date format Twig extension
$twig->getEnvironment()->addExtension(new \App\Extensions\DateTwigExtension());

// Expose admin status for frontend header
$twig->getEnvironment()->addGlobal('is_admin', isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0);

// Routes (pass container and app)
$routes = require __DIR__ . '/../app/Config/routes.php';
if (is_callable($routes)) {
    $routes($app, $container);
}

$errorMiddleware = $app->addErrorMiddleware((bool)($_ENV['APP_DEBUG'] ?? false), true, true);
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

// Register performance logging on shutdown
register_shutdown_function(function () {
    if (!function_exists('envv') || !filter_var(envv('DEBUG_PERFORMANCE', false), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }
    // Defensive check - ensure Logger class is available
    if (!class_exists(\App\Support\Logger::class)) {
        return;
    }
    $duration = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
    \App\Support\Logger::performance(
        $_SERVER['REQUEST_URI'] ?? '/',
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $duration,
        $memoryMb
    );
});

$app->run();
