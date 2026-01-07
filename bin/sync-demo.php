#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * sync-demo.php - Synchronize demo folder with main app while preserving demo features
 *
 * This script updates the demo folder from the main app, applying demo-specific
 * patches to maintain demo functionality (template switching, password protection, etc.)
 *
 * Usage: php bin/sync-demo.php [--dry-run]
 */

$projectRoot = dirname(__DIR__);
$demoRoot = $projectRoot . '/demo';
$dryRun = in_array('--dry-run', $argv);

// Colors for terminal output
function color(string $text, string $color): string {
    $colors = [
        'green' => "\033[0;32m",
        'yellow' => "\033[1;33m",
        'red' => "\033[0;31m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function logMsg(string $msg): void { echo color("[SYNC]", 'green') . " $msg\n"; }
function warnMsg(string $msg): void { echo color("[WARN]", 'yellow') . " $msg\n"; }
function errorMsg(string $msg): void { echo color("[ERROR]", 'red') . " $msg\n"; }

if ($dryRun) {
    echo color("DRY RUN MODE - No changes will be made\n", 'yellow');
}

// ============================================================================
// CONFIGURATION
// ============================================================================

$syncDirs = [
    'app/Config',
    'app/Controllers',
    'app/Extensions',
    'app/Installer',
    'app/Middlewares',
    'app/Repositories',
    'app/Services',
    'app/Support',
    'app/Tasks',
    'app/Views',
    'plugins',
    'resources',
    'storage/translations',
];

$syncFiles = [
    'public/index.php',
    'public/router.php',
    'public/.htaccess',
    'public/sw.js',
    'public/offline.html',
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'vite.config.js',
    'tailwind.config.js',
    'postcss.config.js',
];

// ============================================================================
// SYNC FUNCTIONS
// ============================================================================

function syncDirectory(string $src, string $dst, bool $dryRun): void {
    if (!is_dir($src)) {
        warnMsg("Source directory not found: $src");
        return;
    }

    logMsg("  Syncing " . basename($src) . "/");

    if ($dryRun) return;

    // Create destination if it doesn't exist
    if (!is_dir($dst)) {
        if (!mkdir($dst, 0755, true)) {
            errorMsg("Failed to create directory: $dst");
            return;
        }
    }

    // Get all files in source
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relativePath = substr($item->getPathname(), strlen($src) + 1);
        $dstPath = $dst . '/' . $relativePath;

        // Skip unwanted files
        if (preg_match('/\.(DS_Store|log)$/', $relativePath) ||
            basename($relativePath) === 'CLAUDE.md') {
            continue;
        }

        if ($item->isDir()) {
            if (!is_dir($dstPath)) {
                if (!mkdir($dstPath, 0755, true)) {
                    errorMsg("Failed to create directory: $dstPath");
                }
            }
        } else {
            $dstDir = dirname($dstPath);
            if (!is_dir($dstDir)) {
                if (!mkdir($dstDir, 0755, true)) {
                    errorMsg("Failed to create directory: $dstDir");
                    continue;
                }
            }
            if (!copy($item->getPathname(), $dstPath)) {
                errorMsg("Failed to copy: {$item->getPathname()} to $dstPath");
            }
        }
    }

    // Remove files in dest that don't exist in source (except demo-specific)
    if (is_dir($dst)) {
        $dstIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dst, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($dstIterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($dst) + 1);
            $srcPath = $src . '/' . $relativePath;

            // Don't delete demo-specific files
            if (str_contains($relativePath, '_demo_')) {
                continue;
            }

            if (!file_exists($srcPath) && !is_dir($srcPath)) {
                if ($item->isDir()) {
                    if (!@rmdir($item->getPathname())) {
                        warnMsg("Failed to remove directory: {$item->getPathname()}");
                    }
                } else {
                    if (!@unlink($item->getPathname())) {
                        warnMsg("Failed to remove file: {$item->getPathname()}");
                    }
                }
            }
        }
    }
}

function syncFile(string $src, string $dst, bool $dryRun): void {
    if (!file_exists($src)) {
        warnMsg("Source file not found: $src");
        return;
    }

    logMsg("  Syncing " . basename($src));

    if ($dryRun) return;

    $dstDir = dirname($dst);
    if (!is_dir($dstDir)) {
        if (!mkdir($dstDir, 0755, true)) {
            errorMsg("Failed to create directory: $dstDir");
            return;
        }
    }
    if (!copy($src, $dst)) {
        errorMsg("Failed to copy: $src to $dst");
    }
}

function syncPublicAssets(string $projectRoot, string $demoRoot, bool $dryRun): void {
    logMsg("Syncing public assets...");

    // Sync assets folder
    syncDirectory("$projectRoot/public/assets", "$demoRoot/public/assets", $dryRun);

    // Sync fonts
    syncDirectory("$projectRoot/public/fonts", "$demoRoot/public/fonts", $dryRun);

    if ($dryRun) return;

    // Sync favicons
    $favicons = ['favicon.ico', 'favicon-32x32.png', 'favicon-16x16.png',
                 'apple-touch-icon.png', 'android-chrome-192x192.png',
                 'android-chrome-512x512.png', 'site.webmanifest'];
    foreach ($favicons as $favicon) {
        $src = "$projectRoot/public/$favicon";
        if (file_exists($src)) {
            if (!copy($src, "$demoRoot/public/$favicon")) {
                errorMsg("Failed to copy favicon: $favicon");
            }
        }
    }
}

// ============================================================================
// DEMO PATCHES
// ============================================================================

function patchIndexPhp(string $demoRoot, bool $dryRun): void {
    logMsg("Applying demo patch: public/index.php");
    if ($dryRun) return;

    $file = "$demoRoot/public/index.php";
    $content = file_get_contents($file);

    // Add DEMO_MODE constant after declare(strict_types=1);
    $demoModeBlock = <<<'PHP'

// DEMO MODE: This is a demo instance with fixed credentials and restricted features
define('DEMO_MODE', true);
PHP;

    $content = preg_replace(
        '/^(declare\(strict_types=1\);)$/m',
        "$1$demoModeBlock",
        $content
    );

    if (!str_contains($content, "define('DEMO_MODE', true)")) {
        warnMsg("Failed to apply DEMO_MODE constant patch to index.php");
    }

    // Add demo Twig globals before $app->run();
    $demoGlobals = <<<'PHP'

// DEMO MODE: Expose demo status and credentials for templates
if (defined('DEMO_MODE') && DEMO_MODE) {
    $twig->getEnvironment()->addGlobal('is_demo', true);
    $twig->getEnvironment()->addGlobal('demo_credentials', 'demo@cimaise.local / password123');
}

$app->run();
PHP;

    $content = str_replace('$app->run();', $demoGlobals, $content);

    if (!str_contains($content, "addGlobal('is_demo', true)")) {
        warnMsg("Failed to apply Twig globals patch to index.php");
    }

    file_put_contents($file, $content);
}

function patchAuthController(string $demoRoot, bool $dryRun): void {
    logMsg("Applying demo patch: AuthController.php");
    if ($dryRun) return;

    $file = "$demoRoot/app/Controllers/Admin/AuthController.php";
    $content = file_get_contents($file);

    // Add demo mode block to changePassword method
    $demoBlock = <<<'PHP'
        // DEMO MODE: Block password change
        if (defined('DEMO_MODE') && DEMO_MODE) {
            $_SESSION['flash'][] = [
                'type' => 'warning',
                'message' => 'Il cambio password è disabilitato in modalità demo. Credenziali: demo@cimaise.local / password123'
            ];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

PHP;

    // Insert after the opening brace of changePassword method
    $content = preg_replace(
        '/(public function changePassword\([^)]*\): Response\s*\{)/',
        "$1\n$demoBlock",
        $content
    );

    if (!str_contains($content, '// DEMO MODE: Block password change')) {
        warnMsg("Failed to apply password change block patch to AuthController.php");
    }

    // Add is_demo to showLogin render
    $content = preg_replace(
        "/'csrf' => \\\$_SESSION\['csrf'\] \?\? ''/",
        "'csrf' => \$_SESSION['csrf'] ?? '',\n            'is_demo' => defined('DEMO_MODE') && DEMO_MODE",
        $content
    );

    if (!str_contains($content, "'is_demo' => defined('DEMO_MODE')")) {
        warnMsg("Failed to apply is_demo patch to AuthController.php");
    }

    file_put_contents($file, $content);
}

function patchPageController(string $demoRoot, bool $dryRun): void {
    logMsg("Applying demo patch: PageController.php");
    if ($dryRun) return;

    $file = "$demoRoot/app/Controllers/Frontend/PageController.php";
    $content = file_get_contents($file);

    if (str_contains($content, 'Allow template override via query parameter')) {
        return;
    }

    // Add template override logic
    $templateOverride = <<<'PHP'

        // DEMO MODE: Allow template override via ?template= query parameter
        $templateOverride = $request->getQueryParams()['template'] ?? null;
        $validTemplates = ['classic', 'modern', 'parallax', 'masonry', 'snap', 'gallery'];
        if ($templateOverride && in_array($templateOverride, $validTemplates, true)) {
            $homeTemplate = $templateOverride;
        }
PHP;

    // Insert after the homeTemplate setting line
    $content = preg_replace(
        '/(\$homeTemplate = \(string\)\(\$svc->get\(\'home\.template\', \'classic\'\) \?\? \'classic\'\);)/',
        "$1$templateOverride",
        $content
    );

    if (!str_contains($content, '// DEMO MODE: Allow template override')) {
        warnMsg("Failed to apply template override patch to PageController.php");
    }

    file_put_contents($file, $content);
}

function patchAdminLayout(string $demoRoot, bool $dryRun): void {
    logMsg("Applying demo patch: admin/_layout.twig");
    if ($dryRun) return;

    $file = "$demoRoot/app/Views/admin/_layout.twig";
    $content = file_get_contents($file);

    $demoBanner = <<<'TWIG'
  {# DEMO MODE: Demo banner #}
  {% if is_demo is defined and is_demo %}
  <div class="bg-white text-gray-600 text-center py-2 text-sm font-medium fixed w-full z-50 top-0 border-b border-gray-200">
    <i class="fas fa-flask mr-2 text-gray-400"></i>
    DEMO MODE - Credenziali: <strong class="text-gray-900">{{ demo_credentials|default('demo@cimaise.local / password123') }}</strong> - Reset automatico ogni 24 ore
  </div>
  <style nonce="{{ csp_nonce() }}">
    /* Shift everything down to make room for demo banner */
    body { padding-top: 38px; }
    nav.fixed.top-0 { top: 38px !important; }
    aside.fixed.top-0 { top: 38px !important; }
  </style>
  {% endif %}
TWIG;

    // Insert after <body class="bg-gray-50">
    $content = preg_replace(
        '/(<body class="bg-gray-50">)/',
        "$1\n$demoBanner",
        $content
    );

    if (!str_contains($content, '{# DEMO MODE: Demo banner #}')) {
        warnMsg("Failed to apply demo banner patch to admin/_layout.twig");
    }

    file_put_contents($file, $content);
}

function patchFrontendLayout(string $demoRoot, bool $dryRun): void {
    logMsg("Applying demo patch: frontend/_layout.twig");
    if ($dryRun) return;

    $file = "$demoRoot/app/Views/frontend/_layout.twig";
    $content = file_get_contents($file);

    // Insert demo template menu inside the nav element, after the About link
    // This places it alongside Home, Galleries, About links in the navigation
    $demoMenuInclude = <<<'TWIG'

                        {# DEMO: Home Template Switcher #}
                        {% if is_demo is defined and is_demo %}
                        {% include 'frontend/_demo_template_menu.twig' %}
                        {% endif %}

                        <!-- Categories Mega Menu -->
TWIG;

    // Replace the Categories Mega Menu comment to insert demo menu before it
    $content = preg_replace(
        '/\n\s*<!-- Categories Mega Menu -->/',
        $demoMenuInclude,
        $content,
        1 // Only replace first occurrence (desktop nav)
    );

    if (!str_contains($content, "_demo_template_menu.twig")) {
        warnMsg("Failed to apply template menu patch to frontend/_layout.twig");
    }

    file_put_contents($file, $content);
}

function patchLoginTemplate(string $demoRoot, bool $dryRun): void {
    logMsg("Applying demo patch: admin/login.twig");
    if ($dryRun) return;

    $file = "$demoRoot/app/Views/admin/login.twig";
    $content = file_get_contents($file);

    $demoBox = <<<'TWIG'

    {# DEMO MODE: Show login credentials #}
    {% if is_demo is defined and is_demo %}
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
      <div class="flex items-start gap-3">
        <div class="flex-shrink-0">
          <i class="fas fa-flask text-amber-600 text-lg"></i>
        </div>
        <div class="flex-1">
          <h3 class="font-semibold text-amber-900 mb-2">Demo Mode</h3>
          <p class="text-sm text-amber-800 mb-3">Use these credentials to explore the admin panel:</p>
          <div class="bg-white/60 rounded-lg p-3 space-y-2">
            <div class="flex items-center gap-2 text-sm">
              <span class="text-amber-700 font-medium w-20">Email:</span>
              <code class="bg-amber-100 px-2 py-0.5 rounded text-amber-900 font-mono">demo@cimaise.local</code>
            </div>
            <div class="flex items-center gap-2 text-sm">
              <span class="text-amber-700 font-medium w-20">Password:</span>
              <code class="bg-amber-100 px-2 py-0.5 rounded text-amber-900 font-mono">password123</code>
            </div>
          </div>
          <p class="text-xs text-amber-600 mt-2">
            <i class="fas fa-sync-alt mr-1"></i>
            This demo resets automatically every 24 hours
          </p>
        </div>
      </div>
    </div>
    {% endif %}

TWIG;

    // Insert before {% if error %}
    $content = preg_replace(
        '/(\s*{% if error %})/m',
        "$demoBox$1",
        $content,
        1
    );

    if (!str_contains($content, '{# DEMO MODE: Show login credentials #}')) {
        warnMsg("Failed to apply credentials box patch to admin/login.twig");
    }

    file_put_contents($file, $content);
}

function patchHtaccess(string $demoRoot, bool $dryRun): void {
    logMsg("Applying demo patch: public/.htaccess");
    if ($dryRun) return;

    $file = "$demoRoot/public/.htaccess";
    $content = file_get_contents($file);

    // Set RewriteBase for /cimaise/public/ subdirectory
    // Note: Server ignores .htaccess, so access via /cimaise/public/ directly
    $content = preg_replace(
        '/# RewriteBase is auto-detected.*\n\s*# RewriteBase \/your-subdirectory\//s',
        "# Demo runs in /cimaise/public/ subdirectory\n  RewriteBase /cimaise/public/",
        $content
    );

    if (!str_contains($content, 'RewriteBase /cimaise/public/')) {
        warnMsg("Failed to apply RewriteBase patch to .htaccess");
    }

    file_put_contents($file, $content);
}

function patchRootHtaccess(string $demoRoot, bool $dryRun): void {
    logMsg("Creating universal root .htaccess (no hardcoded paths)");
    if ($dryRun) return;

    // Copy the universal htaccess from main project root
    $srcFile = dirname(__DIR__) . '/.htaccess';
    $dstFile = "$demoRoot/.htaccess";

    if (file_exists($srcFile)) {
        copy($srcFile, $dstFile);
        logMsg("  Copied universal .htaccess from main project");
    } else {
        warnMsg("Universal .htaccess not found at $srcFile, creating fallback");
        // Create universal htaccess without hardcoded paths
        $content = <<<'HTACCESS'
# Universal .htaccess for Cimaise CMS
# Works with: subdomain, subdirectory, or root installation
# DocumentRoot can point to this folder (not public/)

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block access to sensitive files
    RewriteRule ^\.env$ - [F,L]
    RewriteRule ^\.htaccess$ - [F,L]
    RewriteRule \.sqlite$ - [F,L]
    RewriteRule \.log$ - [F,L]
    RewriteRule ^composer\.(json|lock)$ - [F,L]
    RewriteRule ^package(-lock)?\.json$ - [F,L]

    # Block access to sensitive directories
    RewriteRule ^vendor/ - [F,L]
    RewriteRule ^storage/ - [F,L]
    RewriteRule ^app/ - [F,L]
    RewriteRule ^database/ - [F,L]
    RewriteRule ^config/ - [F,L]
    RewriteRule ^bin/ - [F,L]

    # If file exists in current directory, serve it
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # If request is for public/ directory, serve directly
    RewriteCond %{REQUEST_URI} ^/public/
    RewriteRule ^public/(.*)$ public/$1 [L]

    # Route everything through index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

# Fallback: If mod_rewrite is not available
<IfModule !mod_rewrite.c>
    DirectoryIndex index.php
    ErrorDocument 404 /index.php
</IfModule>

# Deny access to hidden files (dotfiles)
<FilesMatch "^\.">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# Deny access to sensitive file extensions
<FilesMatch "\.(env|sqlite|log|sql|bak|old|orig|tmp)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "DENY"
    Header set X-XSS-Protection "1; mode=block"
</IfModule>

# Disable directory listing
Options -Indexes

# Set default charset
AddDefaultCharset UTF-8
HTACCESS;
        file_put_contents($dstFile, $content);
    }
}

function createRootIndexPhp(string $demoRoot, bool $dryRun): void {
    logMsg("Creating universal root index.php (auto-detects base path)");
    if ($dryRun) return;

    // Copy the universal router from main project root
    $srcFile = dirname(__DIR__) . '/index.php';
    $dstFile = "$demoRoot/index.php";

    if (file_exists($srcFile)) {
        copy($srcFile, $dstFile);
        logMsg("  Copied universal router from main project");
    } else {
        warnMsg("Universal router not found at $srcFile, creating inline version");
        $content = createUniversalRouterContent();
        file_put_contents($dstFile, $content);
    }
}

function createUniversalRouterContent(): string {
    return <<<'PHP'
<?php
declare(strict_types=1);
/**
 * Universal Router for Cimaise CMS
 *
 * Works with ANY installation type:
 * - Subdomain (e.g., demo.example.com) - basePath = ""
 * - Subdirectory (e.g., example.com/cimaise/) - basePath = "/cimaise"
 * - Root installation (e.g., example.com/) - basePath = ""
 *
 * DocumentRoot can point to this folder (not public/)
 *
 * This file:
 * 1. Auto-detects the base path for subdirectory installations
 * 2. Serves static files from public/ with correct MIME types
 * 3. Routes /media/* through PHP for access control (NSFW/password protection)
 * 4. Forwards all other requests to public/index.php
 */

// Detect base path from SCRIPT_NAME
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = dirname($scriptName);
if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
    $basePath = '';
}

// Get the full request URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$fullPath = parse_url($requestUri, PHP_URL_PATH);
if ($fullPath === '' || $fullPath === false) {
    $fullPath = '/';
}

// Remove base path from request to get the relative path
$path = $fullPath;
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}

// Ensure path starts with /
if (!str_starts_with($path, '/')) {
    $path = '/' . $path;
}

// Remove trailing slash except for root
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

// MIME types for static files
$mimeTypes = [
    'js' => 'application/javascript',
    'mjs' => 'application/javascript',
    'css' => 'text/css',
    'json' => 'application/json',
    'webmanifest' => 'application/manifest+json',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'bmp' => 'image/bmp',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'eot' => 'application/vnd.ms-fontobject',
    'xml' => 'application/xml',
    'txt' => 'text/plain',
    'html' => 'text/html',
    'htm' => 'text/html',
    'pdf' => 'application/pdf',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mp3' => 'audio/mpeg',
    'ogg' => 'audio/ogg',
    'wav' => 'audio/wav',
    'zip' => 'application/zip',
];

// Security: Block access to sensitive files and directories
$blockedPatterns = [
    '/^\\/\\./',
    '/\\.sqlite$/i',
    '/\\.log$/i',
    '/^\\/vendor\\//i',
    '/^\\/storage\\//i',
    '/^\\/app\\//i',
    '/^\\/database\\//i',
    '/^\\/config\\//i',
    '/^\\/bin\\//i',
    '/composer\\.(json|lock)$/i',
    '/package(-lock)?\\.json$/i',
];

foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

// /media/* requests MUST go through PHP for access control
$forcePhpRoutes = [
    '/^\\/media\\//',
    '/^\\/fonts\\/typography\\.css$/',
];

$forcePhp = false;
foreach ($forcePhpRoutes as $pattern) {
    if (preg_match($pattern, $path)) {
        $forcePhp = true;
        break;
    }
}

// Check if this is a request for a static file in public/
if (!$forcePhp) {
    $publicFile = __DIR__ . '/public' . $path;
    $realPath = realpath($publicFile);
    $publicDir = realpath(__DIR__ . '/public');

    if ($realPath !== false && $publicDir !== false && str_starts_with($realPath, $publicDir) && is_file($realPath)) {
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

        if (isset($mimeTypes[$ext])) {
            $mimeType = $mimeTypes[$ext];
            $cacheableTypes = ['js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot'];
            if (in_array($ext, $cacheableTypes)) {
                header('Cache-Control: public, max-age=31536000, immutable');
            }

            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($realPath));
            header('X-Content-Type-Options: nosniff');

            $etag = '"' . md5_file($realPath) . '"';
            header('ETag: ' . $etag);

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                http_response_code(304);
                exit;
            }

            readfile($realPath);
            exit;
        }
    }
}

// Route through PHP application
chdir(__DIR__ . '/public');
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = $basePath . '/index.php';
$_SERVER['PHP_SELF'] = $basePath . '/index.php';

require __DIR__ . '/public/index.php';
PHP;
}

function createDemoTemplateMenu(string $demoRoot, bool $dryRun): void {
    logMsg("Preserving demo-only file: _demo_template_menu.twig");
    if ($dryRun) return;

    $file = "$demoRoot/app/Views/frontend/_demo_template_menu.twig";

    // If file already exists with demo content, skip (preserve manual edits)
    if (file_exists($file) && str_contains(file_get_contents($file), 'Demo Mode')) {
        logMsg("  Template menu already exists, preserving");
        return;
    }

    // Create comprehensive demo menu with login link and detailed descriptions
    $content = <<<'TWIG'
{# Demo Mode: Home Template Switcher Dropdown + Admin Login Link #}
<style nonce="{{ csp_nonce() }}">
#demo-home-templates .demo-dropdown {
    opacity: 0;
    visibility: hidden;
    transform: translateY(4px);
    transition: all 0.2s ease;
}
#demo-home-templates:hover .demo-dropdown,
#demo-home-templates:focus-within .demo-dropdown {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}
#demo-home-templates:hover .demo-chevron,
#demo-home-templates:focus-within .demo-chevron {
    transform: rotate(180deg);
}
.demo-menu-item:hover {
    background-color: #f3f4f6;
}
.dark .demo-menu-item:hover {
    background-color: #404040;
}
</style>
<div class="relative" id="demo-home-templates">
    <button class="flex items-center gap-2 text-sm font-medium hover:text-gray-600 dark:hover:text-gray-300 py-2 transition-colors" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-flask text-amber-500"></i>
        <span>Demo</span>
        <i class="fas fa-chevron-down text-xs transition-transform demo-chevron"></i>
    </button>
    <div class="demo-dropdown absolute left-0 top-full mt-1 w-80 bg-white dark:bg-neutral-800 border border-gray-200 dark:border-neutral-700 shadow-xl rounded-xl z-50">
        <div class="p-3">
            {# Demo Info Header #}
            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/50 rounded-lg p-3 mb-3">
                <div class="flex items-center gap-2 text-amber-800 dark:text-amber-200 font-medium text-sm mb-1">
                    <i class="fas fa-info-circle"></i>
                    Demo Mode Active
                </div>
                <p class="text-xs text-amber-700 dark:text-amber-300/80">
                    This is a live demo of Cimaise CMS. Explore different homepage templates below or access the admin panel to test all features.
                </p>
            </div>

            {# Admin Login Link #}
            <a href="{{ base_path }}/admin/login" class="demo-menu-item flex items-center gap-3 px-3 py-3 text-sm rounded-lg transition-colors bg-gray-900 dark:bg-white text-white dark:text-gray-900 hover:bg-gray-700 dark:hover:bg-gray-200 mb-3">
                <i class="fas fa-sign-in-alt w-5 text-center"></i>
                <div class="flex-1">
                    <div class="font-semibold">Access Admin Panel</div>
                    <div class="text-xs opacity-80">demo@cimaise.local / password123</div>
                </div>
                <i class="fas fa-arrow-right text-xs opacity-60"></i>
            </a>

            {# Template Switcher Section #}
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400 font-semibold mb-2 px-3">Choose a Homepage Template</div>
            <p class="text-xs text-gray-500 dark:text-gray-400 px-3 mb-3">Click any template to preview how your photography portfolio could look:</p>

            <ul class="space-y-1">
                <li>
                    <a href="{{ base_path }}/?template=classic" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-images text-gray-600 dark:text-gray-400 w-5 text-center"></i>
                        <div class="flex-1">
                            <div class="font-medium">Classic</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Traditional portfolio layout with hero section, masonry photo grid, and album carousel. Perfect for professional photographers.</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=modern" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-th-large text-indigo-500 w-5 text-center"></i>
                        <div class="flex-1">
                            <div class="font-medium">Modern</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Minimalist design with fixed sidebar, two-column infinite scroll grid, and Lenis smooth scrolling. Ultra-modern feel.</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=parallax" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-layer-group text-cyan-500 w-5 text-center"></i>
                        <div class="flex-1">
                            <div class="font-medium">Parallax</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Immersive full-screen sections with parallax depth effects. Each scroll reveals new stunning visuals with cinematic transitions.</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=masonry" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-grip-vertical text-pink-500 w-5 text-center"></i>
                        <div class="flex-1">
                            <div class="font-medium">Pure Masonry</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Pinterest-style masonry grid showcasing photos in their native aspect ratios. No cropping, pure visual impact.</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=snap" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-arrows-alt-v text-amber-500 w-5 text-center"></i>
                        <div class="flex-1">
                            <div class="font-medium">Snap Albums</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Full-screen album presentations with scroll-snap navigation. Each album gets a dedicated full-viewport showcase.</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=gallery" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-grip-horizontal text-green-500 w-5 text-center"></i>
                        <div class="flex-1">
                            <div class="font-medium">Gallery Wall</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Horizontal scrolling gallery wall with smooth animation. Like walking through a real photography exhibition.</div>
                        </div>
                    </a>
                </li>
            </ul>

            {# Reset Info #}
            <div class="mt-3 pt-3 border-t border-gray-200 dark:border-neutral-700">
                <p class="text-xs text-gray-400 dark:text-gray-500 px-3 text-center">
                    <i class="fas fa-sync-alt mr-1"></i>
                    Demo resets automatically every 24 hours
                </p>
            </div>
        </div>
    </div>
</div>
TWIG;

    file_put_contents($file, $content);
}

// ============================================================================
// MAIN
// ============================================================================

logMsg("Starting demo sync...");
logMsg("Project root: $projectRoot");
logMsg("Demo root: $demoRoot");
echo "\n";

if (!is_dir($demoRoot)) {
    errorMsg("Demo directory not found: $demoRoot");
    exit(1);
}

// Step 1: Sync directories
logMsg("Syncing directories...");
foreach ($syncDirs as $dir) {
    syncDirectory("$projectRoot/$dir", "$demoRoot/$dir", $dryRun);
}

// Step 2: Sync individual files
logMsg("Syncing files...");
foreach ($syncFiles as $file) {
    syncFile("$projectRoot/$file", "$demoRoot/$file", $dryRun);
}

// Step 3: Sync public assets
syncPublicAssets($projectRoot, $demoRoot, $dryRun);

echo "\n";
logMsg("Applying demo-specific patches...");

// Step 4: Apply demo patches
patchIndexPhp($demoRoot, $dryRun);
patchHtaccess($demoRoot, $dryRun);
patchRootHtaccess($demoRoot, $dryRun);
createRootIndexPhp($demoRoot, $dryRun);
patchAuthController($demoRoot, $dryRun);
patchPageController($demoRoot, $dryRun);
patchAdminLayout($demoRoot, $dryRun);
patchFrontendLayout($demoRoot, $dryRun);
patchLoginTemplate($demoRoot, $dryRun);
createDemoTemplateMenu($demoRoot, $dryRun);

echo "\n";
logMsg("Demo sync complete!");

if ($dryRun) {
    echo "\n";
    warnMsg("DRY RUN - No actual changes were made");
}
