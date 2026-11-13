<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Support\Database;
use App\Support\PluginManager;
use PHPUnit\Framework\TestCase;


/** Regression for audit finding C11 (+ deactivate slug confinement). */
final class PluginUninstallTombstoneTest extends TestCase
{
    private string $dir;
    private ?\Closure $restoreCache = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cimaise_plugin_tomb_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/plugins/tomb-probe', 0777, true);
        file_put_contents($this->dir . '/plugins/tomb-probe/plugin.php', "<?php\n");
        file_put_contents($this->dir . '/plugins/tomb-probe/install.php', "<?php\nfile_put_contents(__DIR__ . '/../../install.log', \"x\\n\", FILE_APPEND);\n");
        require_once dirname(__DIR__) . '/Support/TempQueryCache.php';
        $this->restoreCache = \TempQueryCache::install();
        $ref = new \ReflectionProperty(PluginManager::class, 'instance');
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(PluginManager::class, 'instance'))->setValue(null, null);
        if ($this->restoreCache) {
            ($this->restoreCache)();
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function manager(): PluginManager
    {
        $pm = PluginManager::getInstance();
        $pm->setDatabase(new Database(database: $this->dir . '/db.sqlite', isSqlite: true));
        return $pm;
    }

    public function testUninstallIsNotUndoneByNextRequest(): void
    {
        $plugins = $this->dir . '/plugins';
        $pm = $this->manager();
        $pm->loadPlugins($plugins); // auto-install of a dropped-in plugin
        self::assertTrue($pm->isPluginActive('tomb-probe'));

        self::assertTrue($pm->deactivatePlugin('tomb-probe', $plugins)['success']);
        self::assertTrue($pm->uninstallPlugin('tomb-probe', $plugins)['success']);

        // Next request: fresh singleton, same DB and plugins dir.
        (new \ReflectionProperty(PluginManager::class, 'instance'))->setValue(null, null);
        $pm = $this->manager();
        $pm->loadPlugins($plugins);

        self::assertFalse($pm->isPluginActive('tomb-probe'));
        self::assertFalse((bool) ($pm->getPluginStatus('tomb-probe')['is_installed'] ?? true));
        self::assertSame(1, substr_count((string) @file_get_contents($this->dir . '/install.log'), 'x'));

        // An explicit admin install still works.
        self::assertTrue($pm->installPlugin('tomb-probe', $plugins)['success']);
    }

    public function testDeactivateRejectsTraversalSlug(): void
    {
        $pm = $this->manager();
        $result = $pm->deactivatePlugin('../../outside', $this->dir . '/plugins');
        self::assertFalse($result['success']);
    }
}
