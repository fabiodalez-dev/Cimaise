<?php
/**
 * Plugin Name: Hello Cimaise
 * Description: Simple example plugin demonstrating the hooks system
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
 * Hello Cimaise Plugin
 *
 * Demonstrates basic plugin functionality:
 * - Adding admin menu item
 * - Adding settings tab
 * - Hooking into application lifecycle
 * - Logging events
 */
class HelloCimaisePlugin
{
    private const PLUGIN_NAME = 'hello-cimaise';
    private const VERSION = '1.0.0';

    public function __construct()
    {
        // Auto-initialization
        $this->init();
    }

    /**
     * Initialize plugin hooks
     */
    public function init(): void
    {
        // Application lifecycle
        Hooks::addAction('cimaise_init', [$this, 'onAppInit'], 10, self::PLUGIN_NAME);

        // Admin menu
        Hooks::addFilter('admin_menu_items', [$this, 'addMenuItems'], 10, self::PLUGIN_NAME);

        // Settings tab
        Hooks::addFilter('settings_tabs', [$this, 'addSettingsTab'], 10, self::PLUGIN_NAME);

        // Log album creation
        Hooks::addAction('album_after_create', [$this, 'logAlbumCreation'], 10, self::PLUGIN_NAME);

        // Add custom message to frontend footer
        Hooks::addFilter('footer_content', [$this, 'addFooterMessage'], 10, self::PLUGIN_NAME);

        error_log("Hello Cimaise plugin initialized v" . self::VERSION);
    }

    /**
     * Hook: cimaise_init
     * Called when application boots
     */
    public function onAppInit($db, $pluginManager): void
    {
        error_log("Hello Cimaise: Application initialized!");

        // Example: Check database connection
        if ($db) {
            error_log("Hello Cimaise: Database connected successfully");
        }

        // Example: Get plugin stats
        if (is_object($pluginManager) && method_exists($pluginManager, 'getStats')) {
            $stats = $pluginManager->getStats();
            error_log("Hello Cimaise: Total hooks registered: " . $stats['total_hooks']);
        }
    }

    /**
     * Hook: admin_menu_items (filter)
     * Add custom admin menu item
     */
    public function addMenuItems(array $menuItems): array
    {
        $menuItems[] = [
            'title' => 'Hello Plugin',
            'url' => '/admin/hello-plugin',
            'icon' => '👋',
            'position' => 999, // Bottom of menu
        ];

        return $menuItems;
    }

    /**
     * Hook: settings_tabs (filter)
     * Add custom settings tab
     */
    public function addSettingsTab(array $tabs): array
    {
        $tabs['hello'] = [
            'title' => 'Hello Plugin',
            'icon' => 'hand-wave',
            'description' => 'Settings for Hello Cimaise plugin',
            'fields' => [
                'hello_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable Hello Plugin',
                    'description' => 'Turn on/off the Hello plugin features',
                    'default' => true
                ],
                'hello_message' => [
                    'type' => 'text',
                    'label' => 'Welcome Message',
                    'description' => 'Custom message shown in footer',
                    'default' => 'Powered by Hello Cimaise Plugin!',
                    'placeholder' => 'Enter your message...'
                ],
                'hello_log_level' => [
                    'type' => 'select',
                    'label' => 'Log Level',
                    'description' => 'How verbose should logging be?',
                    'options' => [
                        'none' => 'None (disable logging)',
                        'error' => 'Errors only',
                        'info' => 'Info + Errors',
                        'debug' => 'Everything (debug)'
                    ],
                    'default' => 'info'
                ]
            ]
        ];

        return $tabs;
    }

    /**
     * Hook: album_after_create (action)
     * Log when a new album is created
     */
    public function logAlbumCreation(int $albumId, array $albumData): void
    {
        $title = $albumData['title'] ?? 'Unknown';
        $message = "Hello Cimaise: New album created! ID: {$albumId}, Title: {$title}";

        error_log($message);

        // Could also:
        // - Send notification email
        // - Post to Slack/Discord
        // - Update statistics
        // - Trigger other automation
    }

    /**
     * Hook: footer_content (filter)
     * Add custom message to frontend footer
     */
    public function addFooterMessage(string $html): string
    {
        $message = "Powered by Hello Cimaise Plugin v" . self::VERSION;

        $customHtml = <<<HTML
        <div class="hello-plugin-footer" style="text-align: center; padding: 10px; color: #666; font-size: 0.9em;">
            <p>👋 {$message}</p>
        </div>
        HTML;

        return $html . $customHtml;
    }
}

// Initialize plugin
new HelloCimaisePlugin();
