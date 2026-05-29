<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Logger;
use App\Support\PluginManager;
use Dotenv\Dotenv;

// Define core version constant (required by plugins)
if (!defined('CIMAISE_VERSION')) {
    define('CIMAISE_VERSION', '1.0.0');
}

if (!function_exists('envv')) {
    function envv(string $key, $default = null) {
        if (array_key_exists($key, $_ENV)) return $_ENV[$key];
        if (array_key_exists($key, $_SERVER)) return $_SERVER[$key];
        $v = getenv($key);
        return $v !== false ? $v : $default;
    }
}

$root = dirname(__DIR__, 2);

if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(envv('APP_TIMEZONE', 'UTC'));

// Pre-initialize the C locale BEFORE anything in the request lifecycle can
// trigger libintl's lazy locale auto-detection. PEL (the EXIF parser used by
// UploadService/ExifService) calls dgettext() during EXIF reads — on macOS
// Apple Silicon php-fpm that lookup hits CFPreferences via dispatch_apply
// and segfaults the worker mid-upload. Forcing the locale here makes every
// subsequent dgettext() / setlocale() resolve from libc state, never from
// Core Foundation. Harmless on Linux/Docker because C.UTF-8 is the standard
// container default; on systems without C.UTF-8 we fall back to POSIX/C.
if (!getenv('LANG'))   { putenv('LANG=C.UTF-8'); }
if (!getenv('LC_ALL')) { putenv('LC_ALL=C.UTF-8'); }
@setlocale(LC_ALL, 'C.UTF-8', 'C.utf8', 'C', 'POSIX');

// Support both MySQL and SQLite - default to SQLite for installer compatibility
$connection = (string)envv('DB_CONNECTION', 'sqlite');
if ($connection === 'sqlite') {
    $dbPath = (string)envv('DB_DATABASE', dirname(__DIR__, 1) . '/database/database.sqlite');
    
    // If the path is relative, make it absolute relative to project root
    if (!str_starts_with($dbPath, '/')) {
        $dbPath = $root . '/' . $dbPath;
    }
    
    $db = new Database(database: $dbPath, isSqlite: true);
} else {
    $db = new Database(
        host: (string)envv('DB_HOST', '127.0.0.1'),
        port: (int)envv('DB_PORT', 3306),
        database: (string)envv('DB_DATABASE', 'cimaise'),
        username: (string)envv('DB_USERNAME', 'root'),
        password: (string)envv('DB_PASSWORD', ''),
        charset: (string)envv('DB_CHARSET', 'utf8mb4'),
        collation: (string)envv('DB_COLLATION', 'utf8mb4_unicode_ci'),
    );
}

// Initialize Plugin Manager and load plugins
$pluginManager = PluginManager::getInstance();
$pluginManager->setDatabase($db);
$pluginManager->loadPlugins($root . '/plugins');

// Initialize Logger and connect to database (for database channel)
$logger = Logger::getInstance();
$logger->setDatabase($db);

return [
    'db' => $db,
    'logger' => $logger,
];
