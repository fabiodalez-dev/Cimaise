<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterSqliteBackupTest extends TestCase
{
    public function testBackupPreservesBinaryTextAndStorageTypes(): void
    {
        $db = new Database(database: ':memory:', isSqlite: true);
        $db->pdo()->exec("CREATE TABLE backup_probe (binary BLOB, text TEXT, number INTEGER, empty TEXT, missing TEXT)");
        $db->pdo()->exec("INSERT INTO backup_probe VALUES (X'00FF27', CAST(X'61006227' AS TEXT), 42, '', NULL)");
        $db->pdo()->exec("CREATE TABLE backup_text (plain TEXT)");
        $db->pdo()->exec("INSERT INTO backup_text VALUES ('plain caffè ''quoted''')");
        $path = tempnam(sys_get_temp_dir(), 'cimaise_backup_');
        try {
            $method = new ReflectionMethod(Updater::class, 'backupSqliteDatabase');
            $result = $method->invoke(new Updater($db), $path);
            self::assertTrue($result['success']);
            $restored = new PDO('sqlite::memory:');
            $restored->exec(file_get_contents($path));
            $query = "SELECT hex(binary), typeof(binary), hex(text), typeof(text), number, typeof(number), empty, missing FROM backup_probe";
            self::assertSame($db->pdo()->query($query)->fetch(PDO::FETCH_NUM), $restored->query($query)->fetch(PDO::FETCH_NUM));
            // Ordinary text stays human-readable in the dump.
            self::assertStringContainsString("'plain caffè ''quoted'''", (string) file_get_contents($path));
            $query = "SELECT plain, typeof(plain) FROM backup_text";
            self::assertSame($db->pdo()->query($query)->fetch(PDO::FETCH_NUM), $restored->query($query)->fetch(PDO::FETCH_NUM));
        } finally {
            unlink($path);
        }
    }
}
