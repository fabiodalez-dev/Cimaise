<?php
/**
 * Application Updater
 *
 * Handles version checking, downloading, and installing updates from GitHub releases.
 * Supports both SQLite and MySQL databases.
 *
 * Log output: storage/logs/app-*.log (filter with grep -i "updater")
 */

declare(strict_types=1);

namespace App\Support;

use PDO;
use Exception;
use ZipArchive;

class Updater
{
    /** Default GitHub API base URL (override with env UPDATER_API_BASE) */
    private const DEFAULT_API_BASE = 'https://api.github.com';

    /** Default GitHub repository slug (override with env UPDATER_REPO) */
    private const DEFAULT_REPO = 'fabiodalez-dev/cimaise';

    private Database $db;
    private string $rootPath;
    private string $backupPath;
    private string $tempPath;

    /** @var array<string> Files/directories to preserve during update */
    private array $preservePaths = [
        '.env',
        // 'storage/' is the catch-all: every existing file under storage/ is
        // user/runtime data and must survive updates. Shipping skeleton dirs
        // still works because copyDirectory() only skips targets that EXIST —
        // missing dirs/.gitkeep from the package are still created.
        'storage/',
        'storage/originals',
        'storage/backups',
        'storage/cache',
        'storage/logs',
        'storage/tmp',
        'storage/translations',
        'public/media',
        'public/.htaccess',
        'public/robots.txt',
        // Generated per-install from the uploaded logo (prefix matches:
        // favicon.ico, favicon-*.png, android-chrome-*.png, icon-*.png)
        'public/favicon',
        'public/apple-touch-icon.png',
        'public/android-chrome-',
        'public/icon-',
        'public/site.webmanifest',
        'public/sitemap.xml',
        'public/sitemap_index.xml',
        'database/database.sqlite',
        'database/database.sqlite-wal',
        'database/database.sqlite-shm',
        'CLAUDE.md',
    ];

    /**
     * Directories to skip completely during update.
     * @var array<string>
     */
    private array $skipPaths = [
        '.git',
        'node_modules',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->rootPath = dirname(__DIR__, 2);
        $this->backupPath = $this->rootPath . '/storage/backups';
        // Use storage/tmp instead of sys_get_temp_dir() for shared hosting compatibility
        $storageTmp = $this->rootPath . '/storage/tmp';

        // Ensure storage/tmp directory exists
        if (!is_dir($storageTmp)) {
            @mkdir($storageTmp, 0755, true);
        }

        // Clean up old update temp directories to free disk space
        $this->cleanupOldTempDirs($storageTmp);

        $this->tempPath = $storageTmp . '/cimaise_update_' . uniqid('', true);

        $this->debugLog('DEBUG', 'Updater initialized', [
            'rootPath' => $this->rootPath,
            'backupPath' => $this->backupPath,
            'tempPath' => $this->tempPath,
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'allow_url_fopen' => ini_get('allow_url_fopen'),
            'curl_available' => extension_loaded('curl'),
            'openssl_available' => extension_loaded('openssl'),
            'zip_available' => class_exists('ZipArchive'),
            'database_type' => $this->db->isSqlite() ? 'sqlite' : 'mysql',
        ]);

        // Ensure backup directory exists
        if (!is_dir($this->backupPath)) {
            if (!mkdir($this->backupPath, 0755, true) && !is_dir($this->backupPath)) {
                $this->debugLog('ERROR', 'Cannot create backup directory', [
                    'path' => $this->backupPath,
                    'error' => error_get_last()
                ]);
                throw new \RuntimeException(sprintf('Cannot create backup directory: %s', $this->backupPath));
            }
        }
    }

    /**
     * Debug logging helper - logs to both Logger and error_log
     */
    private function debugLog(string $level, string $message, array $context = []): void
    {
        $fullMessage = "[Updater] {$message}";

        // Always log to error_log for immediate visibility
        error_log($fullMessage . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Also log to Logger
        $method = strtolower($level);
        if (method_exists(Logger::class, $method)) {
            Logger::$method($fullMessage, $context, 'updater');
        } else {
            Logger::info($fullMessage, $context, 'updater');
        }
    }

    /**
     * Clean up old temporary update directories to free disk space.
     * Removes directories older than 1 hour.
     */
    private function cleanupOldTempDirs(string $tmpDir): void
    {
        if (!is_dir($tmpDir)) {
            return;
        }

        // Clean up old update temp directories
        $dirs = @glob($tmpDir . '/cimaise_update_*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return;
        }

        $now = time();
        $maxAge = 3600; // 1 hour

        foreach ($dirs as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime !== false && ($now - $mtime) > $maxAge) {
                $this->debugLog('DEBUG', 'Cleaning old temp directory', ['path' => $dir, 'age_hours' => round(($now - $mtime) / 3600, 2)]);
                $this->deleteDirectorySafe($dir);
            }
        }

        // Also clean up old app backup directories in storage/tmp (not storage/backups)
        $appBackups = @glob($tmpDir . '/cimaise_app_backup_*', GLOB_ONLYDIR);
        if ($appBackups !== false) {
            foreach ($appBackups as $backup) {
                $mtime = @filemtime($backup);
                if ($mtime !== false && ($now - $mtime) > $maxAge) {
                    $this->debugLog('DEBUG', 'Cleaning old app backup temp directory', ['path' => $backup]);
                    $this->deleteDirectorySafe($backup);
                }
            }
        }
    }

    /**
     * Safely delete a directory (for cleanup, doesn't throw on failure)
     */
    private function deleteDirectorySafe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = @scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectorySafe($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Get current installed version
     */
    public function getCurrentVersion(): string
    {
        $versionFile = $this->rootPath . '/version.json';

        $this->debugLog('DEBUG', 'Reading current version', ['file' => $versionFile]);

        if (!file_exists($versionFile)) {
            $this->debugLog('WARNING', 'version.json not found', ['path' => $versionFile]);
            return '0.0.0';
        }

        $content = file_get_contents($versionFile);
        if ($content === false) {
            $this->debugLog('ERROR', 'Cannot read version.json', [
                'path' => $versionFile,
                'error' => error_get_last()
            ]);
            return '0.0.0';
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['version'])) {
            $this->debugLog('ERROR', 'Invalid version.json', [
                'content' => $content,
                'json_error' => json_last_error_msg()
            ]);
            return '0.0.0';
        }

        $this->debugLog('INFO', 'Current version detected', ['version' => $data['version']]);
        return $data['version'];
    }

    /**
     * Check for available updates from GitHub
     * @return array{available: bool, current: string, latest: string, release: array|null, package_asset: bool, asset_name: string|null, error: string|null}
     */
    public function checkForUpdates(): array
    {
        $currentVersion = $this->getCurrentVersion();

        $this->debugLog('INFO', 'Checking for updates', [
            'current_version' => $currentVersion
        ]);

        try {
            $release = $this->getLatestRelease();

            if ($release === null) {
                $this->debugLog('WARNING', 'No release found on GitHub');
                return [
                    'available' => false,
                    'current' => $currentVersion,
                    'latest' => $currentVersion,
                    'release' => null,
                    'package_asset' => false,
                    'asset_name' => null,
                    'error' => 'Unable to fetch release information'
                ];
            }

            $latestVersion = ltrim($release['tag_name'], 'v');
            $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

            // Surface whether the release carries the installable package asset:
            // a release without it cannot be installed (the workflow failed) and
            // the UI must warn instead of offering a doomed update.
            $packageAsset = $this->findPackageAsset($release);

            $this->debugLog('INFO', 'Check completed', [
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'update_available' => $updateAvailable,
                'release_name' => $release['name'] ?? 'N/A',
                'published_at' => $release['published_at'] ?? 'N/A',
                'package_asset' => $packageAsset['name'] ?? null
            ]);

            return [
                'available' => $updateAvailable,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'release' => $release,
                'package_asset' => $packageAsset !== null,
                'asset_name' => $packageAsset['name'] ?? null,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Error checking updates', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return [
                'available' => false,
                'current' => $currentVersion,
                'latest' => $currentVersion,
                'release' => null,
                'package_asset' => false,
                'asset_name' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Read an environment value ($_ENV / $_SERVER / getenv) as string.
     */
    private function envValue(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($value) ? trim($value) : '';
    }

    /**
     * GitHub API base URL (env override: UPDATER_API_BASE) without trailing slash.
     */
    private function apiBase(): string
    {
        $base = $this->envValue('UPDATER_API_BASE');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            $base = self::DEFAULT_API_BASE;
        }
        return rtrim($base, '/');
    }

    /**
     * GitHub repository slug "owner/name" (env override: UPDATER_REPO).
     */
    private function repoSlug(): string
    {
        $repo = $this->envValue('UPDATER_REPO');
        if ($repo === '' || !preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            $repo = self::DEFAULT_REPO;
        }
        return $repo;
    }

    /**
     * Optional GitHub bearer token. The env var UPDATER_GITHUB_TOKEN wins;
     * otherwise an admin-configured token, stored encrypted-at-rest in the
     * settings table (key `updater.github_token`, "ENC:" wire format), is
     * decrypted on demand. Decrypt failure / missing key yields no token —
     * never a plaintext leak.
     */
    private function githubToken(): string
    {
        $env = $this->envValue('UPDATER_GITHUB_TOKEN');
        if ($env !== '') {
            return $env;
        }

        try {
            $stored = (new \App\Services\SettingsService($this->db))->get('updater.github_token');
            if (is_string($stored) && $stored !== '') {
                $plain = \App\Support\SecretBox::decrypt($stored);
                if (is_string($plain) && $plain !== '') {
                    return $plain;
                }
            }
        } catch (\Throwable) {
            // Settings unavailable (e.g. mid-install) — fall through to none.
        }

        return '';
    }

    /**
     * Whether a URL targets the GitHub API host — the ONLY host that may
     * receive the Authorization bearer token. Release asset downloads
     * (browser_download_url and the .sha256 sidecar) resolve to CDN hosts
     * (objects.githubusercontent.com) via redirects; sending the token there
     * would leak it to non-API hosts, so those requests go anonymous
     * (release assets on public repos don't need auth anyway).
     */
    private function isApiUrl(string $url): bool
    {
        $apiHost = parse_url($this->apiBase(), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);

        return is_string($apiHost) && $apiHost !== ''
            && is_string($urlHost) && $urlHost !== ''
            && strcasecmp($apiHost, $urlHost) === 0;
    }

    /**
     * Whether the prerelease (RC) channel is enabled via environment opt-in.
     * UPDATER_ALLOW_PRERELEASE=1/true/yes/on or UPDATER_CHANNEL != "stable".
     */
    private function prereleaseChannelEnabled(): bool
    {
        $allow = strtolower($this->envValue('UPDATER_ALLOW_PRERELEASE'));
        if (in_array($allow, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $channel = strtolower($this->envValue('UPDATER_CHANNEL'));
        return $channel !== '' && $channel !== 'stable';
    }

    /**
     * Build GitHub request headers, optionally with Authorization bearer token.
     * @return array<string>
     */
    private function githubHeaders(string $accept = 'application/vnd.github.v3+json', bool $withAuth = true): array
    {
        $headers = [
            'User-Agent: Cimaise-Updater/1.0',
            'Accept: ' . $accept,
        ];

        $token = $this->githubToken();
        if ($withAuth && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Low-level HTTPS GET against the GitHub API with optional bearer token.
     * On 401/403 with a token configured, retries once without the token
     * (an invalid/expired token must not break public-repo updates).
     *
     * @return array{status: int, body: string|false}
     */
    private function httpGet(string $url, string $accept = 'application/vnd.github.v3+json'): array
    {
        // SECURITY: token scoped to the API host only (see isApiUrl).
        $attemptWithAuth = $this->githubToken() !== '' && $this->isApiUrl($url);
        $withAuth = $attemptWithAuth;

        do {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $this->githubHeaders($accept, $withAuth),
                    'timeout' => 30,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    // SECURITY: SSL verification stays ON, always. No insecure fallback.
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ]
            ]);

            $body = @file_get_contents($url, false, $context);
            // $http_response_header is populated by file_get_contents() over
            // HTTP stream wrappers and is always defined afterwards.
            $responseHeaders = $http_response_header;

            $status = 0;
            if (!empty($responseHeaders[0]) && preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $responseHeaders[0], $matches)) {
                $status = (int)$matches[1];
            }

            if ($withAuth && in_array($status, [401, 403], true)) {
                $this->debugLog('WARNING', 'GitHub auth failed, retrying without token', [
                    'url' => $url,
                    'status' => $status
                ]);
                $withAuth = false;
                continue;
            }

            return ['status' => $status, 'body' => $body];
        } while (true);
    }

    /**
     * Get latest release from GitHub API.
     *
     * Default (stable channel): /releases/latest — GitHub natively excludes
     * drafts and prereleases. With the RC channel enabled (env opt-in), the
     * full release list is walked and the first non-draft entry (prerelease
     * included) is returned.
     */
    private function getLatestRelease(): ?array
    {
        if ($this->prereleaseChannelEnabled()) {
            $this->debugLog('INFO', 'Prerelease channel enabled - walking the release list', [
                'repo' => $this->repoSlug()
            ]);

            foreach ($this->getAllReleases(15) as $release) {
                if (!empty($release['draft'])) {
                    continue;
                }
                if (!isset($release['tag_name'])) {
                    continue;
                }
                return $release;
            }

            return null;
        }

        $url = $this->apiBase() . "/repos/{$this->repoSlug()}/releases/latest";

        $this->debugLog('INFO', 'GitHub API request - latest release', [
            'url' => $url,
            'repo' => $this->repoSlug()
        ]);

        return $this->makeGitHubRequest($url);
    }

    /**
     * Make HTTP request to GitHub API with detailed logging
     */
    private function makeGitHubRequest(string $url): ?array
    {
        $this->debugLog('DEBUG', 'Preparing HTTP request', [
            'url' => $url,
            'method' => 'GET'
        ]);

        // SECURITY: no insecure TLS fallback (see httpGet). A failed certificate
        // check must fail the request — silently disabling verification would
        // let a MITM serve attacker-controlled data.
        $result = $this->httpGet($url);
        $response = $result['body'];
        $statusCode = $result['status'];

        $this->debugLog('DEBUG', 'HTTP response received', [
            'response_length' => $response !== false ? strlen($response) : 0,
            'status_code' => $statusCode
        ]);

        if ($response === false) {
            $error = error_get_last();
            $this->debugLog('ERROR', 'HTTP request failed', [
                'url' => $url,
                'error' => $error
            ]);

            $this->diagnoseConnectionProblem($url);

            throw new Exception('Cannot connect to GitHub: ' . ($error['message'] ?? 'Unknown error'));
        }

        if ($statusCode >= 400) {
            $this->debugLog('ERROR', 'GitHub API returned error', [
                'status_code' => $statusCode,
                'response' => $response
            ]);

            $errorData = json_decode($response, true);
            $errorMessage = $errorData['message'] ?? 'Unknown GitHub error';

            throw new Exception("GitHub API error ({$statusCode}): {$errorMessage}");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->debugLog('ERROR', 'JSON parsing error', [
                'json_error' => json_last_error_msg()
            ]);
            return null;
        }

        if (!is_array($data) || !isset($data['tag_name'])) {
            $this->debugLog('WARNING', 'GitHub response missing tag_name', [
                'keys' => is_array($data) ? array_keys($data) : 'not_array'
            ]);
            return null;
        }

        $this->debugLog('INFO', 'Release found', [
            'tag_name' => $data['tag_name'],
            'name' => $data['name'] ?? 'N/A',
            'assets_count' => count($data['assets'] ?? [])
        ]);

        return $data;
    }

    /**
     * Diagnose connection problems
     */
    private function diagnoseConnectionProblem(string $url): void
    {
        $this->debugLog('INFO', '=== CONNECTION DIAGNOSIS ===');

        $host = parse_url($url, PHP_URL_HOST);
        $ip = @gethostbyname($host);
        $this->debugLog('DEBUG', 'DNS lookup', [
            'host' => $host,
            'resolved_ip' => $ip,
            'dns_ok' => ($ip !== $host)
        ]);

        $this->debugLog('DEBUG', 'PHP config check', [
            'allow_url_fopen' => ini_get('allow_url_fopen'),
            'default_socket_timeout' => ini_get('default_socket_timeout'),
            'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'N/A'
        ]);
    }

    /**
     * Download file using cURL with file_get_contents fallback
     *
     * @param string $url The URL to download
     * @return array{success: bool, content: string|null, error: string|null, method: string}
     */
    private function downloadFile(string $url): array
    {
        // Try cURL first (preferred for reliability)
        if (extension_loaded('curl')) {
            $this->debugLog('DEBUG', 'Attempting download with cURL', ['url' => $url]);

            // SECURITY: token scoped to the API host only (see isApiUrl) —
            // asset downloads redirect to CDN hosts and must stay anonymous.
            $withAuth = $this->githubToken() !== '' && $this->isApiUrl($url);

            do {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_USERAGENT => 'Cimaise-Updater/1.0',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_BUFFERSIZE => 1024 * 1024,  // 1MB buffer
                    CURLOPT_HTTPHEADER => $this->githubHeaders('application/octet-stream', $withAuth),
                ]);

                $content = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $errno = curl_errno($ch);
                curl_close($ch);

                if ($content !== false && $httpCode >= 200 && $httpCode < 400) {
                    $this->debugLog('INFO', 'cURL download successful', [
                        'http_code' => $httpCode,
                        'size_bytes' => strlen($content)
                    ]);
                    return [
                        'success' => true,
                        'content' => $content,
                        'error' => null,
                        'method' => 'curl'
                    ];
                }

                // Invalid/expired token must not break public downloads:
                // retry once without Authorization on 401/403.
                if ($withAuth && in_array($httpCode, [401, 403], true)) {
                    $this->debugLog('WARNING', 'Download auth failed, retrying without token', [
                        'http_code' => $httpCode
                    ]);
                    $withAuth = false;
                    continue;
                }

                break;
            } while (true);

            // SECURITY: no insecure TLS fallback on download. A certificate
            // failure here would allow a MITM to replace the update archive
            // (full code execution on install), so we never disable verification.

            $this->debugLog('WARNING', 'cURL download failed, trying file_get_contents fallback', [
                'http_code' => $httpCode,
                'error' => $error,
                'errno' => $errno
            ]);
        }

        // Fallback to file_get_contents
        if (!ini_get('allow_url_fopen')) {
            return [
                'success' => false,
                'content' => null,
                'error' => 'Neither cURL nor allow_url_fopen is available for downloads',
                'method' => 'none'
            ];
        }

        $this->debugLog('DEBUG', 'Attempting download with file_get_contents', ['url' => $url]);

        // SECURITY: token scoped to the API host only (see isApiUrl).
        $withAuth = $this->githubToken() !== '' && $this->isApiUrl($url);

        do {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $this->githubHeaders('application/octet-stream', $withAuth),
                    'timeout' => 300,
                    'follow_location' => true,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ]
            ]);

            $content = @file_get_contents($url, false, $context);
            // $http_response_header is populated by file_get_contents() over
            // HTTP stream wrappers and is always defined afterwards.
            $responseHeaders = $http_response_header;

            $statusCode = 0;
            if (!empty($responseHeaders[0]) && preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $responseHeaders[0], $matches)) {
                $statusCode = (int)$matches[1];
            }

            if ($withAuth && in_array($statusCode, [401, 403], true)) {
                $this->debugLog('WARNING', 'Download auth failed (file_get_contents), retrying without token', [
                    'status' => $statusCode
                ]);
                $withAuth = false;
                continue;
            }

            break;
        } while (true);

        // SECURITY: no insecure TLS fallback (see httpGet). A failed
        // certificate check fails the download rather than trusting a MITM.

        if ($content === false || $statusCode >= 400) {
            $error = error_get_last();
            return [
                'success' => false,
                'content' => null,
                'error' => $statusCode >= 400
                    ? "Download failed with HTTP status {$statusCode}"
                    : ($error['message'] ?? 'Unknown download error'),
                'method' => 'file_get_contents'
            ];
        }

        $this->debugLog('INFO', 'file_get_contents download successful', [
            'size_bytes' => strlen($content)
        ]);

        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'method' => 'file_get_contents'
        ];
    }

    /**
     * Get all releases for display
     * @return array<array>
     */
    public function getAllReleases(int $limit = 10): array
    {
        $url = $this->apiBase() . "/repos/{$this->repoSlug()}/releases?per_page={$limit}";

        $this->debugLog('INFO', 'Fetching all releases', ['url' => $url, 'limit' => $limit]);

        // SECURITY: no insecure TLS fallback (see httpGet).
        $result = $this->httpGet($url);

        if ($result['body'] === false || $result['status'] >= 400) {
            $this->debugLog('ERROR', 'Cannot fetch releases', [
                'status' => $result['status'],
                'error' => error_get_last()
            ]);
            return [];
        }

        $releases = json_decode($result['body'], true);
        if (!is_array($releases)) {
            $releases = [];
        }

        // Stable channel: prereleases are invisible (update check AND changelog).
        // The env-gated RC channel re-enables them (Pinakes parity).
        if (!$this->prereleaseChannelEnabled()) {
            $releases = array_values(array_filter(
                $releases,
                fn($r) => is_array($r) && empty($r['prerelease'])
            ));
        }

        $this->debugLog('INFO', 'Releases fetched', [
            'count' => count($releases),
            'versions' => array_map(fn($r) => $r['tag_name'] ?? 'unknown', $releases)
        ]);

        return $releases;
    }

    /**
     * Download and extract update package
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function downloadUpdate(string $version): array
    {
        $this->debugLog('INFO', '=== STARTING UPDATE DOWNLOAD ===', ['target_version' => $version]);

        try {
            $this->debugLog('DEBUG', 'Fetching release info for version', ['version' => $version]);
            $release = $this->getReleaseByVersion($version);

            if ($release === null) {
                $this->debugLog('ERROR', 'Release not found', ['version' => $version]);
                throw new Exception('Version not found');
            }

            $this->debugLog('INFO', 'Release found', [
                'tag' => $release['tag_name'],
                'name' => $release['name'] ?? 'N/A',
                'assets' => array_map(fn($a) => $a['name'], $release['assets'] ?? [])
            ]);

            // The packaged asset (cimaise-vX.Y.Z.zip, built and verified by the
            // release workflow) is REQUIRED. The GitHub zipball is just the git
            // tree: it contains neither vendor/ (production composer deps) nor
            // the built frontend assets — installing it would brick the app.
            // Therefore there is deliberately NO zipball fallback.
            $packageAsset = $this->findPackageAsset($release);

            if ($packageAsset === null) {
                $this->debugLog('ERROR', 'Release has no installable package asset', [
                    'release' => $release['tag_name'],
                    'assets' => array_map(fn($a) => $a['name'], $release['assets'] ?? [])
                ]);
                throw new \RuntimeException(sprintf(
                    'Release %s has no installable package asset (cimaise-*.zip) — the release workflow may have failed. Update aborted.',
                    $release['tag_name']
                ));
            }

            $downloadUrl = $packageAsset['browser_download_url'];
            $expectedDigest = $packageAsset['digest'] ?? null; // e.g. "sha256:abc..."

            $this->debugLog('INFO', 'Package asset selected', [
                'name' => $packageAsset['name'],
                'url' => $downloadUrl,
                'digest' => $expectedDigest
            ]);

            // Create temp directory
            if (!is_dir($this->tempPath)) {
                if (!mkdir($this->tempPath, 0755, true) && !is_dir($this->tempPath)) {
                    throw new Exception('Cannot create temporary directory');
                }
            }

            $zipPath = $this->tempPath . '/update.zip';

            // Download the file using cURL with file_get_contents fallback
            $this->debugLog('INFO', 'Starting file download...', ['url' => $downloadUrl]);

            $startTime = microtime(true);
            $downloadResult = $this->downloadFile($downloadUrl);
            $downloadTime = round(microtime(true) - $startTime, 2);

            if (!$downloadResult['success']) {
                throw new Exception('Download failed: ' . $downloadResult['error']);
            }

            $fileContent = $downloadResult['content'];
            $fileSize = strlen($fileContent);
            $this->debugLog('INFO', 'Download completed', [
                'size_bytes' => $fileSize,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
                'time_seconds' => $downloadTime,
                'method' => $downloadResult['method']
            ]);

            if ($fileSize < 1000) {
                throw new Exception('Update file invalid (too small)');
            }

            // SECURITY: integrity verification is MANDATORY. The downloaded bytes
            // must match a published sha256 before we ever extract/install — this
            // is the supply-chain guard that TLS alone does not provide (e.g. a
            // tampered release artifact). Sources, in order of preference:
            //   1) the GitHub asset "digest" field ("sha256:<hex>")
            //   2) the companion "<asset>.sha256" sidecar asset
            // If NEITHER is available the update is refused.
            $expectedHash = null;

            if (is_string($expectedDigest) && str_starts_with($expectedDigest, 'sha256:')) {
                $expectedHash = strtolower(substr($expectedDigest, strlen('sha256:')));
                $this->debugLog('INFO', 'Using GitHub asset digest for integrity check');
            } else {
                $this->debugLog('WARNING', 'No digest field on asset, trying .sha256 sidecar', [
                    'version' => $version
                ]);
                $expectedHash = $this->fetchSidecarChecksum($release, $packageAsset['name']);
            }

            if ($expectedHash === null) {
                $this->debugLog('ERROR', 'No integrity source available for update asset', [
                    'version' => $version,
                    'asset' => $packageAsset['name']
                ]);
                throw new \RuntimeException(
                    'Update integrity check impossible: the release publishes neither a digest nor a .sha256 sidecar. Refusing to install an unverified package.'
                );
            }

            $actualHash = hash('sha256', $fileContent);
            if (!hash_equals($expectedHash, $actualHash)) {
                $this->debugLog('ERROR', 'Update archive digest mismatch', [
                    'expected' => $expectedHash,
                    'actual' => $actualHash
                ]);
                throw new Exception('Update integrity check failed: archive digest mismatch');
            }
            $this->debugLog('INFO', 'Update archive digest verified (sha256)', []);

            // Save file
            $bytesWritten = file_put_contents($zipPath, $fileContent);
            if ($bytesWritten === false) {
                throw new Exception('Cannot save update file');
            }

            // Verify it's a valid zip and extract with retry mechanism
            $extractPath = $this->tempPath . '/extracted';
            $maxRetries = 3;
            $extractionSuccess = false;
            $lastError = 'Unknown error';

            $zipErrors = [
                ZipArchive::ER_EXISTS => 'File already exists',
                ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                ZipArchive::ER_INVAL => 'Invalid argument',
                ZipArchive::ER_MEMORY => 'Malloc failure',
                ZipArchive::ER_NOENT => 'No such file',
                ZipArchive::ER_NOZIP => 'Not a zip archive',
                ZipArchive::ER_OPEN => 'Can\'t open file',
                ZipArchive::ER_READ => 'Read error',
                ZipArchive::ER_SEEK => 'Seek error',
            ];

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $this->debugLog('DEBUG', 'ZIP extraction attempt', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'memory_limit' => ini_get('memory_limit')
                ]);

                $zip = new ZipArchive();
                $zipOpenResult = $zip->open($zipPath);

                if ($zipOpenResult !== true) {
                    $lastError = 'Invalid update file: ' . ($zipErrors[$zipOpenResult] ?? 'Unknown error');
                    $this->debugLog('WARNING', 'ZIP open failed', [
                        'attempt' => $attempt,
                        'error' => $lastError
                    ]);
                } else {
                    $this->debugLog('INFO', 'ZIP valid', [
                        'num_files' => $zip->numFiles,
                        'status' => $zip->status
                    ]);

                    // Ensure extract directory exists
                    if (!is_dir($extractPath)) {
                        mkdir($extractPath, 0755, true);
                    }

                    if ($zip->extractTo($extractPath)) {
                        $zip->close();
                        $extractionSuccess = true;
                        $this->debugLog('INFO', 'ZIP extraction successful', ['attempt' => $attempt]);
                        break;
                    }

                    $lastError = 'Package extraction failed';
                    $zip->close();
                    $this->debugLog('WARNING', 'ZIP extraction failed', [
                        'attempt' => $attempt,
                        'error' => $lastError
                    ]);
                }

                // If not last attempt, increase memory and retry
                if ($attempt < $maxRetries) {
                    $currentLimit = ini_get('memory_limit');
                    if ($currentLimit !== '-1') {
                        $currentBytes = $this->parseMemoryLimit($currentLimit);
                        $newLimit = (int)($currentBytes / 1024 / 1024) * 2;
                        @ini_set('memory_limit', $newLimit . 'M');
                        $this->debugLog('DEBUG', 'Increased memory limit', [
                            'from' => $currentLimit,
                            'to' => $newLimit . 'M'
                        ]);
                    }
                    // Small delay before retry
                    sleep(2);
                }
            }

            if (!$extractionSuccess) {
                throw new Exception($lastError);
            }

            // Find the actual content directory (GitHub adds a prefix)
            $dirs = glob($extractPath . '/*', GLOB_ONLYDIR);
            $contentPath = count($dirs) === 1 ? $dirs[0] : $extractPath;

            // Verify package structure
            $requiredFiles = ['version.json', 'app', 'public'];
            $missingFiles = [];

            foreach ($requiredFiles as $required) {
                if (!file_exists($contentPath . '/' . $required)) {
                    $missingFiles[] = $required;
                }
            }

            if (!empty($missingFiles)) {
                $this->debugLog('ERROR', 'Incomplete package - missing required files', ['missing' => $missingFiles]);
                throw new Exception('Invalid update package: missing ' . implode(', ', $missingFiles));
            }

            // Defense in depth: the package's own version.json must match the
            // requested version. The release workflow already gates tag ==
            // version.json at build time, but a wrong asset uploaded under a
            // tag would otherwise install silently while logging/migrations
            // stay keyed to $version.
            $this->assertPackageVersion($contentPath, $version);

            return [
                'success' => true,
                'path' => $contentPath,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Download/extraction error', [
                'message' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get release by version tag
     */
    private function getReleaseByVersion(string $version): ?array
    {
        $tag = strpos($version, 'v') === 0 ? $version : 'v' . $version;
        $url = $this->apiBase() . "/repos/{$this->repoSlug()}/releases/tags/{$tag}";

        try {
            return $this->makeGitHubRequest($url);
        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Error fetching release by version', [
                'version' => $version,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Reject an extracted package whose own version.json does not declare the
     * requested version. Migrations and update_logs are keyed to the requested
     * version, so installing a mismatched tree would silently desync them.
     *
     * @throws Exception on unreadable/invalid version.json or version mismatch
     */
    private function assertPackageVersion(string $contentPath, string $expectedVersion): void
    {
        $raw = @file_get_contents($contentPath . '/version.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $found = is_array($data) && isset($data['version']) && is_string($data['version'])
            ? $data['version']
            : null;

        if ($found === null) {
            $this->debugLog('ERROR', 'Package version.json unreadable or missing version field', [
                'expected' => $expectedVersion,
            ]);
            throw new Exception('Invalid update package: version.json is unreadable or has no version field');
        }

        // Normalize a leading 'v' (tags are vX.Y.Z, version.json is X.Y.Z) —
        // same convention as checkForUpdates()'s ltrim on tag_name.
        if (ltrim($found, 'v') !== ltrim($expectedVersion, 'v')) {
            $this->debugLog('ERROR', 'Package version mismatch', [
                'expected' => $expectedVersion,
                'found' => $found,
            ]);
            throw new Exception(sprintf(
                'Update package version mismatch: requested %s but the package declares %s — refusing to install',
                $expectedVersion,
                $found
            ));
        }
    }

    /**
     * Find the installable package asset (cimaise-*.zip) in a release payload.
     * Excludes the ".zip.sha256" sidecar by matching on the ".zip" suffix.
     *
     * @param array<string, mixed>|null $release Release payload from the GitHub API
     * @return array<string, mixed>|null The matching asset entry, or null when absent
     */
    private function findPackageAsset(?array $release): ?array
    {
        if ($release === null) {
            return null;
        }

        foreach ($release['assets'] ?? [] as $asset) {
            if (!is_array($asset) || !isset($asset['name'], $asset['browser_download_url'])) {
                continue;
            }
            if (preg_match('/cimaise.*\.zip$/i', (string)$asset['name'])) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Fetch and parse the "<asset>.sha256" sidecar asset of a release.
     * The sidecar uses the shasum format: "<hex>  <filename>".
     *
     * @param array<string, mixed> $release Release payload from the GitHub API
     * @return string|null Lowercase sha256 hex, or null when unavailable/invalid
     */
    private function fetchSidecarChecksum(array $release, string $assetName): ?string
    {
        $sidecarName = $assetName . '.sha256';

        foreach ($release['assets'] ?? [] as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? '') !== $sidecarName) {
                continue;
            }

            $url = $asset['browser_download_url'] ?? '';
            if ($url === '') {
                return null;
            }

            $result = $this->downloadFile($url);
            if (!$result['success'] || !is_string($result['content'])) {
                $this->debugLog('WARNING', 'Cannot download .sha256 sidecar', [
                    'sidecar' => $sidecarName,
                    'error' => $result['error']
                ]);
                return null;
            }

            // First 64-char hex token in the file is the checksum
            if (preg_match('/\b([a-f0-9]{64})\b/i', $result['content'], $matches)) {
                $this->debugLog('INFO', 'Sidecar checksum parsed', ['sidecar' => $sidecarName]);
                return strtolower($matches[1]);
            }

            $this->debugLog('WARNING', 'Sidecar has no parseable sha256 token', [
                'sidecar' => $sidecarName
            ]);
            return null;
        }

        $this->debugLog('WARNING', 'No .sha256 sidecar asset on release', ['sidecar' => $sidecarName]);
        return null;
    }

    /**
     * Create backup before update
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function createBackup(): array
    {
        $logId = null;

        $this->debugLog('INFO', '=== STARTING BACKUP ===');

        try {
            $timestamp = date('Y-m-d_His');
            $backupDir = $this->backupPath . '/update_' . $timestamp;

            if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                throw new Exception('Cannot create backup directory');
            }

            // Log the backup start
            $logId = $this->logUpdateStart($this->getCurrentVersion(), 'backup', $backupDir);

            // Backup database
            $this->debugLog('INFO', 'Starting database backup');
            $dbBackupResult = $this->backupDatabase($backupDir . '/database.sql');

            if (!$dbBackupResult['success']) {
                throw new Exception($dbBackupResult['error']);
            }

            // Mark backup as complete
            $this->logUpdateComplete($logId, true);

            $this->debugLog('INFO', 'Backup completed successfully', [
                'path' => $backupDir
            ]);

            return [
                'success' => true,
                'path' => $backupDir,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Backup error', [
                'message' => $e->getMessage()
            ]);

            if ($logId !== null) {
                $this->logUpdateComplete($logId, false, $e->getMessage());
            }

            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get list of available backups
     * @return array<array{name: string, path: string, size: int, date: string}>
     */
    public function getBackupList(): array
    {
        $backups = [];

        if (!is_dir($this->backupPath)) {
            return $backups;
        }

        $dirs = glob($this->backupPath . '/update_*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $dbFile = $dir . '/database.sql';
            $size = file_exists($dbFile) ? filesize($dbFile) : 0;

            $dateStr = str_replace('update_', '', $name);
            $dateStr = str_replace('_', ' ', $dateStr);

            $backups[] = [
                'name' => $name,
                'path' => $dir,
                'size' => $size,
                'date' => $dateStr,
                'created_at' => filemtime($dir)
            ];
        }

        usort($backups, fn($a, $b) => $b['created_at'] - $a['created_at']);

        return $backups;
    }

    /**
     * Delete a backup
     * @return array{success: bool, error: string|null}
     */
    public function deleteBackup(string $backupName): array
    {
        if (preg_match('/[\/\\\\]|\.\./', $backupName)) {
            return ['success' => false, 'error' => 'Invalid backup name'];
        }

        $backupPath = $this->backupPath . '/' . $backupName;

        if (!is_dir($backupPath)) {
            return ['success' => false, 'error' => 'Backup not found'];
        }

        $realBackupPath = realpath($backupPath);
        $realBackupDir = realpath($this->backupPath);

        if ($realBackupPath === false || $realBackupDir === false ||
            strpos($realBackupPath, $realBackupDir) !== 0) {
            return ['success' => false, 'error' => 'Invalid backup path'];
        }

        try {
            $this->deleteDirectory($backupPath);
            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get backup file path for download
     * @return array{success: bool, path: string|null, filename: string|null, error: string|null}
     */
    public function getBackupDownloadPath(string $backupName): array
    {
        if (preg_match('/[\/\\\\]|\.\./', $backupName)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Invalid backup name'];
        }

        $backupPath = $this->backupPath . '/' . $backupName;
        $dbFile = $backupPath . '/database.sql';

        if (!file_exists($dbFile)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Backup file not found'];
        }

        $realDbFile = realpath($dbFile);
        $realBackupDir = realpath($this->backupPath);

        if ($realDbFile === false || $realBackupDir === false ||
            strpos($realDbFile, $realBackupDir) !== 0) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => 'Invalid backup path'];
        }

        return [
            'success' => true,
            'path' => $realDbFile,
            'filename' => $backupName . '.sql',
            'error' => null
        ];
    }

    /**
     * Backup database to file - supports both SQLite and MySQL
     * @return array{success: bool, error: string|null}
     */
    private function backupDatabase(string $filepath): array
    {
        try {
            $this->debugLog('INFO', 'Starting database backup', [
                'filepath' => $filepath,
                'type' => $this->db->isSqlite() ? 'sqlite' : 'mysql'
            ]);

            if ($this->db->isSqlite()) {
                return $this->backupSqliteDatabase($filepath);
            } else {
                return $this->backupMysqlDatabase($filepath);
            }

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Database backup error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Backup SQLite database
     */
    private function backupSqliteDatabase(string $filepath): array
    {
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new Exception('Cannot open backup file for writing');
        }

        try {
            $pdo = $this->db->pdo();

            fwrite($handle, "-- Cimaise SQLite Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Version: " . $this->getCurrentVersion() . "\n\n");

            // Get all tables
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // Validate table name (alphanumeric and underscore only)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                    $this->debugLog('WARNING', 'Skipping table with invalid name', ['table' => $table]);
                    continue;
                }

                // Get CREATE TABLE statement using prepared statement
                $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
                $stmt->execute([$table]);
                $createStmt = $stmt->fetchColumn();
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $createStmt . ";\n\n");

                // Get data (table name validated above)
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $columns = array_keys($row);
                    $values = array_map(function ($val) use ($pdo) {
                        if ($val === null) return 'NULL';
                        return $pdo->quote((string)$val);
                    }, array_values($row));

                    fwrite($handle, "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n");
                }
                fwrite($handle, "\n");
            }

            fclose($handle);

            $this->debugLog('INFO', 'SQLite backup completed', [
                'filepath' => $filepath,
                'tables' => count($tables)
            ]);

            return ['success' => true, 'error' => null];

        } catch (Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            throw $e;
        }
    }

    /**
     * Backup MySQL database
     */
    private function backupMysqlDatabase(string $filepath): array
    {
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new Exception('Cannot open backup file for writing');
        }

        try {
            $pdo = $this->db->pdo();

            fwrite($handle, "-- Cimaise MySQL Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Version: " . $this->getCurrentVersion() . "\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            // Get all tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // Validate table name (alphanumeric and underscore only)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                    $this->debugLog('WARNING', 'Skipping table with invalid name', ['table' => $table]);
                    continue;
                }

                // Get CREATE TABLE statement (table name validated above)
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $createStmt['Create Table'] . ";\n\n");

                // Get data (table name validated above)
                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $values = array_map(function ($val) use ($pdo) {
                        if ($val === null) return 'NULL';
                        return $pdo->quote((string)$val);
                    }, $row);

                    fwrite($handle, "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n");
                }
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);

            $this->debugLog('INFO', 'MySQL backup completed', [
                'filepath' => $filepath,
                'tables' => count($tables)
            ]);

            return ['success' => true, 'error' => null];

        } catch (Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            throw $e;
        }
    }

    /**
     * Install update from extracted path
     *
     * @param string|null $dbBackupPath Path of the pre-update DB backup
     *                    (from createBackup()), recorded in update_logs.
     * @return array{success: bool, error: string|null}
     */
    public function installUpdate(string $sourcePath, string $targetVersion, ?string $dbBackupPath = null): array
    {
        $appBackupPath = null;
        $logId = null;

        $this->debugLog('INFO', '=== STARTING UPDATE INSTALLATION ===', [
            'source' => $sourcePath,
            'target_version' => $targetVersion
        ]);

        try {
            $currentVersion = $this->getCurrentVersion();

            // Verify source exists
            if (!is_dir($sourcePath)) {
                throw new Exception('Source directory not found');
            }

            // Verify it's a valid package
            $requiredPaths = ['version.json', 'app', 'public'];
            foreach ($requiredPaths as $required) {
                if (!file_exists($sourcePath . '/' . $required)) {
                    throw new Exception(sprintf('Invalid update package: missing %s', $required));
                }
            }

            // Log update start (with the real DB backup path when available)
            $logId = $this->logUpdateStart($currentVersion, $targetVersion, $dbBackupPath);

            // Backup current app files
            $this->debugLog('INFO', 'Backing up application files for rollback');
            $appBackupPath = $this->backupAppFiles();

            // Copy files
            $this->debugLog('INFO', 'Copying update files');
            $this->copyDirectory($sourcePath, $this->rootPath);

            // Clean up orphan files
            $this->debugLog('INFO', 'Cleaning orphan files');
            $this->cleanupOrphanFiles($sourcePath);

            // Run database migrations
            $this->debugLog('INFO', 'Running database migrations', [
                'from' => $currentVersion,
                'to' => $targetVersion
            ]);
            $migrationResult = $this->runMigrations($currentVersion, $targetVersion);

            if (!$migrationResult['success']) {
                throw new Exception($migrationResult['error']);
            }

            // Fix file permissions
            $this->debugLog('INFO', 'Fixing file permissions');
            $this->fixPermissions();

            // Mark update as complete
            $this->logUpdateComplete($logId, true);

            // Cleanup
            $this->cleanup();
            if (is_dir($appBackupPath)) {
                $this->deleteDirectory($appBackupPath);
            }

            $this->debugLog('INFO', '=== INSTALLATION COMPLETED SUCCESSFULLY ===');

            return [
                'success' => true,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Installation error', [
                'message' => $e->getMessage()
            ]);

            // Attempt rollback
            if ($appBackupPath !== null && is_dir($appBackupPath)) {
                try {
                    $this->debugLog('WARNING', 'Attempting rollback', ['backup' => $appBackupPath]);
                    $this->restoreAppFiles($appBackupPath);
                    $this->debugLog('INFO', 'Rollback completed');
                } catch (Exception $rollbackError) {
                    $this->debugLog('ERROR', 'Rollback failed', [
                        'error' => $rollbackError->getMessage()
                    ]);
                }
            }

            if ($logId !== null) {
                $this->logUpdateComplete($logId, false, $e->getMessage());
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Directories saved/restored for atomic rollback.
     * @return array<string>
     */
    private function rollbackDirs(): array
    {
        return [
            'app',
            'public/assets',
            'vendor',
            'database/migrations',
        ];
    }

    /**
     * Single files saved/restored for atomic rollback.
     * @return array<string>
     */
    private function rollbackFiles(): array
    {
        $files = [
            'version.json',
            'index.php',
            '.htaccess',
            'public/index.php',
            // Installed by copyDirectory() and invoked at runtime (cache,
            // settings, blur jobs) — must be restored on a failed update.
            'bin/console',
        ];

        // database/schema.*.sql (sqlite + mysql, future-proof against new ones)
        $schemas = glob($this->rootPath . '/database/schema.*.sql');
        if (is_array($schemas)) {
            foreach ($schemas as $schema) {
                $files[] = 'database/' . basename($schema);
            }
        }

        return $files;
    }

    /**
     * Backup application files for atomic rollback
     */
    private function backupAppFiles(): string
    {
        $timestamp = date('Y-m-d_His');
        // Use storage/tmp instead of sys_get_temp_dir() for shared hosting compatibility
        $backupPath = $this->rootPath . '/storage/tmp/cimaise_app_backup_' . $timestamp;

        if (!mkdir($backupPath, 0755, true) && !is_dir($backupPath)) {
            throw new Exception('Cannot create application backup directory');
        }

        foreach ($this->rollbackDirs() as $dir) {
            $sourcePath = $this->rootPath . '/' . $dir;
            $destPath = $backupPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        foreach ($this->rollbackFiles() as $file) {
            $sourceFile = $this->rootPath . '/' . $file;
            if (file_exists($sourceFile)) {
                $destFile = $backupPath . '/' . $file;
                $destDir = dirname($destFile);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                copy($sourceFile, $destFile);
            }
        }

        return $backupPath;
    }

    /**
     * Restore application files from backup
     */
    private function restoreAppFiles(string $backupPath): void
    {
        foreach ($this->rollbackDirs() as $dir) {
            $sourcePath = $backupPath . '/' . $dir;
            $destPath = $this->rootPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                if (is_dir($destPath)) {
                    $this->deleteDirectory($destPath);
                }
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        foreach ($this->rollbackFiles() as $file) {
            $sourceFile = $backupPath . '/' . $file;
            if (file_exists($sourceFile)) {
                copy($sourceFile, $this->rootPath . '/' . $file);
            }
        }
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectoryRecursive(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($source . '/', '', $item->getPathname());
            $targetPath = $dest . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Clean up orphan files
     */
    private function cleanupOrphanFiles(string $newSourcePath): void
    {
        $dirsToCheck = ['app', 'public/assets'];

        foreach ($dirsToCheck as $dir) {
            $currentDir = $this->rootPath . '/' . $dir;
            $newDir = $newSourcePath . '/' . $dir;

            if (!is_dir($currentDir) || !is_dir($newDir)) {
                continue;
            }

            $this->removeOrphansInDirectory($currentDir, $newDir, $dir);
        }
    }

    /**
     * Remove files in current directory that don't exist in new directory
     */
    private function removeOrphansInDirectory(string $currentDir, string $newDir, string $basePath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($currentDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($currentDir . '/', '', $item->getPathname());
            $newPath = $newDir . '/' . $relativePath;
            $fullRelativePath = $basePath . '/' . $relativePath;

            foreach ($this->preservePaths as $preservePath) {
                if (strpos($fullRelativePath, $preservePath) === 0) {
                    continue 2;
                }
            }

            if (!file_exists($newPath)) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                    $this->debugLog('DEBUG', 'Removed orphan file', ['path' => $fullRelativePath]);
                }
            }
        }
    }

    /**
     * Copy directory contents, respecting preserve and skip lists
     */
    private function copyDirectory(string $source, string $dest): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($source . '/', '', $item->getPathname());

            if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
                throw new Exception(sprintf('Invalid path in package: %s', $relativePath));
            }

            if ($item->isLink()) {
                continue;
            }

            $targetPath = $dest . '/' . $relativePath;

            $realDest = realpath($dest);
            $parentTarget = realpath(dirname($targetPath));
            if ($parentTarget !== false && $realDest !== false && strpos($parentTarget, $realDest) !== 0) {
                throw new Exception(sprintf('Invalid path in package: %s', $relativePath));
            }

            foreach ($this->skipPaths as $skipPath) {
                if (strpos($relativePath, $skipPath) === 0) {
                    continue 2;
                }
            }

            foreach ($this->preservePaths as $preservePath) {
                if (strpos($relativePath, $preservePath) === 0 && file_exists($targetPath)) {
                    continue 2;
                }
            }

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (!mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                        throw new Exception(sprintf('Cannot create directory: %s', $relativePath));
                    }
                }
            } else {
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    if (!mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                        throw new Exception(sprintf('Cannot create directory: %s', dirname($relativePath)));
                    }
                }
                // If target is an existing directory, remove it first (file replacing dir)
                if (is_dir($targetPath)) {
                    $this->debugLog('WARNING', 'Removing directory to replace with file', ['path' => $relativePath]);
                    $this->deleteDirectory($targetPath);
                    // PHP caches filesystem stats, so deleteDirectory's effect
                    // is invisible to is_dir() until we evict the cache.
                    clearstatcache(true, $targetPath);
                    // Verify directory was actually deleted
                    if (is_dir($targetPath)) {
                        $this->debugLog('ERROR', 'Failed to delete directory, attempting force removal', ['path' => $relativePath]);
                        // Try shell command as fallback
                        if (PHP_OS_FAMILY !== 'Windows') {
                            @exec('rm -rf ' . escapeshellarg($targetPath) . ' 2>/dev/null');
                        }
                        // Final check
                        clearstatcache(true, $targetPath);
                        if (is_dir($targetPath)) {
                            throw new Exception(sprintf('Cannot remove directory to replace with file: %s', $relativePath));
                        }
                    }
                }
                // Also handle symlinks pointing to directories
                if (is_link($targetPath)) {
                    $this->debugLog('WARNING', 'Removing symlink to replace with file', ['path' => $relativePath]);
                    @unlink($targetPath);
                }
                if (!copy($item->getPathname(), $targetPath)) {
                    throw new Exception(sprintf('Error copying file: %s', $relativePath));
                }
            }
        }
    }

    /**
     * Run database migrations between versions
     * @return array{success: bool, executed: array<string>, error: string|null}
     */
    public function runMigrations(string $fromVersion, string $toVersion): array
    {
        $executed = [];

        $this->debugLog('INFO', 'Starting migrations', [
            'from' => $fromVersion,
            'to' => $toVersion
        ]);

        try {
            $migrationsPath = $this->rootPath . '/database/migrations';

            if (!is_dir($migrationsPath)) {
                $this->debugLog('WARNING', 'Migrations directory not found', ['path' => $migrationsPath]);
                return ['success' => true, 'executed' => [], 'error' => null];
            }

            // Determine which migration files to use based on database type
            $dbType = $this->db->isSqlite() ? 'sqlite' : 'mysql';
            $files = glob($migrationsPath . "/migrate_*_{$dbType}.sql");
            if ($files === false) {
                $files = [];
            }

            // SEMANTIC version ordering. A lexicographic sort() would run
            // migrate_1.10.0 BEFORE migrate_1.2.0 — version_compare is the
            // same comparator the gating below uses, so order stays coherent.
            usort($files, function (string $a, string $b): int {
                $extract = function (string $file): string {
                    $name = basename($file);
                    return preg_match('/^migrate_(.+)_(?:sqlite|mysql)\.sql$/', $name, $m) ? $m[1] : $name;
                };
                return version_compare($extract($a), $extract($b)) ?: strcmp($a, $b);
            });

            $this->debugLog('DEBUG', 'Migration files found', [
                'count' => count($files),
                'files' => array_map('basename', $files)
            ]);

            foreach ($files as $file) {
                $filename = basename($file);

                // Extract version from filename: migrate_X.X.X_sqlite.sql or migrate_X.X.X_mysql.sql
                if (preg_match('/migrate_(.+)_(?:sqlite|mysql)\.sql$/', $filename, $matches)) {
                    $migrationVersion = $matches[1];

                    if (version_compare($migrationVersion, $fromVersion, '>') &&
                        version_compare($migrationVersion, $toVersion, '<=')) {

                        if ($this->isMigrationExecuted($migrationVersion)) {
                            $this->debugLog('DEBUG', 'Migration already executed, skip', ['version' => $migrationVersion]);
                            continue;
                        }

                        $this->debugLog('INFO', 'Executing migration', ['file' => $filename]);

                        $sql = file_get_contents($file);

                        if ($sql !== false && trim($sql) !== '') {
                            // Migrations carrying triggers/stored routines use
                            // DELIMITER directives and BEGIN...END bodies with
                            // inner ';' — the naive explode(';') splitter would
                            // shred them. Pick the DELIMITER-aware parser for
                            // those; the standard quote-aware splitter otherwise.
                            if ($this->migrationNeedsDelimiterParser($sql)) {
                                $statements = $this->splitSqlWithDelimiters($sql);
                            } else {
                                $statements = $this->splitSqlStatements($sql);
                            }

                            foreach ($statements as $statement) {
                                if (!empty(trim($statement))) {
                                    try {
                                        $this->db->pdo()->exec($statement);
                                    } catch (\PDOException $e) {
                                        $msg = $e->getMessage();
                                        // Ignore certain errors (table exists, column exists, etc.)
                                        $ignorablePatterns = [
                                            '/table.*already exists/i',
                                            '/duplicate column/i',
                                            '/column.*already exists/i'
                                        ];
                                        $isIgnorable = false;
                                        foreach ($ignorablePatterns as $pattern) {
                                            if (preg_match($pattern, $msg)) {
                                                $isIgnorable = true;
                                                break;
                                            }
                                        }
                                        // CREATE/DROP TRIGGER: tolerate ONLY idempotency
                                        // collisions (a trigger SearchIndexer also
                                        // creates/drops at runtime, present on re-run).
                                        // A genuine failure — missing table, syntax
                                        // error — must still abort, so the migration is
                                        // not falsely recorded as applied. SQLite and
                                        // MySQL phrase the collision differently.
                                        if (!$isIgnorable
                                            && preg_match('/^\s*(CREATE|DROP)\s+TRIGGER\b/i', $statement)) {
                                            // Trigger-SPECIFIC collision phrases only — a bare
                                            // "duplicate" (e.g. duplicate column/key) must NOT
                                            // mark a broken trigger migration as applied.
                                            $triggerCollisionPatterns = [
                                                '/\btrigger\b.*\balready exists\b/i',
                                                '/\balready exists\b.*\btrigger\b/i',
                                                '/\bduplicate trigger\b/i',
                                                '/\bno such trigger\b/i',
                                                '/\bunknown trigger\b/i',
                                                '/\btrigger\b.*\bdoes not exist\b/i',
                                            ];
                                            foreach ($triggerCollisionPatterns as $pattern) {
                                                if (preg_match($pattern, $msg)) {
                                                    $isIgnorable = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if (!$isIgnorable) {
                                            throw $e;
                                        }
                                        $this->debugLog('WARNING', 'Ignorable SQL error', [
                                            'error' => $e->getMessage()
                                        ]);
                                    }
                                }
                            }
                        }

                        $this->recordMigration($migrationVersion, $filename);
                        $executed[] = $filename;
                        $this->debugLog('INFO', 'Migration completed', ['file' => $filename]);
                    }
                }
            }

            return [
                'success' => true,
                'executed' => $executed,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Migration error', [
                'error' => $e->getMessage(),
                'executed_so_far' => $executed
            ]);
            return [
                'success' => false,
                'executed' => $executed,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Decide whether a migration script needs the DELIMITER/BEGIN-END-aware
     * parser (triggers, stored routines) rather than the plain quote-aware
     * splitter. True when it carries a DELIMITER directive (MySQL) or any
     * CREATE TRIGGER (both dialects).
     */
    private function migrationNeedsDelimiterParser(string $sql): bool
    {
        // Anchor CREATE TRIGGER to a statement start (line-leading) so the word
        // appearing inside a seed string literal doesn't route a plain
        // migration to the delimiter parser (which would change how it splits).
        return (bool) preg_match('/^\s*DELIMITER\b/im', $sql)
            || (bool) preg_match('/^\s*CREATE\s+(?:DEFINER\s*=\S+(?:\s+\S+)*\s+)?TRIGGER\b/im', $sql);
    }

    /**
     * Split a SQL script into statements on top-level semicolons, ignoring
     * semicolons inside single-quoted string literals (so a ';' in a CSS
     * blob, a default value, or seeded text doesn't split a statement).
     * Comment-only lines (leading `--`) are dropped.
     *
     * @return string[] Trimmed, non-empty statements.
     */
    private function splitSqlStatements(string $sql): array
    {
        // Strip full-line comments first (keeps `--` inside string literals,
        // which is rare in our migrations, out of scope — matches the prior
        // behavior this method replaces).
        $sqlLines = explode("\n", $sql);
        $sqlLines = array_filter($sqlLines, fn($line) => !preg_match('/^\s*--/', $line));
        $sql = implode("\n", $sqlLines);

        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'") {
                // Escaped quote ('') inside a string literal: keep both, skip next.
                if ($inString && $i + 1 < $length && $sql[$i + 1] === "'") {
                    $current .= "''";
                    $i++;
                    continue;
                }
                $inString = !$inString;
                $current .= $char;
                continue;
            }

            if ($char === ';' && !$inString) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Split a SQL script that uses DELIMITER directives (MySQL) and/or
     * multi-statement CREATE TRIGGER ... BEGIN ... END bodies (whose inner ';'
     * must not split the statement). Handles BOTH dialects:
     *   - MySQL: an explicit `DELIMITER $$` block switches the terminator;
     *   - SQLite: no DELIMITER directive — instead a BEGIN...END depth counter
     *     suppresses the inner ';' so `CREATE TRIGGER ... BEGIN ...; ...; END;`
     *     stays a single statement.
     * Normalizes `CREATE DEFINER=...@... TRIGGER` to plain `CREATE TRIGGER` so
     * trigger SQL applies regardless of the creating DB user.
     *
     * Note: BEGIN/END depth is matched on word boundaries. `END IF` / `END WHILE`
     * inside stored routines would mis-count, but Cimaise's migrations only use
     * plain trigger bodies; MySQL routines should use an explicit DELIMITER.
     *
     * @return string[] Trimmed, non-empty statements (delimiter stripped).
     */
    private function splitSqlWithDelimiters(string $sql): array
    {
        $sql = preg_replace('/CREATE\s+DEFINER=`[^`]+`@`[^`]+`\s+TRIGGER/i', 'CREATE TRIGGER', $sql) ?? $sql;

        $statements = [];
        $buffer = '';
        $delimiter = ';';
        $blockDepth = 0;
        $inTrigger = false;

        foreach (explode("\n", $sql) as $line) {
            $trimmed = trim($line);

            // Skip blank / comment-only lines while not mid-statement.
            if ($buffer === '' && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
                continue;
            }

            // DELIMITER directive: switch terminator, do not emit the line.
            if ($blockDepth === 0 && preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
                $delimiter = $m[1];
                continue;
            }

            $buffer .= $line . "\n";

            // Only a CREATE TRIGGER statement carries a BEGIN...END body whose
            // inner ';' must be suppressed. Detect it from the statement start
            // so a transactional `BEGIN TRANSACTION; ... COMMIT;` (which has no
            // matching END) does not leave blockDepth stuck above zero and
            // swallow the rest of the file.
            if (!$inTrigger && $blockDepth === 0) {
                $inTrigger = (bool) preg_match('/^\s*CREATE\s+TRIGGER\b/i', $buffer);
            }

            if ($inTrigger) {
                // Count BEGIN...END nesting on a "code only" view of the line:
                // strip single-quoted literals and a trailing comment, and
                // exclude `END IF`/`END WHILE`/`END LOOP`/`END CASE`/`END REPEAT`
                // (those close an IF/loop, not the outer BEGIN block).
                $code = preg_replace("/'(?:[^']|'')*'/", '', $line) ?? $line;
                $code = preg_replace('/--.*$/', '', $code) ?? $code;
                $blockDepth += preg_match_all('/\bBEGIN\b/i', $code);
                $blockDepth -= preg_match_all('/\bEND\b(?!\s+(?:IF|WHILE|LOOP|CASE|REPEAT)\b)/i', $code);
                if ($blockDepth < 0) {
                    $blockDepth = 0;
                }
            }

            // A statement terminates only at depth 0, when the line (minus any
            // trailing line comment) ends with the active delimiter.
            $codeTrimmed = rtrim(preg_replace('/--.*$/', '', $trimmed) ?? $trimmed);
            if ($blockDepth === 0 && $codeTrimmed !== '' && substr($codeTrimmed, -strlen($delimiter)) === $delimiter) {
                // Drop a trailing line comment from the buffer before slicing
                // off the delimiter, so `END; -- note` flushes cleanly.
                $stmt = rtrim(preg_replace('/--[^\n]*$/', '', rtrim($buffer)) ?? $buffer);
                $stmt = substr($stmt, 0, strlen($stmt) - strlen($delimiter));
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buffer = '';
                $inTrigger = false;
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    /**
     * Check if migrations table exists
     */
    private function migrationsTableExists(): bool
    {
        try {
            if ($this->db->isSqlite()) {
                $result = $this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'");
                return $result->fetch() !== false;
            } else {
                $result = $this->db->pdo()->query("SHOW TABLES LIKE 'migrations'");
                return $result->rowCount() > 0;
            }
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Check if migration was already executed
     */
    private function isMigrationExecuted(string $version): bool
    {
        if (!$this->migrationsTableExists()) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare("SELECT id FROM migrations WHERE version = ?");
        $stmt->execute([$version]);
        return $stmt->fetch() !== false;
    }

    /**
     * Record migration as executed
     */
    private function recordMigration(string $version, string $filename): void
    {
        if (!$this->migrationsTableExists()) {
            $this->createMigrationsTable();
        }

        $stmt = $this->db->pdo()->prepare("SELECT MAX(batch) as max_batch FROM migrations");
        $stmt->execute();
        $row = $stmt->fetch();
        $batch = ($row['max_batch'] ?? 0) + 1;

        $stmt = $this->db->pdo()->prepare("INSERT INTO migrations (version, filename, batch) VALUES (?, ?, ?)");
        $stmt->execute([$version, $filename, $batch]);
    }

    /**
     * Create migrations table if it doesn't exist
     */
    private function createMigrationsTable(): void
    {
        if ($this->db->isSqlite()) {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL UNIQUE,
                filename TEXT NOT NULL,
                batch INTEGER NOT NULL DEFAULT 1,
                executed_at TEXT DEFAULT (datetime('now'))
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS `migrations` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `version` VARCHAR(20) NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `batch` INT NOT NULL DEFAULT 1,
                `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }

        $this->db->pdo()->exec($sql);
    }

    /**
     * Log update start
     */
    private function logUpdateStart(string $fromVersion, string $toVersion, ?string $backupPath): int
    {
        try {
            $this->ensureUpdateLogsTableExists();

            $userId = (isset($_SESSION) && isset($_SESSION['user']['id']))
                ? (int) $_SESSION['user']['id']
                : null;

            $stmt = $this->db->pdo()->prepare("
                INSERT INTO update_logs (from_version, to_version, status, backup_path, executed_by)
                VALUES (?, ?, 'started', ?, ?)
            ");
            $stmt->execute([$fromVersion, $toVersion, $backupPath, $userId]);

            return (int) $this->db->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            $this->debugLog('WARNING', 'Log update start failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Log update completion
     */
    private function logUpdateComplete(int $logId, bool $success, ?string $error = null): void
    {
        if ($logId <= 0) {
            return;
        }

        try {
            $status = $success ? 'completed' : 'failed';
            $now = $this->db->isSqlite() ? "datetime('now')" : 'NOW()';

            $stmt = $this->db->pdo()->prepare("
                UPDATE update_logs
                SET status = ?, error_message = ?, completed_at = {$now}
                WHERE id = ?
            ");
            $stmt->execute([$status, $error, $logId]);
        } catch (\Throwable $e) {
            $this->debugLog('WARNING', 'Log update complete failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Ensure update_logs table exists
     */
    private function ensureUpdateLogsTableExists(): void
    {
        if ($this->db->isSqlite()) {
            $sql = "CREATE TABLE IF NOT EXISTS update_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                from_version TEXT NOT NULL,
                to_version TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'started',
                backup_path TEXT,
                error_message TEXT,
                started_at TEXT DEFAULT (datetime('now')),
                completed_at TEXT,
                executed_by INTEGER
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS `update_logs` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `from_version` VARCHAR(20) NOT NULL,
                `to_version` VARCHAR(20) NOT NULL,
                `status` ENUM('started','completed','failed','rolled_back') NOT NULL DEFAULT 'started',
                `backup_path` VARCHAR(500) DEFAULT NULL,
                `error_message` TEXT,
                `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `completed_at` DATETIME DEFAULT NULL,
                `executed_by` INT DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }

        $this->db->pdo()->exec($sql);
    }

    /**
     * Get update history
     * @return array<array>
     */
    public function getUpdateHistory(int $limit = 20): array
    {
        try {
            $this->ensureUpdateLogsTableExists();

            $stmt = $this->db->pdo()->prepare("
                SELECT ul.*, u.name as executed_by_name
                FROM update_logs ul
                LEFT JOIN users u ON ul.executed_by = u.id
                ORDER BY ul.started_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Cleanup temporary files
     */
    public function cleanup(): void
    {
        if (is_dir($this->tempPath)) {
            $this->deleteDirectory($this->tempPath);
        }

        $this->disableMaintenanceMode();

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Enable maintenance mode
     */
    private function enableMaintenanceMode(): void
    {
        $maintenanceFile = $this->rootPath . '/storage/.maintenance';
        file_put_contents($maintenanceFile, json_encode([
            'time' => time(),
            'message' => 'Update in progress. Please try again in a few minutes.'
        ]));
    }

    /**
     * Disable maintenance mode
     */
    private function disableMaintenanceMode(): void
    {
        $maintenanceFile = $this->rootPath . '/storage/.maintenance';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = @scandir($dir);
        if ($files === false) {
            return;
        }
        $files = array_diff($files, ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Fix file and directory permissions
     */
    private function fixPermissions(): void
    {
        $writableDirs = [
            'storage',
            'storage/backups',
            'storage/cache',
            'storage/logs',
            'storage/originals',
            'storage/tmp',
            'storage/translations',
            'public/media',
        ];

        foreach ($writableDirs as $dir) {
            $fullPath = $this->rootPath . '/' . $dir;
            if (is_dir($fullPath)) {
                chmod($fullPath, 0755);

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        @chmod($item->getPathname(), 0755);
                    } else {
                        @chmod($item->getPathname(), 0644);
                    }
                }
            }
        }

        $envFile = $this->rootPath . '/.env';
        if (file_exists($envFile)) {
            chmod($envFile, 0600);
        }

        $appDirs = ['app', 'vendor'];
        foreach ($appDirs as $dir) {
            $fullPath = $this->rootPath . '/' . $dir;
            if (is_dir($fullPath)) {
                $this->setReadOnlyPermissions($fullPath);
            }
        }
    }

    /**
     * Set read-only permissions recursively
     */
    private function setReadOnlyPermissions(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        chmod($dir, 0755);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @chmod($item->getPathname(), 0755);
            } else {
                @chmod($item->getPathname(), 0644);
            }
        }
    }

    /**
     * Check system requirements
     * @return array{met: bool, requirements: array<array>}
     */
    public function checkRequirements(): array
    {
        $requirements = [];
        $allMet = true;

        $phpVersion = PHP_VERSION;
        $phpMet = version_compare($phpVersion, '8.0.0', '>=');
        $requirements[] = [
            'name' => 'PHP',
            'required' => '8.0+',
            'current' => $phpVersion,
            'met' => $phpMet
        ];
        if (!$phpMet) $allMet = false;

        $zipMet = class_exists('ZipArchive');
        $requirements[] = [
            'name' => 'ZipArchive',
            'required' => 'Required',
            'current' => $zipMet ? 'Installed' : 'Not installed',
            'met' => $zipMet
        ];
        if (!$zipMet) $allMet = false;

        // Check download capability (cURL preferred, allow_url_fopen as fallback)
        $curlAvailable = extension_loaded('curl');
        $urlFopenEnabled = (bool)ini_get('allow_url_fopen');
        $downloadMet = $curlAvailable || $urlFopenEnabled;
        $downloadStatus = [];
        if ($curlAvailable) $downloadStatus[] = 'cURL';
        if ($urlFopenEnabled) $downloadStatus[] = 'allow_url_fopen';
        $requirements[] = [
            'name' => 'Download capability',
            'required' => 'cURL or allow_url_fopen',
            'current' => $downloadMet ? implode(' + ', $downloadStatus) : 'Not available',
            'met' => $downloadMet
        ];
        if (!$downloadMet) $allMet = false;

        $writablePaths = [
            $this->rootPath,
            $this->backupPath,
            $this->rootPath . '/storage',
            $this->rootPath . '/storage/tmp',  // Required for update extraction
        ];

        foreach ($writablePaths as $path) {
            $writable = is_writable($path);
            $requirements[] = [
                'name' => 'Write: ' . basename($path),
                'required' => 'Writable',
                'current' => $writable ? 'Writable' : 'Not writable',
                'met' => $writable
            ];
            if (!$writable) $allMet = false;
        }

        $freeSpace = disk_free_space($this->rootPath);
        if ($freeSpace === false) {
            $freeSpace = 0;
        }
        $minSpace = 200 * 1024 * 1024;
        $spaceMet = $freeSpace >= $minSpace;
        $requirements[] = [
            'name' => 'Free space',
            'required' => '200MB',
            'current' => $freeSpace > 0 ? $this->formatBytes($freeSpace) : 'Not available',
            'met' => $spaceMet
        ];
        if (!$spaceMet) $allMet = false;

        return [
            'met' => $allMet,
            'requirements' => $requirements
        ];
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get changelog between versions
     */
    public function getChangelog(string $fromVersion): array
    {
        $changelog = [];
        $releases = $this->getAllReleases(20);

        foreach ($releases as $release) {
            $releaseVersion = ltrim($release['tag_name'], 'v');

            if (version_compare($releaseVersion, $fromVersion, '>')) {
                $changelog[] = [
                    'version' => $releaseVersion,
                    'name' => $release['name'] ?? $release['tag_name'],
                    'body' => $release['body'] ?? '',
                    'published_at' => $release['published_at'] ?? null,
                    'prerelease' => $release['prerelease'] ?? false
                ];
            }
        }

        return $changelog;
    }

    /**
     * Perform full update process
     * @return array{success: bool, error: string|null, backup_path: string|null}
     */
    public function performUpdate(string $targetVersion): array
    {
        $lockFile = $this->rootPath . '/storage/cache/update.lock';
        $lockHandle = null;

        $this->debugLog('INFO', '========================================');
        $this->debugLog('INFO', '=== PERFORM UPDATE - STARTING PROCESS ===');
        $this->debugLog('INFO', '========================================', [
            'current_version' => $this->getCurrentVersion(),
            'target_version' => $targetVersion,
            'php_version' => PHP_VERSION
        ]);

        $maintenanceFile = $this->rootPath . '/storage/.maintenance';
        register_shutdown_function(function () use ($maintenanceFile, $lockFile) {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                error_log("[Updater] FATAL ERROR during update: " . json_encode($error));

                if (file_exists($maintenanceFile)) {
                    @unlink($maintenanceFile);
                }

                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
            }
        });

        set_time_limit(0);

        $currentMemory = ini_get('memory_limit');
        if ($currentMemory !== '-1') {
            $memoryBytes = $this->parseMemoryLimit($currentMemory);
            $minMemory = 256 * 1024 * 1024;
            if ($memoryBytes < $minMemory) {
                @ini_set('memory_limit', '256M');
            }
        }

        // Acquire lock
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $lockHandle = @fopen($lockFile, 'c');
        if (!$lockHandle) {
            return [
                'success' => false,
                'error' => 'Cannot create lock file for update',
                'backup_path' => null
            ];
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return [
                'success' => false,
                'error' => 'Another update is already in progress. Please try again later.',
                'backup_path' => null
            ];
        }

        ftruncate($lockHandle, 0);
        fwrite($lockHandle, (string)getmypid());
        fflush($lockHandle);

        $this->enableMaintenanceMode();

        $backupResult = ['path' => null, 'success' => false, 'error' => null];
        $result = null;

        try {
            // Step 1: Backup
            $this->debugLog('INFO', '>>> STEP 1: Creating backup <<<');
            $backupResult = $this->createBackup();
            if (!$backupResult['success']) {
                throw new Exception('Backup failed: ' . $backupResult['error']);
            }

            // Step 2: Download
            $this->debugLog('INFO', '>>> STEP 2: Downloading update <<<');
            $downloadResult = $this->downloadUpdate($targetVersion);
            if (!$downloadResult['success']) {
                throw new Exception('Download failed: ' . $downloadResult['error']);
            }

            // Step 2.5: Pre-update patch (optional, signed, NON-FATAL).
            $this->debugLog('INFO', '>>> STEP 2.5: Pre-update patch <<<');
            $this->applyPreUpdatePatch($targetVersion);

            // Step 3: Install
            $this->debugLog('INFO', '>>> STEP 3: Installing update <<<');
            $installResult = $this->installUpdate($downloadResult['path'], $targetVersion, $backupResult['path']);
            if (!$installResult['success']) {
                throw new Exception('Installation failed: ' . $installResult['error']);
            }

            // Step 4: Post-install patch (optional, signed, NON-FATAL). The
            // update is already complete; a patch failure must not undo it.
            $this->debugLog('INFO', '>>> STEP 4: Post-install patch <<<');
            $this->applyPostInstallPatch($targetVersion);

            $result = [
                'success' => true,
                'error' => null,
                'backup_path' => $backupResult['path']
            ];

            $this->debugLog('INFO', '========================================');
            $this->debugLog('INFO', '=== UPDATE COMPLETED SUCCESSFULLY ===');
            $this->debugLog('INFO', '========================================');

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'UPDATE FAILED', [
                'message' => $e->getMessage()
            ]);

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_path' => $backupResult['path'] ?? null
            ];
        } finally {
            $this->cleanup();

            if (\is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }

            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }

        return $result;
    }

    /**
     * Fetch a release asset and its detached Ed25519 signature sibling
     * (<asset>.sig), verifying the signature before returning the bytes.
     *
     * FAIL-CLOSED on every uncertain path: if no signing public key is
     * configured (PluginSignature disabled), if the asset has no .sig sibling,
     * or if the signature does not verify, returns null and nothing is
     * executed. The patch download is anonymous (no bearer token to the CDN),
     * inheriting downloadFile()'s host scoping.
     *
     * This is STRONGER than the upstream (Pinakes) sha256-from-digest scheme:
     * a remote PHP patch must carry a valid signature from the project's
     * offline signing key, not merely match a hash published alongside it.
     */
    private function fetchSignedReleaseAsset(string $version, string $assetName): ?string
    {
        if (!\App\Support\PluginSignature::isEnabled()) {
            $this->debugLog('INFO', 'Remote patch skipped: no signing public key configured (fail-closed)', [
                'asset' => $assetName,
            ]);
            return null;
        }

        $release = $this->getReleaseByVersion($version);
        if (!is_array($release)) {
            return null;
        }

        $assetUrl = null;
        $sigUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = $asset['name'] ?? '';
            if ($name === $assetName) {
                $assetUrl = $asset['browser_download_url'] ?? null;
            } elseif ($name === $assetName . '.sig') {
                $sigUrl = $asset['browser_download_url'] ?? null;
            }
        }

        // No patch asset on this release is the normal case — silently skip.
        if (!is_string($assetUrl) || $assetUrl === '') {
            return null;
        }
        // Patch present but unsigned: refuse (fail-closed).
        if (!is_string($sigUrl) || $sigUrl === '') {
            $this->debugLog('WARNING', 'Patch asset has no .sig sibling — refusing to execute', [
                'asset' => $assetName,
            ]);
            return null;
        }

        $patch = $this->downloadFile($assetUrl);
        if (!($patch['success'] ?? false) || !is_string($patch['content'])) {
            return null;
        }
        $sig = $this->downloadFile($sigUrl);
        if (!($sig['success'] ?? false) || !is_string($sig['content'])) {
            return null;
        }

        if (!\App\Support\PluginSignature::verify($patch['content'], trim($sig['content']))) {
            $this->debugLog('ERROR', 'Patch signature verification FAILED — refusing to execute', [
                'asset' => $assetName,
            ]);
            return null;
        }

        $this->debugLog('INFO', 'Patch asset downloaded and Ed25519 signature verified', [
            'asset' => $assetName,
        ]);
        return $patch['content'];
    }

    /**
     * Load a verified patch definition (a PHP file that returns an array) from
     * a signed release asset, writing it to a random temp file, requiring it,
     * and removing it immediately. Returns the array, or null on any failure.
     *
     * @return array<string, mixed>|null
     */
    private function loadVerifiedPatch(string $version, string $assetName): ?array
    {
        $content = $this->fetchSignedReleaseAsset($version, $assetName);
        if ($content === null) {
            return null;
        }

        $tmp = $this->rootPath . '/storage/tmp/' . pathinfo($assetName, PATHINFO_FILENAME)
            . '-' . bin2hex(random_bytes(16)) . '.php';
        if (!is_dir(dirname($tmp))) {
            @mkdir(dirname($tmp), 0775, true);
        }
        if (@file_put_contents($tmp, $content) === false) {
            return null;
        }
        try {
            $definition = require $tmp;
        } catch (\Throwable $e) {
            $this->debugLog('ERROR', 'Patch file failed to load', ['error' => $e->getMessage()]);
            $definition = null;
        } finally {
            @unlink($tmp);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($tmp, true);
            }
        }

        return is_array($definition) ? $definition : null;
    }

    /**
     * Apply the optional pre-update patch (signed `pre-update-patch.php`).
     * Runs BEFORE new files land, against the current version. NON-FATAL.
     *
     * @return array{success: bool, applied: bool, patches: array<int, mixed>, error: string|null}
     */
    public function applyPreUpdatePatch(string $targetVersion): array
    {
        $result = ['success' => true, 'applied' => false, 'patches' => [], 'error' => null];
        try {
            $def = $this->loadVerifiedPatch($targetVersion, 'pre-update-patch.php');
            if ($def === null) {
                return $result;
            }
            // Gate on the source version, when the patch declares targets.
            $targets = $def['target_versions'] ?? null;
            if (is_array($targets) && !in_array($this->getCurrentVersion(), $targets, true)) {
                return $result;
            }
            $applied = [];
            foreach ($def['patches'] ?? [] as $patch) {
                if (is_array($patch) && $this->applySinglePatch($patch)['success']) {
                    $applied[] = $patch['file'] ?? 'unknown';
                }
            }
            $result['patches'] = $applied;
            $result['applied'] = $applied !== [];
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage(); // success stays true — non-fatal
        }
        return $result;
    }

    /**
     * Apply the optional post-install patch (signed `post-install-patch.php`).
     * Runs AFTER the update is complete. NON-FATAL. Supports file
     * search-replace, file deletion (protected-path guarded), and SQL.
     *
     * @return array{success: bool, applied: bool, patches: array<int, mixed>, cleanup: array<int, mixed>, sql: array<int, mixed>, error: string|null}
     */
    public function applyPostInstallPatch(string $targetVersion): array
    {
        $result = ['success' => true, 'applied' => false, 'patches' => [], 'cleanup' => [], 'sql' => [], 'error' => null];
        try {
            $def = $this->loadVerifiedPatch($targetVersion, 'post-install-patch.php');
            if ($def === null) {
                return $result;
            }
            $any = false;

            foreach ($def['patches'] ?? [] as $patch) {
                if (is_array($patch) && $this->applySinglePatch($patch)['success']) {
                    $result['patches'][] = $patch['file'] ?? 'unknown';
                    $any = true;
                }
            }
            foreach ($def['cleanup'] ?? [] as $rel) {
                if (is_string($rel) && $this->cleanupPatchFile($rel)['success']) {
                    $result['cleanup'][] = $rel;
                    $any = true;
                }
            }
            foreach ($def['sql'] ?? [] as $sql) {
                if (is_string($sql) && $this->executePostInstallSql($sql)['success']) {
                    $result['sql'][] = substr($sql, 0, 80);
                    $any = true;
                }
            }
            $result['applied'] = $any;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        return $result;
    }

    /**
     * Apply one search-replace patch to a file inside the app root. The search
     * string must occur exactly once (no ambiguous / already-applied edits),
     * and the resolved path must stay within the root.
     *
     * @param array<string, mixed> $patch  Requires file, search, replace
     * @return array{success: bool, error: string|null}
     */
    private function applySinglePatch(array $patch): array
    {
        if (!isset($patch['file'], $patch['search'], $patch['replace'])
            || !is_string($patch['file']) || !is_string($patch['search']) || !is_string($patch['replace'])) {
            return ['success' => false, 'error' => 'Invalid patch definition'];
        }

        $real = realpath($this->rootPath . '/' . $patch['file']);
        $root = realpath($this->rootPath);
        if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return ['success' => false, 'error' => 'Invalid file path'];
        }

        $content = @file_get_contents($real);
        if ($content === false) {
            return ['success' => false, 'error' => 'Cannot read file'];
        }
        $occurrences = substr_count($content, $patch['search']);
        if ($occurrences !== 1) {
            return ['success' => false, 'error' => "Search string not unique ({$occurrences})"];
        }
        $patched = str_replace($patch['search'], $patch['replace'], $content);
        if (@file_put_contents($real, $patched) === false) {
            return ['success' => false, 'error' => 'Cannot write file'];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($real, true);
        }
        return ['success' => true, 'error' => null];
    }

    /**
     * Delete a file relative to the app root, refusing protected paths and any
     * path escaping the root. Idempotent (missing file = success).
     *
     * @return array{success: bool, error: string|null}
     */
    private function cleanupPatchFile(string $relativePath): array
    {
        $protected = ['.env', 'version.json', 'composer.json', 'public/index.php', 'database/database.sqlite'];
        $base = basename($relativePath);
        foreach ($protected as $p) {
            if ($relativePath === $p || strpos($relativePath, $p . '/') === 0 || $base === basename($p)) {
                return ['success' => false, 'error' => 'Cannot delete protected file'];
            }
        }

        $real = realpath($this->rootPath . '/' . $relativePath);
        $root = realpath($this->rootPath);
        if ($real === false) {
            return ['success' => true, 'error' => null]; // already gone
        }
        if ($root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return ['success' => false, 'error' => 'Invalid file path'];
        }
        if (is_file($real) && @unlink($real)) {
            return ['success' => true, 'error' => null];
        }
        return ['success' => false, 'error' => 'Cannot delete file'];
    }

    /**
     * Execute a single SQL statement from a post-install patch, refusing a
     * blocklist of catastrophic operations. Runs on whichever engine is
     * active (PDO). Idempotent errors are tolerated.
     *
     * @return array{success: bool, error: string|null}
     */
    private function executePostInstallSql(string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            return ['success' => true, 'error' => null];
        }
        $dangerous = [
            '/\bDROP\s+DATABASE\b/i',
            '/\bTRUNCATE\s+(?:TABLE\s+)?`?users`?\b/i',
            '/\bDELETE\s+FROM\s+`?users`?(?:\s*;|\s*$|\s+WHERE\s+(?:1|true|1\s*=\s*1)\b)/i',
        ];
        foreach ($dangerous as $p) {
            if (preg_match($p, $sql)) {
                return ['success' => false, 'error' => 'Dangerous SQL blocked'];
            }
        }
        try {
            $this->db->pdo()->exec($sql);
            return ['success' => true, 'error' => null];
        } catch (\PDOException $e) {
            foreach (['/already exists/i', '/duplicate column/i', '/duplicate key/i'] as $p) {
                if (preg_match($p, $e->getMessage())) {
                    return ['success' => true, 'error' => null];
                }
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Parse PHP memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Check and remove stale maintenance file
     */
    public static function checkStaleMaintenanceMode(): void
    {
        $maintenanceFile = dirname(__DIR__, 2) . '/storage/.maintenance';

        if (!file_exists($maintenanceFile)) {
            return;
        }

        $content = @file_get_contents($maintenanceFile);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['time'])) {
            return;
        }

        $maxAge = 30 * 60;
        if ((time() - $data['time']) > $maxAge) {
            @unlink($maintenanceFile);
            Logger::warning('[Updater] Maintenance mode automatically removed (expired)', [
                'started' => date('Y-m-d H:i:s', $data['time']),
                'age_minutes' => round((time() - $data['time']) / 60)
            ], 'updater');
        }
    }
}
