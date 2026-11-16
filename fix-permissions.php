<?php
/**
 * Permission Repair Script for Cimaise CMS
 *
 * Run this script once after uploading via FTP/ZIP to fix file permissions.
 * Access: https://your-site.com/fix-permissions.php
 *
 * DELETE THIS FILE AFTER USE FOR SECURITY!
 */

// Security: Only allow execution from browser (not CLI) with auth and origin checks
if (php_sapi_name() === 'cli') {
    die("This script must be run from a web browser.\n");
}

// Token-based auth (works before .env exists)
$expectedToken = '';

// Preferred: .env (if present)
$envPath = __DIR__ . '/.env';
if (is_readable($envPath)) {
    $envContent = file_get_contents($envPath);
    if ($envContent !== false) {
        foreach (preg_split('/\r\n|\r|\n/', $envContent) as $line) {
            if (str_starts_with($line, 'PERMISSIONS_FIX_TOKEN=')) {
                $expectedToken = trim(substr($line, strlen('PERMISSIONS_FIX_TOKEN=')));
                break;
            }
        }
    }
}

// Fallback: token file (create manually before first run)
if ($expectedToken === '') {
    $tokenFileCandidates = [
        __DIR__ . '/storage/tmp/permissions_fix_token.txt',
        __DIR__ . '/storage/permissions_fix_token.txt',
    ];
    foreach ($tokenFileCandidates as $tokenFile) {
        if (is_readable($tokenFile)) {
            $tokenContent = trim((string)file_get_contents($tokenFile));
            if ($tokenContent !== '') {
                $expectedToken = $tokenContent;
                break;
            }
        }
    }
}

if ($expectedToken === '') {
    http_response_code(403);
    die('Permissions token is not configured');
}

$authToken = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $authToken = trim($matches[1]);
    }
}
// The token is never read from the query string: a URL ends up in access
// logs, browser history and Referer headers. A browser sends it through the
// POST form below instead.
if ($authToken === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['token'])) {
    $authToken = trim((string)$_POST['token']);
}

if ($authToken === '') {
    if (isset($_GET['token'])) {
        http_response_code(400);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Fix permissions</title></head><body>'
        . (isset($_GET['token']) ? '<p>The token is no longer accepted in the URL. Enter it below.</p>' : '')
        . '<form method="post" action="fix-permissions.php" autocomplete="off">'
        . '<label>Token <input type="password" name="token" required></label> '
        . '<button type="submit">Fix permissions</button></form></body></html>';
    exit;
}

if (!hash_equals($expectedToken, $authToken)) {
    http_response_code(401);
    die('Access denied');
}

// Origin/Referer check (same-host)
$host = $_SERVER['HTTP_HOST'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$originHost = $origin !== '' ? (parse_url($origin, PHP_URL_HOST) ?? '') : '';
$refererHost = $referer !== '' ? (parse_url($referer, PHP_URL_HOST) ?? '') : '';

// Check origin first, fall back to referer if origin not present
$requestHost = $originHost !== '' ? $originHost : $refererHost;
if ($requestHost !== '' && $requestHost !== $host) {
    http_response_code(403);
    die('Invalid origin');
}

// Set execution time limit (large sites may need more time)
set_time_limit(300);

$root = __DIR__;
$results = [
    'directories' => 0,
    'files' => 0,
    'writable' => 0,
    'errors' => []
];

// Directories that need to be writable (777 or 775)
$writableDirs = [
    'storage',
    'storage/cache',
    'storage/logs',
    'storage/tmp',
    'storage/translations',
    'storage/originals',
    'database',
    'public/media',
];

// Files that need to be writable (666 or 664)
$writableFiles = [
    '.env',
    'database/database.sqlite',
];

/**
 * Recursively fix permissions
 *
 * @param array{directories: int, files: int, writable: int, errors: list<string>} $results
 * @param list<string> $writableDirs
 * @param list<string> $writableFiles
 */
function fixPermissions(string $path, string $root, array &$results, array $writableDirs, array $writableFiles, int $depth = 0, int $maxDepth = 10): void {
    if (!file_exists($path)) {
        return;
    }

    $relativePath = (strpos($path, $root . '/') === 0)
        ? substr($path, strlen($root) + 1)
        : basename($path);

    // Database dumps stay owner-only (0700/0600, set by the updater): this
    // pass would otherwise widen them to 0755/0644 and expose password
    // hashes and secrets to other local users.
    if ($relativePath === 'storage/backups' || strpos($relativePath, 'storage/backups/') === 0) {
        return;
    }

    if (is_dir($path)) {
        if ($depth > $maxDepth) {
            $results['errors'][] = "Max depth exceeded for: $relativePath";
            return;
        }

        // Check if this directory should be writable
        $isWritable = false;
        foreach ($writableDirs as $dir) {
            if ($relativePath === $dir || strpos($relativePath, $dir . '/') === 0) {
                $isWritable = true;
                break;
            }
        }

        $targetPerm = $isWritable ? 0775 : 0755;

        if (chmod($path, $targetPerm)) {
            $results['directories']++;
            if ($isWritable) {
                $results['writable']++;
            }
        } else {
            $error = error_get_last();
            $results['errors'][] = "Failed to chmod directory: $relativePath" . ($error ? " ({$error['message']})" : '');
        }

        if ($relativePath === 'vendor' || strpos($relativePath, 'storage/originals') === 0) {
            return;
        }

        // Process contents
        $items = scandir($path);
        if ($items === false) {
            $error = error_get_last();
            $results['errors'][] = "Failed to scan directory: $relativePath" . ($error ? " ({$error['message']})" : '');
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            fixPermissions($path . '/' . $item, $root, $results, $writableDirs, $writableFiles, $depth + 1, $maxDepth);
        }
    } else {
        // It's a file
        $isWritable = in_array($relativePath, $writableFiles, true);

        // SQLite files in database/ should be writable
        if (strpos($relativePath, 'database/') === 0 && pathinfo($path, PATHINFO_EXTENSION) === 'sqlite') {
            $isWritable = true;
        }

        // Log files should be writable
        if (pathinfo($path, PATHINFO_EXTENSION) === 'log') {
            $isWritable = true;
        }

        $targetPerm = $isWritable ? 0664 : 0644;

        if (chmod($path, $targetPerm)) {
            $results['files']++;
            if ($isWritable) {
                $results['writable']++;
            }
        } else {
            $error = error_get_last();
            $results['errors'][] = "Failed to chmod file: $relativePath" . ($error ? " ({$error['message']})" : '');
        }
    }
}

// Create missing directories
$requiredDirs = [
    'storage/cache',
    'storage/logs',
    'storage/tmp',
    'storage/translations',
    'storage/originals',
    'public/media',
];

foreach ($requiredDirs as $dir) {
    $fullPath = $root . '/' . $dir;
    if (!is_dir($fullPath)) {
        if (mkdir($fullPath, 0775, true)) {
            // Directory will be counted during permission scan
        } else {
            $error = error_get_last();
            $results['errors'][] = "Failed to create directory: $dir" . ($error ? " ({$error['message']})" : '');
        }
    }
}

// Run the permission fix
fixPermissions($root, $root, $results, $writableDirs, $writableFiles);

// Output results
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cimaise - Permission Repair</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        h1 { color: #333; }
        .success { color: #22c55e; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; background: #fef3c7; padding: 15px; border-radius: 8px; margin: 20px 0; }
        ul { background: #f1f5f9; padding: 20px 40px; border-radius: 8px; }
        li { margin: 8px 0; }
        code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>🔧 Permission Repair Complete</h1>

    <ul>
        <li><strong>Directories processed:</strong> <?= $results['directories'] ?></li>
        <li><strong>Files processed:</strong> <?= $results['files'] ?></li>
        <li><strong>Writable items set:</strong> <?= $results['writable'] ?></li>
    </ul>

    <?php if (!empty($results['errors'])): ?>
    <h2 class="error">Errors (<?= count($results['errors']) ?>)</h2>
    <ul>
        <?php foreach ($results['errors'] as $error): ?>
        <li class="error"><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="success">✅ All permissions fixed successfully!</p>
    <?php endif; ?>

    <div class="warning">
        <strong>⚠️ IMPORTANT:</strong> Delete this file (<code>fix-permissions.php</code>) immediately after use for security!
    </div>

    <p><a href="/">← Go to homepage</a></p>
</body>
</html>
