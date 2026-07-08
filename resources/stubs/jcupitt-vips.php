<?php

/**
 * PHPStan symbol stub for the OPTIONAL jcupitt/vips package (#109).
 *
 * ImageEngine references Jcupitt\Vips\Image only behind
 * extension_loaded('vips') && class_exists() guards, and the package is not a
 * composer dependency (it requires ext-ffi and is installed by operators who
 * want the fast libvips path). This file is never autoloaded at runtime — it
 * exists solely so PHPStan (scanFiles in phpstan.neon.dist) can resolve the
 * class and its method signatures. Keep signatures in sync with
 * https://github.com/libvips/php-vips (only the members ImageEngine uses).
 */

namespace Jcupitt\Vips;

if (false) { // never executed nor autoloaded — static analysis only
    class Image
    {
        public int $width;
        public int $height;

        /** @param array<string,mixed> $options */
        public static function thumbnail(string $filename, int $width, array $options = []): self
        {
            throw new \LogicException('stub');
        }

        /** @param array<string,mixed> $options */
        public static function newFromFile(string $filename, array $options = []): self
        {
            throw new \LogicException('stub');
        }

        /** @param array<string,mixed> $options */
        public static function black(int $width, int $height, array $options = []): self
        {
            throw new \LogicException('stub');
        }

        public static function findLoad(string $filename): ?string
        {
            throw new \LogicException('stub');
        }

        public function hasAlpha(): bool
        {
            throw new \LogicException('stub');
        }

        /** @param array<string,mixed> $options */
        public function flatten(array $options = []): self
        {
            throw new \LogicException('stub');
        }

        /** @param array<string,mixed> $options */
        public function writeToFile(string $filename, array $options = []): void
        {
            throw new \LogicException('stub');
        }

        /** @param array<string,mixed> $options */
        public function writeToBuffer(string $suffix, array $options = []): string
        {
            throw new \LogicException('stub');
        }
    }
}
