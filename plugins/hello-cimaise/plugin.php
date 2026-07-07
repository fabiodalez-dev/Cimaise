<?php
/**
 * Plugin Name: Hello Cimaise
 * Description: Simple example plugin demonstrating the hooks system
 * Version: 1.0.0
 * Author: Cimaise Team
 * License: MIT
 *
 * This is the REFERENCE example plugin — it is meant to be copied. Every
 * pattern here is deliberately the *safe* one: guard direct access, read the
 * settings you declare (and actually use them), escape everything you emit to
 * HTML, and never feed unsanitised input to a log. Follow these and your
 * plugin inherits the app's security posture.
 */

declare(strict_types=1);

use App\Support\Hooks;

// Prevent direct access: if this file is reached outside the app bootstrap
// (CIMAISE_VERSION is only defined there), stop immediately. Do NOT define the
// constant yourself and continue — that defeats the guard.
if (!defined('CIMAISE_VERSION')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

/**
 * Hello Cimaise Plugin
 *
 * Demonstrates basic plugin functionality:
 * - Adding an admin menu item and a settings tab
 * - Reading the plugin's own settings and acting on them
 * - Hooking into the application lifecycle
 * - Safely emitting HTML and writing logs
 */
class HelloCimaisePlugin
{
    private const PLUGIN_NAME = 'hello-cimaise';
    private const VERSION = '1.0.0';

    public function __construct()
    {
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
    }

    /**
     * Read one of this plugin's settings. Plugins don't get the container
     * injected into filter callbacks, so reach it via the global set during
     * bootstrap — the canonical way for a plugin to read a setting.
     */
    private function setting(string $key, mixed $default = null): mixed
    {
        $container = $GLOBALS['container'] ?? null;
        if (!$container || empty($container['db'])) {
            return $default;
        }
        try {
            return (new \App\Services\SettingsService($container['db']))->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Hook: cimaise_init
     * Called when the application boots. Kept quiet by default — logging on the
     * hot per-request boot path is noise; gate it behind the declared setting.
     */
    public function onAppInit($db, $pluginManager): void
    {
        if ($this->setting('hello_log_level', 'none') !== 'debug') {
            return;
        }
        error_log('Hello Cimaise: application initialized, db=' . ($db ? 'yes' : 'no'));
    }

    /**
     * Hook: admin_menu_items (filter)
     */
    public function addMenuItems(array $menuItems): array
    {
        $menuItems[] = [
            'title' => 'Hello Plugin',
            'url' => '/admin/hello-plugin',
            'icon' => '👋',
            'position' => 999,
        ];

        return $menuItems;
    }

    /**
     * Hook: settings_tabs (filter)
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
                    'description' => 'Show the footer message',
                    'default' => true
                ],
                'hello_message' => [
                    'type' => 'text',
                    'label' => 'Welcome Message',
                    'description' => 'Custom message shown in the footer',
                    'default' => 'Powered by Hello Cimaise Plugin!',
                    'placeholder' => 'Enter your message...'
                ],
                'hello_log_level' => [
                    'type' => 'select',
                    'label' => 'Log Level',
                    'description' => 'How verbose should logging be?',
                    'options' => [
                        'none' => 'None (disable logging)',
                        'debug' => 'Everything (debug)'
                    ],
                    'default' => 'none'
                ]
            ]
        ];

        return $tabs;
    }

    /**
     * Hook: album_after_create (action)
     * Log when a new album is created. The title is user-controlled, so strip
     * CR/LF before logging — otherwise a crafted title could forge extra log
     * lines (log injection).
     */
    public function logAlbumCreation(int $albumId, array $albumData): void
    {
        if ($this->setting('hello_log_level', 'none') === 'none') {
            return;
        }
        $title = str_replace(["\r", "\n"], ' ', (string)($albumData['title'] ?? 'Unknown'));
        error_log("Hello Cimaise: new album created — ID {$albumId}, title: {$title}");
    }

    /**
     * Hook: footer_content (filter)
     * Append a message to the frontend footer. The `footer_content` filter
     * output is rendered as raw HTML (is_safe:html), so ANYTHING interpolated
     * here MUST be escaped — the message is admin-editable free text and would
     * otherwise be a stored-XSS sink.
     */
    public function addFooterMessage(string $html): string
    {
        if (!$this->setting('hello_enabled', true)) {
            return $html;
        }

        $message = (string)$this->setting('hello_message', 'Powered by Hello Cimaise Plugin!');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $customHtml = <<<HTML
        <div class="hello-plugin-footer" style="text-align: center; padding: 10px; color: #666; font-size: 0.9em;">
            <p>👋 {$safeMessage}</p>
        </div>
        HTML;

        return $html . $customHtml;
    }
}

// Initialize plugin
new HelloCimaisePlugin();
