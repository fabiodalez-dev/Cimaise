<?php
/**
 * Maintenance Mode Plugin - Install Hook
 *
 * Sets up default settings when the plugin is installed.
 * Called automatically by PluginManager::installPlugin()
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
    $settingsService = new \App\Services\SettingsService($container['db']);

    // Set default values using centralized constants
    // Only set if not already configured (to preserve existing settings on reinstall)
    foreach (MaintenanceModePlugin::SETTINGS_DEFAULTS as $key => $value) {
        $existing = $settingsService->get($key, null);
        if ($existing === null) {
            $settingsService->set($key, $value);
        }
    }

} catch (\Throwable $e) {
    // Log error but don't fail installation
    \App\Support\Logger::error('Maintenance Mode: Install hook failed', [
        'error' => $e->getMessage()
    ], 'plugin');
}
