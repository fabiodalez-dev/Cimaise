<?php
declare(strict_types=1);
/**
 * Universal Router for Cimaise CMS
 *
 * Works with ANY installation type:
 * - Subdomain (e.g., photos.example.com) - basePath = ""
 * - Subdirectory (e.g., example.com/portfolio/) - basePath = "/portfolio"
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
// Examples:
//   Subdomain:    SCRIPT_NAME = /index.php           → basePath = ""
//   Subdirectory: SCRIPT_NAME = /portfolio/index.php → basePath = "/portfolio"
//   Root:         SCRIPT_NAME = /index.php           → basePath = ""
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
    // JavaScript
    'js' => 'application/javascript',
    'mjs' => 'application/javascript',
    // CSS
    'css' => 'text/css',
    // JSON
    'json' => 'application/json',
    'webmanifest' => 'application/manifest+json',
    // Images
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'bmp' => 'image/bmp',
    // Fonts
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'eot' => 'application/vnd.ms-fontobject',
    // Documents
    'xml' => 'application/xml',
    'txt' => 'text/plain',
    'html' => 'text/html',
    'htm' => 'text/html',
    'pdf' => 'application/pdf',
    // Video
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    // Audio
    'mp3' => 'audio/mpeg',
    'ogg' => 'audio/ogg',
    'wav' => 'audio/wav',
    // Archives (for downloads)
    'zip' => 'application/zip',
];

// Security: Block access to sensitive files and directories
$blockedPatterns = [
    '/^\/\./',           // Hidden files (.env, .htaccess, etc.)
    '/\.sqlite$/i',      // SQLite databases
    '/\.log$/i',         // Log files
    '/^\/vendor\//i',    // Composer vendor directory
    '/^\/storage\//i',   // Storage directory (logs, cache, etc.) - public/media/storage is different
    '/^\/app\//i',       // Application code
    '/^\/database\//i',  // Database files
    '/^\/config\//i',    // Config files
    '/^\/bin\//i',       // CLI scripts
    '/composer\.(json|lock)$/i', // Composer files
    '/package(-lock)?\.json$/i', // npm files
];

foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

// /media/* requests MUST go through PHP for access control (NSFW/password protection)
// Do NOT serve these as static files even if they exist
$forcePhpRoutes = [
    '/^\/media\//',           // All media requests need session validation
    '/^\/fonts\/typography\.css$/', // Dynamic typography CSS
];

$forcePhp = false;
foreach ($forcePhpRoutes as $pattern) {
    if (preg_match($pattern, $path)) {
        $forcePhp = true;
        break;
    }
}

// Check if this is a request for a static file in public/ (unless forced to PHP)
if (!$forcePhp) {
    $publicFile = __DIR__ . '/public' . $path;

    // Security: Prevent directory traversal
    $realPath = realpath($publicFile);
    $publicDir = realpath(__DIR__ . '/public');

    if ($realPath !== false && $publicDir !== false && str_starts_with($realPath, $publicDir) && is_file($realPath)) {
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

        // Only serve known file types
        if (isset($mimeTypes[$ext])) {
            $mimeType = $mimeTypes[$ext];

            // Set caching headers for static assets
            $cacheableTypes = ['js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot'];
            if (in_array($ext, $cacheableTypes)) {
                header('Cache-Control: public, max-age=31536000, immutable');
            }

            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($realPath));
            header('X-Content-Type-Options: nosniff');

            // ETag support for caching
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

// For all other requests (including /media/*), route through PHP application
// Change working directory to public/ so relative paths work correctly
chdir(__DIR__ . '/public');

// Set SERVER variables that public/index.php expects
// This ensures correct base path detection in the application
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';

// Preserve base path for subdirectory installations
// For subdomain: basePath = "" → SCRIPT_NAME = /index.php
// For subdirectory: basePath = "/portfolio" → SCRIPT_NAME = /portfolio/index.php
$_SERVER['SCRIPT_NAME'] = $basePath . '/index.php';
$_SERVER['PHP_SELF'] = $basePath . '/index.php';

// Include the main application entry point
require __DIR__ . '/public/index.php';
