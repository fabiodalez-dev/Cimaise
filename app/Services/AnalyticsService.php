<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;
use PDO;
use GeoIp2\Database\Reader;

class AnalyticsService
{
    private array $settings;
    private ?Reader $geoReader = null;
    private string $driver = 'mysql';
    /** F043: cached IP-pseudonymization salt resolved lazily on first hashIp() call. */
    private ?string $ipSalt = null;

    public function __construct(private readonly PDO $db)
    {
        try {
            $this->driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';
        } catch (\Throwable) {
            $this->driver = 'mysql';
        }
        $this->loadSettings();
        $this->initGeoReader();
    }

    private function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }
    private function nowMinusHoursExpr(int $hours): string
    {
        return $this->isSqlite()
            ? "datetime('now', '-" . $hours . " hours')"
            : 'DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)';
    }

    /**
     * Compute inclusive start and exclusive end boundaries for a date range.
     * Converts Y-m-d strings to datetime boundaries for sargable WHERE clauses.
     */
    private function dateRangeBounds(string $startDate, string $endDate): array
    {
        return [
            $startDate . ' 00:00:00',
            date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day')),
        ];
    }

    /**
     * Sanitize limit parameter to prevent SQL injection
     */
    private function sanitizeLimit(?string $limit, int $maxRange = 100000): string
    {
        $limitValue = $limit !== null
            ? filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maxRange]])
            : false;
        return $limitValue !== false ? "LIMIT {$limitValue}" : '';
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
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = (int)$value;
                }

                $this->settings[$row['setting_key']] = $value;
            }
        } catch (\PDOException) {
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
                Logger::warning('AnalyticsService: GeoIP database initialization failed', ['error' => $e->getMessage()], 'analytics');
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
     * Hash IP address for privacy.
     *
     * F043: Historical note — ip_hash values produced before this change used a
     * hardcoded fallback salt that was public (the project ships GPLv3). Any
     * pre-existing analytics_*.ip_hash entries should be considered reversible
     * via rainbow tables and SHOULD be truncated on upgrade (see
     * truncateLegacyIpHashes()). New hashes use a per-install secret salt.
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

        // F043: resolve a real, per-install salt. Fails closed if no salt is
        // available — we refuse to fall back to a public/predictable value.
        $salt = $this->getIpSalt();
        return hash('sha256', $ip . $salt);
    }

    /**
     * F043: Resolve the per-install salt used to pseudonymize IP addresses.
     *
     * Resolution order (DB is the single source of truth — SESSION_SECRET only
     * seeds it on first use, so analytics history stays stable across requests
     * regardless of whether SESSION_SECRET happens to be set in the env):
     *   1. In-memory cache (set on first successful resolve).
     *   2. 'ip_salt' row in analytics_settings — if non-empty, cache and return.
     *   3. SESSION_SECRET from $_ENV/$_SERVER. If present, persist it to
     *      analytics_settings AND cache+return so it becomes the canonical salt.
     *   4. Otherwise: generate bin2hex(random_bytes(32)), persist to
     *      analytics_settings, cache+return.
     *
     * Persistence failures (missing table, read-only DB, race conditions) are
     * NEVER fatal: this method always returns a non-empty salt by falling back
     * to an in-memory generated value. Callers (hashIp, getOrCreateSession,
     * trackPageView) catch only PDOException, so a thrown RuntimeException
     * would otherwise escape and break the request.
     */
    private function getIpSalt(): string
    {
        if ($this->ipSalt !== null && $this->ipSalt !== '') {
            return $this->ipSalt;
        }

        // (2) DB FIRST: look up the persisted salt in analytics_settings.
        // Using the DB as the source of truth ensures that ip_hash values stay
        // consistent across requests even if SESSION_SECRET differs between
        // processes (e.g. only set in some FPM workers).
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM analytics_settings WHERE setting_key = ?');
            $stmt->execute(['ip_salt']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['setting_value']) && $row['setting_value'] !== '') {
                $this->ipSalt = (string)$row['setting_value'];
                return $this->ipSalt;
            }
        } catch (\PDOException $e) {
            // Table may not exist yet on legacy installs. Fall through.
        }

        // (3) Seed from SESSION_SECRET if present — but persist it to the DB
        // so subsequent requests (which may or may not have SESSION_SECRET set)
        // all converge on the same canonical value via path (2).
        $sessionSecret = $_ENV['SESSION_SECRET'] ?? $_SERVER['SESSION_SECRET'] ?? null;
        if (is_string($sessionSecret) && $sessionSecret !== '') {
            $persisted = $this->persistIpSalt($sessionSecret);
            $this->ipSalt = $persisted;
            $this->settings['ip_salt'] = $persisted;
            return $this->ipSalt;
        }

        // (4) Generate a fresh salt and persist it so subsequent hashes stay
        // stable. If CSPRNG is unavailable we still must return a usable salt
        // (the alternative — throwing — would break the entire request).
        try {
            $fresh = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            // Last-resort: derive a process-local pseudo-salt. Not cryptographically
            // ideal, but better than killing the request. Logged so operators see it.
            Logger::warning(
                'AnalyticsService: CSPRNG unavailable for IP salt, using degraded in-memory salt',
                ['error' => $e->getMessage()],
                'analytics'
            );
            $fresh = hash('sha256', uniqid('', true) . microtime(true) . getmypid());
        }

        // F012: persistIpSalt may discover that another worker already wrote
        // a different salt under us — in that case the DB value is the
        // canonical one and THIS request must hash with the DB value (not
        // $fresh), otherwise analytics history fragments across the race
        // window. The helper now returns whichever salt actually won.
        $persisted = $this->persistIpSalt($fresh);
        $this->ipSalt = $persisted;
        $this->settings['ip_salt'] = $persisted;
        return $this->ipSalt;
    }

    /**
     * Best-effort persistence of the IP salt to analytics_settings.
     *
     * Returns the salt that is now canonical in the database — usually the
     * argument, but possibly a different value already written by a racing
     * worker (in which case the caller should defer to it).
     *
     * Persistence failures are intentionally swallowed (only logged) so that
     * IP hashing always succeeds — see getIpSalt() docblock. A subsequent
     * request will retry persistence via getIpSalt() path (4).
     */
    private function persistIpSalt(string $salt): string
    {
        try {
            // Portable upsert: try INSERT, on duplicate-key fall back to UPDATE.
            $insert = $this->db->prepare(
                'INSERT INTO analytics_settings (setting_key, setting_value, description) VALUES (?, ?, ?)'
            );
            $insert->execute(['ip_salt', $salt, 'Per-install salt for IP pseudonymization (auto-generated)']);
            return $salt;
        } catch (\PDOException $e) {
            // Likely a unique-key race or a missing table. Try UPDATE, then re-read
            // so two concurrent processes converge on the same salt.
            //
            // F012-related: TRIM() in the WHERE so a row whose setting_value is
            // pure whitespace (e.g. a botched manual seed) still gets healed
            // by the UPDATE. Both SQLite and MySQL implement TRIM with the
            // SQL-standard signature so this is portable.
            try {
                $update = $this->db->prepare('UPDATE analytics_settings SET setting_value = ? WHERE setting_key = ? AND (setting_value IS NULL OR TRIM(setting_value) = \'\')');
                $update->execute([$salt, 'ip_salt']);

                // F012-followup: when the INSERT in the try block lost the race
                // to another worker, that worker's transaction may still be
                // mid-commit when we reach the SELECT — reading too eagerly
                // returns NULL/empty and we'd fall through to logging a
                // spurious failure. Re-read with a small bounded retry so the
                // committed value has time to land. Total worst-case wait:
                // 3 attempts × 20ms = 60ms, well under any realistic commit
                // latency on either engine.
                $read = $this->db->prepare('SELECT setting_value FROM analytics_settings WHERE setting_key = ?');
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $read->execute(['ip_salt']);
                    $row = $read->fetch(PDO::FETCH_ASSOC);
                    if ($row !== false && isset($row['setting_value']) && trim((string)$row['setting_value']) !== '') {
                        // Another process already wrote a salt — defer to it so all
                        // workers converge. The caller will hash THIS request's IPs
                        // with the DB-canonical value (not the in-flight $salt),
                        // closing the F012 race window.
                        return (string)$row['setting_value'];
                    }
                    if ($attempt < 2) {
                        usleep(20_000); // 20ms
                    }
                }
            } catch (\PDOException) {
                // Fall through to log.
            }
            // Non-fatal: log and continue with the in-memory salt. CR-1: do NOT
            // throw — callers catch only PDOException so a RuntimeException would
            // escape and break the request.
            Logger::warning(
                'AnalyticsService: cannot persist IP salt to analytics_settings (using in-memory fallback)',
                ['error' => $e->getMessage()],
                'analytics'
            );
        }
        return $salt;
    }

    /**
     * F043 (optional helper): truncate analytics ip_hash columns that were
     * computed before this service shipped a real salt. Returns the number of
     * rows updated across the analytics tables. Safe to call repeatedly.
     *
     * Covers every analytics table that carries an `ip_hash` column —
     * `analytics_sessions` (core), `analytics_pro_sessions` and
     * `analytics_pro_events` (analytics-pro plugin). Missing tables fall
     * through the per-table try/catch so the helper degrades gracefully
     * when the pro plugin is not installed.
     */
    public function truncateLegacyIpHashes(): int
    {
        $total = 0;
        foreach (['analytics_sessions', 'analytics_pro_sessions', 'analytics_pro_events'] as $table) {
            try {
                $stmt = $this->db->prepare("UPDATE {$table} SET ip_hash = NULL WHERE ip_hash IS NOT NULL");
                $stmt->execute();
                $total += $stmt->rowCount();
            } catch (\PDOException) {
                // Table may not exist or column may differ — ignore.
            }
        }
        return $total;
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
        } catch (\Exception) {
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

            $referrerDomain = $referrer ? parse_url((string) $referrer, PHP_URL_HOST) : null;

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
                // PDO binds PHP false as '' (empty string): MySQL strict mode
                // rejects '' for the TINYINT is_bot column, killing the whole
                // session insert — cast explicitly.
                $referrer, $data['landing_page'] ?? '', (int)$userAgentData['is_bot']
            ]);
        } catch (\PDOException $e) {
            // Missing analytics tables are tolerated (plugin-less installs);
            // anything else must surface in the logs or tracking dies silently.
            if (!$this->isMissingTableError($e)) {
                Logger::error('Analytics session insert failed', ['error' => $e->getMessage()], 'analytics');
            }
        }

        return $sessionId;
    }

    /**
     * True when the PDO error means an analytics table is absent
     * (MySQL 1146 / SQLSTATE 42S02, SQLite "no such table").
     */
    private function isMissingTableError(\PDOException $e): bool
    {
        $info = $e->errorInfo ?? [];
        if (($info[0] ?? '') === '42S02' || ($info[1] ?? 0) === 1146) {
            return true;
        }
        return stripos($e->getMessage(), 'no such table') !== false;
    }

    /**
     * Track page view
     */
    public function trackPageView(array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

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
            // Missing analytics tables are tolerated; real DB errors must be
            // logged or tracking failures stay invisible forever.
            if (!$this->isMissingTableError($e)) {
                Logger::error('Analytics pageview insert failed', ['error' => $e->getMessage()], 'analytics');
            }
        }
    }

    /**
     * Track event
     */
    public function trackEvent(array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

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
            if (!$this->isMissingTableError($e)) {
                Logger::error('Analytics event insert failed', ['error' => $e->getMessage()], 'analytics');
            }
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
                WHERE viewed_at >= ' . $this->nowMinusHoursExpr(24) . '
            ');
            $stmt->execute();
            $realtime = $stmt->fetch(PDO::FETCH_ASSOC);

            // H8: Use range predicates instead of DATE() for index usage
            $todayStart = date('Y-m-d 00:00:00');
            $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

            // Today's stats
            $stmt = $this->db->prepare('
                SELECT
                    COUNT(DISTINCT s.session_id) as sessions_today,
                    COUNT(p.id) as pageviews_today,
                    AVG(s.duration) as avg_duration
                FROM analytics_sessions s
                LEFT JOIN analytics_pageviews p ON s.session_id = p.session_id
                WHERE s.started_at >= ? AND s.started_at < ?
            ');
            $stmt->execute([$todayStart, $tomorrowStart]);
            $today = $stmt->fetch(PDO::FETCH_ASSOC);

            // Top pages today. Group by page_url alone so a URL whose title
            // changed over time still aggregates into a single ranked row, and
            // pick a representative title with MAX(page_title). This is also
            // cross-DB safe: MAX() makes page_title an aggregate, satisfying
            // MySQL's ONLY_FULL_GROUP_BY (on by default since 5.7) which would
            // otherwise reject a bare page_title with error 1055 — and that
            // PDOException would bubble up and the broad catch below would zero
            // out realtime/today too.
            $stmt = $this->db->prepare('
                SELECT page_url, MAX(page_title) as page_title, COUNT(*) as views
                FROM analytics_pageviews
                WHERE viewed_at >= ? AND viewed_at < ?
                GROUP BY page_url
                ORDER BY views DESC
                LIMIT 5
            ');
            $stmt->execute([$todayStart, $tomorrowStart]);
            $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top countries today
            $stmt = $this->db->prepare('
                SELECT country_code, COUNT(*) as sessions
                FROM analytics_sessions
                WHERE started_at >= ? AND started_at < ? AND country_code IS NOT NULL
                GROUP BY country_code
                ORDER BY sessions DESC
                LIMIT 5
            ');
            $stmt->execute([$todayStart, $tomorrowStart]);
            $topCountries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'realtime' => $realtime,
                'today' => $today,
                'top_pages' => $topPages,
                'top_countries' => $topCountries
            ];
        } catch (\PDOException) {
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
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);

            // Sessions over time
            $stmt = $this->db->prepare('
                SELECT DATE(started_at) as date, COUNT(*) as sessions
                FROM analytics_sessions
                WHERE started_at >= ? AND started_at < ?
                GROUP BY DATE(started_at)
                ORDER BY date
            ');
            $stmt->execute([$rangeStart, $rangeEnd]);
            $sessionsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Page views over time
            $stmt = $this->db->prepare('
                SELECT DATE(viewed_at) as date, COUNT(*) as pageviews
                FROM analytics_pageviews
                WHERE viewed_at >= ? AND viewed_at < ?
                GROUP BY DATE(viewed_at)
                ORDER BY date
            ');
            $stmt->execute([$rangeStart, $rangeEnd]);
            $pageviewsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Device types
            $stmt = $this->db->prepare('
                SELECT device_type, COUNT(*) as count
                FROM analytics_sessions
                WHERE started_at >= ? AND started_at < ?
                GROUP BY device_type
            ');
            $stmt->execute([$rangeStart, $rangeEnd]);
            $deviceData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top browsers
            $stmt = $this->db->prepare("
                SELECT browser, COUNT(*) as count
                FROM analytics_sessions
                WHERE started_at >= ? AND started_at < ? AND browser != 'Unknown'
                GROUP BY browser
                ORDER BY count DESC
                LIMIT 6
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $browserData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'sessions' => $sessionsData,
                'pageviews' => $pageviewsData,
                'devices' => $deviceData,
                'browsers' => $browserData
            ];
        } catch (\PDOException) {
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
            $limitClause = $this->sanitizeLimit($limit);
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);

            switch ($type) {
                case 'sessions':
                    $stmt = $this->db->prepare("
                        SELECT session_id, browser, platform, device_type, country_code,
                               started_at, page_views, duration
                        FROM analytics_sessions s
                        WHERE started_at >= ? AND started_at < ? {$botFilter}
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
                        WHERE p.viewed_at >= ? AND p.viewed_at < ? {$botFilter}
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
                        WHERE e.occurred_at >= ? AND e.occurred_at < ? {$botFilter}
                        ORDER BY occurred_at DESC
                        {$limitClause}
                    ");
                    $headers = ['Session ID', 'Event Type', 'Category', 'Action', 'Label', 'Occurred At'];
                    break;

                default:
                    return '';
            }

            $stmt->execute([$rangeStart, $rangeEnd]);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);

            // Generate CSV
            $output = fopen('php://temp', 'r+');
            fputcsv($output, $headers, ',', '"', '\\');
            foreach ($data as $row) {
                fputcsv($output, $row, ',', '"', '\\');
            }
            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            return $csv;
        } catch (\PDOException) {
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
            $limitClause = $this->sanitizeLimit($limit);
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);

            switch ($type) {
                case 'sessions':
                    $stmt = $this->db->prepare("
                        SELECT session_id, browser, platform, device_type, country_code, region, city,
                               referrer_domain, started_at, last_activity, page_views, duration
                        FROM analytics_sessions s
                        WHERE started_at >= ? AND started_at < ? {$botFilter}
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
                        WHERE p.viewed_at >= ? AND p.viewed_at < ? {$botFilter}
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
                        WHERE e.occurred_at >= ? AND e.occurred_at < ? {$botFilter}
                        ORDER BY occurred_at DESC
                        {$limitClause}
                    ");
                    break;

                default:
                    return [];
            }

            $stmt->execute([$rangeStart, $rangeEnd]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
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
            $expr = $this->isSqlite()
                ? "datetime('now', '-" . (int)$retentionDays . " days')"
                : 'DATE_SUB(NOW(), INTERVAL ' . (int)$retentionDays . ' DAY)';
            $stmt = $this->db->prepare('DELETE FROM analytics_sessions WHERE started_at < ' . $expr);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\PDOException) {
            // Analytics tables don't exist - return 0
            return 0;
        }
    }

    /**
     * Get peak hours data (hourly traffic distribution)
     */
    public function getPeakHoursData(string $startDate, string $endDate): array
    {
        try {
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);
            $hourExpr = $this->isSqlite()
                ? "strftime('%H', viewed_at)"
                : "HOUR(viewed_at)";

            $stmt = $this->db->prepare("
                SELECT
                    {$hourExpr} as hour,
                    COUNT(*) as pageviews,
                    COUNT(DISTINCT session_id) as sessions
                FROM analytics_pageviews
                WHERE viewed_at >= ? AND viewed_at < ?
                GROUP BY {$hourExpr}
                ORDER BY hour
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fill in missing hours with zeros
            $hourlyData = array_fill(0, 24, ['pageviews' => 0, 'sessions' => 0]);
            foreach ($data as $row) {
                $hour = (int)$row['hour'];
                $hourlyData[$hour] = [
                    'pageviews' => (int)$row['pageviews'],
                    'sessions' => (int)$row['sessions']
                ];
            }

            return $hourlyData;
        } catch (\PDOException) {
            return array_fill(0, 24, ['pageviews' => 0, 'sessions' => 0]);
        }
    }

    /**
     * Get trend comparison data (current period vs previous period)
     */
    public function getTrendComparison(string $startDate, string $endDate): array
    {
        try {
            // Calculate previous period (same length as current)
            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);
            $diff = $start->diff($end)->days + 1; // Inclusive day count

            $prevEnd = (clone $start)->modify('-1 day');
            // Subtract (diff - 1) days from prevEnd to get same period length
            $prevStart = (clone $prevEnd)->modify('-' . ($diff - 1) . ' days');

            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);
            [$prevRangeStart, $prevRangeEnd] = $this->dateRangeBounds(
                $prevStart->format('Y-m-d'),
                $prevEnd->format('Y-m-d')
            );

            // Current period stats
            $stmt = $this->db->prepare('
                SELECT
                    COUNT(DISTINCT session_id) as sessions,
                    COUNT(*) as pageviews
                FROM analytics_pageviews
                WHERE viewed_at >= ? AND viewed_at < ?
            ');
            $stmt->execute([$rangeStart, $rangeEnd]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            // Previous period stats
            $stmt->execute([$prevRangeStart, $prevRangeEnd]);
            $previous = $stmt->fetch(PDO::FETCH_ASSOC);

            // Current period events
            $stmt = $this->db->prepare('
                SELECT COUNT(*) as total
                FROM analytics_events
                WHERE occurred_at >= ? AND occurred_at < ?
            ');
            $stmt->execute([$rangeStart, $rangeEnd]);
            $currentEvents = $stmt->fetch(PDO::FETCH_ASSOC);

            // Previous period events
            $stmt->execute([$prevRangeStart, $prevRangeEnd]);
            $previousEvents = $stmt->fetch(PDO::FETCH_ASSOC);

            // Calculate percentage changes
            $sessionsChange = $this->calculatePercentageChange(
                (int)($previous['sessions'] ?? 0),
                (int)($current['sessions'] ?? 0)
            );
            $pageviewsChange = $this->calculatePercentageChange(
                (int)($previous['pageviews'] ?? 0),
                (int)($current['pageviews'] ?? 0)
            );
            $eventsChange = $this->calculatePercentageChange(
                (int)($previousEvents['total'] ?? 0),
                (int)($currentEvents['total'] ?? 0)
            );

            return [
                'current' => [
                    'sessions' => (int)($current['sessions'] ?? 0),
                    'pageviews' => (int)($current['pageviews'] ?? 0),
                    'events' => (int)($currentEvents['total'] ?? 0)
                ],
                'previous' => [
                    'sessions' => (int)($previous['sessions'] ?? 0),
                    'pageviews' => (int)($previous['pageviews'] ?? 0),
                    'events' => (int)($previousEvents['total'] ?? 0)
                ],
                'changes' => [
                    'sessions' => $sessionsChange,
                    'pageviews' => $pageviewsChange,
                    'events' => $eventsChange
                ],
                'period_days' => $diff,
                'previous_start' => $prevStart->format('Y-m-d'),
                'previous_end' => $prevEnd->format('Y-m-d')
            ];
        } catch (\PDOException) {
            return [
                'current' => ['sessions' => 0, 'pageviews' => 0, 'events' => 0],
                'previous' => ['sessions' => 0, 'pageviews' => 0, 'events' => 0],
                'changes' => ['sessions' => 0, 'pageviews' => 0, 'events' => 0],
                'period_days' => 0
            ];
        }
    }

    /**
     * Calculate percentage change between two values
     */
    private function calculatePercentageChange(int $previous, int $current): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Get engagement statistics (lightbox opens, downloads)
     */
    public function getEngagementStats(string $startDate, string $endDate): array
    {
        try {
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);

            // Lightbox opens
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as lightbox_opens,
                    COUNT(DISTINCT session_id) as unique_users
                FROM analytics_events
                WHERE event_type = 'lightbox_open'
                AND occurred_at >= ? AND occurred_at < ?
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $lightbox = $stmt->fetch(PDO::FETCH_ASSOC);

            // Downloads
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as downloads,
                    COUNT(DISTINCT session_id) as unique_users
                FROM analytics_events
                WHERE event_type = 'download'
                AND occurred_at >= ? AND occurred_at < ?
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $downloads = $stmt->fetch(PDO::FETCH_ASSOC);

            // Top downloaded images
            $stmt = $this->db->prepare("
                SELECT
                    e.image_id,
                    e.album_id,
                    i.filename,
                    i.title as image_title,
                    a.title as album_title,
                    COUNT(*) as download_count
                FROM analytics_events e
                LEFT JOIN images i ON e.image_id = i.id
                LEFT JOIN albums a ON e.album_id = a.id
                WHERE e.event_type = 'download'
                AND e.image_id IS NOT NULL
                AND e.occurred_at >= ? AND e.occurred_at < ?
                GROUP BY e.image_id, e.album_id, i.filename, i.title, a.title
                ORDER BY download_count DESC
                LIMIT 10
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $topDownloads = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Most viewed in lightbox
            $stmt = $this->db->prepare("
                SELECT
                    e.image_id,
                    e.album_id,
                    i.filename,
                    i.title as image_title,
                    a.title as album_title,
                    COUNT(*) as view_count
                FROM analytics_events e
                LEFT JOIN images i ON e.image_id = i.id
                LEFT JOIN albums a ON e.album_id = a.id
                WHERE e.event_type = 'lightbox_open'
                AND e.image_id IS NOT NULL
                AND e.occurred_at >= ? AND e.occurred_at < ?
                GROUP BY e.image_id, e.album_id, i.filename, i.title, a.title
                ORDER BY view_count DESC
                LIMIT 10
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $topLightbox = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'lightbox' => [
                    'total' => (int)($lightbox['lightbox_opens'] ?? 0),
                    'unique_users' => (int)($lightbox['unique_users'] ?? 0)
                ],
                'downloads' => [
                    'total' => (int)($downloads['downloads'] ?? 0),
                    'unique_users' => (int)($downloads['unique_users'] ?? 0)
                ],
                'top_downloads' => $topDownloads,
                'top_lightbox_views' => $topLightbox
            ];
        } catch (\PDOException) {
            return [
                'lightbox' => ['total' => 0, 'unique_users' => 0],
                'downloads' => ['total' => 0, 'unique_users' => 0],
                'top_downloads' => [],
                'top_lightbox_views' => []
            ];
        }
    }

    /**
     * Get album access stats (pageviews + unique visitors) for a date range.
     */
    public function getAlbumAccessStats(string $startDate, string $endDate, int $limit = 20): array
    {
        try {
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);
            $limitClause = $this->sanitizeLimit((string)$limit, 500);
            $stmt = $this->db->prepare("
                SELECT
                    p.album_id,
                    a.title as album_title,
                    a.slug as album_slug,
                    COUNT(p.id) as pageviews,
                    COUNT(DISTINCT p.session_id) as unique_visitors
                FROM analytics_pageviews p
                LEFT JOIN albums a ON p.album_id = a.id
                WHERE p.album_id IS NOT NULL
                AND p.viewed_at >= ? AND p.viewed_at < ?
                GROUP BY p.album_id, a.title, a.slug
                ORDER BY pageviews DESC
                {$limitClause}
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Get album password unlock stats for a date range.
     */
    public function getAlbumPasswordAccessStats(string $startDate, string $endDate, int $limit = 20): array
    {
        try {
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);
            $limitClause = $this->sanitizeLimit((string)$limit, 500);
            $stmt = $this->db->prepare("
                SELECT
                    e.album_id,
                    a.title as album_title,
                    a.slug as album_slug,
                    COUNT(e.id) as password_unlocks,
                    COUNT(DISTINCT e.session_id) as unique_visitors
                FROM analytics_events e
                LEFT JOIN albums a ON e.album_id = a.id
                WHERE e.event_type = 'album_password_unlock'
                AND e.album_id IS NOT NULL
                AND e.occurred_at >= ? AND e.occurred_at < ?
                GROUP BY e.album_id, a.title, a.slug
                ORDER BY password_unlocks DESC
                {$limitClause}
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Get 404 error pages data
     */
    public function get404Stats(string $startDate, string $endDate): array
    {
        try {
            [$rangeStart, $rangeEnd] = $this->dateRangeBounds($startDate, $endDate);

            $stmt = $this->db->prepare("
                SELECT
                    page_url,
                    COUNT(*) as hits,
                    COUNT(DISTINCT session_id) as unique_visitors
                FROM analytics_pageviews
                WHERE page_type = '404'
                AND viewed_at >= ? AND viewed_at < ?
                GROUP BY page_url
                ORDER BY hits DESC
                LIMIT 20
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Total 404 count
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total
                FROM analytics_pageviews
                WHERE page_type = '404'
                AND viewed_at >= ? AND viewed_at < ?
            ");
            $stmt->execute([$rangeStart, $rangeEnd]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'total' => (int)($total['total'] ?? 0),
                'pages' => $pages
            ];
        } catch (\PDOException) {
            return ['total' => 0, 'pages' => []];
        }
    }
}
