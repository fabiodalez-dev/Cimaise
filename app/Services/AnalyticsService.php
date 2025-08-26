<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use GeoIp2\Database\Reader;

class AnalyticsService
{
    private PDO $db;
    private array $settings;
    private ?Reader $geoReader = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->loadSettings();
        $this->initGeoReader();
    }

    /**
     * Load analytics settings
     */
    private function loadSettings(): void
    {
        $this->settings = [];
        
        try {
            $stmt = $this->db->prepare('SELECT setting_key, setting_value FROM analytics_settings');
            $stmt->execute();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = $row['setting_value'];
                // Convert string booleans to actual booleans
                if ($value === 'true') $value = true;
                elseif ($value === 'false') $value = false;
                elseif (is_numeric($value)) $value = (int)$value;
                
                $this->settings[$row['setting_key']] = $value;
            }
        } catch (\PDOException $e) {
            // Analytics tables don't exist yet - use default settings
            // This happens with existing installations that predate the analytics system
            $this->settings = [
                'analytics_enabled' => true,
                'ip_anonymization' => true,
                'data_retention_days' => 365,
                'real_time_enabled' => true,
                'geolocation_enabled' => true,
                'bot_detection_enabled' => true,
                'session_timeout_minutes' => 30,
                'export_enabled' => true
            ];
        }
    }

    /**
     * Initialize GeoIP reader if geolocation is enabled
     */
    private function initGeoReader(): void
    {
        if (!$this->getSetting('geolocation_enabled', true)) {
            return;
        }

        $geoDbPath = __DIR__ . '/../../storage/GeoLite2-City.mmdb';
        if (file_exists($geoDbPath)) {
            try {
                $this->geoReader = new Reader($geoDbPath);
            } catch (\Exception $e) {
                // Silently fail if GeoIP database is not available
                error_log('GeoIP database initialization failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get analytics setting
     */
    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Check if analytics is enabled
     */
    public function isEnabled(): bool
    {
        return $this->getSetting('analytics_enabled', true);
    }

    /**
     * Generate session ID
     */
    public function generateSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash IP address for privacy
     */
    public function hashIp(string $ip): string
    {
        if ($this->getSetting('ip_anonymization', true)) {
            // Anonymize IP by removing last octet for IPv4 or last 80 bits for IPv6
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $parts = explode('.', $ip);
                $parts[3] = '0';
                $ip = implode('.', $parts);
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                // For IPv6, keep only first 48 bits
                $ip = substr($ip, 0, 19) . '::';
            }
        }
        
        return hash('sha256', $ip . 'photocms_salt');
    }

    /**
     * Parse user agent
     */
    public function parseUserAgent(string $userAgent): array
    {
        $parsed = [
            'browser' => 'Unknown',
            'browser_version' => '',
            'platform' => 'Unknown',
            'device_type' => 'desktop',
            'is_bot' => false
        ];

        // Simple bot detection
        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python',
            'googlebot', 'bingbot', 'facebookexternalhit', 'twitterbot'
        ];
        
        foreach ($botPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                $parsed['is_bot'] = true;
                break;
            }
        }

        // Browser detection
        if (preg_match('/Chrome\/([0-9\.]+)/', $userAgent, $matches)) {
            $parsed['browser'] = 'Chrome';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/Firefox\/([0-9\.]+)/', $userAgent, $matches)) {
            $parsed['browser'] = 'Firefox';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/Safari\/([0-9\.]+)/', $userAgent, $matches)) {
            $parsed['browser'] = 'Safari';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/Edge\/([0-9\.]+)/', $userAgent, $matches)) {
            $parsed['browser'] = 'Edge';
            $parsed['browser_version'] = $matches[1];
        }

        // Platform detection
        if (stripos($userAgent, 'Windows') !== false) {
            $parsed['platform'] = 'Windows';
        } elseif (stripos($userAgent, 'Mac') !== false) {
            $parsed['platform'] = 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $parsed['platform'] = 'Linux';
        } elseif (stripos($userAgent, 'Android') !== false) {
            $parsed['platform'] = 'Android';
            $parsed['device_type'] = 'mobile';
        } elseif (stripos($userAgent, 'iOS') !== false || stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
            $parsed['platform'] = 'iOS';
            $parsed['device_type'] = stripos($userAgent, 'iPad') !== false ? 'tablet' : 'mobile';
        }

        return $parsed;
    }

    /**
     * Get geographic data from IP
     */
    public function getGeoData(string $ip): array
    {
        $geoData = [
            'country_code' => null,
            'region' => null,
            'city' => null
        ];

        if (!$this->geoReader || !$this->getSetting('geolocation_enabled', true)) {
            return $geoData;
        }

        try {
            $record = $this->geoReader->city($ip);
            $geoData['country_code'] = $record->country->isoCode;
            $geoData['region'] = $record->mostSpecificSubdivision->name;
            $geoData['city'] = $record->city->name;
        } catch (\Exception $e) {
            // Silently fail if IP lookup fails
        }

        return $geoData;
    }

    /**
     * Start or get session
     */
    public function getOrCreateSession(array $data): string
    {
        $sessionId = $data['session_id'] ?? $this->generateSessionId();
        
        try {
            // Check if session exists
            $stmt = $this->db->prepare('SELECT session_id FROM analytics_sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            
            if ($stmt->fetch()) {
                // Update last activity
                $updateStmt = $this->db->prepare('
                    UPDATE analytics_sessions 
                    SET last_activity = CURRENT_TIMESTAMP, page_views = page_views + 1 
                    WHERE session_id = ?
                ');
                $updateStmt->execute([$sessionId]);
                return $sessionId;
            }

            // Create new session
            $ip = $data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';
            $referrer = $data['referrer'] ?? $_SERVER['HTTP_REFERER'] ?? '';
            
            $ipHash = $this->hashIp($ip);
            $userAgentData = $this->parseUserAgent($userAgent);
            $geoData = $this->getGeoData($ip);
            
            // Skip bots if bot detection is enabled
            if ($this->getSetting('bot_detection_enabled', true) && $userAgentData['is_bot']) {
                return $sessionId; // Return but don't store
            }

            $referrerDomain = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

            $stmt = $this->db->prepare('
                INSERT INTO analytics_sessions (
                    session_id, ip_hash, user_agent, browser, browser_version, 
                    platform, device_type, country_code, region, city,
                    referrer_domain, referrer_url, landing_page, is_bot
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $sessionId, $ipHash, $userAgent, $userAgentData['browser'], 
                $userAgentData['browser_version'], $userAgentData['platform'], 
                $userAgentData['device_type'], $geoData['country_code'], 
                $geoData['region'], $geoData['city'], $referrerDomain, 
                $referrer, $data['landing_page'] ?? '', $userAgentData['is_bot']
            ]);
        } catch (\PDOException $e) {
            // Analytics tables don't exist - silently return session ID without storing
            // This allows the application to continue functioning
        }

        return $sessionId;
    }

    /**
     * Track page view
     */
    public function trackPageView(array $data): void
    {
        if (!$this->isEnabled()) return;

        try {
            $sessionId = $this->getOrCreateSession($data);
            
            $stmt = $this->db->prepare('
                INSERT INTO analytics_pageviews (
                    session_id, page_url, page_title, page_type, album_id, 
                    category_id, tag_id, viewport_width, viewport_height
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $sessionId,
                $data['page_url'] ?? '',
                $data['page_title'] ?? '',
                $data['page_type'] ?? 'page',
                $data['album_id'] ?? null,
                $data['category_id'] ?? null,
                $data['tag_id'] ?? null,
                $data['viewport_width'] ?? null,
                $data['viewport_height'] ?? null
            ]);
        } catch (\PDOException $e) {
            // Analytics tables don't exist - silently ignore tracking
            // This allows the application to continue functioning
        }
    }

    /**
     * Track event
     */
    public function trackEvent(array $data): void
    {
        if (!$this->isEnabled()) return;

        try {
            $stmt = $this->db->prepare('
                INSERT INTO analytics_events (
                    session_id, event_type, event_category, event_action, 
                    event_label, event_value, page_url, album_id, image_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $data['session_id'] ?? '',
                $data['event_type'] ?? 'custom',
                $data['event_category'] ?? '',
                $data['event_action'] ?? '',
                $data['event_label'] ?? '',
                $data['event_value'] ?? null,
                $data['page_url'] ?? '',
                $data['album_id'] ?? null,
                $data['image_id'] ?? null
            ]);
        } catch (\PDOException $e) {
            // Analytics tables don't exist - silently ignore tracking
            // This allows the application to continue functioning
        }
    }

    /**
     * Get dashboard stats
     */
    public function getDashboardStats(): array
    {
        try {
            // Real-time stats (last 24 hours)
            $stmt = $this->db->prepare('
                SELECT 
                    COUNT(DISTINCT session_id) as active_sessions,
                    COUNT(*) as pageviews_24h
                FROM analytics_pageviews 
                WHERE viewed_at >= datetime("now", "-24 hours")
            ');
            $stmt->execute();
            $realtime = $stmt->fetch(PDO::FETCH_ASSOC);

            // Today's stats
            $stmt = $this->db->prepare('
                SELECT 
                    COUNT(DISTINCT s.session_id) as sessions_today,
                    COUNT(p.id) as pageviews_today,
                    AVG(s.duration) as avg_duration
                FROM analytics_sessions s
                LEFT JOIN analytics_pageviews p ON s.session_id = p.session_id
                WHERE DATE(s.started_at) = DATE("now")
            ');
            $stmt->execute();
            $today = $stmt->fetch(PDO::FETCH_ASSOC);

            // Top pages today
            $stmt = $this->db->prepare('
                SELECT page_url, page_title, COUNT(*) as views
                FROM analytics_pageviews 
                WHERE DATE(viewed_at) = DATE("now")
                GROUP BY page_url 
                ORDER BY views DESC 
                LIMIT 5
            ');
            $stmt->execute();
            $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top countries today
            $stmt = $this->db->prepare('
                SELECT country_code, COUNT(*) as sessions
                FROM analytics_sessions 
                WHERE DATE(started_at) = DATE("now") AND country_code IS NOT NULL
                GROUP BY country_code 
                ORDER BY sessions DESC 
                LIMIT 5
            ');
            $stmt->execute();
            $topCountries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'realtime' => $realtime,
                'today' => $today,
                'top_pages' => $topPages,
                'top_countries' => $topCountries
            ];
        } catch (\PDOException $e) {
            // Analytics tables don't exist - return empty stats
            return [
                'realtime' => ['active_sessions' => 0, 'pageviews_24h' => 0],
                'today' => ['sessions_today' => 0, 'pageviews_today' => 0, 'avg_duration' => 0],
                'top_pages' => [],
                'top_countries' => []
            ];
        }
    }

    /**
     * Get charts data for a date range
     */
    public function getChartsData(string $startDate, string $endDate): array
    {
        try {
            // Sessions over time
            $stmt = $this->db->prepare('
                SELECT DATE(started_at) as date, COUNT(*) as sessions
                FROM analytics_sessions 
                WHERE DATE(started_at) BETWEEN ? AND ?
                GROUP BY DATE(started_at) 
                ORDER BY date
            ');
            $stmt->execute([$startDate, $endDate]);
            $sessionsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Page views over time
            $stmt = $this->db->prepare('
                SELECT DATE(viewed_at) as date, COUNT(*) as pageviews
                FROM analytics_pageviews 
                WHERE DATE(viewed_at) BETWEEN ? AND ?
                GROUP BY DATE(viewed_at) 
                ORDER BY date
            ');
            $stmt->execute([$startDate, $endDate]);
            $pageviewsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Device types
            $stmt = $this->db->prepare('
                SELECT device_type, COUNT(*) as count
                FROM analytics_sessions 
                WHERE DATE(started_at) BETWEEN ? AND ?
                GROUP BY device_type
            ');
            $stmt->execute([$startDate, $endDate]);
            $deviceData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top browsers
            $stmt = $this->db->prepare('
                SELECT browser, COUNT(*) as count
                FROM analytics_sessions 
                WHERE DATE(started_at) BETWEEN ? AND ? AND browser != "Unknown"
                GROUP BY browser 
                ORDER BY count DESC 
                LIMIT 6
            ');
            $stmt->execute([$startDate, $endDate]);
            $browserData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'sessions' => $sessionsData,
                'pageviews' => $pageviewsData,
                'devices' => $deviceData,
                'browsers' => $browserData
            ];
        } catch (\PDOException $e) {
            // Analytics tables don't exist - return empty charts data
            return [
                'sessions' => [],
                'pageviews' => [],
                'devices' => [],
                'browsers' => []
            ];
        }
    }

    /**
     * Export data as CSV
     */
    public function exportData(string $type, string $startDate, string $endDate, bool $includeBots = false, ?string $limit = null): string
    {
        try {
            $data = [];
            $headers = [];
            $botFilter = $includeBots ? '' : 'AND s.is_bot = 0';
            $limitClause = $limit ? "LIMIT {$limit}" : '';

            switch ($type) {
                case 'sessions':
                    $stmt = $this->db->prepare("
                        SELECT session_id, browser, platform, device_type, country_code, 
                               started_at, page_views, duration
                        FROM analytics_sessions s
                        WHERE DATE(started_at) BETWEEN ? AND ? {$botFilter}
                        ORDER BY started_at DESC
                        {$limitClause}
                    ");
                    $headers = ['Session ID', 'Browser', 'Platform', 'Device', 'Country', 'Started At', 'Page Views', 'Duration'];
                    break;

                case 'pageviews':
                    $stmt = $this->db->prepare("
                        SELECT p.session_id, page_url, page_title, page_type, viewed_at
                        FROM analytics_pageviews p
                        JOIN analytics_sessions s ON p.session_id = s.session_id
                        WHERE DATE(p.viewed_at) BETWEEN ? AND ? {$botFilter}
                        ORDER BY viewed_at DESC
                        {$limitClause}
                    ");
                    $headers = ['Session ID', 'Page URL', 'Page Title', 'Page Type', 'Viewed At'];
                    break;

                case 'events':
                    $stmt = $this->db->prepare("
                        SELECT e.session_id, event_type, event_category, event_action, 
                               event_label, occurred_at
                        FROM analytics_events e
                        JOIN analytics_sessions s ON e.session_id = s.session_id
                        WHERE DATE(e.occurred_at) BETWEEN ? AND ? {$botFilter}
                        ORDER BY occurred_at DESC
                        {$limitClause}
                    ");
                    $headers = ['Session ID', 'Event Type', 'Category', 'Action', 'Label', 'Occurred At'];
                    break;

                default:
                    return '';
            }

            $stmt->execute([$startDate, $endDate]);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);

            // Generate CSV
            $output = fopen('php://temp', 'r+');
            fputcsv($output, $headers);
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            return $csv;
        } catch (\PDOException $e) {
            // Analytics tables don't exist - return empty CSV with headers only
            $headers = ['No Data Available - Analytics tables not found'];
            $output = fopen('php://temp', 'r+');
            fputcsv($output, $headers);
            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);
            return $csv;
        }
    }
    
    /**
     * Export data as array for JSON
     */
    public function exportDataAsArray(string $type, string $startDate, string $endDate, bool $includeBots = false, ?string $limit = null): array
    {
        try {
            $botFilter = $includeBots ? '' : 'AND s.is_bot = 0';
            $limitClause = $limit ? "LIMIT {$limit}" : '';

            switch ($type) {
                case 'sessions':
                    $stmt = $this->db->prepare("
                        SELECT session_id, browser, platform, device_type, country_code, region, city,
                               referrer_domain, started_at, last_activity, page_views, duration
                        FROM analytics_sessions s
                        WHERE DATE(started_at) BETWEEN ? AND ? {$botFilter}
                        ORDER BY started_at DESC
                        {$limitClause}
                    ");
                    break;

                case 'pageviews':
                    $stmt = $this->db->prepare("
                        SELECT p.session_id, page_url, page_title, page_type, album_id, category_id, tag_id,
                               viewport_width, viewport_height, time_on_page, viewed_at
                        FROM analytics_pageviews p
                        JOIN analytics_sessions s ON p.session_id = s.session_id
                        WHERE DATE(p.viewed_at) BETWEEN ? AND ? {$botFilter}
                        ORDER BY viewed_at DESC
                        {$limitClause}
                    ");
                    break;

                case 'events':
                    $stmt = $this->db->prepare("
                        SELECT e.session_id, event_type, event_category, event_action, event_label, 
                               event_value, page_url, album_id, image_id, occurred_at
                        FROM analytics_events e
                        JOIN analytics_sessions s ON e.session_id = s.session_id
                        WHERE DATE(e.occurred_at) BETWEEN ? AND ? {$botFilter}
                        ORDER BY occurred_at DESC
                        {$limitClause}
                    ");
                    break;

                default:
                    return [];
            }

            $stmt->execute([$startDate, $endDate]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Analytics tables don't exist - return empty array
            return [];
        }
    }

    /**
     * Cleanup old data based on retention settings
     */
    public function cleanupOldData(): int
    {
        try {
            $retentionDays = $this->getSetting('data_retention_days', 365);
            
            $stmt = $this->db->prepare('
                DELETE FROM analytics_sessions 
                WHERE started_at < datetime("now", "-' . $retentionDays . ' days")
            ');
            $stmt->execute();
            
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // Analytics tables don't exist - return 0
            return 0;
        }
    }
}