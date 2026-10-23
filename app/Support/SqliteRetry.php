<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;

final class SqliteRetry
{
    /** Retry only standalone statements: replaying part of a transaction is unsafe. */
    public static function run(PDO $pdo, callable $operation): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $operation();
            } catch (PDOException $e) {
                $code = (int)($e->errorInfo[1] ?? 0) & 0xff;
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
                    || $pdo->inTransaction() || !in_array($code, [5, 6], true) || $attempt >= 3) {
                    throw $e;
                }
                usleep(50000 * (2 ** $attempt) + random_int(0, 25000));
            }
        }
    }
}
