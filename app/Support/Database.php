<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

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
        private string $collation = 'utf8mb4_0900_ai_ci',
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
            ];
            $this->pdo = new PDO($dsn, null, null, $options);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
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
        $this->pdo->exec($sql);
    }

    public function isSqlite(): bool
    {
        return $this->isSqlite;
    }

    public function isMySQL(): bool
    {
        return !$this->isSqlite;
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
        return $this->isSqlite ? 'datetime("now")' : 'NOW()';
    }

    // Helper for portable date/time interval subtraction
    public function dateSubExpression(string $interval, int $value): string
    {
        if ($this->isSqlite) {
            return "datetime(\"now\", \"-{$value} {$interval}\")";
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
}
