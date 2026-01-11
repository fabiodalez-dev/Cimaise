#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Twig Cache Warmup Script
 * Pre-compiles all Twig templates to cache for instant performance
 * Run this after deployments or when templates change
 *
 * Usage: php scripts/twig-cache-warmup.php
 */

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo "❌ Error: Composer autoloader not found.\n";
    echo "   Please run: composer install\n";
    exit(1);
}
require $autoloadPath;

use Slim\Views\Twig;
use Dotenv\Dotenv;

echo "🔥 Twig Cache Warmup\n";
echo "====================\n\n";

// Bootstrap
$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

// Setup Twig with production settings
$twigCacheDir = $root . '/storage/cache/twig';
if (!is_dir($twigCacheDir)) {
    if (!mkdir($twigCacheDir, 0755, true)) {
        echo "❌ Error: Cannot create cache directory: {$twigCacheDir}\n";
        echo "   Please check permissions on storage/ directory.\n";
        exit(1);
    }
}
if (!is_writable($twigCacheDir)) {
    echo "❌ Error: Cache directory is not writable: {$twigCacheDir}\n";
    echo "   Please fix permissions: chmod 755 {$twigCacheDir}\n";
    exit(1);
}

$twigOptions = [
    'cache' => $twigCacheDir,
    'auto_reload' => false,      // Force no reload
    'strict_variables' => false,
    'optimizations' => -1,       // Maximum optimizations
];

$twig = Twig::create($root . '/app/Views', $twigOptions);
$environment = $twig->getEnvironment();

// Get all template files
$viewsDir = $root . '/app/Views';
$templates = [];
if (!is_dir($viewsDir)) {
    echo "❌ Error: Views directory not found: {$viewsDir}\n";
    exit(1);
}

function findTemplates(string $dir, string $baseDir): array {
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'twig') {
            // Get relative path from views directory
            $relativePath = str_replace($baseDir . '/', '', $file->getPathname());
            $found[] = $relativePath;
        }
    }

    return $found;
}

echo "📁 Scanning for templates...\n";
$templates = findTemplates($viewsDir, $viewsDir);
echo "   Found " . count($templates) . " templates\n\n";

// Compile each template
$compiled = 0;
$failed = 0;
$skipped = 0;

echo "⚙️  Compiling templates...\n";

foreach ($templates as $template) {
    try {
        $templatePath = $viewsDir . '/' . $template;
        if (!file_exists($templatePath)) {
            echo "   ⊘ {$template} (not found on disk)\n";
            $skipped++;
            continue;
        }

        // Compile the template (this writes to cache)
        $environment->load($template);

        echo "   ✓ {$template}\n";
        $compiled++;

    } catch (\Twig\Error\LoaderError $e) {
        echo "   ✗ {$template} - LoaderError: {$e->getMessage()}\n";
        echo "     Trace: " . $e->getTraceAsString() . "\n";
        $failed++;
    } catch (\Throwable $e) {
        echo "   ✗ {$template} - Error: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n📊 Summary\n";
echo "==========\n";
echo "Total templates: " . count($templates) . "\n";
echo "Compiled: {$compiled}\n";
echo "Skipped: {$skipped}\n";
echo "Failed: {$failed}\n";

// Cache directory size
$cacheSize = 0;
try {
    $cacheFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($twigCacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($cacheFiles as $file) {
        if ($file->isFile()) {
            $cacheSize += $file->getSize();
        }
    }
    echo "Cache size: " . round($cacheSize / 1024, 2) . " KB\n";
} catch (\Exception $e) {
    echo "⚠️  Warning: Could not calculate cache size: {$e->getMessage()}\n";
}

if ($failed > 0) {
    echo "\n⚠️  Some templates failed to compile. Please review errors above.\n";
    exit(1);
}

echo "\n✨ Twig cache warmup complete!\n";
echo "All templates are pre-compiled and ready for instant rendering.\n";
exit(0);
