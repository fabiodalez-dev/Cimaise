<?php

declare(strict_types=1);

use App\Support\QueryCache;

/**
 * Points QueryCache at a throw-away file cache for one test, then restores the
 * real instance. Use: $restore = TempQueryCache::install(); ... $restore();
 */
final class TempQueryCache
{
    public static function install(): Closure
    {
        $dir = sys_get_temp_dir() . '/cimaise-qcache-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $property = new ReflectionProperty(QueryCache::class, 'instance');
        $previous = $property->getValue();
        $cache = (new ReflectionClass(QueryCache::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(QueryCache::class, 'useApcu'))->setValue($cache, false);
        (new ReflectionProperty(QueryCache::class, 'cacheDir'))->setValue($cache, $dir);
        $property->setValue(null, $cache);

        return static function () use ($property, $previous, $dir): void {
            $property->setValue(null, $previous);
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        };
    }
}
