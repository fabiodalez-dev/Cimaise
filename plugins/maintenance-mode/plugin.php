<?php
/**
 * Plugin Name: Maintenance Mode
 * Description: Put your site under construction with a beautiful maintenance page. Only admins can access the site.
 * Version: 1.0.0
 * Author: Cimaise Team
 * License: MIT
 */

declare(strict_types=1);

use App\Support\Hooks;

// Prevent direct access
if (!defined('CIMAISE_VERSION')) {
    define('CIMAISE_VERSION', '1.0.0');
}

/**
 * Maintenance Mode Plugin
 *
 * Features:
 * - Block all frontend access when enabled
 * - Allow admin login page access
 * - Show beautiful maintenance page
 * - Add noindex meta to all pages during maintenance
 * - Use site logo and name for branding
 */
class MaintenanceModePlugin
{
    private const PLUGIN_NAME = 'maintenance-mode';
    private const VERSION = '1.0.0';

    /**
     * Settings keys used by this plugin (dot notation for consistency)
     * Shared constant to avoid divergence between install/uninstall/config
     */
    public const SETTINGS_KEYS = [
        'maintenance.enabled',
        'maintenance.title',
        'maintenance.message',
        'maintenance.show_logo',
        'maintenance.show_countdown',
    ];

    /**
     * Default values for settings
     */
    public const SETTINGS_DEFAULTS = [
        'maintenance.enabled' => false,
        'maintenance.title' => '',
        'maintenance.message' => 'We are currently working on some improvements. Please check back soon!',
        'maintenance.show_logo' => true,
        'maintenance.show_countdown' => true,
    ];

    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize plugin hooks
     */
    public function init(): void
    {
        // Add settings tab
        Hooks::addFilter('settings_tabs', [$this, 'addSettingsTab'], 10, self::PLUGIN_NAME);

        // Filter robots meta tag when maintenance mode is on
        Hooks::addFilter('robots_meta', [$this, 'filterRobotsMeta'], 10, self::PLUGIN_NAME);

        // Add noindex header when maintenance mode is on
        Hooks::addAction('cimaise_init', [$this, 'addNoindexHeader'], 5, self::PLUGIN_NAME);
    }

    /**
     * Hook: settings_tabs (filter)
     * Add maintenance mode settings tab
     */
    public function addSettingsTab(array $tabs): array
    {
        $tabs['maintenance'] = [
            'title' => 'Maintenance Mode',
            'icon' => 'tools',
            'description' => 'Control site access during maintenance',
            'fields' => [
                'maintenance_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable Maintenance Mode',
                    'description' => 'When enabled, only logged-in admins can access the site. Visitors see a maintenance page.',
                    'default' => false
                ],
                'maintenance_title' => [
                    'type' => 'text',
                    'label' => 'Maintenance Page Title',
                    'description' => 'Title shown on the maintenance page (uses site name if empty)',
                    'default' => '',
                    'placeholder' => 'Site Under Construction'
                ],
                'maintenance_message' => [
                    'type' => 'textarea',
                    'label' => 'Maintenance Message',
                    'description' => 'Message shown to visitors on the maintenance page',
                    'default' => 'We are currently working on some improvements. Please check back soon!',
                    'placeholder' => 'We will be back shortly...'
                ],
                'maintenance_show_logo' => [
                    'type' => 'checkbox',
                    'label' => 'Show Site Logo',
                    'description' => 'Display your site logo on the maintenance page',
                    'default' => true
                ],
                'maintenance_show_countdown' => [
                    'type' => 'checkbox',
                    'label' => 'Show Progress Animation',
                    'description' => 'Show a subtle loading animation to indicate work in progress',
                    'default' => true
                ]
            ]
        ];

        return $tabs;
    }

    /**
     * Hook: robots_meta (filter)
     * Force noindex,nofollow when maintenance mode is enabled
     */
    public function filterRobotsMeta(string $robots): string
    {
        if ($this->isMaintenanceEnabled()) {
            return 'noindex,nofollow';
        }
        return $robots;
    }

    /**
     * Hook: cimaise_init (action)
     * Add X-Robots-Tag header when maintenance is enabled
     */
    public function addNoindexHeader($db, $pluginManager): void
    {
        if ($this->isMaintenanceEnabled()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }
    }

    /**
     * Check if maintenance mode is enabled via settings
     * Note: Uses global $container as hooks don't inject dependencies
     */
    private function isMaintenanceEnabled(): bool
    {
        global $container;
        if (!isset($container['db']) || !$container['db']) {
            return false;
        }

        try {
            $settingsService = new \App\Services\SettingsService($container['db']);
            return (bool)$settingsService->get('maintenance.enabled', false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Static method to check if current request should show maintenance page
     * Called from index.php before routing
     */
    public static function shouldShowMaintenancePage(\App\Support\Database $db): bool
    {
        try {
            $settingsService = new \App\Services\SettingsService($db);
            $enabled = (bool)$settingsService->get('maintenance.enabled', false);

            if (!$enabled) {
                return false;
            }

            // Check if user is logged in as admin
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
                return false; // Admins can access the site
            }

            // Check current path - allow login and admin login
            $path = $_SERVER['REQUEST_URI'] ?? '/';
            $path = parse_url($path, PHP_URL_PATH) ?? '/';

            // Calculate base path to normalize the request path
            // (handles subdirectory installations like /photos/admin/login)
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $scriptDir = dirname($scriptName);
            $isBuiltInServer = php_sapi_name() === 'cli-server';
            $basePath = $isBuiltInServer ? '' : ($scriptDir === '/' ? '' : $scriptDir);
            if (str_ends_with($basePath, '/public')) {
                $basePath = substr($basePath, 0, -7);
            }

            // Normalize path by removing base path prefix
            $normalizedPath = $path;
            if ($basePath !== '' && str_starts_with($path, $basePath)) {
                $normalizedPath = substr($path, strlen($basePath)) ?: '/';
            }

            // Allow these paths even in maintenance mode
            $allowedPaths = [
                '/login',
                '/admin/login',
                '/admin-login',
                '/admin/logout',
            ];

            foreach ($allowedPaths as $allowed) {
                if ($normalizedPath === $allowed) {
                    return false;
                }
            }

            // Allow static assets
            if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $path)) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Static method to get maintenance page configuration
     */
    public static function getMaintenanceConfig(\App\Support\Database $db): array
    {
        try {
            $settingsService = new \App\Services\SettingsService($db);

            $customTitle = trim((string)$settingsService->get('maintenance.title', self::SETTINGS_DEFAULTS['maintenance.title']));
            $siteTitle = $settingsService->get('site.title', 'Cimaise');
            $siteLanguage = $settingsService->get('site.language', 'en');

            return [
                'title' => $customTitle ?: $siteTitle,
                'has_custom_title' => $customTitle !== '',
                'message' => $settingsService->get('maintenance.message', self::SETTINGS_DEFAULTS['maintenance.message']),
                'show_logo' => (bool)$settingsService->get('maintenance.show_logo', self::SETTINGS_DEFAULTS['maintenance.show_logo']),
                'show_countdown' => (bool)$settingsService->get('maintenance.show_countdown', self::SETTINGS_DEFAULTS['maintenance.show_countdown']),
                'site_title' => $siteTitle,
                'site_logo' => $settingsService->get('site.logo', null),
                'admin_login_text' => $siteLanguage === 'it' ? 'Accesso Admin' : 'Admin Login',
            ];
        } catch (\Throwable $e) {
            return [
                'title' => 'Site Under Construction',
                'has_custom_title' => false,
                'message' => self::SETTINGS_DEFAULTS['maintenance.message'],
                'show_logo' => false,
                'show_countdown' => true,
                'site_title' => 'Cimaise',
                'site_logo' => null,
                'admin_login_text' => 'Admin Login',
            ];
        }
    }
}

// Initialize plugin
new MaintenanceModePlugin();
