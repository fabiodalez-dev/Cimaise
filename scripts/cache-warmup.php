#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cache Warmup Script
 * Preloads critical data into QueryCache for instant performance
 * Run this after deployments or cache clears
 *
 * Usage: php scripts/cache-warmup.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\QueryCache;
use App\Support\Database;
use Dotenv\Dotenv;

// Bootstrap
$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// Database connection
$connection = $_ENV['DB_CONNECTION'] ?? 'sqlite';
if ($connection === 'sqlite') {
    $dbPath = $_ENV['DB_DATABASE'] ?? $root . '/database/database.sqlite';
    if (!str_starts_with($dbPath, '/')) {
        $dbPath = $root . '/' . $dbPath;
    }
    $db = new Database(database: $dbPath, isSqlite: true);
} else {
    $db = new Database(
        host: $_ENV['DB_HOST'] ?? '127.0.0.1',
        port: (int)($_ENV['DB_PORT'] ?? 3306),
        database: $_ENV['DB_DATABASE'] ?? 'cimaise',
        username: $_ENV['DB_USERNAME'] ?? 'root',
        password: $_ENV['DB_PASSWORD'] ?? ''
    );
}

$cache = QueryCache::getInstance();

echo "🔥 Cimaise Cache Warmup\n";
echo "=======================\n\n";

// 1. Cache all settings
echo "Warming up settings cache...\n";
$settings = $cache->remember('settings:all', function() use ($db) {
    $stmt = $db->query('SELECT `key`, `value` FROM settings');
    return $stmt->fetchAll();
}, 3600); // Cache for 1 hour
echo "  ✓ Cached " . count($settings) . " settings\n";

// 2. Cache published albums count
echo "Warming up albums cache...\n";
$albumsCount = $cache->remember('albums:published:count', function() use ($db) {
    $stmt = $db->query('SELECT COUNT(*) FROM albums WHERE is_published = 1');
    return $stmt->fetchColumn();
}, 600); // Cache for 10 minutes
echo "  ✓ Cached albums count: {$albumsCount}\n";

// 3. Cache categories
echo "Warming up categories cache...\n";
$categories = $cache->remember('categories:all', function() use ($db) {
    $stmt = $db->query('SELECT * FROM categories ORDER BY sort_order ASC');
    return $stmt->fetchAll();
}, 1800); // Cache for 30 minutes
echo "  ✓ Cached " . count($categories) . " categories\n";

// 4. Cache active templates
echo "Warming up templates cache...\n";
$templates = $cache->remember('templates:active', function() use ($db) {
    $stmt = $db->query('SELECT * FROM templates WHERE show_in_switcher = 1');
    return $stmt->fetchAll();
}, 3600); // Cache for 1 hour
echo "  ✓ Cached " . count($templates) . " templates\n";

// 5. Cache analytics settings (if table exists)
echo "Warming up analytics settings...\n";
try {
    $analyticsSettings = $cache->remember('analytics:settings', function() use ($db) {
        $stmt = $db->query('SELECT * FROM analytics_settings');
        return $stmt->fetchAll();
    }, 3600);
    echo "  ✓ Cached " . count($analyticsSettings) . " analytics settings\n";
} catch (\Throwable) {
    echo "  ⊘ Skipped (table not found)\n";
}

// Stats
echo "\n📊 Cache Statistics\n";
echo "===================\n";
$stats = $cache->getStats();
echo "Backend: " . $stats['backend'] . "\n";
echo "Entries: " . $stats['entries'] . "\n";
if ($stats['backend'] === 'APCu') {
    echo "Hits: " . $stats['hits'] . "\n";
    echo "Misses: " . $stats['misses'] . "\n";
    echo "Memory: " . round($stats['memory_used'] / 1024 / 1024, 2) . " MB\n";
}

echo "\n✨ Cache warmup complete!\n";
