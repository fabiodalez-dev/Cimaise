<?php
/**
 * Maintenance Mode Plugin - Uninstall Hook
 *
 * Cleans up settings when the plugin is uninstalled.
 * Called automatically by PluginManager::uninstallPlugin()
 */

declare(strict_types=1);

// Access to database is available via global $container
global $container;

if (!isset($container['db']) || !$container['db']) {
    return;
}

try {
    $db = $container['db'];
    $pdo = $db->pdo();

    // Remove all maintenance mode settings
    $settingsToRemove = [
        'maintenance_enabled',
        'maintenance_title',
        'maintenance_message',
        'maintenance_show_logo',
        'maintenance_show_countdown',
    ];

    $placeholders = implode(',', array_fill(0, count($settingsToRemove), '?'));
    $stmt = $pdo->prepare("DELETE FROM settings WHERE `key` IN ({$placeholders})");
    $stmt->execute($settingsToRemove);

} catch (\Throwable $e) {
    // Log error but don't fail uninstallation
    \App\Support\Logger::error('Maintenance Mode: Uninstall hook failed', [
        'error' => $e->getMessage()
    ], 'plugin');
}
