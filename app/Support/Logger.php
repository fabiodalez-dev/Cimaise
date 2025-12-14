<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Simple file-based logger with log level filtering.
 * Supports multiple channels (file, database, null).
 */
class Logger
{
    public const DEBUG = 100;
    public const INFO = 200;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;

    private static ?self $instance = null;
    private bool $enabled;
    private int $minLevel;
    private string $channel;
    private string $logPath;
    private int $maxFiles;
    private ?Database $db = null;

    private static array $levelNames = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
        self::CRITICAL => 'CRITICAL',
    ];

    private static array $levelMap = [
        'debug' => self::DEBUG,
        'info' => self::INFO,
        'warning' => self::WARNING,
        'error' => self::ERROR,
        'critical' => self::CRITICAL,
    ];

    private function __construct()
    {
        $this->enabled = filter_var(envv('LOG_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
        $this->minLevel = self::$levelMap[strtolower((string)envv('LOG_LEVEL', 'warning'))] ?? self::WARNING;
        $this->channel = (string)envv('LOG_CHANNEL', 'file');
        $this->logPath = (string)envv('LOG_PATH', 'storage/logs');
        $this->maxFiles = (int)envv('LOG_MAX_FILES', 30);

        // Make log path absolute if relative
        if (!str_starts_with($this->logPath, '/')) {
            $this->logPath = dirname(__DIR__, 2) . '/' . $this->logPath;
        }

        // Create log directory if it doesn't exist
        if ($this->channel === 'file' && !is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setDatabase(Database $db): void
    {
        $this->db = $db;
    }

    /**
     * Log a debug message
     */
    public static function debug(string $message, array $context = [], string $category = 'app'): void
    {
        self::getInstance()->log(self::DEBUG, $message, $context, $category);
    }

    /**
     * Log an info message
     */
    public static function info(string $message, array $context = [], string $category = 'app'): void
    {
        self::getInstance()->log(self::INFO, $message, $context, $category);
    }

    /**
     * Log a warning message
     */
    public static function warning(string $message, array $context = [], string $category = 'app'): void
    {
        self::getInstance()->log(self::WARNING, $message, $context, $category);
    }

    /**
     * Log an error message
     */
    public static function error(string $message, array $context = [], string $category = 'app'): void
    {
        self::getInstance()->log(self::ERROR, $message, $context, $category);
    }

    /**
     * Log a critical message
     */
    public static function critical(string $message, array $context = [], string $category = 'app'): void
    {
        self::getInstance()->log(self::CRITICAL, $message, $context, $category);
    }

    /**
     * Log a message at the specified level
     */
    public function log(int $level, string $message, array $context = [], string $category = 'app'): void
    {
        if (!$this->enabled || $level < $this->minLevel) {
            return;
        }

        // Sanitize context - remove sensitive data
        $context = $this->sanitizeContext($context);

        $levelName = self::$levelNames[$level] ?? 'UNKNOWN';
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        $formattedMessage = "[{$timestamp}] [{$levelName}] [{$category}] {$message}{$contextJson}";

        switch ($this->channel) {
            case 'file':
                $this->writeToFile($formattedMessage);
                break;
            case 'database':
                $this->writeToDatabase($level, $levelName, $category, $message, $context, $timestamp);
                break;
            case 'null':
            default:
                // Do nothing
                break;
        }
    }

    /**
     * Write log to file
     */
    private function writeToFile(string $message): void
    {
        $filename = $this->logPath . '/app-' . date('Y-m-d') . '.log';
        @file_put_contents($filename, $message . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Cleanup old log files periodically (1% chance per request)
        if (mt_rand(1, 100) === 1) {
            $this->cleanupOldLogs();
        }
    }

    /**
     * Write log to database
     */
    private function writeToDatabase(int $level, string $levelName, string $category, string $message, array $context, string $timestamp): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $sql = "INSERT INTO logs (level, level_name, category, message, context, created_at) VALUES (:level, :level_name, :category, :message, :context, :created_at)";
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([
                ':level' => $level,
                ':level_name' => $levelName,
                ':category' => $category,
                ':message' => $message,
                ':context' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $timestamp,
            ]);
        } catch (\Throwable $e) {
            // Fallback to file if database fails
            $this->channel = 'file';
            $this->writeToFile("[{$timestamp}] [{$levelName}] [{$category}] {$message} | " . json_encode($context));
        }
    }

    /**
     * Remove log files older than maxFiles days
     */
    private function cleanupOldLogs(): void
    {
        $files = glob($this->logPath . '/app-*.log');
        if ($files === false) {
            return;
        }

        $cutoff = strtotime("-{$this->maxFiles} days");
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Sanitize context to remove sensitive data
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'auth', 'credential', 'credit_card', 'cc'];

        array_walk_recursive($context, function (&$value, $key) use ($sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitive) {
                if (is_string($key) && stripos($key, $sensitive) !== false) {
                    $value = '[REDACTED]';
                    break;
                }
            }
        });

        return $context;
    }

    /**
     * Log SQL query (for debug mode)
     */
    public static function sql(string $query, array $params = [], float $executionTime = 0): void
    {
        if (!filter_var(envv('DEBUG_SQL', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        self::debug('SQL Query', [
            'query' => $query,
            'params' => $params,
            'time_ms' => round($executionTime * 1000, 2),
        ], 'sql');
    }

    /**
     * Log HTTP request (for debug mode)
     */
    public static function request(string $method, string $uri, int $statusCode, float $duration): void
    {
        if (!filter_var(envv('DEBUG_REQUESTS', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        self::debug('HTTP Request', [
            'method' => $method,
            'uri' => $uri,
            'status' => $statusCode,
            'duration_ms' => round($duration * 1000, 2),
        ], 'http');
    }

    /**
     * Log performance metrics
     */
    public static function performance(string $uri, string $method, float $duration, float $memoryMb): void
    {
        if (!filter_var(envv('DEBUG_PERFORMANCE', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        self::debug('Performance', [
            'uri' => $uri,
            'method' => $method,
            'duration_ms' => round($duration * 1000, 2),
            'memory_mb' => round($memoryMb, 2),
        ], 'performance');
    }
}
