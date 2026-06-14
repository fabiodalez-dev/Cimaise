<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\Updater;

// Updater logging goes through the global envv() helper (defined in
// app/Config/bootstrap.php, not loaded by the PHPUnit bootstrap). Provide a
// minimal shim so safeUnlink()'s refusal-path Logger::warning() doesn't fatal.
if (!function_exists('envv')) {
    function envv(string $key, $default = null)
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null) ? $default : $v;
    }
}

/**
 * Coverage for the root-confined deletion helpers added in the updater
 * hardening: safeUnlink() (every in-class unlink funnels through it) and
 * forceDeleteDirectory() (the pure-PHP replacement for the shell `rm -rf`
 * fallback). Both are private and reflected off a constructor-less Updater
 * with only rootPath injected.
 */
final class UpdaterSafeDeletionTest extends TestCase
{
    private Updater $updater;
    private string $root;
    /** @var list<string> extra paths created outside the root, cleaned up at teardown */
    private array $external = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cimaise_del_' . uniqid();
        mkdir($this->root, 0775, true);

        $this->updater = (new ReflectionClass(Updater::class))->newInstanceWithoutConstructor();
        $p = new ReflectionProperty(Updater::class, 'rootPath');
        $p->setAccessible(true);
        $p->setValue($this->updater, $this->root);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        foreach ($this->external as $path) {
            if (is_dir($path)) {
                $this->rrmdir($path);
            } elseif (is_file($path) || is_link($path)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- teardown of test-created fixtures under sys_get_temp_dir().
                @unlink($path);
            }
        }
    }

    /** @return mixed */
    private function call(string $method, mixed ...$args)
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
            @chmod($path, 0777);
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- teardown recursion over $this->root, a self-created sys_get_temp_dir() fixture tree.
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ---- safeUnlink(): containment ----------------------------------------

    public function testDeletesFileInsideRoot(): void
    {
        $f = $this->root . '/inside.txt';
        file_put_contents($f, 'x');
        $this->assertTrue($this->call('safeUnlink', $f));
        $this->assertFileDoesNotExist($f);
    }

    public function testDeletesFileInNestedSubdir(): void
    {
        mkdir($this->root . '/a/b', 0775, true);
        $f = $this->root . '/a/b/deep.txt';
        file_put_contents($f, 'x');
        $this->assertTrue($this->call('safeUnlink', $f));
        $this->assertFileDoesNotExist($f);
    }

    public function testRefusesFileOutsideRoot(): void
    {
        $outside = dirname($this->root) . '/cimaise_outside_' . uniqid() . '.txt';
        $this->external[] = $outside;
        file_put_contents($outside, 'keep me');

        $this->assertFalse($this->call('safeUnlink', $outside), 'must refuse a file outside the root');
        $this->assertFileExists($outside, 'the outside file must survive');
    }

    public function testRefusesTraversalPathResolvingOutsideRoot(): void
    {
        $outside = dirname($this->root) . '/cimaise_trav_' . uniqid() . '.txt';
        $this->external[] = $outside;
        file_put_contents($outside, 'keep me');

        $traversal = $this->root . '/../' . basename($outside);
        $this->assertFalse($this->call('safeUnlink', $traversal), 'must refuse a traversal escaping the root');
        $this->assertFileExists($outside, 'the outside file must survive the traversal attempt');
    }

    public function testIdempotentOnMissingFile(): void
    {
        // A never-existed file is treated as success (nothing to delete).
        $this->assertTrue($this->call('safeUnlink', $this->root . '/never_existed.txt'));
    }

    // ---- safeUnlink(): symlinks are removed as links, never followed -------

    public function testRemovesSymlinkInsideRootKeepingTarget(): void
    {
        $target = $this->root . '/target.txt';
        file_put_contents($target, 'data');
        $link = $this->root . '/link.txt';
        symlink($target, $link);

        $this->assertTrue($this->call('safeUnlink', $link));
        $this->assertFalse(is_link($link), 'the symlink itself must be removed');
        $this->assertFileExists($target, 'the symlink target must survive');
    }

    public function testSymlinkToOutsideTargetRemovesLinkButKeepsExternalTarget(): void
    {
        // The link lives inside the root but points OUTSIDE it. safeUnlink must
        // remove the link (its location is contained) without following it out
        // of the root and deleting the external target.
        $externalTarget = dirname($this->root) . '/cimaise_ext_' . uniqid() . '.txt';
        $this->external[] = $externalTarget;
        file_put_contents($externalTarget, 'external');

        $link = $this->root . '/escape.txt';
        symlink($externalTarget, $link);

        $this->assertTrue($this->call('safeUnlink', $link));
        $this->assertFalse(is_link($link), 'the link must be removed');
        $this->assertFileExists($externalTarget, 'the external target must NOT be followed/deleted');
    }

    public function testDoesNotDeleteADirectory(): void
    {
        // safeUnlink only handles files/symlinks; a directory hits the
        // is_file/is_link guard and is left intact (reported as no-op success).
        $dir = $this->root . '/a_dir';
        mkdir($dir, 0775);
        $this->assertTrue($this->call('safeUnlink', $dir));
        $this->assertDirectoryExists($dir, 'safeUnlink must not remove directories');
    }

    // ---- forceDeleteDirectory(): pure-PHP forced removal -------------------

    public function testForceDeleteRemovesTreeWithReadOnlyFile(): void
    {
        $dir = $this->root . '/tree';
        mkdir($dir . '/nested', 0775, true);
        file_put_contents($dir . '/nested/ro.txt', 'x');
        chmod($dir . '/nested/ro.txt', 0444); // read-only file

        $this->call('forceDeleteDirectory', $dir);
        $this->assertDirectoryDoesNotExist($dir, 'the whole tree must be removed despite the read-only file');
    }

    public function testForceDeleteRemovesDeepTreeAndIsNoopOnMissingDir(): void
    {
        $dir = $this->root . '/deep';
        mkdir($dir . '/l1/l2/l3', 0775, true);
        file_put_contents($dir . '/l1/l2/l3/file.txt', 'x');
        file_put_contents($dir . '/l1/top.txt', 'y');

        $this->call('forceDeleteDirectory', $dir);
        $this->assertDirectoryDoesNotExist($dir, 'a deeply nested tree must be removed');

        // No-op (no exception) when the directory is already gone.
        $this->call('forceDeleteDirectory', $dir);
        $this->assertDirectoryDoesNotExist($dir);
    }
}
