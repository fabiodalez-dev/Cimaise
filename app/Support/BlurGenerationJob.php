<?php

declare(strict_types=1);

namespace App\Support;

class BlurGenerationJob
{
    public static function dispatch(int $imageId): bool
    {
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        if (!is_file($consolePath) || !is_readable($consolePath)) {
            Logger::warning('BlurGenerationJob: console script not available', [
                'image_id' => $imageId,
                'console_path' => $consolePath,
            ], 'media');
            return false;
        }

        // At most one detached job per image per PENDING_TTL: anonymous denied
        // requests for protected media all land here while a blur is missing,
        // and each dispatch is a full PHP process outside the worker pool.
        if (!self::claimPending($imageId)) {
            return true;
        }

        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        $logFile = is_dir($logDir) && is_writable($logDir) ? $logDir . '/blur_generation.log' : '/dev/null';
        $cmd = 'nohup php ' . escapeshellarg($consolePath)
            . ' images:generate-blur --image=' . escapeshellarg((string)$imageId)
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            Logger::warning('BlurGenerationJob: failed to dispatch blur generation', [
                'image_id' => $imageId,
                'exit_code' => $exitCode,
            ], 'media');
            return false;
        }

        return true;
    }

    private const PENDING_TTL = 300;

    /**
     * Claim the right to dispatch for $imageId: false only when a job for this
     * image was dispatched within PENDING_TTL seconds (or another request is
     * claiming it right now). Read-check-update runs under a lock on a stable
     * per-image lock file that is never deleted, so two requests can never
     * both replace a stale claim. Any filesystem problem returns true: the
     * caller then dispatches without deduplication (previous behaviour)
     * instead of silently skipping the blur.
     */
    private static function claimPending(int $imageId): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/tmp/blur-locks';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true;
        }
        $lock = @fopen($dir . '/' . $imageId . '.claim', 'c+');
        if ($lock === false) {
            return true;
        }
        try {
            $wouldBlock = 0;
            if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                // Contention: another request is claiming this image right now.
                // Any other lock failure must not suppress the job.
                return $wouldBlock !== 1;
            }
            $marker = $dir . '/' . $imageId . '.pending';
            $mtime = @filemtime($marker);
            if ($mtime !== false && (time() - $mtime) < self::PENDING_TTL) {
                return false;
            }
            if (!@touch($marker)) {
                return true; // cannot record the claim: dispatch anyway
            }
            clearstatcache(true, $marker);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
