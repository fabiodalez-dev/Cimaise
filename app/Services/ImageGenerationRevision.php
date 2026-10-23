<?php

declare(strict_types=1);

namespace App\Services;

/** An opaque completion marker; public readers receive no job or image details. */
final class ImageGenerationRevision
{
    private static function path(): string
    {
        return dirname(__DIR__, 2) . '/storage/image-jobs/revision';
    }

    public static function current(): string
    {
        $revision = trim((string)@file_get_contents(self::path()));
        return preg_match('/^[a-f0-9]{32}$/D', $revision) ? $revision : '0';
    }

    /**
     * True while any image still has a queued generation job. One directory
     * listing; visitors' pages only watch for upgrades while this holds.
     */
    public static function pending(): bool
    {
        foreach (glob(dirname(self::path()) . '/*.job') ?: [] as $job) {
            if (ctype_digit(basename($job, '.job'))) {
                return true;
            }
        }
        return false;
    }

    public static function advance(): void
    {
        $path = self::path();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temporary, bin2hex(random_bytes(16)), LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot publish image-generation revision');
        }
    }
}
