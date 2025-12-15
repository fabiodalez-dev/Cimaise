<?php
/**
 * Plugin Name: Analytics Logger
 * Description: Advanced analytics logging with custom events and detailed tracking
 * Version: 1.0.0
 * Author: Cimaise Team
 * License: MIT
 */

declare(strict_types=1);

use App\Support\Hooks;

/**
 * Analytics Logger Plugin
 *
 * Extends built-in analytics with:
 * - Custom event tracking
 * - Detailed user journey logging
 * - Performance metrics
 * - Export enhancements
 */
class AnalyticsLoggerPlugin
{
    private const PLUGIN_NAME = 'analytics-logger';
    private const VERSION = '1.0.0';

    private PDO $db;
    private array $sessionData = [];

    public function __construct()
    {
        // Get DB from container (will be passed via hook)
        $this->init();
    }

    public function init(): void
    {
        // Get database connection
        Hooks::addAction('cimaise_init', [$this, 'setDatabase'], 10, self::PLUGIN_NAME);

        // Track additional events
        Hooks::addAction('user_after_login', [$this, 'trackLogin'], 10, self::PLUGIN_NAME);
        Hooks::addAction('album_after_create', [$this, 'trackAlbumCreate'], 10, self::PLUGIN_NAME);
        Hooks::addAction('image_after_upload', [$this, 'trackImageUpload'], 10, self::PLUGIN_NAME);

        // Enhance analytics data
        Hooks::addFilter('analytics_track_pageview', [$this, 'enhancePageview'], 10, self::PLUGIN_NAME);
        Hooks::addFilter('analytics_export_data', [$this, 'addCustomColumns'], 10, self::PLUGIN_NAME);

        // Add dashboard widget
        Hooks::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget'], 10, self::PLUGIN_NAME);

        error_log("Analytics Logger plugin initialized");
    }

    public function setDatabase($db, $pluginManager): void
    {
        $this->db = $db;
        $this->createTables();
    }

    /**
     * Create plugin tables if not exist
     */
    private function createTables(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS plugin_analytics_custom_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id VARCHAR(64),
                event_type VARCHAR(50) NOT NULL,
                event_category VARCHAR(100),
                event_action VARCHAR(100),
                event_label VARCHAR(255),
                event_value INTEGER,
                user_id INTEGER NULL,
                metadata TEXT, -- JSON
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ";

        try {
            $this->db->exec($sql);
            error_log("Analytics Logger: Tables created successfully");
        } catch (PDOException $e) {
            error_log("Analytics Logger: Error creating tables: " . $e->getMessage());
        }

        // Create index
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_custom_events_session ON plugin_analytics_custom_events(session_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_custom_events_type ON plugin_analytics_custom_events(event_type)");
    }

    /**
     * Track user login events
     */
    public function trackLogin(?int $userId, array $userData): void
    {
        $this->logCustomEvent('user_login', [
            'category' => 'authentication',
            'action' => 'login',
            'label' => $userData['email'] ?? 'unknown',
            'user_id' => $userId,
            'metadata' => [
                'role' => $userData['role'] ?? 'user',
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * Track album creation
     */
    public function trackAlbumCreate(int $albumId, array $albumData): void
    {
        $this->logCustomEvent('album_created', [
            'category' => 'content',
            'action' => 'create_album',
            'label' => $albumData['title'] ?? 'Untitled',
            'value' => $albumId,
            'metadata' => [
                'category_id' => $albumData['category_id'] ?? null,
                'published' => $albumData['is_published'] ?? 0
            ]
        ]);
    }

    /**
     * Track image uploads
     */
    public function trackImageUpload(int $imageId, array $imageData, string $filePath): void
    {
        $fileSize = file_exists($filePath) ? filesize($filePath) : 0;

        $this->logCustomEvent('image_uploaded', [
            'category' => 'media',
            'action' => 'upload_image',
            'label' => basename($filePath),
            'value' => $imageId,
            'metadata' => [
                'album_id' => $imageData['album_id'] ?? null,
                'width' => $imageData['width'] ?? 0,
                'height' => $imageData['height'] ?? 0,
                'file_size' => $fileSize,
                'mime' => $imageData['mime'] ?? 'unknown'
            ]
        ]);
    }

    /**
     * Enhance pageview data with additional context
     */
    public function enhancePageview(array $data): array
    {
        // Add referrer analysis
        if (isset($data['referrer_url'])) {
            $data['referrer_type'] = $this->categorizeReferrer($data['referrer_url']);
        }

        // Add device fingerprint (simple hash)
        $data['device_fingerprint'] = $this->generateDeviceFingerprint($data);

        // Add timestamp bucket (for time-of-day analysis)
        $hour = (int)date('H');
        $data['time_bucket'] = match(true) {
            $hour >= 6 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 18 => 'afternoon',
            $hour >= 18 && $hour < 22 => 'evening',
            default => 'night'
        };

        return $data;
    }

    /**
     * Add custom columns to analytics export
     */
    public function addCustomColumns(array $data, string $format, array $filters): array
    {
        // Add custom events to export
        if ($format === 'csv') {
            // Fetch custom events for the same time range
            $customEvents = $this->getCustomEvents($filters);

            if (!empty($customEvents)) {
                $data['custom_events'] = $customEvents;
            }
        }

        return $data;
    }

    /**
     * Add dashboard widget showing custom events summary
     */
    public function addDashboardWidget(array $widgets): array
    {
        $widgets[] = [
            'id' => 'analytics-logger-summary',
            'title' => 'Custom Events (Last 7 Days)',
            'icon' => 'activity',
            'content' => $this->renderDashboardWidget(),
            'position' => 20
        ];

        return $widgets;
    }

    /**
     * Render dashboard widget HTML
     */
    private function renderDashboardWidget(): string
    {
        // Get event counts for last 7 days
        $sql = "
            SELECT event_type, COUNT(*) as count
            FROM plugin_analytics_custom_events
            WHERE created_at >= datetime('now', '-7 days')
            GROUP BY event_type
            ORDER BY count DESC
            LIMIT 10
        ";

        $stmt = $this->db->query($sql);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($events)) {
            return '<p style="color: #666;">No custom events tracked yet.</p>';
        }

        $html = '<ul style="list-style: none; padding: 0;">';
        foreach ($events as $event) {
            $html .= sprintf(
                '<li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                    <strong>%s</strong>: <span style="color: #0066cc;">%d events</span>
                </li>',
                htmlspecialchars($event['event_type']),
                $event['count']
            );
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * Log custom event to database
     */
    private function logCustomEvent(string $eventType, array $data): void
    {
        try {
            $sql = "
                INSERT INTO plugin_analytics_custom_events
                (session_id, event_type, event_category, event_action, event_label, event_value, user_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['session_id'] ?? session_id(),
                $eventType,
                $data['category'] ?? null,
                $data['action'] ?? null,
                $data['label'] ?? null,
                $data['value'] ?? null,
                $data['user_id'] ?? null,
                json_encode($data['metadata'] ?? [])
            ]);

            error_log("Analytics Logger: Event '{$eventType}' logged successfully");
        } catch (PDOException $e) {
            error_log("Analytics Logger: Error logging event: " . $e->getMessage());
        }
    }

    /**
     * Categorize referrer type
     */
    private function categorizeReferrer(string $referrerUrl): string
    {
        if (empty($referrerUrl)) {
            return 'direct';
        }

        $host = parse_url($referrerUrl, PHP_URL_HOST);

        // Social media
        $social = ['facebook.com', 'instagram.com', 'twitter.com', 'linkedin.com', 'pinterest.com'];
        foreach ($social as $site) {
            if (str_contains($host, $site)) {
                return 'social';
            }
        }

        // Search engines
        $search = ['google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com'];
        foreach ($search as $engine) {
            if (str_contains($host, $engine)) {
                return 'search';
            }
        }

        return 'referral';
    }

    /**
     * Generate device fingerprint
     */
    private function generateDeviceFingerprint(array $data): string
    {
        $components = [
            $data['user_agent'] ?? '',
            $data['screen_resolution'] ?? '',
            $data['platform'] ?? ''
        ];

        return substr(md5(implode('|', $components)), 0, 16);
    }

    /**
     * Get custom events for export
     */
    private function getCustomEvents(array $filters): array
    {
        $sql = "SELECT * FROM plugin_analytics_custom_events WHERE 1=1";
        $params = [];

        // Apply date filters if present
        if (isset($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT 1000";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Initialize plugin
new AnalyticsLoggerPlugin();
