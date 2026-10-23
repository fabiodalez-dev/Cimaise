<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestFilesystem.php';

/** Runs the real worker loop against a stubbed `php` and an isolated tree. */
final class ImageWorkerTest extends TestCase
{
    private string $root;
    /** @var resource|null */
    private $process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cimaise_worker_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/bin', 0775, true);
        mkdir($this->root . '/storage/image-jobs', 0775, true);
        // Stands in for `php bin/console images:work`: records the call and
        // completes every queued job, as a successful drain would.
        file_put_contents($this->root . '/bin/php', "#!/bin/sh\necho \"\$*\" >> '{$this->root}/calls.log'\nrm -f '{$this->root}'/storage/image-jobs/*.job\n");
        chmod($this->root . '/bin/php', 0755);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            // Signal 9 works without requiring the optional pcntl extension.
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
        TestFilesystem::remove($this->root);
    }

    private function start(): void
    {
        $env = ['PATH' => $this->root . '/bin:/usr/bin:/bin', 'CIMAISE_IMAGE_WORKER_INTERVAL' => '1'];
        // nosemgrep: php.lang.security.exec-use.exec-use -- fixed argv (no shell): the worker script under test and this fixture's own temp directory.
        $this->process = proc_open(
            ['/bin/sh', dirname(__DIR__, 2) . '/bin/image-worker.sh', $this->root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->root,
            $env
        );
        self::assertIsResource($this->process);
    }

    private function calls(): int
    {
        clearstatcache();
        return is_file($this->root . '/calls.log') ? count(file($this->root . '/calls.log') ?: []) : 0;
    }

    private function waitForCalls(int $expected, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while ($this->calls() < $expected && microtime(true) < $deadline) {
            usleep(100000);
        }
    }

    public function testPhpIsStartedOnlyWhileJobsAreQueued(): void
    {
        file_put_contents($this->root . '/.env', "DB_CONNECTION=sqlite\n");
        $this->start();

        // Idle queue: several intervals pass without booting PHP.
        usleep(2500000);
        self::assertSame(0, $this->calls());

        file_put_contents($this->root . '/storage/image-jobs/42.job', '');
        $this->waitForCalls(1, 5.0);
        self::assertSame(1, $this->calls());
        self::assertStringContainsString('images:work', (string) file_get_contents($this->root . '/calls.log'));

        // Queue empty again: no further runs.
        usleep(2500000);
        self::assertSame(1, $this->calls());
    }

    public function testNothingRunsBeforeInstallOrDuringAnUpdate(): void
    {
        file_put_contents($this->root . '/storage/image-jobs/42.job', '');
        $this->start();
        usleep(2500000);
        self::assertSame(0, $this->calls(), 'no .env yet: the installer has not run');

        file_put_contents($this->root . '/.env', "DB_CONNECTION=sqlite\n");
        file_put_contents($this->root . '/storage/.maintenance', '');
        usleep(2500000);
        self::assertSame(0, $this->calls(), 'update in progress');

        TestFilesystem::remove($this->root . '/storage/.maintenance');
        $this->waitForCalls(1, 5.0);
        self::assertSame(1, $this->calls());
    }

    public function testBackingOffAndParkedJobsDoNotStartPhp(): void
    {
        file_put_contents($this->root . '/.env', "DB_CONNECTION=sqlite\n");
        file_put_contents($this->root . '/storage/image-jobs/42.job', json_encode(['attempts' => 3, 'next_at' => time() + 3600]));
        touch($this->root . '/storage/image-jobs/43.failed');
        $this->start();

        usleep(2500000);
        self::assertSame(0, $this->calls(), 'a job waiting for its retry, or parked, must not boot PHP');

        // Its retry time has come.
        file_put_contents($this->root . '/storage/image-jobs/42.job', json_encode(['attempts' => 3, 'next_at' => time() - 1]));
        $this->waitForCalls(1, 5.0);
        self::assertSame(1, $this->calls());
    }
}
