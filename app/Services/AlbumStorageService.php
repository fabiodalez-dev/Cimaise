<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Support\QueryCache;

final class AlbumStorageService
{
    private readonly string $root;

    public function __construct(private readonly PDO $pdo, ?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2);
    }

    /**
     * Count existing media files once per album, including shared originals.
     * @param int[] $albumIds
     * @return array<int, array{original_bytes: int, variant_bytes: int, total_bytes: int}>
     */
    public function usage(array $albumIds, bool $refresh = false): array
    {
        $albumIds = array_values(array_unique(array_map('intval', $albumIds)));
        if ($albumIds === []) {
            return [];
        }
        $usage = array_fill_keys($albumIds, ['original_bytes' => 0, 'variant_bytes' => 0, 'total_bytes' => 0]);
        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, album_id, original_path FROM images WHERE album_id IN ($placeholders) ORDER BY id");
        $stmt->execute($albumIds);
        $originals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $stmt = $this->pdo->prepare("SELECT v.image_id, i.album_id, v.path, v.size_bytes FROM image_variants v JOIN images i ON i.id = v.image_id WHERE i.album_id IN ($placeholders) ORDER BY v.image_id, v.path, v.size_bytes");
        $stmt->execute($albumIds);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        // Fingerprint exact media records, not approximate counts/sums. Uploads, moves,
        // deletion and re-encoding invalidate immediately without mutation hooks.
        $records = array_fill_keys($albumIds, ['originals' => [], 'variants' => []]);
        foreach ($originals as $row) { $records[(int)$row['album_id']]['originals'][] = $row; }
        foreach ($variants as $row) { $records[(int)$row['album_id']]['variants'][] = $row; }
        $cache = QueryCache::getInstance();
        $missing = [];
        foreach ($records as $id => $media) {
            $key = 'album_storage:v1:' . hash('sha256', $this->root) . ':' . $id;
            $signature = hash('sha256', serialize($media));
            $cached = $refresh ? null : $cache->get($key);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature && isset($cached['usage'])) {
                $usage[$id] = $cached['usage'];
            } else {
                $missing[$id] = ['key' => $key, 'signature' => $signature];
            }
        }
        if ($missing === []) { return $usage; }
        clearstatcache();

        // Release SQLite read cursors before inspecting files; never move media here.
        $seen = [];
        $count = static function (int $albumId, ?string $file, string $kind) use (&$usage, &$seen): void {
            if ($file === null || isset($seen[$albumId][$file])) {
                return;
            }
            $seen[$albumId][$file] = true;
            $bytes = @filesize($file);
            if ($bytes !== false) {
                $usage[$albumId][$kind] += $bytes;
                $usage[$albumId]['total_bytes'] += $bytes;
            }
        };
        foreach ($originals as $row) {
            if (isset($missing[(int)$row['album_id']])) {
                $count((int)$row['album_id'], OriginalImage::resolve((string)$row['original_path'], $this->root), 'original_bytes');
            }
        }
        $directories = array_filter(array_map(realpath(...), [$this->root . '/public/media', $this->root . '/storage/protected-media']));
        foreach ($variants as $row) {
            if (!isset($missing[(int)$row['album_id']])) { continue; }
            $path = (string)$row['path'];
            if (str_contains($path, '..') || str_contains($path, '\\')
                || !preg_match('~^/?(?:public/)?media/(?:protected/)?(\d+_[a-z0-9_-]+\.(?:jpg|jpeg|webp|avif|jxl|png))$~iD', $path, $match)) {
                continue;
            }
            foreach ($directories as $directory) {
                $file = realpath($directory . '/' . $match[1]);
                if ($file && is_file($file) && str_starts_with($file, $directory . DIRECTORY_SEPARATOR)) {
                    $count((int)$row['album_id'], $file, 'variant_bytes');
                }
            }
        }
        foreach ($missing as $id => $entry) {
            // Files changed outside Cimaise (including mounts) are reconciled within a minute.
            $cache->set($entry['key'], ['signature' => $entry['signature'], 'usage' => $usage[$id]], 60);
        }
        return $usage;
    }
}
