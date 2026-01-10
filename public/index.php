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
    if (file_exists($installedMarker)) {
        // Validate .env exists and is not empty (check for any content, not arbitrary size)
        // Use @filesize to handle race condition where file could be deleted between checks
        if (file_exists($envFile) && is_readable($envFile)) {
            $size = @filesize($envFile);
            if ($size !== false && $size > 0) {
                $installed = true;
            } else {
                // Stale marker: .env is empty or unreadable
                @unlink($installedMarker);
            }
        } else {
            // Stale marker: .env was removed or corrupted after installation (e.g., reset)
            @unlink($installedMarker);
        }
    } elseif (file_exists($envFile) && is_readable($envFile) && ($size = @filesize($envFile)) !== false && $size > 0) {
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
    $app->add(new \App\Middlewares\CacheMiddleware($settingsService));
    $app->add(new \App\Middlewares\CompressionMiddleware($settingsService));
}

$app->add(new CsrfMiddleware());
$app->add(new FlashMiddleware());
$app->add(new SecurityHeadersMiddleware());

$twigCacheDir = __DIR__ . '/../storage/cache/twig';
if (!is_dir($twigCacheDir)) {
    @mkdir($twigCacheDir, 0755, true);
}
$twig = Twig::create(__DIR__ . '/../app/Views', ['cache' => $twigCacheDir]);

// Add custom Twig extensions
$twig->getEnvironment()->addExtension(new \App\Extensions\AnalyticsTwigExtension());
$twig->getEnvironment()->addExtension(new \App\Extensions\SecurityTwigExtension());
$twig->getEnvironment()->addExtension(new \App\Extensions\HooksTwigExtension());

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

// Expose about URL from settings (only if not installer route and database exists)
if (!$isInstallerRoute && $container['db'] !== null) {
    try {
        $settingsSvc = new \App\Services\SettingsService($container['db']);
        $aboutSlug = (string)($settingsSvc->get('about.slug', 'about') ?? 'about');
        $aboutSlug = $aboutSlug !== '' ? $aboutSlug : 'about';
        $twig->getEnvironment()->addGlobal('about_url', $basePath . '/' . $aboutSlug);
        // Expose galleries URL
        $galleriesSlug = (string)($settingsSvc->get('galleries.slug', 'galleries') ?? 'galleries');
        $galleriesSlug = $galleriesSlug !== '' ? $galleriesSlug : 'galleries';
        $twig->getEnvironment()->addGlobal('galleries_url', $basePath . '/' . $galleriesSlug);
        // Expose license URL and visibility for footer
        $licenseSlug = (string)($settingsSvc->get('license.slug', 'license') ?? 'license');
        $licenseSlug = $licenseSlug !== '' ? $licenseSlug : 'license';
        $twig->getEnvironment()->addGlobal('license_url', $basePath . '/' . $licenseSlug);
        $twig->getEnvironment()->addGlobal('license_show_in_footer', (bool)$settingsSvc->get('license.show_in_footer', false));
        $twig->getEnvironment()->addGlobal('license_title_footer', (string)($settingsSvc->get('license.title', 'License') ?? 'License'));
        // Expose privacy policy URL and visibility for footer
        $privacySlug = (string)($settingsSvc->get('privacy.slug', 'privacy-policy') ?? 'privacy-policy');
        $privacySlug = $privacySlug !== '' ? $privacySlug : 'privacy-policy';
        $twig->getEnvironment()->addGlobal('privacy_url', $basePath . '/' . $privacySlug);
        $twig->getEnvironment()->addGlobal('privacy_show_in_footer', (bool)$settingsSvc->get('privacy.show_in_footer', false));
        $twig->getEnvironment()->addGlobal('privacy_title_footer', (string)($settingsSvc->get('privacy.title', 'Privacy Policy') ?? 'Privacy Policy'));
        // Expose cookie policy URL and visibility for footer
        $cookieSlug = (string)($settingsSvc->get('cookie.slug', 'cookie-policy') ?? 'cookie-policy');
        $cookieSlug = $cookieSlug !== '' ? $cookieSlug : 'cookie-policy';
        $twig->getEnvironment()->addGlobal('cookie_url', $basePath . '/' . $cookieSlug);
        $twig->getEnvironment()->addGlobal('cookie_show_in_footer', (bool)$settingsSvc->get('cookie.show_in_footer', false));
        $twig->getEnvironment()->addGlobal('cookie_title_footer', (string)($settingsSvc->get('cookie.title', 'Cookie Policy') ?? 'Cookie Policy'));
        // Expose site title and logo globally for layouts
        $siteTitle = (string)($settingsSvc->get('site.title', 'Cimaise') ?? 'Cimaise');
        $siteLogo = $settingsSvc->get('site.logo', null);
        $logoType = (string)($settingsSvc->get('site.logo_type', 'text') ?? 'text');
        $twig->getEnvironment()->addGlobal('site_title', $siteTitle);
        $twig->getEnvironment()->addGlobal('site_logo', $siteLogo);
        $twig->getEnvironment()->addGlobal('logo_type', $logoType);
        $siteCopyright = (string)($settingsSvc->get('site.copyright', '') ?? '');
        $twig->getEnvironment()->addGlobal('site_copyright', $siteCopyright);
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
        $twig->getEnvironment()->addGlobal('admin_debug', (bool)$settingsSvc->get('admin.debug_logs', false));
        $twig->getEnvironment()->addGlobal('app_debug', (bool)($_ENV['APP_DEBUG'] ?? false));
        // Dark mode setting
        $twig->getEnvironment()->addGlobal('dark_mode', (bool)$settingsSvc->get('frontend.dark_mode', false));
        // Custom CSS (frontend only, already sanitized in controller)
        $twig->getEnvironment()->addGlobal('custom_css', (string)$settingsSvc->get('frontend.custom_css', ''));
        // Expose translation maps for JS bundles (admin/frontend)
        if ($translationService !== null) {
            if ($isAdminRoute) {
                $twig->getEnvironment()->addGlobal('admin_translations', $translationService->all());
            } else {
                $twig->getEnvironment()->addGlobal('frontend_translations', $translationService->all());
            }
        }
        // Cookie banner settings
        $twig->getEnvironment()->addGlobal('cookie_banner_enabled', $settingsSvc->get('privacy.cookie_banner_enabled', true));
        $twig->getEnvironment()->addGlobal('custom_js_essential', $settingsSvc->get('privacy.custom_js_essential', ''));
        $twig->getEnvironment()->addGlobal('custom_js_analytics', $settingsSvc->get('privacy.custom_js_analytics', ''));
        $twig->getEnvironment()->addGlobal('custom_js_marketing', $settingsSvc->get('privacy.custom_js_marketing', ''));
        $twig->getEnvironment()->addGlobal('show_analytics', $settingsSvc->get('cookie_banner.show_analytics', false));
        $twig->getEnvironment()->addGlobal('show_marketing', $settingsSvc->get('cookie_banner.show_marketing', false));
        // NSFW global warning - only show if enabled AND there are published NSFW albums
        $nsfwGlobalEnabled = (bool)$settingsSvc->get('privacy.nsfw_global_warning', false);
        $hasNsfwAlbums = false;
        if ($nsfwGlobalEnabled) {
            $nsfwCheck = $container['db']->pdo()->query('SELECT 1 FROM albums WHERE is_published = 1 AND is_nsfw = 1 LIMIT 1');
            $hasNsfwAlbums = $nsfwCheck && $nsfwCheck->fetchColumn() !== false;
        }
        $twig->getEnvironment()->addGlobal('nsfw_global_warning', $nsfwGlobalEnabled && $hasNsfwAlbums);
        // Lightbox settings
        $twig->getEnvironment()->addGlobal('lightbox_show_exif', $settingsSvc->get('lightbox.show_exif', true));
        $twig->getEnvironment()->addGlobal('disable_right_click', (bool)$settingsSvc->get('frontend.disable_right_click', true));
        // Social profiles for header (frontend only)
        if (!$isAdminRoute) {
            $rawProfiles = $settingsSvc->get('social.profiles', []);
            $socialProfiles = is_array($rawProfiles) ? $rawProfiles : [];
            // Filter and sanitize profiles for security
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
                // Only allow http/https URLs
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
        // Navigation tags for header (frontend only, with session cache)
        // Cache invalidation: see TagsController::store/update/delete
        $showTagsInHeader = (bool)$settingsSvc->get('navigation.show_tags_in_header', false);
        $twig->getEnvironment()->addGlobal('show_tags_in_header', $showTagsInHeader);
        if (!$isAdminRoute && $showTagsInHeader) {
            $navTags = [];
            // Check if cached in session and not expired (5 minutes TTL)
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
                    // Cache results in session
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
        // SEO settings for frontend
        if (!$isAdminRoute) {
            $twig->getEnvironment()->addGlobal('og_site_name', $settingsSvc->get('seo.og_site_name', $siteTitle));
            $twig->getEnvironment()->addGlobal('og_type', $settingsSvc->get('seo.og_type', 'website'));
            $twig->getEnvironment()->addGlobal('og_locale', $settingsSvc->get('seo.og_locale', 'en_US'));
            $twig->getEnvironment()->addGlobal('twitter_card', $settingsSvc->get('seo.twitter_card', 'summary_large_image'));
            $twig->getEnvironment()->addGlobal('twitter_site', $settingsSvc->get('seo.twitter_site', ''));
            $twig->getEnvironment()->addGlobal('twitter_creator', $settingsSvc->get('seo.twitter_creator', ''));
            $twig->getEnvironment()->addGlobal('robots', $settingsSvc->get('seo.robots_default', 'index,follow'));
            // Schema/structured data settings
            $twig->getEnvironment()->addGlobal('schema', [
                'enabled' => (bool)$settingsSvc->get('seo.schema_enabled', true),
                'author_name' => $settingsSvc->get('seo.author_name', ''),
                'author_url' => $settingsSvc->get('seo.author_url', ''),
                'organization_name' => $settingsSvc->get('seo.organization_name', ''),
                'organization_url' => $settingsSvc->get('seo.organization_url', ''),
                'image_copyright_notice' => $settingsSvc->get('seo.image_copyright_notice', ''),
                'image_license_url' => $settingsSvc->get('seo.image_license_url', ''),
            ]);
            $twig->getEnvironment()->addGlobal('analytics_gtag', $settingsSvc->get('seo.analytics_gtag', ''));
            $twig->getEnvironment()->addGlobal('analytics_gtm', $settingsSvc->get('seo.analytics_gtm', ''));

            // Font preloading for performance (prevent FOUT)
            $typographyService = new \App\Services\TypographyService($settingsSvc);
            $criticalFonts = $typographyService->getCriticalFontsForPreload($basePath);
            $twig->getEnvironment()->addGlobal('critical_fonts_preload', $criticalFonts);
        }
    } catch (\Throwable) {
        $twig->getEnvironment()->addGlobal('about_url', $basePath . '/about');
        $twig->getEnvironment()->addGlobal('galleries_url', $basePath . '/galleries');
        $twig->getEnvironment()->addGlobal('site_title', 'Cimaise');
        $twig->getEnvironment()->addGlobal('site_logo', null);
        $twig->getEnvironment()->addGlobal('logo_type', 'text');
        $twig->getEnvironment()->addGlobal('site_copyright', '');
        \App\Support\DateHelper::setDisplayFormat('Y-m-d');
        $twig->getEnvironment()->addGlobal('date_format', 'Y-m-d');
        $twig->getEnvironment()->addGlobal('site_language', 'en');
        $twig->getEnvironment()->addGlobal('admin_language', 'en');
        $twig->getEnvironment()->addGlobal('admin_debug', false);
        $twig->getEnvironment()->addGlobal('app_debug', (bool)($_ENV['APP_DEBUG'] ?? false));
        // Cookie banner defaults on error
        $twig->getEnvironment()->addGlobal('cookie_banner_enabled', true);
        $twig->getEnvironment()->addGlobal('custom_js_essential', '');
        $twig->getEnvironment()->addGlobal('custom_js_analytics', '');
        $twig->getEnvironment()->addGlobal('custom_js_marketing', '');
        $twig->getEnvironment()->addGlobal('show_analytics', false);
        $twig->getEnvironment()->addGlobal('show_marketing', false);
        $twig->getEnvironment()->addGlobal('lightbox_show_exif', true);
        $twig->getEnvironment()->addGlobal('disable_right_click', true);
        // Social profiles default on error
        if (!$isAdminRoute) {
            $twig->getEnvironment()->addGlobal('social_profiles', []);
        }
        // Navigation tags defaults on error
        $twig->getEnvironment()->addGlobal('show_tags_in_header', false);
        $twig->getEnvironment()->addGlobal('nav_tags', []);
        // SEO defaults on error
        if (!$isAdminRoute) {
            $twig->getEnvironment()->addGlobal('og_site_name', 'Cimaise');
            $twig->getEnvironment()->addGlobal('og_type', 'website');
            $twig->getEnvironment()->addGlobal('og_locale', 'en_US');
            $twig->getEnvironment()->addGlobal('twitter_card', 'summary_large_image');
            $twig->getEnvironment()->addGlobal('twitter_site', '');
            $twig->getEnvironment()->addGlobal('twitter_creator', '');
            $twig->getEnvironment()->addGlobal('robots', 'index,follow');
            $twig->getEnvironment()->addGlobal('schema', ['enabled' => true, 'author_name' => '', 'author_url' => '', 'organization_name' => '', 'organization_url' => '', 'image_copyright_notice' => '', 'image_license_url' => '']);
            $twig->getEnvironment()->addGlobal('analytics_gtag', '');
            $twig->getEnvironment()->addGlobal('analytics_gtm', '');
        }
        // Font preload fallback
        $twig->getEnvironment()->addGlobal('critical_fonts_preload', []);
    }
} else {
    $twig->getEnvironment()->addGlobal('about_url', $basePath . '/about');
    $twig->getEnvironment()->addGlobal('galleries_url', $basePath . '/galleries');
    $twig->getEnvironment()->addGlobal('site_title', 'Cimaise');
    $twig->getEnvironment()->addGlobal('site_logo', null);
    $twig->getEnvironment()->addGlobal('logo_type', 'text');
    $twig->getEnvironment()->addGlobal('site_copyright', '');
    \App\Support\DateHelper::setDisplayFormat('Y-m-d');
    $twig->getEnvironment()->addGlobal('date_format', 'Y-m-d');
    $twig->getEnvironment()->addGlobal('site_language', 'en');
    $twig->getEnvironment()->addGlobal('admin_language', 'en');
    $twig->getEnvironment()->addGlobal('admin_debug', false);
    // Cookie banner defaults for installer
    $twig->getEnvironment()->addGlobal('cookie_banner_enabled', false);
    $twig->getEnvironment()->addGlobal('custom_js_essential', '');
    $twig->getEnvironment()->addGlobal('custom_js_analytics', '');
    $twig->getEnvironment()->addGlobal('custom_js_marketing', '');
    $twig->getEnvironment()->addGlobal('show_analytics', false);
    $twig->getEnvironment()->addGlobal('show_marketing', false);
    $twig->getEnvironment()->addGlobal('lightbox_show_exif', true);
    $twig->getEnvironment()->addGlobal('disable_right_click', true);
    // Navigation tags defaults for installer
    $twig->getEnvironment()->addGlobal('show_tags_in_header', false);
    $twig->getEnvironment()->addGlobal('nav_tags', []);
    // Font preload fallback for installer/error states
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
