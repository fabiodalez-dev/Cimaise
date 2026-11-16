<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Tells whether this install is the official Cimaise Docker image, where the
 * application code lives in the image and an update means pulling a new tag.
 */
final class DockerEnvironment
{
    public const UPDATE_MESSAGE = 'This installation runs in Docker. Pull the desired Cimaise image and recreate the container, preserving your persistent volumes. Direct application updates are disabled.';

    /**
     * Only the CIMAISE_DOCKER marker baked into the official image counts.
     * Generic container markers (/.dockerenv, /run/.containerenv) are
     * deliberately ignored: a ZIP install running inside some other PHP
     * container has no Cimaise image to pull and must keep the in-app updater.
     */
    public static function detected(): bool
    {
        return getenv('CIMAISE_DOCKER') === '1';
    }
}
