<?php
/**
 * Plugin Name: Demo Mode
 * Description: Activates demo mode features for showcasing Cimaise CMS
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
 * Demo Mode Plugin
 *
 * Features:
 * - Template switcher dropdown in frontend navigation
 * - Login link in frontend navigation (desktop, mobile, mega-menu)
 * - Demo banner in admin panel with credentials
 * - Login page credentials box
 * - Password change protection for demo user
 * - Demo mode footer indicator
 * - Auto-creates demo user on activation, removes on deactivation/uninstall
 */
class DemoModePlugin
{
    private const PLUGIN_NAME = 'demo-mode';
    private const VERSION = '1.0.0';

    // Demo user credentials
    private const DEMO_EMAIL = 'demo@cimaise.local';
    private const DEMO_PASSWORD = 'password123';

    // Available home templates for switcher
    private const TEMPLATES = [
        'classic' => 'Classic',
        'modern' => 'Modern',
        'parallax' => 'Parallax',
        'masonry' => 'Masonry',
        'snap' => 'Snap',
        'gallery' => 'Gallery',
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
        // Create demo user on app init
        Hooks::addAction('cimaise_init', [$this, 'ensureDemoUserExists'], 10, self::PLUGIN_NAME);

        // Frontend navigation - template switcher dropdown
        Hooks::addAction('frontend_nav_after_about', [$this, 'renderTemplateSwitcher'], 10, self::PLUGIN_NAME);

        // Admin panel - demo banner
        Hooks::addAction('admin_body_start', [$this, 'renderAdminBanner'], 10, self::PLUGIN_NAME);

        // Login page - credentials box
        Hooks::addAction('login_before_form', [$this, 'renderLoginCredentials'], 10, self::PLUGIN_NAME);

        // Password change protection
        Hooks::addFilter('auth_can_change_password', [$this, 'checkCanChangePassword'], 10, self::PLUGIN_NAME);

        // Frontend footer - demo mode indicator
        Hooks::addAction('frontend_footer', [$this, 'renderFooterNotice'], 10, self::PLUGIN_NAME);

        // Also try to create demo user immediately if database is available
        $this->tryCreateDemoUser();
    }

    /**
     * Try to create demo user immediately (fallback if cimaise_init doesn't fire)
     */
    private function tryCreateDemoUser(): void
    {
        $container = $GLOBALS['container'] ?? null;
        if ($container && isset($container['db'])) {
            $this->createDemoUserIfNotExists($container['db']);
        }
    }

    /**
     * Hook: cimaise_init - Ensure demo user exists
     */
    public function ensureDemoUserExists($db, $_pluginManager = null): void
    {
        if ($db instanceof \App\Support\Database) {
            $this->createDemoUserIfNotExists($db);
        }
    }

    /**
     * Create demo user if it doesn't exist
     */
    private function createDemoUserIfNotExists($db): void
    {
        if (!$db instanceof \App\Support\Database) {
            return;
        }

        try {
            $pdo = $db->pdo();

            // Check if demo user already exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
            $stmt->execute([':email' => self::DEMO_EMAIL]);
            $existing = $stmt->fetch();

            if ($existing) {
                // User exists, update password to ensure it's correct
                $hashedPassword = password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = :password WHERE email = :email');
                $stmt->execute([
                    ':password' => $hashedPassword,
                    ':email' => self::DEMO_EMAIL
                ]);
                return;
            }

            // Create demo user
            $hashedPassword = password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID);
            $now = $db->nowExpression();

            $stmt = $pdo->prepare("
                INSERT INTO users (first_name, last_name, email, password_hash, role, is_active, created_at, updated_at)
                VALUES (:first_name, :last_name, :email, :password, 'admin', 1, {$now}, {$now})
            ");
            $stmt->execute([
                ':first_name' => 'Demo',
                ':last_name' => 'User',
                ':email' => self::DEMO_EMAIL,
                ':password' => $hashedPassword,
            ]);

            error_log("Demo Mode: Created demo user " . self::DEMO_EMAIL);
        } catch (\Throwable $e) {
            error_log("Demo Mode: Failed to create demo user - " . $e->getMessage());
        }
    }

    /**
     * Render template switcher dropdown in frontend navigation
     */
    public function renderTemplateSwitcher(array $context): void
    {
        $basePath = $context['base_path'] ?? '';
        $location = $context['location'] ?? 'desktop';
        $cspNonce = $context['csp_nonce'] ?? '';

        // Get current template from URL or default, with validation
        $currentTemplate = isset($_GET['template']) && is_string($_GET['template'])
            ? $_GET['template']
            : 'classic';
        if (!array_key_exists($currentTemplate, self::TEMPLATES)) {
            $currentTemplate = 'classic';
        }

        if ($location === 'desktop') {
            $this->renderDesktopTemplateSwitcher($basePath, $currentTemplate, $cspNonce);
            $this->renderDesktopLoginLink($basePath);
        } elseif ($location === 'mobile') {
            $this->renderMobileTemplateSwitcher($basePath, $currentTemplate);
            $this->renderMobileLoginLink($basePath);
        } elseif ($location === 'mega-menu') {
            $this->renderMegaMenuTemplateSwitcher($basePath, $currentTemplate);
            $this->renderMegaMenuLoginLink($basePath);
        }
    }

    /**
     * Render desktop login link
     */
    private function renderDesktopLoginLink(string $basePath): void
    {
        echo <<<HTML
        <a href="{$basePath}/admin/login" class="text-sm font-medium inline-flex items-center gap-2 hover:text-gray-600 transition-all duration-200 py-2 px-1 rounded-md">
            <i class="fas fa-sign-in-alt text-xs"></i>
            Login
        </a>
HTML;
    }

    /**
     * Render mobile login link
     */
    private function renderMobileLoginLink(string $basePath): void
    {
        echo <<<HTML
        <div class="border-t border-gray-200 pt-2 mt-2">
            <a href="{$basePath}/admin/login" class="block text-sm font-medium text-gray-900 hover:bg-gray-100 rounded-md p-3 transition-colors duration-200">
                <i class="fas fa-sign-in-alt mr-2"></i>
                Admin Login
            </a>
        </div>
HTML;
    }

    /**
     * Render mega menu login link (for modern template)
     */
    private function renderMegaMenuLoginLink(string $basePath): void
    {
        echo <<<HTML
        <a href="{$basePath}/admin/login" class="mega-menu_link">Login</a>
HTML;
    }

    /**
     * Render desktop template switcher
     */
    private function renderDesktopTemplateSwitcher(string $basePath, string $currentTemplate, string $cspNonce = ''): void
    {
        $nonceAttr = $cspNonce ? " nonce=\"{$cspNonce}\"" : '';

        // Build menu items
        $menuItems = '';
        foreach (self::TEMPLATES as $slug => $label) {
            $isActive = $slug === $currentTemplate ? ' bg-gray-100 font-semibold' : '';
            $checkIcon = $slug === $currentTemplate ? '<i class="fas fa-check text-xs text-green-600 ml-auto"></i>' : '';
            $menuItems .= <<<HTML
                        <a href="{$basePath}/?template={$slug}" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors{$isActive}">
                            {$label}{$checkIcon}
                        </a>
HTML;
        }

        echo <<<HTML
        <div class="relative" id="template-switcher-wrapper">
            <button id="template-switcher-toggle" class="text-sm font-medium inline-flex items-center gap-2 hover:text-gray-600 transition-all duration-200 py-2 px-1 rounded-md">
                <i class="fas fa-palette text-xs"></i>
                Templates
                <i class="fas fa-chevron-down text-xs transition-transform duration-200" id="template-switcher-icon"></i>
            </button>
            <div id="template-switcher-menu" class="absolute left-0 top-full mt-2 w-48 bg-white border border-gray-200 shadow-xl rounded-xl z-50 overflow-hidden opacity-0 invisible transform translate-y-2 transition-all duration-300 ease-out">
                <div class="py-2">
                    {$menuItems}
                </div>
            </div>
        </div>
        <script{$nonceAttr}>
        (function(){
            var wrapper = document.getElementById('template-switcher-wrapper');
            var toggle = document.getElementById('template-switcher-toggle');
            var menu = document.getElementById('template-switcher-menu');
            var icon = document.getElementById('template-switcher-icon');
            if (!wrapper || !toggle || !menu) return;

            var isOpen = false;
            var hoverTimeout;

            function openMenu() {
                clearTimeout(hoverTimeout);
                isOpen = true;
                menu.classList.remove('opacity-0', 'invisible', 'translate-y-2');
                menu.classList.add('opacity-100', 'visible', 'translate-y-0');
                if (icon) icon.classList.add('rotate-180');
            }

            function closeMenu() {
                isOpen = false;
                menu.classList.add('opacity-0', 'invisible', 'translate-y-2');
                menu.classList.remove('opacity-100', 'visible', 'translate-y-0');
                if (icon) icon.classList.remove('rotate-180');
            }

            function scheduleClose() {
                clearTimeout(hoverTimeout);
                hoverTimeout = setTimeout(closeMenu, 180);
            }

            function clearClose() {
                clearTimeout(hoverTimeout);
            }

            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                isOpen ? closeMenu() : openMenu();
            });

            wrapper.addEventListener('mouseenter', openMenu);
            wrapper.addEventListener('mouseleave', scheduleClose);
            menu.addEventListener('mouseenter', clearClose);
            menu.addEventListener('mouseleave', scheduleClose);

            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) closeMenu();
            });
        })();
        </script>
HTML;
    }

    /**
     * Render mobile template switcher
     */
    private function renderMobileTemplateSwitcher(string $basePath, string $currentTemplate): void
    {
        echo <<<HTML
        <div class="border-t border-gray-200 pt-2 mt-2">
            <p class="text-xs uppercase tracking-wider text-gray-500 font-semibold mb-2 px-3">Home Templates</p>
            <div class="space-y-1 px-2">
HTML;
        foreach (self::TEMPLATES as $slug => $label) {
            $isActive = $slug === $currentTemplate ? ' bg-gray-100 font-semibold' : '';
            $checkIcon = $slug === $currentTemplate ? '<i class="fas fa-check text-xs text-green-600 ml-2"></i>' : '';
            echo <<<HTML
                <a href="{$basePath}/?template={$slug}" class="block text-sm font-medium text-gray-900 hover:bg-gray-100 rounded-md p-3 transition-colors duration-200{$isActive}">
                    {$label}{$checkIcon}
                </a>
HTML;
        }
        echo <<<HTML
            </div>
        </div>
HTML;
    }

    /**
     * Render mega menu template switcher (for modern template)
     */
    private function renderMegaMenuTemplateSwitcher(string $basePath, string $currentTemplate): void
    {
        foreach (self::TEMPLATES as $slug => $label) {
            $isActive = $slug === $currentTemplate ? ' is-active' : '';
            echo <<<HTML
        <a href="{$basePath}/?template={$slug}" class="mega-menu_link{$isActive}">{$label}</a>
HTML;
        }
    }

    /**
     * Render admin demo banner
     */
    public function renderAdminBanner(array $_context): void
    {
        echo <<<HTML
    <div id="demo-mode-banner" style="background: #111827; color: #fff; padding: 0.5rem 1rem; text-align: center; font-size: 0.75rem; position: fixed; top: 0; left: 0; right: 0; z-index: 9999;">
        <span><strong>Demo Mode</strong> — demo@cimaise.local / password123</span>
    </div>
    <style>
        body:has(#demo-mode-banner) { padding-top: 36px; }
        body:has(#demo-mode-banner) nav.fixed.top-0 { top: 36px !important; }
    </style>
HTML;
    }

    /**
     * Render login page credentials box
     */
    public function renderLoginCredentials(array $_context): void
    {
        $email = self::DEMO_EMAIL;
        $password = self::DEMO_PASSWORD;

        echo <<<HTML
    <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem;">
        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
            <div style="flex-shrink: 0;">
                <i class="fas fa-info-circle" style="color: #6b7280; font-size: 1.125rem;"></i>
            </div>
            <div style="flex: 1;">
                <h3 style="font-size: 0.875rem; font-weight: 600; color: #111827; margin-bottom: 0.5rem;">Demo Credentials</h3>
                <div style="font-size: 0.875rem; color: #374151;">
                    <p style="margin-bottom: 0.25rem;"><strong>Email:</strong> <code style="background: #ffffff; border: 1px solid #e5e7eb; padding: 0.125rem 0.5rem; border-radius: 0.25rem; color: #111827;">{$email}</code></p>
                    <p><strong>Password:</strong> <code style="background: #ffffff; border: 1px solid #e5e7eb; padding: 0.125rem 0.5rem; border-radius: 0.25rem; color: #111827;">{$password}</code></p>
                </div>
                <p style="margin-top: 0.5rem; font-size: 0.75rem; color: #6b7280;">This is a demo instance. All data may be reset periodically.</p>
            </div>
        </div>
    </div>
HTML;
    }

    /**
     * Check if user can change password (filter)
     * Returns false to block password change for demo user
     */
    public function checkCanChangePassword(bool $canChange, int $userId): bool
    {
        // If already blocked by another filter, respect that
        if (!$canChange) {
            return false;
        }

        // Check if this is the demo user
        global $db;
        if (!isset($db)) {
            // Try to get database from global container
            $container = $GLOBALS['container'] ?? null;
            if ($container && isset($container['db'])) {
                $db = $container['db'];
            }
        }

        if (isset($db) && $db instanceof \App\Support\Database) {
            try {
                $stmt = $db->pdo()->prepare('SELECT email FROM users WHERE id = :id');
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch();

                if ($user && $user['email'] === self::DEMO_EMAIL) {
                    // Set flash message and block
                    $_SESSION['flash'][] = [
                        'type' => 'warning',
                        'message' => 'Password changes are disabled in demo mode.'
                    ];
                    return false;
                }
            } catch (\Throwable $e) {
                // Silently fail - allow password change if we can't verify
            }
        }

        return $canChange;
    }

    /**
     * Render footer demo mode notice
     */
    public function renderFooterNotice(array $_context): void
    {
        echo <<<HTML
        <div id="demo-footer" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e5e5; text-align: center;">
            <div style="display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.75rem; color: #737373;">
                <i class="fas fa-flask"></i>
                <span>Demo Mode Active</span>
                <span id="demo-footer-sep" style="color: #d4d4d4;">|</span>
                <a id="demo-footer-link" href="https://github.com/fabiodalez-dev/Cimaise/" target="_blank" rel="noopener noreferrer" style="color: #525252; text-decoration: underline;">
                    <i class="fab fa-github" style="margin-right: 0.25rem;"></i>Get Cimaise
                </a>
            </div>
        </div>
        <style>
            html.dark #demo-footer { border-top-color: #404040 !important; }
            html.dark #demo-footer > div { color: #a3a3a3 !important; }
            html.dark #demo-footer-sep { color: #525252 !important; }
            html.dark #demo-footer-link { color: #a3a3a3 !important; }
            html.dark #demo-footer-link:hover { color: #d4d4d4 !important; }
        </style>
HTML;
    }
}

// Initialize plugin
new DemoModePlugin();
