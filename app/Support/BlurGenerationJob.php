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

        $cmd = 'nohup php ' . escapeshellarg($consolePath)
            . ' images:generate-blur --image=' . escapeshellarg((string)$imageId)
            . ' > /tmp/blur_generation.log 2>&1 &';
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
}
