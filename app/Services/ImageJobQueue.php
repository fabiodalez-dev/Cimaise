<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use RuntimeException;

/**
 * Durable queue of "generate the variants of this image" jobs.
 *
 * One empty file per image under storage/image-jobs: no schema, and a job
 * survives a killed PHP process or a container restart. A job is removed only
 * after its image has every variant this runtime can produce; a failure keeps
 * it with an exponential backoff with a capped delay. After MAX_ATTEMPTS
 * failures the job is parked as `{id}.failed`: it no longer wakes the worker
 * or admin requests, and enqueueing the image again (re-upload, regenerate)
 * starts it afresh.
 *
 * Who drains it:
 *  - PHP-FPM: the upload request itself, after the response has been sent;
 *  - the Docker image: bin/image-worker.sh, which starts `images:work` only
 *    while jobs exist;
 *  - anything else: `php bin/console images:work` from cron, or maintenance:run.
 */
class ImageJobQueue
{
    /** Failures after which a job is parked instead of retried. */
    public const MAX_ATTEMPTS = 10;
    /** A synchronous upload waits at most this long for a running drain. */
    private const SYNC_LOCK_WAIT = 20;
    private const BACKOFF_BASE = 30;
    private const BACKOFF_MAX = 3600;
    /** Page caches are refreshed at most this often while a long batch runs. */
    private const INVALIDATION_INTERVAL = 30;

    private readonly string $directory;
    /** @var \Closure(int): ?int Generates one image; returns its album id (null: image gone). */
    private readonly \Closure $processor;
    /** @var \Closure(int[]): void Invalidates the page caches of the given albums. */
    private readonly \Closure $invalidator;

    public function __construct(
        private readonly Database $db,
        ?string $directory = null,
        ?\Closure $processor = null,
        ?\Closure $invalidator = null,
        private readonly int $invalidationInterval = self::INVALIDATION_INTERVAL,
        private readonly float $syncLockWait = self::SYNC_LOCK_WAIT
    ) {
        $this->directory = $directory ?? dirname(__DIR__, 2) . '/storage/image-jobs';
        $this->processor = $processor ?? $this->process(...);
        $this->invalidator = $invalidator ?? $this->invalidateAlbums(...);
    }

    public function enqueue(int $imageId): void
    {
        if ($imageId <= 0) {
            return;
        }
        $this->ensureDirectory();
        $path = $this->jobPath($imageId);
        // An explicit enqueue is a fresh request: a parked job starts over.
        $parked = $this->failedPath($imageId);
        if (is_file($parked)) {
            UploadService::safeUnlink($parked);
        }
        // Exclusive create: enqueueing twice must not reset the retry state.
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fclose($handle);
        } elseif (!is_file($path)) {
            throw new RuntimeException('Cannot persist image job ' . $imageId);
        }
    }

    public function hasJobs(): bool
    {
        return $this->jobFiles() !== [];
    }

    /** Jobs that may run now; those still backing off do not count. */
    public function hasDueJobs(): bool
    {
        return $this->dueJobs() !== [];
    }

    /** Jobs parked after MAX_ATTEMPTS failures. */
    public function failed(): int
    {
        clearstatcache();
        return count(glob($this->directory . '/*.failed') ?: []);
    }

    public function pending(): int
    {
        return count($this->jobFiles());
    }

    /**
     * Process every due job, including those enqueued while this call runs.
     *
     * Only one drain runs at a time; a second caller returns at once and its
     * jobs are picked up by the running one.
     *
     * @param int $timeBudget Seconds after which no further job is started (0: unlimited).
     * @return array{processed: int, failed: int, remaining: int}
     */
    public function drain(int $timeBudget = 0): array
    {
        $result = ['processed' => 0, 'failed' => 0, 'remaining' => 0];
        if (!is_dir($this->directory)) {
            return $result;
        }
        $lock = @fopen($this->directory . '/worker.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the image worker lock');
        }
        $albums = [];
        $lastInvalidation = time();
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                $result['remaining'] = $this->pending();
                return $result;
            }
            $deadline = $timeBudget > 0 ? time() + $timeBudget : null;
            // A job that failed in this run is not retried by it, even if its
            // backoff could not be written.
            $failed = [];
            while (($due = array_diff_key($this->dueJobs(), $failed)) !== []) {
                foreach ($due as $imageId => $state) {
                    if ($deadline !== null && time() >= $deadline) {
                        break 2;
                    }
                    try {
                        $albumId = ($this->processor)($imageId);
                        $this->complete($imageId);
                        $result['processed']++;
                        if ($albumId !== null) {
                            $albums[$albumId] = true;
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $failed[$imageId] = true;
                        $this->fail($imageId, $state, $e);
                    }
                    // One refresh for the batch, not one per image; a long
                    // batch still publishes its progress now and then.
                    if ($albums !== [] && time() - $lastInvalidation >= $this->invalidationInterval) {
                        // On failure the albums stay listed for the next refresh.
                        if ($this->refreshPageCaches(array_keys($albums))) {
                            $albums = [];
                        }
                        $lastInvalidation = time();
                    }
                }
            }
            $this->pruneOrphanLocks();
        } finally {
            if ($albums !== []) {
                $this->refreshPageCaches(array_keys($albums));
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $result['remaining'] = $this->pending();
        return $result;
    }

    /**
     * Per-image lock files are never unlinked while an image exists (see
     * ImageProcessingLock). Those of deleted images can no longer be contended
     * and are removed here, under the worker lock.
     */
    private function pruneOrphanLocks(): void
    {
        $locks = [];
        foreach (glob($this->directory . '/*.lock') ?: [] as $path) {
            $id = basename($path, '.lock');
            if (ctype_digit($id) && !is_file($this->jobPath((int) $id))) {
                $locks[(int) $id] = $path;
            }
        }
        if ($locks === []) {
            return;
        }
        try {
            $existing = [];
            foreach (array_chunk(array_keys($locks), 500) as $chunk) {
                $stmt = $this->db->pdo()->prepare(
                    'SELECT id FROM images WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
                );
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
                    $existing[(int) $id] = true;
                }
            }
            foreach (array_diff_key($locks, $existing) as $path) {
                UploadService::safeUnlink($path);
            }
        } catch (\Throwable $e) {
            Logger::warning('Image queue: lock cleanup skipped', ['error' => $e->getMessage()], 'upload');
        }
    }

    /**
     * The page cache is a side effect: its failure must not stop the jobs.
     *
     * @param int[] $albumIds
     */
    private function refreshPageCaches(array $albumIds): bool
    {
        try {
            ($this->invalidator)($albumIds);
            return true;
        } catch (\Throwable $e) {
            Logger::warning('Image queue: page cache invalidation failed', ['error' => $e->getMessage()], 'upload');
            return false;
        }
    }

    /**
     * Drain once the response has reached the client. Only PHP-FPM can keep
     * working after closing the connection; elsewhere a web process would hold
     * the request open, so the jobs are left to the worker / CLI.
     */
    public function drainAfterResponse(int $timeBudget = 240): void
    {
        static $scheduled = false;
        if ($scheduled || PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
            return;
        }
        $scheduled = true;
        register_shutdown_function(function () use ($timeBudget): void {
            // Release the session lock first: generation can take minutes and
            // the same admin keeps uploading and browsing meanwhile.
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            @fastcgi_finish_request();
            ignore_user_abort(true);
            @set_time_limit($timeBudget + 60);
            try {
                if (!$this->hasDueJobs()) {
                    return;
                }
                // The connection sat idle while the client downloaded the
                // response; make sure the server did not reap it.
                $this->db->pdo()->query('SELECT 1');
                $this->drain($timeBudget);
            } catch (\Throwable $e) {
                Logger::warning('Image queue: deferred to the next run', ['error' => $e->getMessage()], 'upload');
            }
        });
    }

    /**
     * Generate everything one image needs: the variant matrix, the LQIP
     * placeholder and, for protected albums, the blurred preview.
     *
     * @return int|null Album id, or null when the image no longer exists.
     */
    public function process(int $imageId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.album_id, a.is_nsfw, a.password_hash FROM images i JOIN albums a ON a.id = i.album_id WHERE i.id = ?'
        );
        $stmt->execute([$imageId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            return null;
        }

        $this->report($imageId, ['state' => 'processing', 'completed' => 0, 'total' => 0, 'current' => '', 'next_at' => 0]);
        $settings = new SettingsService($this->db);
        $uploads = new UploadService($this->db);
        $stats = $uploads->generateVariantsForImage($imageId, progress: function (int $completed, int $total, string $current) use ($imageId): void {
            $steps = 1;
            $this->report($imageId, ['state' => 'processing', 'next_at' => 0, 'completed' => $completed, 'total' => $total + $steps, 'current' => $current]);
        });

        // Formats this runtime cannot encode are reported as failures by the
        // generator; only what could have been produced decides a retry.
        [$formats, $variants] = (new VariantMaintenanceService($this->db))->resolveEnabledFormatsAndVariants($settings);
        $expected = count($formats) * count($variants);
        $produced = $stats['generated'] + $stats['skipped'];
        if ($produced < $expected) {
            throw new RuntimeException(sprintf('%d of %d variants generated', $produced, $expected));
        }

        $protected = !empty($row['is_nsfw']) || !empty($row['password_hash']);
        // Public albums get an LQIP; protected albums deliberately omit it
        // and use a private blurred preview instead.
        $placeholder = $protected ? $uploads->generateBlurredVariant($imageId) : $uploads->generateLQIP($imageId);
        if ($placeholder === null) {
            throw new RuntimeException('Image placeholder generation failed');
        }

        $steps = 1;
        $this->report($imageId, ['state' => 'complete', 'completed' => $expected + $steps, 'total' => $expected + $steps, 'current' => '']);
        return (int) $row['album_id'];
    }

    /** @param int[] $albumIds */
    private function invalidateAlbums(array $albumIds): void
    {
        $tags = [];
        $pivot = $this->db->pdo()->prepare('SELECT category_id FROM album_category WHERE album_id = ?');
        $fallback = $this->db->pdo()->prepare('SELECT category_id FROM albums WHERE id = ?');
        foreach ($albumIds as $albumId) {
            array_push($tags, ...CacheTags::albumRelated($albumId));
            $pivot->execute([$albumId]);
            $categoryIds = array_map(intval(...), $pivot->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            $pivot->closeCursor();
            if ($categoryIds === []) {
                $fallback->execute([$albumId]);
                $categoryIds = [(int) ($fallback->fetchColumn() ?: 0)];
                $fallback->closeCursor();
            }
            foreach ($categoryIds as $categoryId) {
                if ($categoryId > 0) {
                    $tags[] = CacheTags::category($categoryId);
                }
            }
        }
        (new PageCacheService(new SettingsService($this->db), $this->db))->invalidateByTags(array_values(array_unique($tags)));
        ImageGenerationRevision::advance();
    }

    /**
     * Jobs whose backoff has elapsed, oldest first.
     *
     * @return array<int, array{attempts: int, next_at: int}>
     */
    private function dueJobs(): array
    {
        $now = time();
        $due = [];
        foreach ($this->jobFiles() as $path) {
            $state = $this->readState($path);
            if ($state['next_at'] <= $now) {
                $due[(int) basename($path, '.job')] = $state + ['mtime' => (int) @filemtime($path)];
            }
        }
        uasort($due, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
        return array_map(static fn (array $s): array => ['attempts' => $s['attempts'], 'next_at' => $s['next_at']], $due);
    }

    /** @return string[] */
    private function jobFiles(): array
    {
        clearstatcache();
        return array_values(array_filter(
            glob($this->directory . '/*.job') ?: [],
            static fn (string $path): bool => ctype_digit(basename($path, '.job')) && !is_link($path)
        ));
    }

    /** @return array{attempts: int, next_at: int} */
    private function readState(string $path): array
    {
        $state = json_decode((string) @file_get_contents($path), true);
        return [
            'attempts' => is_array($state) ? max(0, (int) ($state['attempts'] ?? 0)) : 0,
            'next_at' => is_array($state) ? (int) ($state['next_at'] ?? 0) : 0,
        ];
    }

    private function complete(int $imageId): void
    {
        $path = $this->jobPath($imageId);
        if (is_file($path) && !UploadService::safeUnlink($path)) {
            throw new RuntimeException('Cannot remove completed image job ' . $imageId);
        }
    }

    /** @param array{attempts: int, next_at: int} $state */
    private function fail(int $imageId, array $state, \Throwable $error): void
    {
        $attempts = $state['attempts'] + 1;
        $path = $this->jobPath($imageId);
        if ($attempts >= self::MAX_ATTEMPTS) {
            // Park it: a job that keeps failing (an encoder this host lacks, a
            // corrupt original) must not restart PHP every retry interval forever.
            if (@rename($path, $this->failedPath($imageId))) {
                $this->report($imageId, ['state' => 'failed', 'next_at' => 0, 'attempts' => $attempts]);
                Logger::error('Image queue: job abandoned after repeated failures', [
                    'image_id' => $imageId,
                    'attempts' => $attempts,
                    'error' => $error->getMessage(),
                ], 'upload');
                return;
            }
        }
        $attempts = min(self::MAX_ATTEMPTS, $attempts);
        $nextAt = time() + min(self::BACKOFF_MAX, self::BACKOFF_BASE * 2 ** ($attempts - 1));
        // Write-then-rename: a crash mid-write leaves the previous state, and
        // an unreadable file simply counts as a fresh, immediately due job.
        $temporary = $path . '.tmp';
        if (@file_put_contents($temporary, json_encode(['attempts' => $attempts, 'next_at' => $nextAt])) === false
            || !@rename($temporary, $path)) {
            UploadService::safeUnlink($temporary);
        }
        $this->report($imageId, ['state' => 'retrying', 'next_at' => $nextAt, 'attempts' => $attempts]);
        Logger::warning('Image queue: job will be retried', [
            'image_id' => $imageId,
            'attempts' => $attempts,
            'next_at' => $nextAt,
            'error' => $error->getMessage(),
        ], 'upload');
    }

    private function jobPath(int $imageId): string
    {
        return $this->directory . '/' . $imageId . '.job';
    }

    private function failedPath(int $imageId): string
    {
        return $this->directory . '/' . $imageId . '.failed';
    }

    public function trackUpload(int $imageId, string $token): void
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $token)) {
            $this->report($imageId, ['upload_token' => $token, 'state' => 'uploading', 'completed' => 0, 'total' => 0, 'current' => '']);
        }
    }

    /** @return array<int, array<string, mixed>> File-based progress avoids SQLite writes. */
    public function status(): array
    {
        $jobs = [];
        $total = null;
        $expectedSteps = function () use (&$total): int {
            if ($total === null) {
                $configuration = UploadService::variantConfiguration(new SettingsService($this->db));
                $total = count($configuration['breakpoints']) * count($configuration['formats']) + 1;
            }
            return $total;
        };
        foreach (glob($this->directory . '/*.progress') ?: [] as $path) {
            $id = (int)basename($path, '.progress');
            $state = json_decode((string)@file_get_contents($path), true);
            if (!is_array($state)) {
                continue;
            }
            $pending = is_file($this->directory . '/' . $id . '.job');
            // Correlate an upload before its HTTP response, including synchronous generation.
            if (($state['state'] ?? '') === 'uploading' && (int)($state['updated_at'] ?? 0) >= time() - 120) {
                $pending = true;
            }
            if ($pending && ($state['state'] ?? '') === 'complete') {
                $state = ['state' => 'queued', 'completed' => 0, 'total' => 0, 'current' => ''];
            }
            if (!$pending && (int)($state['updated_at'] ?? 0) < time() - 300) {
                continue;
            }
            if ($pending && empty($state['total'])) {
                $state['total'] = $expectedSteps();
            }
            $jobs[] = ['id' => $id, 'pending' => $pending] + $state;
        }
        // Include legacy job markers that predate progress reporting.
        $known = array_column($jobs, 'id');
        foreach (glob($this->directory . '/*.job') ?: [] as $path) {
            $id = (int)basename($path, '.job');
            if (!in_array($id, $known, true)) {
                $jobs[] = ['id' => $id, 'pending' => true, 'state' => 'queued', 'completed' => 0, 'total' => $expectedSteps(), 'current' => ''];
            }
        }
        return $jobs;
    }

    /** @param array<string, mixed> $changes */
    private function report(int $id, array $changes): void
    {
        $this->ensureDirectory();
        $path = $this->directory . '/' . $id . '.progress';
        $state = json_decode((string)@file_get_contents($path), true) ?: [];
        $state = array_replace($state, $changes, ['updated_at' => time()]);
        $temporary = $path . '.tmp';
        // Queue workers are serialized. Atomic rename gives readers a full snapshot.
        if (@file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) !== false) {
            @rename($temporary, $path);
        }
        // Bounded retention; active jobs are never removed.
        if (($changes['state'] ?? '') === 'complete') {
            foreach (glob($this->directory . '/*.progress') ?: [] as $old) {
                if (@filemtime($old) < time() - 300 && !is_file(substr($old, 0, -9) . '.job')) {
                    @unlink($old);
                }
            }
        }
    }

    /**
     * Persist before waiting so interruption never loses foreground work.
     * A running drain can hold the lock for minutes: after SYNC_LOCK_WAIT the
     * upload returns and the job stays queued for that drain or the worker.
     */
    public function runSynchronously(int $imageId): void
    {
        $this->enqueue($imageId);
        $lock = @fopen($this->directory . '/worker.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open image worker lock');
        }
        try {
            $deadline = microtime(true) + $this->syncLockWait;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    $this->drainAfterResponse();
                    throw new RuntimeException('Image worker busy; job ' . $imageId . ' left queued');
                }
                usleep(250000);
            }
            $albumId = ($this->processor)($imageId);
            $this->complete($imageId);
            if ($albumId !== null) {
                $this->refreshPageCaches([$albumId]);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Compatibility with the fork's original queue API. */
    public function processImage(int $imageId): void
    {
        $this->runSynchronously($imageId);
    }

    /** Only PHP-FPM can continue after closing a web response. */
    public function schedule(): void
    {
        $this->drainAfterResponse();
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the image job directory');
        }
    }
}
