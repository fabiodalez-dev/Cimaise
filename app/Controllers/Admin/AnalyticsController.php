<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AnalyticsService;
use App\Support\Database;
use App\Support\Logger;
use PDO;

class AnalyticsController extends BaseController
{
    private const TOP_ALBUMS_LIMIT = 20;
    private PDO $db;
    private Twig $twig;
    private AnalyticsService $analytics;

    public function __construct(Database $database, Twig $twig)
    {
        parent::__construct();
        $this->db = $database->pdo();
        $this->twig = $twig;
        $this->analytics = new AnalyticsService($this->db);
    }

    /**
     * Validate and sanitize date range from query parameters
     *
     * @return array{0: string, 1: string} [startDate, endDate]
     * @throws \InvalidArgumentException if date format is invalid
     */
    private function validateDateRange(array $params): array
    {
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));

        // Validate date formats
        if (!\DateTime::createFromFormat('Y-m-d', $startDate)) {
            throw new \InvalidArgumentException('Invalid start_date format. Expected Y-m-d');
        }
        if (!\DateTime::createFromFormat('Y-m-d', $endDate)) {
            throw new \InvalidArgumentException('Invalid end_date format. Expected Y-m-d');
        }

        // Validate logical consistency
        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('start_date must be before or equal to end_date');
        }

        return [$startDate, $endDate];
    }

    /**
     * Analytics dashboard
     */
    public function index(Request $request, Response $response): Response
    {
        $dashboardStats = $this->analytics->getDashboardStats();

        // Get and validate date range from query params
        try {
            [$startDate, $endDate] = $this->validateDateRange($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            // Fallback to default range on invalid input
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }

        $chartsData = $this->analytics->getChartsData($startDate, $endDate);
        $peakHoursData = $this->analytics->getPeakHoursData($startDate, $endDate);
        $trendsData = $this->analytics->getTrendComparison($startDate, $endDate);
        $engagementData = $this->analytics->getEngagementStats($startDate, $endDate);
        $errorData = $this->analytics->get404Stats($startDate, $endDate);
        $albumAccessStats = $this->analytics->getAlbumAccessStats($startDate, $endDate, self::TOP_ALBUMS_LIMIT);
        $albumPasswordAccessStats = $this->analytics->getAlbumPasswordAccessStats($startDate, $endDate, self::TOP_ALBUMS_LIMIT);

        return $this->twig->render($response, 'admin/analytics/index.twig', [
            'stats' => $dashboardStats,
            'charts_data' => $chartsData,
            'peak_hours' => $peakHoursData,
            'trends' => $trendsData,
            'engagement' => $engagementData,
            'errors_404' => $errorData,
            'album_access_stats' => $albumAccessStats,
            'album_password_access_stats' => $albumPasswordAccessStats,
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
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/analytics/settings'))->withStatus(302);
        }

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

        // Define which settings are boolean checkboxes
        $booleanSettings = ['analytics_enabled', 'ip_anonymization', 'real_time_enabled', 'geolocation_enabled', 'bot_detection_enabled', 'export_enabled'];

        try {
            $this->db->beginTransaction();

            foreach ($allowedSettings as $key) {
                // Boolean checkbox settings: checked = present in POST, unchecked = absent
                if (in_array($key, $booleanSettings)) {
                    $value = isset($data[$key]) ? 'true' : 'false';
                    $stmt = $this->db->prepare('
                        UPDATE analytics_settings
                        SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE setting_key = ?
                    ');
                    $stmt->execute([$value, $key]);
                } elseif (isset($data[$key])) {
                    // Non-boolean settings: only update if present in POST
                    $stmt = $this->db->prepare('
                        UPDATE analytics_settings
                        SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE setting_key = ?
                    ');
                    $stmt->execute([$data[$key], $key]);
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

        return $response->withHeader('Location', $this->redirect('/admin/analytics/settings'))->withStatus(302);
    }

    /**
     * Export analytics data
     */
    public function export(Request $request, Response $response): Response
    {
        // If GET request, show export page
        $queryParams = $request->getQueryParams();
        if ($request->getMethod() === 'GET' && !isset($queryParams['type'])) {
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
        if (!$this->analytics->isEnabled()) {
            $response->getBody()->write(json_encode(['error' => 'Analytics disabled']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        try {
            [$startDate, $endDate] = $this->validateDateRange($request->getQueryParams());
            $data = $this->analytics->getChartsData($startDate, $endDate);

            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * API endpoint for peak hours data
     */
    public function apiPeakHours(Request $request, Response $response): Response
    {
        if (!$this->analytics->isEnabled()) {
            $response->getBody()->write(json_encode(['error' => 'Analytics disabled']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        try {
            [$startDate, $endDate] = $this->validateDateRange($request->getQueryParams());
            $data = $this->analytics->getPeakHoursData($startDate, $endDate);

            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * API endpoint for trend comparison
     */
    public function apiTrends(Request $request, Response $response): Response
    {
        if (!$this->analytics->isEnabled()) {
            $response->getBody()->write(json_encode(['error' => 'Analytics disabled']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        try {
            [$startDate, $endDate] = $this->validateDateRange($request->getQueryParams());
            $data = $this->analytics->getTrendComparison($startDate, $endDate);

            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * API endpoint for engagement statistics
     */
    public function apiEngagement(Request $request, Response $response): Response
    {
        if (!$this->analytics->isEnabled()) {
            $response->getBody()->write(json_encode(['error' => 'Analytics disabled']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        try {
            [$startDate, $endDate] = $this->validateDateRange($request->getQueryParams());
            $data = $this->analytics->getEngagementStats($startDate, $endDate);

            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * API endpoint for 404 error statistics
     */
    public function api404Stats(Request $request, Response $response): Response
    {
        if (!$this->analytics->isEnabled()) {
            $response->getBody()->write(json_encode(['error' => 'Analytics disabled']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        try {
            [$startDate, $endDate] = $this->validateDateRange($request->getQueryParams());
            $data = $this->analytics->get404Stats($startDate, $endDate);

            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * API endpoint for real-time data
     */
    public function apiRealtime(Request $request, Response $response): Response
    {
        if (!$this->analytics->isEnabled()) {
            $response->getBody()->write(json_encode(['error' => 'Analytics disabled']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        try {
            $driver = 'mysql';
            try { $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql'; } catch (\Throwable) {}
            $isSqlite = ($driver === 'sqlite');
            $minus5min = $isSqlite ? "datetime('now', '-5 minutes')" : 'DATE_SUB(NOW(), INTERVAL 5 MINUTE)';
            $minus1h   = $isSqlite ? "datetime('now', '-1 hour')"     : 'DATE_SUB(NOW(), INTERVAL 1 HOUR)';
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
                GROUP BY s.country_code, p.page_url, p.page_title
                ORDER BY current_visitors DESC
                LIMIT 10
            ');
            $stmt->execute();
            $currentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get top pages in last hour
            $stmt = $this->db->prepare('
                SELECT page_url, page_title, COUNT(*) as views
                FROM analytics_pageviews
                WHERE viewed_at >= ' . $minus1h . '
                GROUP BY page_url, page_title
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
        
        // Enhanced input validation
        if (strlen($body) > 8192) { // Max 8KB payload
            return $response->withStatus(413); // Payload too large
        }
        
        $data = json_decode($body, true);
        
        // Even if data is empty or invalid, return 204 to avoid browser errors
        if (!$data || !is_array($data)) {
            return $response->withStatus(204);
        }

        // Validate and sanitize input data
        $data = $this->validateAndSanitizeAnalyticsData($data);
        if ($data === null) {
            return $response->withStatus(204); // Invalid data, but don't error
        }

        try {
            switch ($data['type'] ?? '') {
                case 'pageview':
                    // Track 404 pages specifically
                    if (!empty($data['is_404']) || ($data['page_type'] ?? '') === '404') {
                        $data['page_type'] = '404';
                    }
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
            Logger::error('AnalyticsController::track error', [
                'event_type' => $data['event_type'] ?? null,
                'session_id' => $data['session_id'] ?? null,
                'error' => $e->getMessage()
            ], 'analytics');
            // Return 204 instead of 500 to avoid breaking frontend functionality
            // Even in case of errors, we don't want the browser to show network errors
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
            // CSRF validation
            if (!$this->validateCsrf($request)) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
                return $response->withHeader('Location', $this->redirect('/admin/analytics/settings'))->withStatus(302);
            }

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

            return $response->withHeader('Location', $this->redirect('/admin/analytics/settings'))->withStatus(302);
        }

        return $response->withStatus(405); // Method not allowed
    }
    
    /**
     * Get albums data for export
     */
    private function getAlbumsData(string $startDate, string $endDate, ?string $limit = null): array
    {
        try {
            // Sanitize limit parameter to prevent SQL injection
            $limitValue = $limit !== null ? filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) : false;
            $limitClause = $limitValue !== false ? "LIMIT {$limitValue}" : "";
            
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

    /**
     * Validate and sanitize analytics data
     */
    private function validateAndSanitizeAnalyticsData(array $data): ?array
    {
        // Check required type field
        $type = $data['type'] ?? '';
        if (!in_array($type, ['pageview', 'event', 'heartbeat', 'page_end'])) {
            return null;
        }

        // Sanitize common fields
        $sanitized = ['type' => $type];
        
        // Common validation rules
        $stringFields = [
            'page_url' => 2048,      // Max URL length
            'page_title' => 200,     // Reasonable title length  
            'page_type' => 50,
            'session_id' => 128,     // Reasonable session ID length
            'event_type' => 50,
            'event_category' => 100,
            'event_action' => 100,
            'event_label' => 200,
            'user_agent' => 512,
            'referrer' => 2048
        ];

        foreach ($stringFields as $field => $maxLen) {
            if (isset($data[$field])) {
                $value = trim((string)$data[$field]);
                // Basic XSS prevention: no HTML tags or JavaScript
                $value = strip_tags($value);
                if (strpos($value, 'javascript:') !== false || strpos($value, 'data:') !== false) {
                    $value = ''; // Remove dangerous protocols
                }
                // Trim to max length
                if (strlen($value) > $maxLen) {
                    $value = substr($value, 0, $maxLen);
                }
                if ($value !== '') {
                    $sanitized[$field] = $value;
                }
            }
        }

        // Validate integer fields
        $intFields = [
            'album_id', 'category_id', 'tag_id', 'image_id', 
            'viewport_width', 'viewport_height', 'time_on_page', 'event_value'
        ];
        
        foreach ($intFields as $field) {
            if (isset($data[$field])) {
                $value = filter_var($data[$field], FILTER_VALIDATE_INT);
                if ($value !== false && $value >= 0) {
                    $sanitized[$field] = $value;
                }
            }
        }

        // Validate boolean fields
        $boolFields = ['is_404', 'is_bounce'];
        foreach ($boolFields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = (bool)$data[$field];
            }
        }

        // Type-specific validation
        switch ($type) {
            case 'pageview':
                // Pageviews should have at least a URL
                if (empty($sanitized['page_url'])) {
                    return null;
                }
                break;
                
            case 'event':
                // Events should have category and action
                if (empty($sanitized['event_category']) || empty($sanitized['event_action'])) {
                    return null;
                }
                break;
                
            case 'heartbeat':
            case 'page_end':
                // These should have session_id and time data
                if (empty($sanitized['session_id'])) {
                    return null;
                }
                break;
        }

        // Additional security: remove any suspicious patterns
        foreach ($sanitized as $key => $value) {
            if (is_string($value)) {
                // Remove common attack patterns
                $dangerous = ['<script', 'javascript:', 'vbscript:', 'onload=', 'onerror=', 'onclick='];
                foreach ($dangerous as $pattern) {
                    if (stripos($value, $pattern) !== false) {
                        unset($sanitized[$key]);
                        break;
                    }
                }
            }
        }

        return $sanitized;
    }
}
