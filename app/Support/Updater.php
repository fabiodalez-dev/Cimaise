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
    private Database $db;
    private string $repoOwner = 'fabiodalez-dev';
    private string $repoName = 'cimaise';
    private string $rootPath;
    private string $backupPath;
    private string $tempPath;

    /** @var array<string> Files/directories to preserve during update */
    private array $preservePaths = [
        '.env',
        'storage/originals',
        'storage/backups',
        'storage/cache',
        'storage/logs',
        'storage/tmp',
        'storage/translations',
        'public/media',
        'public/.htaccess',
        'public/robots.txt',
        'public/favicon.ico',
        'public/sitemap.xml',
        'database/database.sqlite',
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
        $this->tempPath = sys_get_temp_dir() . '/cimaise_update_' . uniqid('', true);

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
     * @return array{available: bool, current: string, latest: string, release: array|null, error: string|null}
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
                    'error' => 'Unable to fetch release information'
                ];
            }

            $latestVersion = ltrim($release['tag_name'], 'v');
            $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

            $this->debugLog('INFO', 'Check completed', [
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'update_available' => $updateAvailable,
                'release_name' => $release['name'] ?? 'N/A',
                'published_at' => $release['published_at'] ?? 'N/A'
            ]);

            return [
                'available' => $updateAvailable,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'release' => $release,
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
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get latest release from GitHub API
     */
    private function getLatestRelease(): ?array
    {
        $url = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases/latest";

        $this->debugLog('INFO', 'GitHub API request - latest release', [
            'url' => $url,
            'repo' => "{$this->repoOwner}/{$this->repoName}"
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

        $headers = [
            'User-Agent: Cimaise-Updater/1.0',
            'Accept: application/vnd.github.v3+json'
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => 30,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        $responseHeaders = [];
        $response = @file_get_contents($url, false, $context);

        if (isset($http_response_header)) {
            $responseHeaders = $http_response_header;
        }

        $this->debugLog('DEBUG', 'HTTP response received', [
            'response_length' => $response !== false ? strlen($response) : 0,
            'response_headers' => $responseHeaders
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

        // Parse status code from headers
        $statusCode = 0;
        if (!empty($responseHeaders[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $responseHeaders[0], $matches);
            $statusCode = (int)($matches[1] ?? 0);
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
     * Get all releases for display
     * @return array<array>
     */
    public function getAllReleases(int $limit = 10): array
    {
        $url = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases?per_page={$limit}";

        $this->debugLog('INFO', 'Fetching all releases', ['url' => $url, 'limit' => $limit]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Cimaise-Updater/1.0',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->debugLog('ERROR', 'Cannot fetch releases', [
                'error' => error_get_last()
            ]);
            return [];
        }

        $releases = json_decode($response, true) ?? [];

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

            // Find the source code zip asset or use zipball_url
            $downloadUrl = $release['zipball_url'] ?? null;

            // Check for custom asset named cimaise-vX.X.X.zip first
            foreach ($release['assets'] ?? [] as $asset) {
                if (preg_match('/cimaise.*\.zip$/i', $asset['name'])) {
                    $downloadUrl = $asset['browser_download_url'];
                    $this->debugLog('INFO', 'Found custom asset', [
                        'name' => $asset['name'],
                        'url' => $downloadUrl
                    ]);
                    break;
                }
            }

            if (!$downloadUrl) {
                $this->debugLog('ERROR', 'Download URL not found', [
                    'release' => $release['tag_name']
                ]);
                throw new Exception('Download URL not found');
            }

            $this->debugLog('INFO', 'Download URL selected', ['url' => $downloadUrl]);

            // Create temp directory
            if (!is_dir($this->tempPath)) {
                if (!mkdir($this->tempPath, 0755, true) && !is_dir($this->tempPath)) {
                    throw new Exception('Cannot create temporary directory');
                }
            }

            $zipPath = $this->tempPath . '/update.zip';

            // Download the file
            $this->debugLog('INFO', 'Starting file download...', ['url' => $downloadUrl]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Cimaise-Updater/1.0',
                        'Accept: application/octet-stream'
                    ],
                    'timeout' => 300,
                    'follow_location' => true,
                    'ignore_errors' => true
                ]
            ]);

            $startTime = microtime(true);
            $fileContent = @file_get_contents($downloadUrl, false, $context);
            $downloadTime = round(microtime(true) - $startTime, 2);

            if ($fileContent === false) {
                $error = error_get_last();
                throw new Exception('Download failed: ' . ($error['message'] ?? 'Unknown error'));
            }

            $fileSize = strlen($fileContent);
            $this->debugLog('INFO', 'Download completed', [
                'size_bytes' => $fileSize,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
                'time_seconds' => $downloadTime
            ]);

            if ($fileSize < 1000) {
                throw new Exception('Update file invalid (too small)');
            }

            // Save file
            $bytesWritten = file_put_contents($zipPath, $fileContent);
            if ($bytesWritten === false) {
                throw new Exception('Cannot save update file');
            }

            // Verify it's a valid zip
            $zip = new ZipArchive();
            $zipOpenResult = $zip->open($zipPath);

            if ($zipOpenResult !== true) {
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
                throw new Exception('Invalid update file: ' . ($zipErrors[$zipOpenResult] ?? 'Unknown error'));
            }

            $this->debugLog('INFO', 'ZIP valid', [
                'num_files' => $zip->numFiles,
                'status' => $zip->status
            ]);

            // Extract to temp directory
            $extractPath = $this->tempPath . '/extracted';

            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                throw new Exception('Package extraction failed');
            }
            $zip->close();

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
                $this->debugLog('WARNING', 'Incomplete package', ['missing' => $missingFiles]);
            }

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
        $url = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases/tags/{$tag}";

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
                        return $pdo->quote($val);
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
                        return $pdo->quote($val);
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
     * @return array{success: bool, error: string|null}
     */
    public function installUpdate(string $sourcePath, string $targetVersion): array
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

            // Log update start
            $logId = $this->logUpdateStart($currentVersion, $targetVersion, null);

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
            if ($appBackupPath !== null && is_dir($appBackupPath)) {
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
     * Backup application files for atomic rollback
     */
    private function backupAppFiles(): string
    {
        $timestamp = date('Y-m-d_His');
        $backupPath = sys_get_temp_dir() . '/cimaise_app_backup_' . $timestamp;

        if (!mkdir($backupPath, 0755, true) && !is_dir($backupPath)) {
            throw new Exception('Cannot create application backup directory');
        }

        $dirsToBackup = ['app', 'public/assets'];

        foreach ($dirsToBackup as $dir) {
            $sourcePath = $this->rootPath . '/' . $dir;
            $destPath = $backupPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        $versionFile = $this->rootPath . '/version.json';
        if (file_exists($versionFile)) {
            copy($versionFile, $backupPath . '/version.json');
        }

        return $backupPath;
    }

    /**
     * Restore application files from backup
     */
    private function restoreAppFiles(string $backupPath): void
    {
        $dirsToRestore = ['app', 'public/assets'];

        foreach ($dirsToRestore as $dir) {
            $sourcePath = $backupPath . '/' . $dir;
            $destPath = $this->rootPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                if (is_dir($destPath)) {
                    $this->deleteDirectory($destPath);
                }
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        $backupVersion = $backupPath . '/version.json';
        if (file_exists($backupVersion)) {
            copy($backupVersion, $this->rootPath . '/version.json');
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
            sort($files);

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
                            // Remove comments
                            $sqlLines = explode("\n", $sql);
                            $sqlLines = array_filter($sqlLines, fn($line) => !preg_match('/^\s*--/', $line));
                            $sql = implode("\n", $sqlLines);

                            $statements = array_filter(
                                array_map('trim', explode(';', $sql)),
                                fn($s) => !empty($s)
                            );

                            foreach ($statements as $statement) {
                                if (!empty(trim($statement))) {
                                    try {
                                        $this->db->pdo()->exec($statement);
                                    } catch (\PDOException $e) {
                                        // Ignore certain errors (table exists, column exists, etc.)
                                        $ignorablePatterns = [
                                            '/table.*already exists/i',
                                            '/duplicate column/i',
                                            '/column.*already exists/i'
                                        ];
                                        $isIgnorable = false;
                                        foreach ($ignorablePatterns as $pattern) {
                                            if (preg_match($pattern, $e->getMessage())) {
                                                $isIgnorable = true;
                                                break;
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

        $writablePaths = [
            $this->rootPath,
            $this->backupPath,
            $this->rootPath . '/storage',
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
        $minSpace = 100 * 1024 * 1024;
        $spaceMet = $freeSpace >= $minSpace;
        $requirements[] = [
            'name' => 'Free space',
            'required' => '100MB',
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

            // Step 3: Install
            $this->debugLog('INFO', '>>> STEP 3: Installing update <<<');
            $installResult = $this->installUpdate($downloadResult['path'], $targetVersion);
            if (!$installResult['success']) {
                throw new Exception('Installation failed: ' . $installResult['error']);
            }

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

            if ($lockHandle !== null && \is_resource($lockHandle)) {
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
