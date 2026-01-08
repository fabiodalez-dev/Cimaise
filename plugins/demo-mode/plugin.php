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
    exit('Direct access not permitted');
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

    // Blocked admin endpoints for demo user (security protection)
    private const BLOCKED_ENDPOINTS = [
        '/admin/commands' => 'System commands are disabled in demo mode.',
        '/admin/updates' => 'System updates are disabled in demo mode.',
        '/admin/users/create' => 'Creating users is disabled in demo mode.',
        '/admin/users/store' => 'Creating users is disabled in demo mode.',
        '/admin/users/delete' => 'Deleting users is disabled in demo mode.',
    ];

    /**
     * Initialize plugin hooks
     */
    public function init(): void
    {
        // Security: Block dangerous endpoints for demo user (high priority - runs first)
        Hooks::addAction('cimaise_init', [$this, 'blockRestrictedEndpoints'], 5, self::PLUGIN_NAME);

        // Handle seed endpoint (must run before route matching)
        Hooks::addAction('cimaise_init', [$this, 'handleSeedEndpoint'], 3, self::PLUGIN_NAME);

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

        // Admin settings - seed database button
        Hooks::addAction('admin_settings_after_form', [$this, 'renderSettingsSeedCard'], 10, self::PLUGIN_NAME);

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
     * Hook: cimaise_init (priority 5) - Block restricted endpoints for demo user
     * Only blocks access for the demo user, real admins have full access
     */
    public function blockRestrictedEndpoints($db, $_pluginManager = null): void
    {
        // Only check if user is logged in
        if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_email'])) {
            return;
        }

        // Only restrict the demo user, not real admins
        if ($_SESSION['admin_email'] !== self::DEMO_EMAIL) {
            return;
        }

        // Get current request path (normalize by removing query string and base path)
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';

        // Remove common base path prefixes
        $path = preg_replace('#^(/public|/cimaise)?#', '', $path);

        // Check if path matches any blocked endpoint
        foreach (self::BLOCKED_ENDPOINTS as $blockedPath => $message) {
            if (str_starts_with($path, $blockedPath)) {
                $this->renderBlockedPage($message);
                exit;
            }
        }
    }

    /**
     * Render blocked access page for demo user
     */
    private function renderBlockedPage(string $message): void
    {
        http_response_code(403);
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Restricted - Demo Mode</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f9fafb; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { text-align: center; padding: 2rem; max-width: 500px; }
        .icon { font-size: 4rem; color: #f59e0b; margin-bottom: 1.5rem; }
        h1 { font-size: 1.5rem; color: #111827; margin-bottom: 0.75rem; }
        p { color: #6b7280; margin-bottom: 1.5rem; line-height: 1.6; }
        .back-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: #111827; color: #fff; text-decoration: none; border-radius: 0.5rem; font-size: 0.875rem; transition: background 0.2s; }
        .back-btn:hover { background: #374151; }
        .demo-note { margin-top: 2rem; padding: 1rem; background: #fef3c7; border-radius: 0.5rem; font-size: 0.875rem; color: #92400e; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon"><i class="fas fa-shield-alt"></i></div>
        <h1>Access Restricted</h1>
        <p>{$safeMessage}</p>
        <a href="javascript:history.back()" class="back-btn">
            <i class="fas fa-arrow-left"></i> Go Back
        </a>
        <div class="demo-note">
            <i class="fas fa-info-circle"></i>
            You are logged in as a demo user. Some features are restricted for security reasons.
        </div>
    </div>
</body>
</html>
HTML;
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
            $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :email');
            $stmt->execute([':email' => self::DEMO_EMAIL]);
            $existing = $stmt->fetch();

            if ($existing) {
                // User exists, verify password and update only if needed
                if (!password_verify(self::DEMO_PASSWORD, $existing['password_hash'])) {
                    $hashedPassword = password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID);
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password WHERE email = :email');
                    $stmt->execute([
                        ':password' => $hashedPassword,
                        ':email' => self::DEMO_EMAIL
                    ]);
                }
                return;
            }

            // Create demo user
            $hashedPassword = password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID);
            $nowExpr = $db->nowExpression(); // datetime('now') for SQLite, NOW() for MySQL

            $stmt = $pdo->prepare("
                INSERT INTO users (first_name, last_name, email, password_hash, role, is_active, created_at, updated_at)
                VALUES (:first_name, :last_name, :email, :password, 'admin', 1, {$nowExpr}, {$nowExpr})
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
        $safeBasePath = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <a href="{$safeBasePath}/admin/login" class="text-sm font-medium inline-flex items-center gap-2 hover:text-gray-600 transition-all duration-200 py-2 px-1 rounded-md">
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
        $safeBasePath = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <div class="border-t border-gray-200 pt-2 mt-2">
            <a href="{$safeBasePath}/admin/login" class="block text-sm font-medium text-gray-900 hover:bg-gray-100 rounded-md p-3 transition-colors duration-200">
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
        $safeBasePath = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <a href="{$safeBasePath}/admin/login" class="mega-menu_link">Login</a>
HTML;
    }

    /**
     * Render desktop template switcher
     */
    private function renderDesktopTemplateSwitcher(string $basePath, string $currentTemplate, string $cspNonce = ''): void
    {
        $safeNonce = htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8');
        $nonceAttr = $cspNonce ? " nonce=\"{$safeNonce}\"" : '';
        $safeBasePath = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');

        // Build menu items
        $menuItems = '';
        foreach (self::TEMPLATES as $slug => $label) {
            $safeSlug = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $isActive = $slug === $currentTemplate ? ' bg-gray-100 font-semibold' : '';
            $checkIcon = $slug === $currentTemplate ? '<i class="fas fa-check text-xs text-green-600 ml-auto"></i>' : '';
            $menuItems .= <<<HTML
                        <a href="{$safeBasePath}/?template={$safeSlug}" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors{$isActive}">
                            {$safeLabel}{$checkIcon}
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
        $safeBasePath = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <div class="border-t border-gray-200 pt-2 mt-2">
            <p class="text-xs uppercase tracking-wider text-gray-500 font-semibold mb-2 px-3">Home Templates</p>
            <div class="space-y-1 px-2">
HTML;
        foreach (self::TEMPLATES as $slug => $label) {
            $safeSlug = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $isActive = $slug === $currentTemplate ? ' bg-gray-100 font-semibold' : '';
            $checkIcon = $slug === $currentTemplate ? '<i class="fas fa-check text-xs text-green-600 ml-2"></i>' : '';
            echo <<<HTML
                <a href="{$safeBasePath}/?template={$safeSlug}" class="block text-sm font-medium text-gray-900 hover:bg-gray-100 rounded-md p-3 transition-colors duration-200{$isActive}">
                    {$safeLabel}{$checkIcon}
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
        $safeBasePath = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
        foreach (self::TEMPLATES as $slug => $label) {
            $safeSlug = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $isActive = $slug === $currentTemplate ? ' is-active' : '';
            echo <<<HTML
        <a href="{$safeBasePath}/?template={$safeSlug}" class="mega-menu_link{$isActive}">{$safeLabel}</a>
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
     * Uses fail-closed approach: denies password change if verification cannot be performed
     */
    public function checkCanChangePassword(bool $canChange, int $userId): bool
    {
        // If already blocked by another filter, respect that
        if (!$canChange) {
            return false;
        }

        // Try to get database connection
        $database = null;
        $container = $GLOBALS['container'] ?? null;
        if ($container && isset($container['db'])) {
            $database = $container['db'];
        }

        // Fail-closed: if database is unavailable, deny password change
        if (!$database instanceof \App\Support\Database) {
            $_SESSION['flash'][] = [
                'type' => 'warning',
                'message' => 'Password changes are disabled in demo mode.'
            ];
            return false;
        }

        try {
            $stmt = $database->pdo()->prepare('SELECT email FROM users WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if ($user && $user['email'] === self::DEMO_EMAIL) {
                // Demo user - block password change
                $_SESSION['flash'][] = [
                    'type' => 'warning',
                    'message' => 'Password changes are disabled in demo mode.'
                ];
                return false;
            }

            // Verified: not the demo user, allow password change
            return $canChange;
        } catch (\Throwable $e) {
            // Fail-closed: on any error, deny password change to be safe
            error_log("Demo Mode: Failed to verify user for password change - " . $e->getMessage());
            $_SESSION['flash'][] = [
                'type' => 'warning',
                'message' => 'Password changes are disabled in demo mode.'
            ];
            return false;
        }
    }

    /**
     * Render footer demo mode notice
     */
    public function renderFooterNotice(array $_context): void
    {
        echo <<<HTML
        <div id="demo-footer" style="position: fixed; bottom: 0; left: 0; right: 0; padding: 0.5rem 1rem; text-align: center; background: rgba(255,255,255,0.95); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 40; border-top: 1px solid #e5e5e5;">
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
            html.dark #demo-footer { background: rgba(23,23,23,0.95) !important; border-top-color: #404040 !important; }
            html.dark #demo-footer > div { color: #a3a3a3 !important; }
            html.dark #demo-footer-sep { color: #525252 !important; }
            html.dark #demo-footer-link { color: #a3a3a3 !important; }
            html.dark #demo-footer-link:hover { color: #d4d4d4 !important; }
        </style>
HTML;
    }

    /**
     * Hook: admin_settings_after_form - Render seed database card in settings
     */
    public function renderSettingsSeedCard(array $context): void
    {
        // Only show for logged-in admin users
        if (!isset($_SESSION['admin_id'])) {
            return;
        }

        $basePath = $context['base_path'] ?? '';
        $cspNonce = $context['csp_nonce'] ?? '';

        echo <<<HTML
    <!-- Demo Mode: Seed Database -->
    <div class="card mb-6">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
          <i class="fas fa-database mr-3 text-purple-600"></i>
          Demo Data Seeder
        </h3>
        <p class="text-sm text-gray-600 mb-4">
          Populate the database with comprehensive demo data including categories, albums, images, equipment, and more.
          This will download sample images from Unsplash and generate all necessary variants.
        </p>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
          <div class="flex items-start">
            <i class="fas fa-exclamation-triangle text-amber-600 mr-3 mt-0.5"></i>
            <div class="text-sm text-amber-800">
              <strong>Warning:</strong> This operation will add demo content to your database.
              It may take several minutes to complete as it downloads images and generates variants.
            </div>
          </div>
        </div>

        <div id="demo-seed-section" class="border-t border-gray-200 pt-4">
          <button id="demo-seed-btn" type="button" class="btn-secondary bg-purple-50 border-purple-200 text-purple-700 hover:bg-purple-100">
            <i id="demo-seed-icon" class="fas fa-seedling mr-2"></i>
            <span id="demo-seed-text">Seed Demo Data</span>
          </button>
          <div id="demo-seed-status" class="mt-3 hidden">
            <div class="text-sm text-purple-600 flex items-center">
              <i class="fas fa-spinner fa-spin mr-2"></i>
              <span id="demo-seed-status-text">Seeding database... This may take a few minutes.</span>
            </div>
          </div>
          <div id="demo-seed-result" class="mt-3 hidden"></div>
        </div>
      </div>
    </div>

    <script nonce="{$cspNonce}">
    document.addEventListener('DOMContentLoaded', function() {
      const seedBtn = document.getElementById('demo-seed-btn');
      if (!seedBtn) return;

      seedBtn.addEventListener('click', async function() {
        const confirmMsg = 'This will populate the database with demo content including categories, albums, images, and equipment.\\n\\nImages will be downloaded from Unsplash and variants will be generated.\\n\\nThis may take several minutes. Continue?';
        if (!confirm(confirmMsg)) return;

        const btn = document.getElementById('demo-seed-btn');
        const icon = document.getElementById('demo-seed-icon');
        const text = document.getElementById('demo-seed-text');
        const status = document.getElementById('demo-seed-status');
        const result = document.getElementById('demo-seed-result');

        // Show loading state
        btn.disabled = true;
        icon.className = 'fas fa-spinner fa-spin mr-2';
        text.textContent = 'Seeding...';
        status.classList.remove('hidden');
        result.classList.add('hidden');

        try {
          const response = await fetch('{$basePath}/admin/demo-seed', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-Token': document.querySelector('input[name="csrf"]')?.value || ''
            }
          });

          const data = await response.json();

          status.classList.add('hidden');
          result.classList.remove('hidden');

          if (data.success) {
            result.innerHTML = '<div class="text-sm text-green-600 flex items-start"><i class="fas fa-check-circle mr-2 mt-0.5"></i><div><strong>Success!</strong> ' + (data.message || 'Demo data seeded successfully.') + '</div></div>';
            icon.className = 'fas fa-check mr-2';
            text.textContent = 'Seeding Complete';
          } else {
            result.innerHTML = '<div class="text-sm text-red-600 flex items-start"><i class="fas fa-times-circle mr-2 mt-0.5"></i><div><strong>Error:</strong> ' + (data.error || 'An error occurred.') + '</div></div>';
            icon.className = 'fas fa-seedling mr-2';
            text.textContent = 'Seed Demo Data';
            btn.disabled = false;
          }
        } catch (err) {
          status.classList.add('hidden');
          result.classList.remove('hidden');
          result.innerHTML = '<div class="text-sm text-red-600 flex items-start"><i class="fas fa-times-circle mr-2 mt-0.5"></i><div><strong>Error:</strong> ' + err.message + '</div></div>';
          icon.className = 'fas fa-seedling mr-2';
          text.textContent = 'Seed Demo Data';
          btn.disabled = false;
        }
      });
    });
    </script>
HTML;
    }

    /**
     * Hook: cimaise_init (priority 3) - Handle seed endpoint
     */
    public function handleSeedEndpoint($db, $_pluginManager = null): void
    {
        // Only handle POST requests to /admin/demo-seed
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
        $path = preg_replace('#^(/public|/cimaise)?#', '', $path);

        if ($path !== '/admin/demo-seed') {
            return;
        }

        // Check if user is logged in
        if (!isset($_SESSION['admin_id'])) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Authentication required.'], 401);
            exit;
        }

        // Validate CSRF token
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf'] ?? '';
        if (!hash_equals($sessionToken, $csrfToken)) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
            exit;
        }

        // Execute the seed script
        $this->executeSeedScript();
    }

    /**
     * Execute the seed script and return JSON response
     */
    private function executeSeedScript(): void
    {
        $seedScript = dirname(__DIR__, 2) . '/bin/dev/seed_demo_data.php';

        if (!is_file($seedScript)) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Seed script not found. Please ensure bin/dev/seed_demo_data.php exists.'
            ], 404);
            exit;
        }

        // Increase time limit for long-running seed operation
        set_time_limit(600); // 10 minutes

        // Capture output from the seed script
        ob_start();
        $error = null;

        try {
            // The seed script expects to be run from the CLI, but we can include it
            // We need to buffer output and capture any errors
            $output = [];
            $returnCode = 0;

            // Use exec to run the script in a subprocess for better isolation
            $phpBinary = PHP_BINARY;
            $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($seedScript) . ' 2>&1';
            exec($command, $output, $returnCode);

            $outputText = implode("\n", $output);

            if ($returnCode === 0) {
                // Extract summary from output
                $summary = $this->extractSeedSummary($outputText);
                $this->sendJsonResponse([
                    'success' => true,
                    'message' => $summary ?: 'Demo data seeded successfully. Refresh the page to see the new content.'
                ]);
            } else {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Seed script returned an error (code ' . $returnCode . '). Check server logs for details.',
                    'details' => substr($outputText, -500) // Last 500 chars of output
                ], 500);
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ], 500);
        }

        exit;
    }

    /**
     * Extract a summary from the seed script output
     */
    private function extractSeedSummary(string $output): string
    {
        // Look for the summary section in output
        if (preg_match('/Albums:\s*(\d+)/i', $output, $albumMatch) &&
            preg_match('/Images:\s*(\d+)/i', $output, $imageMatch)) {
            return "Seeded {$albumMatch[1]} albums with {$imageMatch[1]} images. Refresh to see the content.";
        }

        if (str_contains($output, 'SEEDING COMPLETE')) {
            return 'Demo data seeded successfully. Refresh the page to see the new content.';
        }

        return '';
    }

    /**
     * Send a JSON response and exit
     */
    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}

// Initialize plugin
new DemoModePlugin();
