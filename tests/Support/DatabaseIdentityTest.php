<?php

declare(strict_types=1);

use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseIdentityTest extends TestCase
{
    public function testIdentityDistinguishesDatabasesAndHidesCredentials(): void
    {
        $dir = sys_get_temp_dir() . '/cimaise-dbid-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $a = new Database(database: $dir . '/a.sqlite', isSqlite: true);
            $b = new Database(database: $dir . '/b.sqlite', isSqlite: true);
            self::assertNotSame($a->identity(), $b->identity(), 'The plugin_status flag must not carry over to another database');
            self::assertSame($a->identity(), (new Database(database: $dir . '/a.sqlite', isSqlite: true))->identity());
            self::assertStringStartsWith('sqlite:', $a->identity());
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
