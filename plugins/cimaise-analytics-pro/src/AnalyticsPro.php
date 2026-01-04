<?php
declare(strict_types=1);

namespace CimaiseAnalyticsPro;

use App\Services\SettingsService;
use App\Support\Database;

class AnalyticsPro
{
    private Database $db;
    private SettingsService $settings;
    private ?string $ipSalt = null;

    public function __construct(Database $db, ?SettingsService $settings = null)
    {
        $this->db = $db;
        $this->settings = $settings ?? new SettingsService($db);
        $this->ensureTables();
    }

    /**
     * Ensure analytics tables exist
     */
    private function ensureTables(): void
    {
        try {
            if ($this->db->isSqlite()) {
                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_events (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        event_name TEXT NOT NULL,
                        category TEXT,
                        action TEXT,
                        label TEXT,
                        value INTEGER,
                        user_id INTEGER,
                        session_id TEXT,
                        ip_hash TEXT,
                        user_agent TEXT,
                        referrer TEXT,
                        device_type TEXT,
                        browser TEXT,
                        country TEXT,
                        metadata TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                        FOREIGN KEY (session_id) REFERENCES analytics_pro_sessions(session_id) ON DELETE SET NULL
                    )
                ");

                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_sessions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        session_id TEXT NOT NULL UNIQUE,
                        user_id INTEGER,
                        ip_hash TEXT,
                        user_agent TEXT,
                        device_type TEXT,
                        browser TEXT,
                        country TEXT,
                        started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                        ended_at DATETIME,
                        duration INTEGER DEFAULT 0,
                        pageviews INTEGER DEFAULT 0,
                        events_count INTEGER DEFAULT 0,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    )
                ");

                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_funnels (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        description TEXT,
                        steps TEXT NOT NULL,
                        is_active INTEGER DEFAULT 1,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )
                ");

                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_dimensions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        event_id INTEGER NOT NULL,
                        dimension_name TEXT NOT NULL,
                        dimension_value TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (event_id) REFERENCES analytics_pro_events(id) ON DELETE CASCADE
                    )
                ");

                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_event_name ON analytics_pro_events(event_name)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_category ON analytics_pro_events(category)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_created_at ON analytics_pro_events(created_at)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_user_id ON analytics_pro_events(user_id)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_session_id ON analytics_pro_events(session_id)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_sessions_user_id ON analytics_pro_sessions(user_id)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_sessions_started_at ON analytics_pro_sessions(started_at)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_dimensions_event_id ON analytics_pro_dimensions(event_id)");
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_analytics_pro_dimensions_name ON analytics_pro_dimensions(dimension_name)");
            } else {
                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_sessions (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        session_id VARCHAR(64) NOT NULL,
                        user_id INT UNSIGNED NULL,
                        ip_hash CHAR(64) NULL,
                        user_agent TEXT NULL,
                        device_type VARCHAR(50) NULL,
                        browser VARCHAR(50) NULL,
                        country VARCHAR(80) NULL,
                        started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                        ended_at DATETIME NULL,
                        duration INT DEFAULT 0,
                        pageviews INT DEFAULT 0,
                        events_count INT DEFAULT 0,
                        PRIMARY KEY (id),
                        UNIQUE KEY idx_analytics_pro_session_id (session_id),
                        KEY idx_analytics_pro_sessions_user_id (user_id),
                        KEY idx_analytics_pro_sessions_started_at (started_at),
                        CONSTRAINT fk_analytics_pro_sessions_user
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_events (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        event_name VARCHAR(120) NOT NULL,
                        category VARCHAR(120) NULL,
                        action VARCHAR(120) NULL,
                        label VARCHAR(255) NULL,
                        value INT NULL,
                        user_id INT UNSIGNED NULL,
                        session_id VARCHAR(64) NULL,
                        ip_hash CHAR(64) NULL,
                        user_agent TEXT NULL,
                        referrer TEXT NULL,
                        device_type VARCHAR(50) NULL,
                        browser VARCHAR(50) NULL,
                        country VARCHAR(80) NULL,
                        metadata TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_analytics_pro_event_name (event_name),
                        KEY idx_analytics_pro_category (category),
                        KEY idx_analytics_pro_created_at (created_at),
                        KEY idx_analytics_pro_user_id (user_id),
                        KEY idx_analytics_pro_session_id (session_id),
                        CONSTRAINT fk_analytics_pro_events_user
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                        CONSTRAINT fk_analytics_pro_events_session
                            FOREIGN KEY (session_id) REFERENCES analytics_pro_sessions(session_id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_funnels (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        name VARCHAR(190) NOT NULL,
                        description TEXT NULL,
                        steps TEXT NOT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $this->db->pdo()->exec("
                    CREATE TABLE IF NOT EXISTS analytics_pro_dimensions (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        event_id INT UNSIGNED NOT NULL,
                        dimension_name VARCHAR(190) NOT NULL,
                        dimension_value TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_analytics_pro_dimensions_event_id (event_id),
                        KEY idx_analytics_pro_dimensions_name (dimension_name),
                        CONSTRAINT fk_analytics_pro_dimensions_event
                            FOREIGN KEY (event_id) REFERENCES analytics_pro_events(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error creating tables: " . $e->getMessage());
        }
    }

    /**
     * Track an event
     */
    public function trackEvent(string $eventName, array $data = []): bool
    {
        try {
            $sessionId = $this->getOrCreateSession();

            $stmt = $this->db->pdo()->prepare("
                INSERT INTO analytics_pro_events
                (event_name, category, action, label, value, user_id, session_id, ip_hash,
                 user_agent, referrer, device_type, browser, country, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $eventName,
                $data['category'] ?? null,
                $data['action'] ?? null,
                $data['label'] ?? null,
                $data['value'] ?? null,
                $data['user_id'] ?? $_SESSION['user_id'] ?? null,
                $sessionId,
                $this->hashIp($_SERVER['REMOTE_ADDR'] ?? null),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['HTTP_REFERER'] ?? null,
                $data['device_type'] ?? null,
                $data['browser'] ?? null,
                $data['country'] ?? null,
                isset($data['metadata']) ? json_encode($data['metadata']) : null,
            ]);

            // Update session
            $this->updateSession($sessionId);

            // Track custom dimensions if present
            if (!empty($data['dimensions'])) {
                $this->trackDimensions($this->db->pdo()->lastInsertId(), $data['dimensions']);
            }

            return true;
        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error tracking event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or create session
     */
    private function getOrCreateSession(): string
    {
        if (!isset($_SESSION['analytics_session_id'])) {
            $_SESSION['analytics_session_id'] = bin2hex(random_bytes(16));
            $_SESSION['session_start'] = time();

            // Create session record
            $stmt = $this->db->pdo()->prepare("
                INSERT INTO analytics_pro_sessions
                (session_id, user_id, ip_hash, user_agent, device_type, browser, country)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $_SESSION['analytics_session_id'],
                $_SESSION['user_id'] ?? null,
                $this->hashIp($_SERVER['REMOTE_ADDR'] ?? null),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $this->detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                $this->detectBrowser($_SERVER['HTTP_USER_AGENT'] ?? ''),
                $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
            ]);
        }

        return $_SESSION['analytics_session_id'];
    }

    /**
     * Update session activity
     */
    private function updateSession(string $sessionId): void
    {
        $stmt = $this->db->pdo()->prepare("
            UPDATE analytics_pro_sessions
            SET last_activity = CURRENT_TIMESTAMP,
                duration = (julianday(CURRENT_TIMESTAMP) - julianday(started_at)) * 86400,
                events_count = events_count + 1
            WHERE session_id = ?
        ");

        $stmt->execute([$sessionId]);
    }

    private function hashIp(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }

        $anonymize = filter_var($this->settings->get('ip_anonymization', true), FILTER_VALIDATE_BOOLEAN);

        if ($anonymize) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $parts = explode('.', $ip);
                $parts[3] = '0';
                $ip = implode('.', $parts);
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $binary = inet_pton($ip);
                if ($binary !== false) {
                    $masked = substr($binary, 0, 6) . str_repeat("\0", 10);
                    $ip = inet_ntop($masked) ?: $ip;
                }
            }
        }

        return hash('sha256', $ip . $this->getIpSalt());
    }

    private function getIpSalt(): string
    {
        if ($this->ipSalt !== null) {
            return $this->ipSalt;
        }

        $salt = '';
        try {
            $salt = (string)$this->settings->get('analytics.ip_salt', '');
        } catch (\Throwable $e) {
            error_log("Analytics Pro: Unable to retrieve IP salt from settings: " . $e->getMessage());
            $salt = '';
        }

        if ($salt === '') {
            $salt = bin2hex(random_bytes(16));
            try {
                $this->settings->set('analytics.ip_salt', $salt);
            } catch (\Throwable $e) {
                error_log("Analytics Pro: Unable to persist IP salt to settings: " . $e->getMessage());
            }
        }

        $this->ipSalt = $salt;
        return $salt;
    }

    /**
     * Track custom dimensions
     */
    private function trackDimensions(int $eventId, array $dimensions): void
    {
        $stmt = $this->db->pdo()->prepare("
            INSERT INTO analytics_pro_dimensions (event_id, dimension_name, dimension_value)
            VALUES (?, ?, ?)
        ");

        foreach ($dimensions as $name => $value) {
            $stmt->execute([$eventId, $name, $value]);
        }
    }

    /**
     * Get real-time statistics
     */
    public function getRealtimeStats(): array
    {
        $stats = [];

        try {
            // Active users (last 5 minutes)
            $stmt = $this->db->pdo()->query("
                SELECT COUNT(DISTINCT session_id) as active_users
                FROM analytics_pro_sessions
                WHERE last_activity >= datetime('now', '-5 minutes')
            ");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stats['active_users'] = $result['active_users'] ?? 0;

            // Events today
            $stmt = $this->db->pdo()->query("
                SELECT COUNT(*) as events_today
                FROM analytics_pro_events
                WHERE DATE(created_at) = DATE('now')
            ");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stats['events_today'] = $result['events_today'] ?? 0;

            // Pageviews today
            $stmt = $this->db->pdo()->query("
                SELECT COUNT(*) as pageviews_today
                FROM analytics_pro_events
                WHERE event_name = 'page_view' AND DATE(created_at) = DATE('now')
            ");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stats['pageviews_today'] = $result['pageviews_today'] ?? 0;

            // Average session duration today
            $stmt = $this->db->pdo()->query("
                SELECT AVG(duration) as avg_duration
                FROM analytics_pro_sessions
                WHERE DATE(started_at) = DATE('now') AND duration > 0
            ");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stats['avg_session_duration'] = round($result['avg_duration'] ?? 0, 2);

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error getting realtime stats: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Get event statistics by period
     */
    public function getEventStats(string $period = 'day', int $limit = 30): array
    {
        $dateFormat = match ($period) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-W%W',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        try {
            $stmt = $this->db->pdo()->prepare("
                SELECT
                    strftime(?, created_at) as period,
                    event_name,
                    COUNT(*) as count,
                    COUNT(DISTINCT session_id) as unique_sessions,
                    COUNT(DISTINCT user_id) as unique_users
                FROM analytics_pro_events
                WHERE created_at >= datetime('now', ?)
                GROUP BY period, event_name
                ORDER BY period DESC, count DESC
                LIMIT ?
            ");

            $interval = match ($period) {
                'hour' => '-24 hours',
                'day' => "-{$limit} days",
                'week' => "-{$limit} weeks",
                'month' => "-{$limit} months",
                default => "-{$limit} days",
            };

            $stmt->execute([$dateFormat, $interval, $limit * 100]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error getting event stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get top events by category
     */
    public function getTopEventsByCategory(string $category, int $limit = 10): array
    {
        try {
            $stmt = $this->db->pdo()->prepare("
                SELECT
                    event_name,
                    COUNT(*) as count,
                    COUNT(DISTINCT session_id) as unique_sessions,
                    MAX(created_at) as last_occurrence
                FROM analytics_pro_events
                WHERE category = ? AND created_at >= datetime('now', '-30 days')
                GROUP BY event_name
                ORDER BY count DESC
                LIMIT ?
            ");

            $stmt->execute([$category, $limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error getting top events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user journey (events for a session)
     */
    public function getUserJourney(string $sessionId): array
    {
        try {
            $stmt = $this->db->pdo()->prepare("
                SELECT *
                FROM analytics_pro_events
                WHERE session_id = ?
                ORDER BY created_at ASC
            ");

            $stmt->execute([$sessionId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error getting user journey: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get conversion funnel analysis
     */
    public function getFunnelAnalysis(array $steps): array
    {
        $results = [];
        $previousCount = null;

        try {
            foreach ($steps as $index => $step) {
                $stmt = $this->db->pdo()->prepare("
                    SELECT COUNT(DISTINCT session_id) as count
                    FROM analytics_pro_events
                    WHERE event_name = ? AND created_at >= datetime('now', '-30 days')
                ");

                $stmt->execute([$step]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                $count = $result['count'] ?? 0;

                $results[] = [
                    'step' => $step,
                    'count' => $count,
                    'conversion_rate' => $previousCount ? ($count / $previousCount) * 100 : 100,
                    'drop_off' => $previousCount ? (($previousCount - $count) / $previousCount) * 100 : 0,
                ];

                $previousCount = $count;
            }

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error getting funnel analysis: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Export events to CSV
     */
    public function exportToCSV(array $filters = []): string
    {
        $query = "SELECT * FROM analytics_pro_events WHERE 1=1";
        $params = [];

        if (!empty($filters['date_from'])) {
            $query .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $query .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['event_name'])) {
            $query .= " AND event_name = ?";
            $params[] = $filters['event_name'];
        }

        if (!empty($filters['category'])) {
            $query .= " AND category = ?";
            $params[] = $filters['category'];
        }

        $query .= " ORDER BY created_at DESC LIMIT 10000";

        try {
            $stmt = $this->db->pdo()->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Generate CSV
            $csv = fopen('php://temp', 'r+');

            // Header
            if (!empty($results)) {
                fputcsv($csv, array_keys($results[0]));

                // Data
                foreach ($results as $row) {
                    fputcsv($csv, $row);
                }
            }

            rewind($csv);
            $output = stream_get_contents($csv);
            fclose($csv);

            return $output;

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error exporting to CSV: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Get device statistics
     */
    public function getDeviceStats(int $days = 30): array
    {
        try {
            $stmt = $this->db->pdo()->prepare("
                SELECT
                    device_type,
                    COUNT(*) as count,
                    COUNT(DISTINCT session_id) as unique_sessions,
                    ROUND(AVG(duration), 2) as avg_session_duration
                FROM analytics_pro_sessions
                WHERE started_at >= datetime('now', ?)
                GROUP BY device_type
                ORDER BY count DESC
            ");

            $stmt->execute(["-{$days} days"]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error getting device stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get browser statistics
     */
    public function getBrowserStats(int $days = 30): array
    {
        try {
            $stmt = $this->db->pdo()->prepare("
                SELECT
                    browser,
                    COUNT(*) as count,
                    COUNT(DISTINCT session_id) as unique_sessions
                FROM analytics_pro_sessions
                WHERE started_at >= datetime('now', ?)
                GROUP BY browser
                ORDER BY count DESC
            ");

            $stmt->execute(["-{$days} days"]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error getting browser stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean old data
     */
    public function cleanup(int $days = 90): int
    {
        try {
            $stmt = $this->db->pdo()->prepare("
                DELETE FROM analytics_pro_events
                WHERE created_at < datetime('now', ?)
            ");

            $stmt->execute(["-{$days} days"]);
            $deleted = $stmt->rowCount();

            // Also cleanup old sessions
            $stmt = $this->db->pdo()->prepare("
                DELETE FROM analytics_pro_sessions
                WHERE started_at < datetime('now', ?)
            ");

            $stmt->execute(["-{$days} days"]);
            $deleted += $stmt->rowCount();

            return $deleted;

        } catch (\Throwable $e) {
            error_log("Analytics Pro: Error cleaning up data: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Detect device type
     */
    private function detectDeviceType(string $userAgent): string
    {
        if (preg_match('/tablet|ipad/i', $userAgent)) {
            return 'tablet';
        }
        if (preg_match('/mobile|android|iphone/i', $userAgent)) {
            return 'mobile';
        }
        return 'desktop';
    }

    /**
     * Detect browser
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
}
