<?php
/**
 * Permission Repair Script for Cimaise CMS
 *
 * Run this script once after uploading via FTP/ZIP to fix file permissions.
 * Access: https://your-site.com/fix-permissions.php
 *
 * DELETE THIS FILE AFTER USE FOR SECURITY!
 */

// Security: Only allow execution from browser (not CLI) and check referer
if (php_sapi_name() === 'cli') {
    die("This script must be run from a web browser.\n");
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
 */
function fixPermissions($path, $root, &$results, $writableDirs, $writableFiles) {
    if (!file_exists($path)) {
        return;
    }

    $relativePath = str_replace($root . '/', '', $path);

    if (is_dir($path)) {
        // Check if this directory should be writable
        $isWritable = false;
        foreach ($writableDirs as $dir) {
            if ($relativePath === $dir || strpos($relativePath, $dir . '/') === 0) {
                $isWritable = true;
                break;
            }
        }

        $targetPerm = $isWritable ? 0775 : 0755;

        if (@chmod($path, $targetPerm)) {
            $results['directories']++;
            if ($isWritable) {
                $results['writable']++;
            }
        } else {
            $results['errors'][] = "Failed to chmod directory: $relativePath";
        }

        // Process contents
        $items = @scandir($path);
        if ($items) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                fixPermissions($path . '/' . $item, $root, $results, $writableDirs, $writableFiles);
            }
        }
    } else {
        // It's a file
        $isWritable = in_array($relativePath, $writableFiles);

        // SQLite files in database/ should be writable
        if (strpos($relativePath, 'database/') === 0 && pathinfo($path, PATHINFO_EXTENSION) === 'sqlite') {
            $isWritable = true;
        }

        // Log files should be writable
        if (pathinfo($path, PATHINFO_EXTENSION) === 'log') {
            $isWritable = true;
        }

        $targetPerm = $isWritable ? 0664 : 0644;

        if (@chmod($path, $targetPerm)) {
            $results['files']++;
            if ($isWritable) {
                $results['writable']++;
            }
        } else {
            $results['errors'][] = "Failed to chmod file: $relativePath";
        }
    }
}

// Run the permission fix
fixPermissions($root, $root, $results, $writableDirs, $writableFiles);

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
        if (@mkdir($fullPath, 0775, true)) {
            $results['directories']++;
            $results['writable']++;
        } else {
            $results['errors'][] = "Failed to create directory: $dir";
        }
    }
}

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
