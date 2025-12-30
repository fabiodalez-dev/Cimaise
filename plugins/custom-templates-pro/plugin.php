<?php
/**
 * Plugin Name: Custom Templates Pro
 * Description: Carica template personalizzati per gallerie, album e homepage con guide complete per LLM
 * Version: 1.0.0
 * Author: Cimaise Team
 * Requires: 1.0.0
 * License: MIT
 */

declare(strict_types=1);

use App\Support\Hooks;
use App\Support\Database;
use CustomTemplatesPro\Services\TemplateIntegrationService;
use CustomTemplatesPro\Controllers\CustomTemplatesController;

// Prevent direct access
if (!defined('CIMAISE_VERSION')) {
    exit('Direct access not allowed');
}

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'CustomTemplatesPro\\';
    $base_dir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Custom Templates Pro Plugin
 */
class CustomTemplatesProPlugin
{
    private const PLUGIN_NAME = 'custom-templates-pro';
    private const VERSION = '1.0.0';

    private ?Database $db = null;
    private ?TemplateIntegrationService $integrationService = null;

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

        // Sidebar menu
        Hooks::addAction('admin_sidebar_navigation', [$this, 'addSidebarMenu'], 10, self::PLUGIN_NAME);

        // Template hooks - Gallerie
        Hooks::addFilter('available_gallery_templates', [$this, 'addCustomGalleryTemplates'], 10, self::PLUGIN_NAME);

        // Template hooks - Homepage
        Hooks::addFilter('available_home_templates', [$this, 'addCustomHomeTemplates'], 10, self::PLUGIN_NAME);

        // Template hooks - Album Page
        Hooks::addFilter('available_album_page_templates', [$this, 'addCustomAlbumPageTemplates'], 10, self::PLUGIN_NAME);

        // Twig paths
        Hooks::addFilter('twig_loader_paths', [$this, 'addTwigPaths'], 10, self::PLUGIN_NAME);

        // Frontend assets
        Hooks::addAction('frontend_head', [$this, 'addFrontendAssets'], 10, self::PLUGIN_NAME);

        // Template rendering
        Hooks::addFilter('gallery_template_path', [$this, 'resolveGalleryTemplatePath'], 10, self::PLUGIN_NAME);

        error_log("Custom Templates Pro plugin initialized v" . self::VERSION);
    }

    /**
     * Hook: cimaise_init
     */
    public function onAppInit($db, $pluginManager): void
    {
        $this->db = $db;
        $this->integrationService = new TemplateIntegrationService($db);

        error_log("Custom Templates Pro: Integration service initialized");
    }

    /**
     * Hook: admin_sidebar_navigation
     * Aggiunge voce al menu sidebar
     */
    public function addSidebarMenu(array $context): void
    {
        $basePath = $context['base_path'] ?? '';
        echo <<<HTML
            <a href="{$basePath}/admin/custom-templates" class="sidebar-link" data-spa-link>
                <i class="fas fa-palette"></i>Custom Templates
            </a>
HTML;
    }

    /**
     * Hook: available_gallery_templates
     * Aggiunge template gallerie custom
     */
    public function addCustomGalleryTemplates(array $templates): array
    {
        if (!$this->integrationService) {
            return $templates;
        }

        $customTemplates = $this->integrationService->getGalleryTemplatesForCore();
        return array_merge($templates, $customTemplates);
    }

    /**
     * Hook: available_home_templates
     * Aggiunge template homepage custom
     */
    public function addCustomHomeTemplates(array $templates): array
    {
        if (!$this->integrationService) {
            return $templates;
        }

        $customTemplates = $this->integrationService->getHomepageTemplatesForCore();
        return array_merge($templates, $customTemplates);
    }

    /**
     * Hook: available_album_page_templates
     * Aggiunge template pagina album custom
     */
    public function addCustomAlbumPageTemplates(array $templates): array
    {
        if (!$this->integrationService) {
            return $templates;
        }

        $customTemplates = $this->integrationService->getAlbumPageTemplatesForCore();
        return array_merge($templates, $customTemplates);
    }

    /**
     * Hook: twig_loader_paths
     * Aggiunge path per template custom
     */
    public function addTwigPaths(array $paths): array
    {
        if (!$this->integrationService) {
            return $paths;
        }

        $customPaths = $this->integrationService->getTwigPaths();
        return array_merge($paths, $customPaths);
    }

    /**
     * Hook: frontend_head
     * Aggiunge assets CSS/JS dei template custom
     */
    public function addFrontendAssets(): void
    {
        // Questo hook verrà chiamato nel template
        // Gli asset specifici vengono caricati per ogni template
    }

    /**
     * Hook: gallery_template_path
     * Risolve il path del template galleria custom
     */
    public function resolveGalleryTemplatePath(string $path, int $templateId): string
    {
        if (!$this->integrationService) {
            return $path;
        }

        if ($this->integrationService->isCustomTemplate($templateId)) {
            $customPath = $this->integrationService->getGalleryTemplatePath($templateId);
            return $customPath ?? $path;
        }

        return $path;
    }

    /**
     * Registra le rotte del plugin
     * Questa funzione viene chiamata manualmente dal file routes.php
     */
    public static function registerRoutes($app, $db, $view): void
    {
        $controller = new CustomTemplatesController($db, $view);

        // Dashboard
        $app->get('/admin/custom-templates', [$controller, 'dashboard']);

        // Lista templates
        $app->get('/admin/custom-templates/list', [$controller, 'list']);

        // Upload
        $app->get('/admin/custom-templates/upload', [$controller, 'uploadForm']);
        $app->post('/admin/custom-templates/upload', [$controller, 'upload']);

        // Toggle/Delete
        $app->post('/admin/custom-templates/{id}/toggle', [$controller, 'toggle']);
        $app->post('/admin/custom-templates/{id}/delete', [$controller, 'delete']);

        // Guide
        $app->get('/admin/custom-templates/guides', [$controller, 'guides']);
        $app->get('/admin/custom-templates/guides/{type}/download', [$controller, 'downloadGuide']);

        error_log("Custom Templates Pro: Routes registered");
    }
}

// Initialize plugin
$customTemplatesPlugin = new CustomTemplatesProPlugin();

// Register Twig namespace and extension for plugin templates
if (isset($GLOBALS['twig'])) {
    try {
        $loader = $GLOBALS['twig']->getLoader();
        if (method_exists($loader, 'addPath')) {
            $loader->addPath(__DIR__ . '/templates', 'custom-templates-pro');
        }

        // Register plugin translation extension
        require_once __DIR__ . '/Extensions/PluginTranslationTwigExtension.php';
        require_once __DIR__ . '/Services/PluginTranslationService.php';

        $pluginTranslator = new \CustomTemplatesPro\Services\PluginTranslationService();

        // Try to get admin language from settings
        if (isset($GLOBALS['container']['db']) && $GLOBALS['container']['db'] !== null) {
            try {
                $settings = new \App\Services\SettingsService($GLOBALS['container']['db']);
                $adminLang = $settings->get('admin.language', 'en');
                $pluginTranslator->setLanguage($adminLang);
            } catch (\Throwable $e) {
                // Fallback to English
            }
        }

        $GLOBALS['twig']->getEnvironment()->addExtension(
            new \CustomTemplatesPro\Extensions\PluginTranslationTwigExtension($pluginTranslator)
        );
    } catch (\Exception $e) {
        error_log("Custom Templates Pro: Could not register Twig extension: " . $e->getMessage());
    }
}
