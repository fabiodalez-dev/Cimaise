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
     * Atomically claim the right to dispatch for $imageId. Returns false when a
     * job was already dispatched within PENDING_TTL seconds.
     */
    private static function claimPending(int $imageId): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/tmp/blur-locks';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true; // cannot dedupe: fall back to the previous behaviour
        }
        $marker = $dir . '/' . $imageId . '.pending';
        $mtime = @filemtime($marker);
        if ($mtime !== false && (time() - $mtime) < self::PENDING_TTL) {
            return false;
        }
        if ($mtime !== false) {
            @unlink($marker); // stale claim
        }
        $fh = @fopen($marker, 'x');
        if ($fh === false) {
            return false; // a concurrent request won the claim
        }
        fclose($fh);
        return true;
    }
}
