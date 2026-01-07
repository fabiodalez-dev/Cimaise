<?php
/**
 * Demo Mode Plugin - Uninstall Script
 *
 * This script is executed when the plugin is uninstalled.
 * It removes the demo user from the database.
 */

declare(strict_types=1);

// Get database from global container
$container = $GLOBALS['container'] ?? null;
if (!$container || !isset($container['db'])) {
    return;
}

$db = $container['db'];
if (!$db instanceof \App\Support\Database) {
    return;
}

try {
    $pdo = $db->pdo();

    // Delete the demo user
    $stmt = $pdo->prepare('DELETE FROM users WHERE email = :email');
    $stmt->execute([':email' => 'demo@cimaise.local']);

    error_log("Demo Mode: Removed demo user on uninstall");
} catch (\Throwable $e) {
    error_log("Demo Mode: Failed to remove demo user on uninstall - " . $e->getMessage());
}
