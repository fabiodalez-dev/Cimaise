<?php
/**
 * Demo Mode Plugin - Deactivation Hook
 *
 * This file is executed when the plugin is deactivated.
 * It removes the demo user to prevent unauthorized access while the plugin is inactive.
 *
 * The demo user will be re-created automatically when the plugin is activated again.
 */

declare(strict_types=1);

// Get database from global container
$container = $GLOBALS['container'] ?? null;

if (!$container || !isset($container['db'])) {
    error_log('Demo Mode: Cannot deactivate - database not available');
    return;
}

$db = $container['db'];

if (!$db instanceof \App\Support\Database) {
    error_log('Demo Mode: Cannot deactivate - invalid database instance');
    return;
}

try {
    $pdo = $db->pdo();

    // Delete the demo user
    $stmt = $pdo->prepare('DELETE FROM users WHERE email = :email');
    $stmt->execute([':email' => 'demo@cimaise.local']);

    $deletedCount = $stmt->rowCount();
    if ($deletedCount > 0) {
        error_log('Demo Mode: Deactivated - removed demo user demo@cimaise.local');
    } else {
        error_log('Demo Mode: Deactivated - demo user was not present');
    }
} catch (\Throwable $e) {
    error_log('Demo Mode: Deactivation error - ' . $e->getMessage());
}
