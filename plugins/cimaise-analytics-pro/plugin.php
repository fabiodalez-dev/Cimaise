<?php
/**
 * Plugin Name: Cimaise Analytics Pro
 * Version: 1.0.0
 * Description: Sistema di analytics professionale con tracking avanzato, dashboard interattiva, report personalizzabili, funnel analysis, heatmap, export dati e real-time monitoring per Cimaise
 * Author: Cimaise Team
 */

declare(strict_types=1);

use App\Support\Hooks;
use App\Support\Database;

// Autoload delle classi del plugin
spl_autoload_register(function ($class) {
    if (strpos($class, 'CimaiseAnalyticsPro\\') === 0) {
        $file = __DIR__ . '/src/' . str_replace('CimaiseAnalyticsPro\\', '', $class) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

class CimaiseAnalyticsProPlugin
{
    private ?\CimaiseAnalyticsPro\AnalyticsPro $analytics = null;
    private ?Database $db = null;

    public function __construct()
    {
        // Hook principale: inizializzazione app
        Hooks::addAction('cimaise_init', [$this, 'initialize'], 10, 'cimaise-analytics-pro');

        // Tracking hooks
        Hooks::addAction('user_login_success', [$this, 'trackLogin'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('user_logout', [$this, 'trackLogout'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('album_created', [$this, 'trackAlbumCreated'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('album_updated', [$this, 'trackAlbumUpdated'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('album_deleted', [$this, 'trackAlbumDeleted'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('image_uploaded', [$this, 'trackImageUpload'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('image_deleted', [$this, 'trackImageDeleted'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('category_created', [$this, 'trackCategoryCreated'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('tag_created', [$this, 'trackTagCreated'], 10, 'cimaise-analytics-pro');

        // Frontend tracking
        Hooks::addAction('frontend_page_view', [$this, 'trackPageView'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('frontend_album_view', [$this, 'trackAlbumView'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('image_lightbox_open', [$this, 'trackLightboxOpen'], 10, 'cimaise-analytics-pro');
        Hooks::addAction('image_download', [$this, 'trackImageDownload'], 10, 'cimaise-analytics-pro');

        // Search tracking
        Hooks::addAction('search_performed', [$this, 'trackSearch'], 10, 'cimaise-analytics-pro');

        // Filter: Aggiunge dati extra alle pageviews
        Hooks::addFilter('analytics_pageview_data', [$this, 'enhancePageviewData'], 10, 'cimaise-analytics-pro');

        // Admin UI hooks
        Hooks::addFilter('admin_menu_items', [$this, 'addAdminMenuItem'], 20, 'cimaise-analytics-pro');
        Hooks::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidgets'], 10, 'cimaise-analytics-pro');
    }

    /**
     * Inizializzazione plugin
     */
    public function initialize($db, $pdo = null, $pluginManager = null): void
    {
        if (!$db instanceof Database) {
            return;
        }

        $this->db = $db;
        $this->analytics = new \CimaiseAnalyticsPro\AnalyticsPro($db);
    }

    /**
     * Track login
     */
    public function trackLogin(?int $userId, array $userData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('user_login', [
            'category' => 'authentication',
            'action' => 'login',
            'label' => $userData['email'] ?? 'unknown',
            'user_id' => $userId,
            'metadata' => [
                'role' => $userData['role'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]
        ]);
    }

    /**
     * Track logout
     */
    public function trackLogout(?int $userId): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('user_logout', [
            'category' => 'authentication',
            'action' => 'logout',
            'user_id' => $userId,
        ]);
    }

    /**
     * Track album creation
     */
    public function trackAlbumCreated(int $albumId, array $albumData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('album_created', [
            'category' => 'content',
            'action' => 'create_album',
            'label' => $albumData['title'] ?? "Album #{$albumId}",
            'value' => $albumId,
            'metadata' => [
                'visibility' => $albumData['visibility'] ?? 'public',
                'category_id' => $albumData['category_id'] ?? null,
            ]
        ]);
    }

    /**
     * Track album update
     */
    public function trackAlbumUpdated(int $albumId, array $oldData, array $newData): void
    {
        if (!$this->analytics) {
            return;
        }

        $changes = [];
        foreach ($newData as $key => $value) {
            if (isset($oldData[$key]) && $oldData[$key] !== $value) {
                $changes[$key] = ['from' => $oldData[$key], 'to' => $value];
            }
        }

        $this->analytics->trackEvent('album_updated', [
            'category' => 'content',
            'action' => 'update_album',
            'label' => $newData['title'] ?? "Album #{$albumId}",
            'value' => $albumId,
            'metadata' => [
                'changes' => $changes,
            ]
        ]);
    }

    /**
     * Track album deletion
     */
    public function trackAlbumDeleted(int $albumId, array $albumData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('album_deleted', [
            'category' => 'content',
            'action' => 'delete_album',
            'label' => $albumData['title'] ?? "Album #{$albumId}",
            'value' => $albumId,
        ]);
    }

    /**
     * Track image upload
     */
    public function trackImageUpload(int $imageId, array $imageData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('image_uploaded', [
            'category' => 'media',
            'action' => 'upload_image',
            'label' => $imageData['filename'] ?? "Image #{$imageId}",
            'value' => $imageId,
            'metadata' => [
                'album_id' => $imageData['album_id'] ?? null,
                'file_size' => $imageData['file_size'] ?? null,
                'mime_type' => $imageData['mime_type'] ?? null,
            ]
        ]);
    }

    /**
     * Track image deletion
     */
    public function trackImageDeleted(int $imageId, array $imageData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('image_deleted', [
            'category' => 'media',
            'action' => 'delete_image',
            'label' => $imageData['filename'] ?? "Image #{$imageId}",
            'value' => $imageId,
        ]);
    }

    /**
     * Track category creation
     */
    public function trackCategoryCreated(int $categoryId, array $categoryData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('category_created', [
            'category' => 'taxonomy',
            'action' => 'create_category',
            'label' => $categoryData['name'] ?? "Category #{$categoryId}",
            'value' => $categoryId,
        ]);
    }

    /**
     * Track tag creation
     */
    public function trackTagCreated(int $tagId, array $tagData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('tag_created', [
            'category' => 'taxonomy',
            'action' => 'create_tag',
            'label' => $tagData['name'] ?? "Tag #{$tagId}",
            'value' => $tagId,
        ]);
    }

    /**
     * Track frontend page view
     */
    public function trackPageView(string $path, array $pageData = []): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('page_view', [
            'category' => 'engagement',
            'action' => 'view_page',
            'label' => $path,
            'metadata' => array_merge($pageData, [
                'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ])
        ]);
    }

    /**
     * Track album view (frontend)
     */
    public function trackAlbumView(int $albumId, array $albumData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('album_view', [
            'category' => 'engagement',
            'action' => 'view_album',
            'label' => $albumData['title'] ?? "Album #{$albumId}",
            'value' => $albumId,
            'metadata' => [
                'image_count' => $albumData['image_count'] ?? 0,
            ]
        ]);
    }

    /**
     * Track lightbox image open
     */
    public function trackLightboxOpen(int $imageId, array $imageData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('lightbox_open', [
            'category' => 'engagement',
            'action' => 'open_lightbox',
            'label' => $imageData['filename'] ?? "Image #{$imageId}",
            'value' => $imageId,
        ]);
    }

    /**
     * Track image download
     */
    public function trackImageDownload(int $imageId, array $imageData): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('image_download', [
            'category' => 'engagement',
            'action' => 'download_image',
            'label' => $imageData['filename'] ?? "Image #{$imageId}",
            'value' => $imageId,
            'metadata' => [
                'album_id' => $imageData['album_id'] ?? null,
                'file_size' => $imageData['file_size'] ?? null,
            ]
        ]);
    }

    /**
     * Track search
     */
    public function trackSearch(string $query, array $results): void
    {
        if (!$this->analytics) {
            return;
        }

        $this->analytics->trackEvent('search', [
            'category' => 'engagement',
            'action' => 'search',
            'label' => $query,
            'value' => count($results),
            'metadata' => [
                'result_count' => count($results),
                'query_length' => strlen($query),
            ]
        ]);
    }

    /**
     * Enhance pageview data with extra analytics
     */
    public function enhancePageviewData(array $data): array
    {
        // Add device type detection
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $data['device_type'] = $this->detectDeviceType($userAgent);

        // Add browser detection
        $data['browser'] = $this->detectBrowser($userAgent);

        // Add geographic data (if available from headers)
        if (isset($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $data['country'] = $_SERVER['HTTP_CF_IPCOUNTRY'];
        }

        // Add session duration tracking
        if (isset($_SESSION['session_start'])) {
            $data['session_duration'] = time() - $_SESSION['session_start'];
        }

        // Add referrer category
        $data['referrer_category'] = $this->categorizeReferrer($_SERVER['HTTP_REFERER'] ?? '');

        return $data;
    }

    /**
     * Add admin menu item
     */
    public function addAdminMenuItem(array $menuItems): array
    {
        $menuItems[] = [
            'title' => 'Analytics Pro',
            'url' => '/admin/analytics-pro',
            'icon' => '📊',
            'position' => 45,
            'badge' => 'PRO',
        ];

        return $menuItems;
    }

    /**
     * Add dashboard widgets
     */
    public function addDashboardWidgets(array $widgets): array
    {
        if (!$this->analytics) {
            return $widgets;
        }

        // Get real-time statistics
        $stats = $this->analytics->getRealtimeStats();

        $widgets['analytics_pro'] = [
            'title' => 'Analytics Pro - Real-time',
            'priority' => 5,
            'content' => $this->renderDashboardWidget($stats),
        ];

        return $widgets;
    }

    /**
     * Render dashboard widget
     */
    private function renderDashboardWidget(array $stats): string
    {
        ob_start();
        ?>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center">
                <div class="text-3xl font-bold text-blue-600"><?= $stats['active_users'] ?? 0 ?></div>
                <div class="text-sm text-gray-600">Utenti Attivi</div>
            </div>
            <div class="text-center">
                <div class="text-3xl font-bold text-green-600"><?= $stats['events_today'] ?? 0 ?></div>
                <div class="text-sm text-gray-600">Eventi Oggi</div>
            </div>
            <div class="text-center">
                <div class="text-3xl font-bold text-purple-600"><?= $stats['pageviews_today'] ?? 0 ?></div>
                <div class="text-sm text-gray-600">Pageviews Oggi</div>
            </div>
            <div class="text-center">
                <div class="text-3xl font-bold text-orange-600"><?= number_format($stats['avg_session_duration'] ?? 0) ?>s</div>
                <div class="text-sm text-gray-600">Durata Media</div>
            </div>
        </div>
        <div class="mt-4">
            <a href="/admin/analytics-pro" class="text-sm text-blue-600 hover:text-blue-800">
                Vedi Report Completo →
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Detect device type from user agent
     */
    private function detectDeviceType(string $userAgent): string
    {
        if (preg_match('/mobile|android|iphone|ipad|tablet/i', $userAgent)) {
            if (preg_match('/tablet|ipad/i', $userAgent)) {
                return 'tablet';
            }
            return 'mobile';
        }
        return 'desktop';
    }

    /**
     * Detect browser from user agent
     */
    private function detectBrowser(string $userAgent): string
    {
        if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'Safari') !== false) return 'Safari';
        if (strpos($userAgent, 'Edge') !== false) return 'Edge';
        if (strpos($userAgent, 'Opera') !== false) return 'Opera';
        return 'Unknown';
    }

    /**
     * Categorize referrer
     */
    private function categorizeReferrer(string $referrer): string
    {
        if (empty($referrer)) return 'direct';

        if (preg_match('/google\.|bing\.|yahoo\.|duckduckgo\./i', $referrer)) {
            return 'search_engine';
        }

        if (preg_match('/facebook\.|twitter\.|instagram\.|linkedin\.|pinterest\./i', $referrer)) {
            return 'social_media';
        }

        return 'referral';
    }
}

// Initialize plugin
new CimaiseAnalyticsProPlugin();
