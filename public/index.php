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
use App\Support\Hooks;
use App\Support\CookieHelper;
use App\Support\PluginManager;

// Check if installer is being accessed
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$isInstallerRoute = strpos($requestUri, '/install') !== false || strpos($requestUri, 'installer.php') !== false;
$isAdminRoute = strpos($requestUri, '/admin') !== false;
$isLoginRoute = strpos($requestUri, '/login') !== false;

// Check if already installed (for all routes except installer itself)
// PERFORMANCE: Use file-based marker first (fast), only fall back to full check if needed
if (!$isInstallerRoute) {
    $root = dirname(__DIR__);
    $installedMarker = $root . '/storage/tmp/.installed';
    $installed = false;

    // Fast path: check for installed marker file AND .env existence + validity
    // Both must exist - if .env is removed or empty, marker is stale and should be cleared
    $envFile = $root . '/.env';
    $hasEnv = file_exists($envFile) && is_readable($envFile) && ($size = @filesize($envFile)) !== false && $size > 0;
    $markerPresent = file_exists($installedMarker);

    if ($markerPresent && $hasEnv) {
        $envContent = @file_get_contents($envFile) ?: '';
        $env = [];
        foreach (explode("\n", $envContent) as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                [$key, $value] = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }

        $connection = $env['DB_CONNECTION'] ?? 'sqlite';
        if ($connection === 'sqlite') {
            $dbPath = $env['DB_DATABASE'] ?? $root . '/database/database.sqlite';
            if (!str_starts_with($dbPath, '/')) {
                $dbPath = $root . '/' . $dbPath;
            }
            if (!file_exists($dbPath)) {
                @unlink($installedMarker);
                $markerPresent = false;
            }
        }
    } elseif ($markerPresent && !$hasEnv) {
        // Stale marker: .env was removed or corrupted after installation (e.g., reset)
        @unlink($installedMarker);
        $markerPresent = false;
    }

    if ($markerPresent && $hasEnv) {
        $installed = true;
    }

    if (!$installed && $hasEnv) {
        // Slow path: .env exists but no marker - verify installation properly
        try {
            $installer = new \App\Installer\Installer($root);
            $installed = $installer->isInstalled();
            // Create marker file for future fast checks
            if ($installed) {
                // Ensure storage/tmp directory exists before writing marker
                $markerDir = dirname($installedMarker);
                if (!is_dir($markerDir)) {
                    @mkdir($markerDir, 0775, true);
                }
                if (is_dir($markerDir)) {
                    @file_put_contents($installedMarker, date('Y-m-d H:i:s'), LOCK_EX);
                }
            }
        } catch (\Throwable $e) {
            $installed = false;
        }
    }

    // If not installed, redirect to installer
    if (!$installed) {
        // Avoid redirect loop - check if we're already on install page or accessing media/assets
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isInstallerPath = strpos($uri, '/install') !== false;
        $isMediaPath = strpos($uri, '/media/') !== false;
        $isAssetsPath = strpos($uri, '/assets/') !== false;

        if (!$isInstallerPath && !$isMediaPath && !$isAssetsPath) {
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
// CRITICAL: Set session cookie path to root to ensure it works in subdirectory installations
// Without this, the cookie may be restricted to /subdir/public/ and not sent to /subdir/admin/
ini_set('session.cookie_path', '/');
// Only set secure cookie flag if actually using HTTPS
// Checking APP_DEBUG alone breaks HTTP localhost testing in production mode
if (CookieHelper::isHttps()) {
    ini_set('session.cookie_secure', '1');
}
session_start();

// Calculate base path once for subdirectory installations
// Note: PHP built-in server sets SCRIPT_NAME to the requested URI when using a router,
// so we need to detect this and use an empty base path instead
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = dirname($scriptName);
$isBuiltInServer = php_sapi_name() === 'cli-server';
$basePath = $isBuiltInServer ? '' : ($scriptDir === '/' ? '' : $scriptDir);
// Remove /public from the base path if present (since document root should be public/)
if (str_ends_with($basePath, '/public')) {
    $basePath = substr($basePath, 0, -7);
}

// Initialize plugins after bootstrap
try {
    $pluginManager = PluginManager::getInstance();
    if ($container['db'] !== null) {
        Hooks::doAction('cimaise_init', $container['db'], $pluginManager);
    }
} catch (\Throwable $e) {
    // Plugin init failures should not block the app bootstrap
}

// Maintenance mode check - must be after session_start() and before routing
// PERFORMANCE: Cache plugin active status to avoid database query on every request
if ($container['db'] !== null && !$isInstallerRoute) {
    $maintenancePluginFile = __DIR__ . '/../plugins/maintenance-mode/plugin.php';
    if (file_exists($maintenancePluginFile)) {
        try {
            // Check cached status first (30 second TTL - plugin state rarely changes)
            $cacheFile = __DIR__ . '/../storage/tmp/maintenance_plugin_status.cache';
            $isActive = null;
            $cacheTtl = 30;

            if (file_exists($cacheFile)) {
                $cached = @file_get_contents($cacheFile);
                if ($cached !== false) {
                    $data = @json_decode($cached, true);
                    if (is_array($data) && isset($data['time'], $data['active']) && (time() - $data['time']) < $cacheTtl) {
                        $isActive = (bool) $data['active'];
                    }
                }
            }

            // If not cached, query database and cache result
            if ($isActive === null) {
                $pluginCheckStmt = $container['db']->pdo()->prepare('SELECT is_active FROM plugin_status WHERE slug = ? AND is_installed = 1');
                $pluginCheckStmt->execute(['maintenance-mode']);
                $pluginStatus = $pluginCheckStmt->fetch(\PDO::FETCH_ASSOC);
                $isActive = $pluginStatus && $pluginStatus['is_active'];
                // Atomic write: ensure directory exists, write to temp file, then rename
                $cacheDir = dirname($cacheFile);
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0775, true);
                }
                $payload = json_encode(['time' => time(), 'active' => (bool)$isActive]);
                if ($payload !== false && is_dir($cacheDir)) {
                    $tmpFile = $cacheFile . '.tmp';
                    if (@file_put_contents($tmpFile, $payload, LOCK_EX) !== false) {
                        @rename($tmpFile, $cacheFile);
                    }
                }
            }

            if ($isActive) {
                require_once $maintenancePluginFile;

                if (MaintenanceModePlugin::shouldShowMaintenancePage($container['db'])) {
                    // Get configuration and render maintenance page (uses $basePath calculated above)
                    $config = MaintenanceModePlugin::getMaintenanceConfig($container['db']);
                    require __DIR__ . '/../plugins/maintenance-mode/templates/maintenance.php';
                    exit;
                }
            }
        } catch (\Throwable $e) {
            // If plugin check fails, continue normally
            error_log('Maintenance mode check failed: ' . $e->getMessage());
        }
    }
}

$app = AppFactory::create();

if ($basePath) {
    $app->setBasePath($basePath);
}

$app->addBodyParsingMiddleware();

// Performance middleware (cache and compression)
$settingsService = null;
if ($container['db'] !== null) {
    $settingsService = new \App\Services\SettingsService($container['db']);
    $app->add(new \App\Middlewares\CacheMiddleware($settingsService, $container['db']));
    $app->add(new \App\Middlewares\CompressionMiddleware($settingsService));
}

$app->add(new CsrfMiddleware());
$app->add(new FlashMiddleware());
$app->add(new SecurityHeadersMiddleware());

$twigCacheDir = __DIR__ . '/../storage/cache/twig';
$twigCache = false;
if (!is_dir($twigCacheDir)) {
    @mkdir($twigCacheDir, 0755, true);
}
if (is_dir($twigCacheDir) && is_writable($twigCacheDir)) {
    $twigCache = $twigCacheDir;
}

// Twig configuration with performance optimizations
$isProduction = !filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
$twigOptions = [
    'cache' => $twigCache,
    // Disable auto_reload in production (huge performance gain - no file stat checks)
    'auto_reload' => !$isProduction,
    // Disable strict_variables in production (faster, less checks)
    'strict_variables' => !$isProduction,
    // Maximum optimization level (-1 = all optimizations enabled)
    'optimizations' => -1,
];

$twig = Twig::create(__DIR__ . '/../app/Views', $twigOptions);

// Add custom Twig extensions
$twig->getEnvironment()->addExtension(new \App\Extensions\AnalyticsTwigExtension());
$twig->getEnvironment()->addExtension(new \App\Extensions\SecurityTwigExtension());
$twig->getEnvironment()->addExtension(new \App\Extensions\HooksTwigExtension());
$twig->getEnvironment()->addExtension(new \App\Extensions\ViteTwigExtension($basePath));

// Register plugin Twig namespaces
$pluginTemplatesDir = __DIR__ . '/../plugins/custom-templates-pro/templates';
if (is_dir($pluginTemplatesDir)) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginTemplatesDir, 'custom-templates-pro');
    }
}

// Register additional Twig paths from plugins
$loader = $twig->getLoader();
if ($loader instanceof \Twig\Loader\FilesystemLoader) {
    $pluginTwigPaths = Hooks::applyFilter('twig_loader_paths', []);
    foreach ($pluginTwigPaths as $pluginTwigPath) {
        if (is_string($pluginTwigPath) && $pluginTwigPath !== '') {
            $loader->addPath($pluginTwigPath);
        }
    }
}

// Add translation extension (only if database is available)
$translationService = null;
if ($container['db'] !== null) {
    $translationService = new \App\Services\TranslationService($container['db']);
    $twig->getEnvironment()->addExtension(new \App\Extensions\TranslationTwigExtension($translationService));
    // Expose globally for trans() helper function in controllers
    $GLOBALS['translationService'] = $translationService;

    // Add performance extension for optimization features
    if ($settingsService === null) {
        $settingsService = new \App\Services\SettingsService($container['db']);
    }
    $performanceService = new \App\Services\PerformanceService($container['db'], $settingsService, $basePath);
    $twig->getEnvironment()->addExtension(new \App\Extensions\PerformanceTwigExtension($performanceService));
}

// Let plugins register Twig extensions
Hooks::doAction('twig_environment', $twig);

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

// Load Twig globals from cache (APCu/file) - reduces ~50 settings queries to ~1
// Cache is invalidated when settings change via TwigGlobalsCache::invalidate()
if (!$isInstallerRoute && $container['db'] !== null) {
    try {
        // Load cached globals (most settings come from here)
        $cachedGlobals = \App\Services\TwigGlobalsCache::getGlobals(
            $container['db'],
            $basePath,
            $isAdminRoute
        );

        // Add all cached globals to Twig
        foreach ($cachedGlobals as $key => $value) {
            $twig->getEnvironment()->addGlobal($key, $value);
        }

        // Initialize date format from cached globals
        $dateFormat = $cachedGlobals['date_format'] ?? 'Y-m-d';
        \App\Support\DateHelper::setDisplayFormat($dateFormat);

        // Initialize translation service with cached language settings
        $siteLanguage = $cachedGlobals['site_language'] ?? 'en';
        $adminLanguage = $cachedGlobals['admin_language'] ?? 'en';
        if ($translationService !== null) {
            $translationService->setLanguage($siteLanguage);
            $translationService->setAdminLanguage($adminLanguage);
            if ($isAdminRoute) {
                $translationService->setScope('admin');
            }
        }

        // App debug flag (from environment, not cached)
        $twig->getEnvironment()->addGlobal('app_debug', (bool)($_ENV['APP_DEBUG'] ?? false));

        // Translation maps for JS bundles (request-specific, not cached)
        if ($translationService !== null) {
            if ($isAdminRoute) {
                $twig->getEnvironment()->addGlobal('admin_translations', $translationService->all());
            } else {
                $twig->getEnvironment()->addGlobal('frontend_translations', $translationService->all());
            }
        }

        // NSFW global warning check (requires db query, not cached)
        $nsfwGlobalWarning = false;
        $settingsSvc = new \App\Services\SettingsService($container['db']);
        $nsfwGlobalEnabled = (bool)$settingsSvc->get('privacy.nsfw_global_warning', false);
        if ($nsfwGlobalEnabled) {
            $nsfwCheck = $container['db']->pdo()->query('SELECT 1 FROM albums WHERE is_published = 1 AND is_nsfw = 1 LIMIT 1');
            $nsfwGlobalWarning = $nsfwCheck && $nsfwCheck->fetchColumn() !== false;
        }
        $twig->getEnvironment()->addGlobal('nsfw_global_warning', $nsfwGlobalWarning);

        // Social profiles for header (frontend only, requires iteration)
        if (!$isAdminRoute) {
            $rawProfiles = $settingsSvc->get('social.profiles', []);
            $socialProfiles = is_array($rawProfiles) ? $rawProfiles : [];
            $safeProfiles = [];
            $profileNetworks = [
                'instagram' => ['name' => 'Instagram', 'icon' => 'fab fa-instagram'],
                'facebook' => ['name' => 'Facebook', 'icon' => 'fab fa-facebook-f'],
                'x' => ['name' => 'X', 'icon' => 'fab fa-x-twitter'],
                'threads' => ['name' => 'Threads', 'icon' => 'fab fa-threads'],
                'bluesky' => ['name' => 'Bluesky', 'icon' => 'fab fa-bluesky'],
                'tiktok' => ['name' => 'TikTok', 'icon' => 'fab fa-tiktok'],
                'youtube' => ['name' => 'YouTube', 'icon' => 'fab fa-youtube'],
                'vimeo' => ['name' => 'Vimeo', 'icon' => 'fab fa-vimeo-v'],
                'behance' => ['name' => 'Behance', 'icon' => 'fab fa-behance'],
                'dribbble' => ['name' => 'Dribbble', 'icon' => 'fab fa-dribbble'],
                'flickr' => ['name' => 'Flickr', 'icon' => 'fab fa-flickr'],
                'deviantart' => ['name' => 'DeviantArt', 'icon' => 'fab fa-deviantart'],
                'pinterest' => ['name' => 'Pinterest', 'icon' => 'fab fa-pinterest-p'],
                'linkedin' => ['name' => 'LinkedIn', 'icon' => 'fab fa-linkedin-in'],
                'tumblr' => ['name' => 'Tumblr', 'icon' => 'fab fa-tumblr'],
                'patreon' => ['name' => 'Patreon', 'icon' => 'fab fa-patreon'],
                '500px' => ['name' => '500px', 'icon' => 'fab fa-500px'],
                'website' => ['name' => 'Website', 'icon' => 'fas fa-globe'],
            ];
            foreach ($socialProfiles as $profile) {
                if (!isset($profile['network'], $profile['url'])) continue;
                $url = trim($profile['url']);
                if (!preg_match('#^https?://#i', $url)) continue;
                $network = $profile['network'];
                if (!isset($profileNetworks[$network])) continue;
                $safeProfiles[] = [
                    'network' => $network,
                    'url' => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
                    'name' => $profileNetworks[$network]['name'],
                    'icon' => $profileNetworks[$network]['icon'],
                ];
            }
            $twig->getEnvironment()->addGlobal('social_profiles', $safeProfiles);
        }

        // Navigation tags (has its own session-based caching)
        $showTagsInHeader = $cachedGlobals['show_tags_in_header'] ?? false;
        if (!$isAdminRoute && $showTagsInHeader) {
            $navTags = [];
            if (isset($_SESSION['nav_tags_cache']) &&
                isset($_SESSION['nav_tags_cache_time']) &&
                (time() - $_SESSION['nav_tags_cache_time']) < 300) {
                $navTags = $_SESSION['nav_tags_cache'];
            } else {
                try {
                    $tagsQuery = $container['db']->query('
                        SELECT t.id, t.name, t.slug, COUNT(at.album_id) as albums_count
                        FROM tags t
                        JOIN album_tag at ON at.tag_id = t.id
                        JOIN albums a ON a.id = at.album_id AND a.is_published = 1
                        GROUP BY t.id, t.name, t.slug
                        ORDER BY albums_count DESC, t.name ASC
                        LIMIT 20
                    ');
                    $navTags = $tagsQuery->fetchAll(\PDO::FETCH_ASSOC);
                    $_SESSION['nav_tags_cache'] = $navTags;
                    $_SESSION['nav_tags_cache_time'] = time();
                } catch (\Throwable) {
                    // Tags table might not exist
                }
            }
            $twig->getEnvironment()->addGlobal('nav_tags', $navTags);
        } else {
            $twig->getEnvironment()->addGlobal('nav_tags', []);
        }

        // Font preloading (frontend only, requires service)
        if (!$isAdminRoute) {
            $typographyService = new \App\Services\TypographyService($settingsSvc);
            $criticalFonts = $typographyService->getCriticalFontsForPreload($basePath);
            $twig->getEnvironment()->addGlobal('critical_fonts_preload', $criticalFonts);
        }
    } catch (\Throwable) {
        // Fallback: use TwigGlobalsCache defaults on error
        $defaults = \App\Services\TwigGlobalsCache::getDefaults($basePath);
        foreach ($defaults as $key => $value) {
            $twig->getEnvironment()->addGlobal($key, $value);
        }
        \App\Support\DateHelper::setDisplayFormat('Y-m-d');
        $twig->getEnvironment()->addGlobal('app_debug', (bool)($_ENV['APP_DEBUG'] ?? false));
        $twig->getEnvironment()->addGlobal('nsfw_global_warning', false);
        if (!$isAdminRoute) {
            $twig->getEnvironment()->addGlobal('social_profiles', []);
        }
        $twig->getEnvironment()->addGlobal('nav_tags', []);
        $twig->getEnvironment()->addGlobal('critical_fonts_preload', []);
    }
} else {
    // Installer route: use defaults
    $defaults = \App\Services\TwigGlobalsCache::getDefaults($basePath);
    foreach ($defaults as $key => $value) {
        $twig->getEnvironment()->addGlobal($key, $value);
    }
    \App\Support\DateHelper::setDisplayFormat('Y-m-d');
    $twig->getEnvironment()->addGlobal('app_debug', (bool)($_ENV['APP_DEBUG'] ?? false));
    $twig->getEnvironment()->addGlobal('cookie_banner_enabled', false);
    $twig->getEnvironment()->addGlobal('nsfw_global_warning', false);
    $twig->getEnvironment()->addGlobal('nav_tags', []);
    $twig->getEnvironment()->addGlobal('critical_fonts_preload', []);
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
$errorMiddleware->setErrorHandler(HttpNotFoundException::class, function ($request, \Throwable $exception, bool $displayErrorDetails) use ($twig, $translationService) {
    $response = new \Slim\Psr7\Response(404);
    $path = $request->getUri()->getPath();
    $isAdmin = str_contains($path, '/admin');

    // Set translation scope
    if ($translationService !== null) {
        $translationService->setScope($isAdmin ? 'admin' : 'frontend');
    }

    $template = $isAdmin ? 'errors/404_admin.twig' : 'errors/404.twig';
    return $twig->render($response, $template);
});
$errorMiddleware->setDefaultErrorHandler(function ($request, \Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) use ($twig, $translationService) {
    $response = new \Slim\Psr7\Response(500);
    $path = $request->getUri()->getPath();
    $isAdmin = str_contains($path, '/admin');

    // Set translation scope
    if ($translationService !== null) {
        $translationService->setScope($isAdmin ? 'admin' : 'frontend');
    }

    $template = $isAdmin ? 'errors/500_admin.twig' : 'errors/500.twig';
    return $twig->render($response, $template, [
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
