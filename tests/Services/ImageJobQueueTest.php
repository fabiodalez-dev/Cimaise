<?php

declare(strict_types=1);

use App\Services\ImageJobQueue;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestFilesystem.php';

final class ImageJobQueueTest extends TestCase
{
    private string $directory;
    private Database $db;
    /** @var int[] */
    private array $processed = [];
    /** @var array<int, int[]> */
    private array $invalidations = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cimaise-jobs-' . bin2hex(random_bytes(6));
        $this->db = new Database(database: ':memory:', isSqlite: true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove($this->directory);
    }

    /** @param (\Closure(int): ?int)|null $processor */
    private function queue(?\Closure $processor = null): ImageJobQueue
    {
        return new ImageJobQueue(
            $this->db,
            $this->directory,
            $processor ?? function (int $imageId): int {
                $this->processed[] = $imageId;
                return $imageId < 10 ? 1 : 2; // album id
            },
            function (array $albumIds): void {
                $this->invalidations[] = $albumIds;
            }
        );
    }

    private function state(int $imageId): array
    {
        return (array) json_decode((string) file_get_contents($this->directory . '/' . $imageId . '.job'), true);
    }

    public function testDrainProcessesEveryJobAndRefreshesEachAlbumOnce(): void
    {
        $queue = $this->queue();
        foreach ([1, 2, 3, 11] as $imageId) {
            $queue->enqueue($imageId);
        }
        self::assertSame(4, $queue->pending());

        $result = $queue->drain();

        self::assertSame(['processed' => 4, 'failed' => 0, 'remaining' => 0], $result);
        self::assertEqualsCanonicalizing([1, 2, 3, 11], $this->processed);
        // Four images, two albums: one cache refresh, not one per image.
        self::assertCount(1, $this->invalidations);
        self::assertEqualsCanonicalizing([1, 2], $this->invalidations[0]);
        self::assertFalse($queue->hasJobs());
    }

    public function testEmptyQueueDoesNothing(): void
    {
        self::assertSame(['processed' => 0, 'failed' => 0, 'remaining' => 0], $this->queue()->drain());
        self::assertSame([], $this->invalidations);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testFailedJobIsKeptWithABackoffAndNotRetriedBeforeItIsDue(): void
    {
        $attempts = 0;
        $queue = $this->queue(function (int $imageId) use (&$attempts): int {
            $attempts++;
            throw new RuntimeException('encoder crashed');
        });
        $queue->enqueue(5);

        self::assertSame(['processed' => 0, 'failed' => 1, 'remaining' => 1], $queue->drain());
        $state = $this->state(5);
        self::assertSame(1, $state['attempts']);
        self::assertGreaterThan(time(), $state['next_at']);

        // Still backing off: a second drain must not touch it.
        self::assertSame(['processed' => 0, 'failed' => 0, 'remaining' => 1], $queue->drain());
        self::assertSame(1, $attempts);
        self::assertSame([], $this->invalidations);
    }

    public function testRetrySucceedsOnceTheBackoffHasElapsed(): void
    {
        $queue = $this->queue();
        $queue->enqueue(5);
        file_put_contents($this->directory . '/5.job', json_encode(['attempts' => 2, 'next_at' => time() - 1]));

        self::assertSame(['processed' => 1, 'failed' => 0, 'remaining' => 0], $queue->drain());
        self::assertSame([5], $this->processed);
    }

    public function testJobIsParkedAfterMaxAttempts(): void
    {
        $queue = $this->queue(static function (): int {
            throw new RuntimeException('corrupt original');
        });
        $queue->enqueue(5);
        file_put_contents($this->directory . '/5.job', json_encode([
            'attempts' => ImageJobQueue::MAX_ATTEMPTS - 1,
            'next_at' => 0,
        ]));

        self::assertSame(['processed' => 0, 'failed' => 1, 'remaining' => 0], $queue->drain());
        self::assertFileDoesNotExist($this->directory . '/5.job');
        self::assertFileExists($this->directory . '/5.failed');
        self::assertFalse($queue->hasJobs());
        self::assertSame(1, $queue->failed());
    }

    public function testEnqueueingAParkedJobStartsItAfresh(): void
    {
        $queue = $this->queue();
        mkdir($this->directory, 0775, true);
        touch($this->directory . '/5.failed');

        $queue->enqueue(5);

        self::assertFileDoesNotExist($this->directory . '/5.failed');
        self::assertSame(['processed' => 1, 'failed' => 0, 'remaining' => 0], $queue->drain());
        self::assertSame([5], $this->processed);
    }

    public function testJobsStillBackingOffAreNotDue(): void
    {
        $queue = $this->queue();
        $queue->enqueue(5);
        file_put_contents($this->directory . '/5.job', json_encode(['attempts' => 2, 'next_at' => time() + 600]));

        self::assertTrue($queue->hasJobs());
        self::assertFalse($queue->hasDueJobs());

        file_put_contents($this->directory . '/5.job', json_encode(['attempts' => 2, 'next_at' => time() - 1]));
        self::assertTrue($queue->hasDueJobs());
    }

    public function testDrainRemovesLocksOfDeletedImagesOnly(): void
    {
        $this->db->pdo()->exec('CREATE TABLE images (id INTEGER PRIMARY KEY)');
        $this->db->pdo()->exec('INSERT INTO images (id) VALUES (7)');
        $queue = $this->queue();
        $queue->enqueue(1);
        touch($this->directory . '/7.lock');
        touch($this->directory . '/8.lock');

        $queue->drain();

        self::assertFileExists($this->directory . '/7.lock');
        self::assertFileDoesNotExist($this->directory . '/8.lock');
    }

    public function testSynchronousRunGivesUpWhileADrainHoldsTheLockAndKeepsTheJob(): void
    {
        $queue = new ImageJobQueue($this->db, $this->directory, function (int $imageId): int {
            $this->processed[] = $imageId;
            return 1;
        }, static function (): void {}, syncLockWait: 0.5);
        mkdir($this->directory, 0775, true);
        $held = fopen($this->directory . '/worker.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX));
        try {
            $started = microtime(true);
            try {
                $queue->runSynchronously(5);
                self::fail('A busy worker lock must not block the upload indefinitely');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('left queued', $e->getMessage());
            }
            self::assertLessThan(5.0, microtime(true) - $started);
            self::assertSame([], $this->processed);
            self::assertFileExists($this->directory . '/5.job');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $queue->runSynchronously(5);
        self::assertSame([5], $this->processed);
        self::assertFileDoesNotExist($this->directory . '/5.job');
    }

    public function testEnqueueingAgainKeepsTheRetryState(): void
    {
        $queue = $this->queue();
        $queue->enqueue(5);
        $state = ['attempts' => 3, 'next_at' => time() + 600];
        file_put_contents($this->directory . '/5.job', json_encode($state));

        $queue->enqueue(5);

        self::assertSame($state, $this->state(5));
        self::assertSame(1, $queue->pending());
    }

    public function testJobsEnqueuedWhileDrainingAreProcessedByTheSameRun(): void
    {
        $queue = null;
        $queue = $this->queue(function (int $imageId) use (&$queue): int {
            $this->processed[] = $imageId;
            if ($imageId === 1) {
                $queue->enqueue(2); // another upload arrives meanwhile
            }
            return 1;
        });
        $queue->enqueue(1);

        self::assertSame(['processed' => 2, 'failed' => 0, 'remaining' => 0], $queue->drain());
        self::assertSame([1, 2], $this->processed);
    }

    public function testOnlyOneDrainRunsAtATime(): void
    {
        $queue = $this->queue();
        $queue->enqueue(1);
        $lock = fopen($this->directory . '/worker.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            self::assertSame(['processed' => 0, 'failed' => 0, 'remaining' => 1], $queue->drain());
            self::assertSame([], $this->processed);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame(1, $queue->drain()['processed']);
    }

    public function testTimeBudgetStopsStartingNewJobsAndLeavesThemQueued(): void
    {
        $queue = $this->queue(function (int $imageId): int {
            $this->processed[] = $imageId;
            sleep(1);
            return 1;
        });
        $queue->enqueue(1);
        $queue->enqueue(2);

        $result = $queue->drain(1);

        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['remaining']);
        self::assertCount(1, $this->invalidations, 'finished work is still published');
    }

    public function testDeletedImageCompletesItsJobWithoutACacheRefresh(): void
    {
        $queue = $this->queue(static fn (int $imageId): ?int => null);
        $queue->enqueue(7);

        self::assertSame(['processed' => 1, 'failed' => 0, 'remaining' => 0], $queue->drain());
        self::assertSame([], $this->invalidations);
    }

    public function testAFailingCacheRefreshDoesNotFailTheJobs(): void
    {
        $queue = new ImageJobQueue(
            $this->db,
            $this->directory,
            static fn (int $imageId): int => 1,
            static function (): void {
                throw new RuntimeException('page cache unavailable');
            }
        );
        $queue->enqueue(1);
        $queue->enqueue(2);

        self::assertSame(['processed' => 2, 'failed' => 0, 'remaining' => 0], $queue->drain());
    }

    public function testAlbumsOfAFailedPeriodicRefreshAreRefreshedAgainLater(): void
    {
        $calls = [];
        $queue = new ImageJobQueue(
            $this->db,
            $this->directory,
            static fn (int $imageId): int => $imageId, // one album per image
            static function (array $albumIds) use (&$calls): void {
                $calls[] = $albumIds;
                if (count($calls) === 1) {
                    throw new RuntimeException('page cache unavailable');
                }
            },
            0 // refresh after every job
        );
        $queue->enqueue(1);
        $queue->enqueue(2);

        self::assertSame(2, $queue->drain()['processed']);
        self::assertSame([1], $calls[0]);
        self::assertEqualsCanonicalizing([1, 2], $calls[1], 'album 1 must not be forgotten after its refresh failed');
    }

    public function testStrayFilesAreNotTreatedAsJobs(): void
    {
        $queue = $this->queue();
        $queue->enqueue(1);
        file_put_contents($this->directory . '/notes.job', '');
        file_put_contents($this->directory . '/1.job.tmp', '');

        self::assertSame(1, $queue->pending());
        self::assertSame(1, $queue->drain()['processed']);
        self::assertSame([1], $this->processed);
    }
}
