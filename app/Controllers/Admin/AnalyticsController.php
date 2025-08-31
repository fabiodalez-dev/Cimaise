<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AnalyticsService;
use App\Support\Database;
use PDO;

class AnalyticsController
{
    private PDO $db;
    private Twig $twig;
    private AnalyticsService $analytics;

    public function __construct(Database $database, Twig $twig)
    {
        $this->db = $database->pdo();
        $this->twig = $twig;
        $this->analytics = new AnalyticsService($this->db);
    }

    /**
     * Analytics dashboard
     */
    public function index(Request $request, Response $response): Response
    {
        $dashboardStats = $this->analytics->getDashboardStats();
        
        // Get date range from query params
        $params = $request->getQueryParams();
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        
        $chartsData = $this->analytics->getChartsData($startDate, $endDate);

        return $this->twig->render($response, 'admin/analytics/index.twig', [
            'stats' => $dashboardStats,
            'charts_data' => $chartsData,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'analytics_enabled' => $this->analytics->isEnabled()
        ]);
    }

    /**
     * Real-time analytics
     */
    public function realtime(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/analytics/realtime.twig', [
            'analytics_enabled' => $this->analytics->isEnabled()
        ]);
    }

    /**
     * Analytics settings
     */
    public function settings(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'POST') {
            return $this->saveSettings($request, $response);
        }

        // Get current settings - handle case where analytics tables don't exist
        $settings = [];
        try {
            $stmt = $this->db->prepare('SELECT setting_key, setting_value, description FROM analytics_settings ORDER BY setting_key');
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Analytics tables don't exist - show message about running migration
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash'][] = [
                'type' => 'warning', 
                'message' => 'Analytics tables not found. Please run: php bin/console db:migrate to set up analytics functionality.'
            ];
        }

        return $this->twig->render($response, 'admin/analytics/settings.twig', [
            'settings' => $settings
        ]);
    }

    /**
     * Save analytics settings
     */
    private function saveSettings(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // Define allowed settings
        $allowedSettings = [
            'analytics_enabled',
            'ip_anonymization',
            'data_retention_days',
            'real_time_enabled',
            'geolocation_enabled',
            'bot_detection_enabled',
            'session_timeout_minutes',
            'export_enabled'
        ];

        try {
            $this->db->beginTransaction();

            foreach ($allowedSettings as $key) {
                if (isset($data[$key])) {
                    $value = $data[$key];
                    
                    // Convert checkboxes to boolean strings
                    if (in_array($key, ['analytics_enabled', 'ip_anonymization', 'real_time_enabled', 'geolocation_enabled', 'bot_detection_enabled', 'export_enabled'])) {
                        $value = isset($data[$key]) ? 'true' : 'false';
                    }

                    $stmt = $this->db->prepare('
                        UPDATE analytics_settings 
                        SET setting_value = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE setting_key = ?
                    ');
                    $stmt->execute([$value, $key]);
                }
            }

            $this->db->commit();

            // Add flash message
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Analytics settings updated successfully'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error updating settings: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', $this->basePath . '/admin/analytics/settings')->withStatus(302);
    }

    /**
     * Export analytics data
     */
    public function export(Request $request, Response $response): Response
    {
        // If GET request, show export page
        if ($request->getMethod() === 'GET' && !$request->getQueryParam('type')) {
            return $this->twig->render($response, 'admin/analytics/export.twig', [
                'csrf' => $_SESSION['csrf'] ?? ''
            ]);
        }
        
        if (!$this->analytics->getSetting('export_enabled', true)) {
            return $response->withStatus(403);
        }

        $params = $request->getQueryParams();
        $type = $params['type'] ?? 'sessions';
        $format = $params['format'] ?? 'csv';
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $includeBots = isset($params['include_bots']);
        $limit = $params['limit'] ?? null;

        if ($format === 'csv') {
            $csvData = $this->analytics->exportData($type, $startDate, $endDate, $includeBots, $limit);
            
            $response->getBody()->write($csvData);
            return $response
                ->withHeader('Content-Type', 'text/csv')
                ->withHeader('Content-Disposition', "attachment; filename=\"analytics_{$type}_{$startDate}_to_{$endDate}.csv\"");
        }

        // JSON export
        $data = [];
        switch ($type) {
            case 'dashboard':
                $data = $this->analytics->getDashboardStats();
                break;
            case 'charts':
                $data = $this->analytics->getChartsData($startDate, $endDate);
                break;
            case 'albums':
                $data = $this->getAlbumsData($startDate, $endDate, $limit);
                break;
            case 'sessions':
            case 'pageviews':
            case 'events':
                $data = $this->analytics->exportDataAsArray($type, $startDate, $endDate, $includeBots, $limit);
                break;
        }

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', "attachment; filename=\"analytics_{$type}_{$startDate}_to_{$endDate}.json\"");
    }

    /**
     * API endpoint for charts data
     */
    public function apiChartsData(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        $data = $this->analytics->getChartsData($startDate, $endDate);

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API endpoint for real-time data
     */
    public function apiRealtime(Request $request, Response $response): Response
    {
        try {
            $driver = 'mysql';
            try { $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql'; } catch (\Throwable) {}
            $isSqlite = ($driver === 'sqlite');
            $minus5min = $isSqlite ? 'datetime("now", "-5 minutes")' : 'DATE_SUB(NOW(), INTERVAL 5 MINUTE)';
            $minus1h   = $isSqlite ? 'datetime("now", "-1 hour")'     : 'DATE_SUB(NOW(), INTERVAL 1 HOUR)';
            // Get current visitors (last 5 minutes)
            $stmt = $this->db->prepare('
                SELECT 
                    COUNT(DISTINCT s.session_id) as current_visitors,
                    COUNT(p.id) as pageviews_5min,
                    s.country_code,
                    p.page_url,
                    p.page_title
                FROM analytics_sessions s
                LEFT JOIN analytics_pageviews p ON s.session_id = p.session_id
                WHERE s.last_activity >= ' . $minus5min . '
                GROUP BY s.country_code, p.page_url
                ORDER BY s.last_activity DESC
                LIMIT 10
            ');
            $stmt->execute();
            $currentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get top pages in last hour
            $stmt = $this->db->prepare('
                SELECT page_url, page_title, COUNT(*) as views
                FROM analytics_pageviews
                WHERE viewed_at >= ' . $minus1h . '
                GROUP BY page_url
                ORDER BY views DESC
                LIMIT 5
            ');
            $stmt->execute();
            $topPagesHour = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [
                'current_activity' => $currentActivity,
                'top_pages_hour' => $topPagesHour,
                'timestamp' => time()
            ];
        } catch (\PDOException $e) {
            // Analytics tables don't exist - return empty data
            $data = [
                'current_activity' => [],
                'top_pages_hour' => [],
                'timestamp' => time(),
                'error' => 'Analytics tables not found. Please run database migration.'
            ];
        }

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Data collection endpoint
     */
    public function track(Request $request, Response $response): Response
    {
        // Storage enabled: process payload and persist (returns 204 on success/fallback)
        if (!$this->analytics->isEnabled()) {
            return $response->withStatus(204); // No content
        }

        $body = $request->getBody()->getContents();
        $data = json_decode($body, true);
        
        if (!$data) {
            return $response->withStatus(400);
        }

        try {
            switch ($data['type'] ?? '') {
                case 'pageview':
                    $this->analytics->trackPageView($data);
                    break;
                    
                case 'event':
                    $this->analytics->trackEvent($data);
                    break;
                    
                case 'heartbeat':
                case 'page_end':
                    // Update session duration and page time - with exception handling
                    if (isset($data['session_id']) && isset($data['time_on_page'])) {
                        try {
                            $stmt = $this->db->prepare('
                                UPDATE analytics_sessions 
                                SET duration = ?, last_activity = CURRENT_TIMESTAMP 
                                WHERE session_id = ?
                            ');
                            $stmt->execute([$data['time_on_page'], $data['session_id']]);
                        } catch (\PDOException $e) {
                            // Analytics tables don't exist - silently ignore
                            // This allows the application to continue functioning
                        }
                    }
                    break;
            }

            return $response->withStatus(204); // No content
            
        } catch (\Exception $e) {
            error_log('Analytics tracking error: ' . $e->getMessage());
            // Return 204 instead of 500 to avoid breaking frontend functionality
            return $response->withStatus(204);
        }
    }

    /**
     * Albums analytics
     */
    public function albums(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));

        // Get album performance data - handle missing analytics tables
        $albumsData = [];
        try {
            $stmt = $this->db->prepare('
                SELECT 
                    p.album_id,
                    a.title as album_title,
                    a.slug as album_slug,
                    COUNT(p.id) as pageviews,
                    COUNT(DISTINCT p.session_id) as unique_visitors,
                    AVG(p.time_on_page) as avg_time_on_page
                FROM analytics_pageviews p
                LEFT JOIN albums a ON p.album_id = a.id
                WHERE p.album_id IS NOT NULL 
                AND DATE(p.viewed_at) BETWEEN ? AND ?
                GROUP BY p.album_id
                ORDER BY pageviews DESC
                LIMIT 20
            ');
            $stmt->execute([$startDate, $endDate]);
            $albumsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Analytics tables don't exist - use empty array
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash'][] = [
                'type' => 'warning', 
                'message' => 'Analytics tables not found. Please run: php bin/console db:migrate to set up analytics functionality.'
            ];
        }

        return $this->twig->render($response, 'admin/analytics/albums.twig', [
            'albums_data' => $albumsData,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
    }

    /**
     * Cleanup old analytics data
     */
    public function cleanup(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'POST') {
            try {
                $deletedRecords = $this->analytics->cleanupOldData();
                
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['flash'][] = [
                    'type' => 'success', 
                    'message' => "Cleaned up {$deletedRecords} old analytics records"
                ];
            } catch (\Exception $e) {
                $_SESSION['flash'][] = [
                    'type' => 'error', 
                    'message' => 'Error during cleanup: ' . $e->getMessage()
                ];
            }

            return $response->withHeader('Location', $this->basePath . '/admin/analytics/settings')->withStatus(302);
        }

        return $response->withStatus(405); // Method not allowed
    }
    
    /**
     * Get albums data for export
     */
    private function getAlbumsData(string $startDate, string $endDate, ?string $limit = null): array
    {
        try {
            $limitClause = $limit ? "LIMIT {$limit}" : "";
            
            $stmt = $this->db->prepare("
                SELECT 
                    p.album_id,
                    a.title as album_title,
                    a.slug as album_slug,
                    COUNT(p.id) as pageviews,
                    COUNT(DISTINCT p.session_id) as unique_visitors,
                    AVG(p.time_on_page) as avg_time_on_page
                FROM analytics_pageviews p
                LEFT JOIN albums a ON p.album_id = a.id
                WHERE p.album_id IS NOT NULL 
                AND DATE(p.viewed_at) BETWEEN ? AND ?
                GROUP BY p.album_id
                ORDER BY pageviews DESC
                {$limitClause}
            ");
            $stmt->execute([$startDate, $endDate]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Analytics tables don't exist - return empty array
            return [];
        }
    }
}
