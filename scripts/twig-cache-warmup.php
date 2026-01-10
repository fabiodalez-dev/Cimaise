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

require __DIR__ . '/../vendor/autoload.php';

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
    mkdir($twigCacheDir, 0755, true);
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

function findTemplates(string $dir, string $baseDir): array {
    $found = [];
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path)) {
            $found = array_merge($found, findTemplates($path, $baseDir));
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'twig') {
            // Get relative path from views directory
            $relativePath = str_replace($baseDir . '/', '', $path);
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
        // Check if template is valid
        $source = $environment->getLoader()->getSourceContext($template);

        // Compile the template (this writes to cache)
        $environment->loadTemplate($source);

        echo "   ✓ {$template}\n";
        $compiled++;

    } catch (\Twig\Error\LoaderError $e) {
        // Template not found or not accessible
        echo "   ⊘ {$template} (not accessible)\n";
        $skipped++;
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
$cacheFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($twigCacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($cacheFiles as $file) {
    if ($file->isFile()) {
        $cacheSize += $file->getSize();
    }
}

echo "Cache size: " . round($cacheSize / 1024, 2) . " KB\n";

if ($failed > 0) {
    echo "\n⚠️  Some templates failed to compile. Please review errors above.\n";
    exit(1);
}

echo "\n✨ Twig cache warmup complete!\n";
echo "All templates are pre-compiled and ready for instant rendering.\n";
exit(0);
