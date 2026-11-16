<?php
declare(strict_types=1);

namespace App\Support;

final class DockerEnvironment
{
    public const UPDATE_MESSAGE = 'This installation runs in Docker. Pull the desired Cimaise image and recreate the container, preserving your persistent volumes. Direct application updates are disabled.';

    public static function detected(): bool
    {
        return getenv('CIMAISE_DOCKER') === '1'
            || is_file('/.dockerenv')
            || is_file('/run/.containerenv');
    }
}
