<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Support\Database;
use App\Tasks\ImagesGenerateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** images:generate keeps its historical contract on the shared encoder. */
final class ImagesGenerateCommandTest extends TestCase
{
    private Database $db;
    private mixed $settingsSnapshot;

    protected function setUp(): void
    {
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $this->settingsSnapshot = $property->getValue();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        // One image without an original: generation fails fast, but the run
        // still reaches its summary, which states whether it was forced.
        $this->db->pdo()->exec("INSERT INTO categories(id,name,slug) VALUES(765440,'Fixture','fixture-generate');
            INSERT INTO albums(id,title,slug,category_id,is_published) VALUES(765440,'Fixture','fixture-generate',765440,1);
            INSERT INTO images(id,album_id,original_path,file_hash,width,height,mime) VALUES(765440,765440,'/storage/originals/absent-765440.jpg','absent',10,10,'image/jpeg')");
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->settingsSnapshot);
        @unlink(\App\Support\ImageProcessingLock::directory() . '/765440.lock');
    }

    private function generate(array $input): string
    {
        $tester = new CommandTester(new ImagesGenerateCommand($this->db));
        $tester->execute($input);
        return $tester->getDisplay();
    }

    public function testWithoutOptionsEveryVariantIsRegenerated(): void
    {
        self::assertStringContainsString('FORCE MODE', $this->generate([]));
    }

    public function testMissingOnlyFillsInAbsentVariants(): void
    {
        self::assertStringNotContainsString('FORCE MODE', $this->generate(['--missing' => true]));
    }

    public function testForceStillWinsOverMissing(): void
    {
        self::assertStringContainsString('FORCE MODE', $this->generate(['--missing' => true, '--force' => true]));
    }

    public function testDescriptionIsTheHistoricalOne(): void
    {
        self::assertStringContainsString('unless --missing', (new ImagesGenerateCommand($this->db))->getDescription());
    }

    /** Starts a child process that holds the library lock until it is terminated. */
    private function holdLibraryLock(): array
    {
        $ready = sys_get_temp_dir() . '/cimaise-gen-lock-' . bin2hex(random_bytes(4));
        $lockPath = ImagesGenerateCommand::lockPath();
        if (!is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0775, true);
        }
        $script = '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); touch($argv[2]); sleep(30);';
        $process = proc_open([PHP_BINARY, '-r', $script, $lockPath, $ready], [], $pipes);
        self::assertIsResource($process);
        $deadline = microtime(true) + 5;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFileExists($ready, 'the lock holder did not start');
        return [$process, $ready];
    }

    private function release(array $holder): void
    {
        [$process, $ready] = $holder;
        proc_terminate($process);
        proc_close($process);
        @unlink($ready);
    }

    public function testASecondRunIsRefusedWithoutTouchingTheRunningLog(): void
    {
        $log = dirname(__DIR__, 2) . '/storage/logs/image_generation_test_' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents($log, "first run output\n");
        $holder = $this->holdLibraryLock();
        try {
            self::assertTrue(ImagesGenerateCommand::isRunning());
            $display = $this->generate(['--reset-log' => $log]);
            self::assertStringContainsString('still in progress', $display);
            self::assertStringNotContainsString('FORCE MODE', $display);
            self::assertSame("first run output\n", file_get_contents($log), 'the refused run must not empty the running log');
        } finally {
            $this->release($holder);
            @unlink($log);
        }
        self::assertFalse(ImagesGenerateCommand::isRunning());
    }

    public function testTheRunHoldingTheLockStartsWithAnEmptyLog(): void
    {
        $log = dirname(__DIR__, 2) . '/storage/logs/image_generation_test_' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents($log, "previous run output\n");
        try {
            self::assertStringContainsString('FORCE MODE', $this->generate(['--reset-log' => $log]));
            self::assertSame('', file_get_contents($log));
        } finally {
            @unlink($log);
        }
    }

    public function testResetLogIgnoresPathsOutsideTheLogsDirectory(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'cimaise-outside');
        file_put_contents($outside, 'keep me');
        try {
            $this->generate(['--reset-log' => $outside]);
            self::assertSame('keep me', file_get_contents($outside));
        } finally {
            @unlink($outside);
        }
    }
}
