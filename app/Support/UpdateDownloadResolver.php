<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Helper for building download URL candidates for GitHub releases.
 * Keeps logic separate so it can be tested without touching database/updater.
 */
class UpdateDownloadResolver
{
    /**
     * Build ordered list of download URLs to try for a release.
     *
     * Priority:
     *  1) Custom zip assets (e.g. cimaise-vX.Y.Z.zip)
     *  2) Public GitHub tag archive (archive/refs/tags/{tag}.zip)
     *  3) zipball_url from GitHub API
     *  4) Any remaining assets
     *
     * @param string $owner   GitHub repository owner
     * @param string $repo    GitHub repository name
     * @param array  $release Release payload from the GitHub API
     *
     * @return array<int, string> Unique, ordered list of URLs
     */
    public static function buildCandidates(string $owner, string $repo, array $release): array
    {
        $candidates = [];
        $tag = $release['tag_name'] ?? '';

        // 1) Prefer packaged zip assets that match the project name
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            $url = $asset['browser_download_url'] ?? '';

            if (!$url) {
                continue;
            }

            if (preg_match('/cimaise.*\\.zip$/i', $name)) {
                $candidates[] = $url;
            }
        }

        // 2) Public GitHub archive URL (does not require API headers)
        if ($tag !== '') {
            $candidates[] = "https://github.com/{$owner}/{$repo}/archive/refs/tags/{$tag}.zip";
        }

        // 3) API zipball endpoint
        if (!empty($release['zipball_url'])) {
            $candidates[] = $release['zipball_url'];
        }

        // 4) Any other downloadable assets GitHub exposes (fallbacks)
        foreach ($release['assets'] ?? [] as $asset) {
            $url = $asset['browser_download_url'] ?? '';
            if ($url) {
                $candidates[] = $url;
            }
        }

        // Remove empties and duplicates while preserving order
        $unique = [];
        foreach ($candidates as $candidate) {
            if ($candidate === '' || isset($unique[$candidate])) {
                continue;
            }
            $unique[$candidate] = true;
        }

        return array_keys($unique);
    }
}
