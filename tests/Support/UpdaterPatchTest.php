<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\Updater;
use App\Support\Database;
use App\Support\PluginSignature;

// Updater::debugLog() -> Logger uses the global envv() helper, which lives in
// app/Config/bootstrap.php (not loaded by the PHPUnit bootstrap). Provide a
// minimal shim so the patch methods' logging doesn't fatal under test.
if (!function_exists('envv')) {
    function envv(string $key, $default = null)
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null) ? $default : $v;
    }
}

/**
 * Coverage for the signed remote-patch mechanism: the fail-closed signature
 * gate and the three guarded operation types (file search-replace, file
 * delete, SQL). Private methods are reflected off a constructor-less Updater
 * with rootPath + db injected.
 */
final class UpdaterPatchTest extends TestCase
{
    private Updater $updater;
    private string $root;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cimaise_patch_' . uniqid();
        mkdir($this->root . '/storage/tmp', 0775, true);
        $this->dbFile = $this->root . '/test.sqlite';

        $db = new Database(database: $this->dbFile, isSqlite: true);

        $ref = new ReflectionClass(Updater::class);
        $this->updater = $ref->newInstanceWithoutConstructor();
        $this->setProp('rootPath', $this->root);
        $this->setProp('db', $db);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function setProp(string $name, mixed $value): void
    {
        $p = new ReflectionProperty(Updater::class, $name);
        $p->setAccessible(true);
        $p->setValue($this->updater, $value);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(Updater::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->updater, ...$args);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ---- fetchSignedReleaseAsset fail-closed ------------------------------

    public function testFetchSignedAssetReturnsNullWhenSigningDisabled(): void
    {
        putenv('PLUGIN_SIGNING_PUBKEY');
        unset($_ENV['PLUGIN_SIGNING_PUBKEY'], $_SERVER['PLUGIN_SIGNING_PUBKEY']);
        if (PluginSignature::isEnabled()) {
            $this->markTestSkipped('a signing key is configured in this environment');
        }
        $this->assertNull($this->call('fetchSignedReleaseAsset', '1.5.0', 'post-install-patch.php'));
    }

    public function testApplyPostInstallPatchIsNoopWhenSigningDisabled(): void
    {
        putenv('PLUGIN_SIGNING_PUBKEY');
        unset($_ENV['PLUGIN_SIGNING_PUBKEY'], $_SERVER['PLUGIN_SIGNING_PUBKEY']);
        if (PluginSignature::isEnabled()) {
            $this->markTestSkipped('a signing key is configured in this environment');
        }
        $res = $this->updater->applyPostInstallPatch('1.5.0');
        $this->assertTrue($res['success']);
        $this->assertFalse($res['applied']);
    }

    // ---- applySinglePatch -------------------------------------------------

    public function testApplySinglePatchReplacesUniqueOccurrence(): void
    {
        file_put_contents($this->root . '/conf.php', "<?php return ['debug' => false];");
        $res = $this->call('applySinglePatch', [
            'file' => 'conf.php',
            'search' => "'debug' => false",
            'replace' => "'debug' => true",
        ]);
        $this->assertTrue($res['success']);
        $this->assertStringContainsString("'debug' => true", file_get_contents($this->root . '/conf.php'));
    }

    public function testApplySinglePatchRejectsNonUniqueSearch(): void
    {
        file_put_contents($this->root . '/dup.txt', "x\nx\n");
        $res = $this->call('applySinglePatch', ['file' => 'dup.txt', 'search' => 'x', 'replace' => 'y']);
        $this->assertFalse($res['success']);
    }

    public function testApplySinglePatchRejectsPathTraversal(): void
    {
        $res = $this->call('applySinglePatch', ['file' => '../../etc/hosts', 'search' => 'a', 'replace' => 'b']);
        $this->assertFalse($res['success']);
        $this->assertSame('Invalid file path', $res['error']);
    }

    public function testApplySinglePatchRejectsMissingKeys(): void
    {
        $res = $this->call('applySinglePatch', ['file' => 'conf.php']);
        $this->assertFalse($res['success']);
    }

    // ---- cleanupPatchFile -------------------------------------------------

    public function testCleanupDeletesFileInRoot(): void
    {
        file_put_contents($this->root . '/stale.txt', 'gone');
        $res = $this->call('cleanupPatchFile', 'stale.txt');
        $this->assertTrue($res['success']);
        $this->assertFileDoesNotExist($this->root . '/stale.txt');
    }

    public function testCleanupRefusesProtectedFiles(): void
    {
        foreach (['.env', 'version.json', 'composer.json', 'public/index.php'] as $p) {
            $res = $this->call('cleanupPatchFile', $p);
            $this->assertFalse($res['success'], "$p must be protected");
            $this->assertSame('Cannot delete protected file', $res['error']);
        }
    }

    public function testCleanupRefusesTraversalToExistingOutsideFile(): void
    {
        // A real file OUTSIDE the root must not be deletable via traversal.
        $outside = dirname($this->root) . '/cimaise_outside_' . uniqid() . '.txt';
        file_put_contents($outside, 'keep me');
        try {
            $rel = '../' . basename($outside);
            $res = $this->call('cleanupPatchFile', $rel);
            $this->assertFalse($res['success'], 'must refuse a path resolving outside root');
            $this->assertFileExists($outside, 'the outside file must survive');
        } finally {
            @unlink($outside);
        }
    }

    public function testCleanupMissingFileIsIdempotentSuccess(): void
    {
        $res = $this->call('cleanupPatchFile', 'never_existed.txt');
        $this->assertTrue($res['success']);
    }

    // ---- executePostInstallSql -------------------------------------------

    public function testSqlBlocklistRefusesCatastrophicStatements(): void
    {
        foreach ([
            'DROP DATABASE cimaise',
            'TRUNCATE TABLE users',
            'TRUNCATE users',
            'DELETE FROM users WHERE 1',
        ] as $sql) {
            $res = $this->call('executePostInstallSql', $sql);
            $this->assertFalse($res['success'], "must block: $sql");
            $this->assertSame('Dangerous SQL blocked', $res['error']);
        }
    }

    public function testSqlExecutesSafeStatement(): void
    {
        $res = $this->call('executePostInstallSql', 'CREATE TABLE patch_marker (id INTEGER PRIMARY KEY)');
        $this->assertTrue($res['success']);
        $count = $this->updaterDb()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='patch_marker'")->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testSqlToleratesIdempotentError(): void
    {
        $this->call('executePostInstallSql', 'CREATE TABLE dupe (id INTEGER)');
        // Re-create without IF NOT EXISTS → "table already exists" must be tolerated.
        $res = $this->call('executePostInstallSql', 'CREATE TABLE dupe (id INTEGER)');
        $this->assertTrue($res['success']);
    }

    private function updaterDb(): PDO
    {
        $p = new ReflectionProperty(Updater::class, 'db');
        $p->setAccessible(true);
        return $p->getValue($this->updater)->pdo();
    }
}
