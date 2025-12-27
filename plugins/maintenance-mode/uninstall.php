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

// Load plugin class to access constants
require_once __DIR__ . '/plugin.php';

try {
    $db = $container['db'];
    $pdo = $db->pdo();

    // Remove all maintenance mode settings using centralized keys
    $settingsToRemove = MaintenanceModePlugin::SETTINGS_KEYS;

    $placeholders = implode(',', array_fill(0, count($settingsToRemove), '?'));
    $stmt = $pdo->prepare("DELETE FROM settings WHERE `key` IN ({$placeholders})");
    $stmt->execute($settingsToRemove);

} catch (\Throwable $e) {
    // Log error but don't fail uninstallation
    \App\Support\Logger::error('Maintenance Mode: Uninstall hook failed', [
        'error' => $e->getMessage()
    ], 'plugin');
}
