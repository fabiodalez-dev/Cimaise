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

try {
    $settingsService = new \App\Services\SettingsService($container['db']);

    // Set default values for maintenance mode settings
    // Only set if not already configured (to preserve existing settings on reinstall)

    $defaults = [
        'maintenance_enabled' => false,
        'maintenance_title' => '',
        'maintenance_message' => 'We are currently working on some improvements. Please check back soon!',
        'maintenance_show_logo' => true,
        'maintenance_show_countdown' => true,
    ];

    foreach ($defaults as $key => $value) {
        // Check if setting already exists
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
