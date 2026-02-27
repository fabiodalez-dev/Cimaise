<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;
use App\Support\Logger;

class Database
{
    private PDO $pdo;
    private bool $isSqlite = false;

    public function __construct(
        private ?string $host = null,
        private ?int $port = null,
        private ?string $database = null,
        private ?string $username = null,
        private ?string $password = null,
        private string $charset = 'utf8mb4',
        private string $collation = 'utf8mb4_unicode_ci',
        bool $isSqlite = false
    ) {
        $this->isSqlite = $isSqlite;
        
        if ($this->isSqlite) {
            // SQLite mode
            $dir = dirname($this->database);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $dsn = 'sqlite:' . $this->database;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, null, null, $options);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');      // Write-Ahead Logging for better concurrency
            $this->pdo->exec('PRAGMA busy_timeout = 30000');    // Wait up to 30 seconds on lock
        } else {
            // MySQL mode
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $this->host, $this->port, $this->database, $this->charset);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            $this->pdo->exec("SET NAMES '{$this->charset}' COLLATE '{$this->collation}'");
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query with optional SQL debug logging
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $startTime = microtime(true);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // Log SQL query if DEBUG_SQL is enabled
        if (function_exists('envv') && filter_var(envv('DEBUG_SQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $duration = microtime(true) - $startTime;
            Logger::sql($sql, $params, $duration);
        }

        return $stmt;
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE) with optional SQL debug logging
     */
    public function execute(string $sql, array $params = []): int
    {
        $startTime = microtime(true);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rowCount = $stmt->rowCount();

        // Log SQL query if DEBUG_SQL is enabled
        if (function_exists('envv') && filter_var(envv('DEBUG_SQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $duration = microtime(true) - $startTime;
            Logger::sql($sql, $params, $duration);
        }

        return $rowCount;
    }

    public function testConnection(): array
    {
        if ($this->isSqlite) {
            $row = $this->pdo->query('SELECT sqlite_version() AS version')->fetch();
            return [
                'driver' => 'sqlite',
                'version' => $row['version'] ?? null,
                'database' => $this->database,
                'file_size' => file_exists($this->database) ? filesize($this->database) : 0,
            ];
        } else {
            $row = $this->pdo->query('SELECT VERSION() AS version')->fetch();
            return [
                'driver' => 'mysql',
                'version' => $row['version'] ?? null,
                'database' => $this->database,
                'host' => $this->host,
                'port' => $this->port,
            ];
        }
    }

    public function execSqlFile(string $path): void
    {
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Cannot read SQL file: {$path}");
        }

        if ($this->isSqlite) {
            $this->pdo->exec($sql);
        } else {
            // MySQL: split into individual statements respecting string literals
            // (naive explode on ';' breaks when values contain semicolons)
            $statements = $this->splitSqlStatements($sql);

            foreach ($statements as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }

    public function isSqlite(): bool
    {
        return $this->isSqlite;
    }

    public function isMySQL(): bool
    {
        return !$this->isSqlite;
    }

    /**
     * Split SQL file content into individual statements, respecting string literals.
     * Unlike explode(';'), this won't break on semicolons inside quoted values.
     *
     * @return string[]
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inSingleQuote = false;
        $inLineComment = false;
        $inBlockComment = false;
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Handle line comments
            if (!$inSingleQuote && !$inBlockComment && $char === '-' && $next === '-') {
                $inLineComment = true;
                continue;
            }
            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            // Handle block comments
            if (!$inSingleQuote && !$inBlockComment && $char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++; // skip *
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++; // skip /
                }
                continue;
            }

            // Handle backslash escapes inside single-quoted strings
            if ($inSingleQuote && $char === '\\') {
                $current .= $char . $next;
                $i++;
                continue;
            }

            // Handle single-quoted strings (with escaped quotes)
            if ($char === "'" && !$inBlockComment && !$inLineComment) {
                if ($inSingleQuote) {
                    // Check for escaped quote ('')
                    if ($next === "'") {
                        $current .= "''";
                        $i++;
                        continue;
                    }
                    $inSingleQuote = false;
                } else {
                    $inSingleQuote = true;
                }
                $current .= $char;
                continue;
            }

            // Semicolon outside of quotes = statement delimiter
            if ($char === ';' && !$inSingleQuote) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        // Last statement (may not end with ;)
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    // Helper for cross-database ORDER BY with NULL handling
    public function orderByNullsLast(string $column): string
    {
        if ($this->isSqlite) {
            return "CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END, {$column}";
        } else {
            return "{$column} IS NULL, {$column}";
        }
    }

    // Helper keyword for portable INSERT IGNORE
    public function insertIgnoreKeyword(): string
    {
        return $this->isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    // Helper for portable current timestamp in SQL
    public function nowExpression(): string
    {
        return $this->isSqlite ? "datetime('now')" : 'NOW()';
    }

    // Helper for portable date/time interval subtraction
    public function dateSubExpression(string $interval, int $value): string
    {
        if ($this->isSqlite) {
            return "datetime('now', '-{$value} {$interval}')";
        }
        $mysqlInterval = match (strtolower($interval)) {
            'hours', 'hour' => 'HOUR',
            'days', 'day' => 'DAY',
            'minutes', 'minute' => 'MINUTE',
            'seconds', 'second' => 'SECOND',
            'weeks', 'week' => 'WEEK',
            'months', 'month' => 'MONTH',
            'years', 'year' => 'YEAR',
            default => strtoupper($interval),
        };
        return "DATE_SUB(NOW(), INTERVAL {$value} {$mysqlInterval})";
    }

    // Helper for portable year extraction from date column
    public function yearExpression(string $column): string
    {
        return $this->isSqlite ? "strftime('%Y', {$column})" : "YEAR({$column})";
    }

    // Helper for INSERT OR REPLACE / REPLACE INTO
    public function replaceKeyword(): string
    {
        return $this->isSqlite ? 'INSERT OR REPLACE' : 'REPLACE';
    }

    // Helper for portable current date (without time)
    public function currentDateExpression(): string
    {
        return $this->isSqlite ? "DATE('now')" : 'CURDATE()';
    }

    /**
     * Portable date formatting.
     * Supported portable specifiers: %Y, %m, %d, %H, %M (minutes), %S.
     * %W (week number) is translated to MySQL %u (Monday-based, 00-53).
     * For ISO week numbering, use db-specific expressions directly.
     */
    public function dateFormatExpression(string $column, string $format): string
    {
        if (preg_match('/[\'";]/', $format)) {
            throw new \InvalidArgumentException('Invalid characters in date format');
        }
        if ($this->isSqlite) {
            return "strftime('{$format}', {$column})";
        }
        // Translate SQLite format specifiers to MySQL equivalents
        // %W (week number) → %u, %M (minutes) → %i (MySQL %M = month name)
        $mysqlFormat = str_replace(['%W', '%M'], ['%u', '%i'], $format);
        return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
    }
}
