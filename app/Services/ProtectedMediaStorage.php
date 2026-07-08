<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;

/**
 * Keeps sharp variants for password/NSFW albums outside the public web root.
 *
 * Database URLs remain /media/... so templates and routes do not change. The
 * MediaController resolves those URLs to storage/protected-media after access
 * checks. Blur variants deliberately remain public.
 */
final readonly class ProtectedMediaStorage
{
    private const MIGRATION_MARKER = '/storage/tmp/protected-media-v1.done';
    private const MIGRATION_LOCK = '/storage/tmp/protected-media-v1.lock';

    private string $root;
    private string $publicDir;
    private string $privateDir;

    public function __construct(private Database $db)
    {
        $this->root = dirname(__DIR__, 2);
        $this->publicDir = $this->root . '/public/media';
        $this->privateDir = $this->root . '/storage/protected-media';
    }

    public function directoryForProtection(bool $protected): string
    {
        $dir = $protected ? $this->privateDir : $this->publicDir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function isImageProtected(int $imageId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.is_nsfw, a.password_hash
             FROM images i
             JOIN albums a ON a.id = i.album_id
             WHERE i.id = :id"
        );
        $stmt->execute([':id' => $imageId]);
        $album = $stmt->fetch();

        return $album
            && ((int)($album['is_nsfw'] ?? 0) === 1 || !empty($album['password_hash']));
    }

    /**
     * Resolve a DB media path after enforcing its correct storage location.
     */
    public function resolveVariantPath(string $dbPath, bool $protected): ?string
    {
        $basename = $this->mediaBasename($dbPath);
        if ($basename === null) {
            return null;
        }

        $publicPath = $this->publicDir . '/' . $basename;
        $privatePath = $this->privateDir . '/' . $basename;

        if (!$protected) {
            $this->moveToPublic($privatePath, $publicPath);
            return is_file($publicPath) ? (realpath($publicPath) ?: $publicPath) : null;
        }

        // A variant may be shared by an explicitly attached public image. In
        // that case the bytes are already public by design and must remain so.
        if ($this->hasPublicReference($dbPath)) {
            $this->moveToPublic($privatePath, $publicPath);
            return is_file($publicPath) ? (realpath($publicPath) ?: $publicPath) : null;
        }

        $this->quarantineFile($publicPath, $privatePath);
        return is_file($privatePath) ? (realpath($privatePath) ?: $privatePath) : null;
    }

    /**
     * Move every non-blur variant in an album to the appropriate storage root.
     *
     * @return array{moved:int, deleted:int, skipped:int}
     */
    public function relocateAlbumVariants(int $albumId, bool $protected): array
    {
        $stats = ['moved' => 0, 'deleted' => 0, 'skipped' => 0];
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT iv.path
             FROM image_variants iv
             JOIN images i ON i.id = iv.image_id
             WHERE i.album_id = :album_id AND iv.variant <> 'blur'"
        );
        $stmt->execute([':album_id' => $albumId]);

        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $dbPath) {
            $dbPath = (string)$dbPath;
            $basename = $this->mediaBasename($dbPath);
            if ($basename === null) {
                $stats['skipped']++;
                continue;
            }

            $publicPath = $this->publicDir . '/' . $basename;
            $privatePath = $this->privateDir . '/' . $basename;

            if ($protected && !$this->hasPublicReference($dbPath)) {
                $beforePublic = is_file($publicPath);
                $beforePrivate = is_file($privatePath);
                $this->quarantineFile($publicPath, $privatePath);
                if (!$beforePrivate && is_file($privatePath)) {
                    $stats['moved']++;
                } elseif ($beforePublic && !is_file($publicPath) && !is_file($privatePath)) {
                    // Fail closed: an unreadable public variant is deleted and
                    // can be regenerated later from the private original.
                    $stats['deleted']++;
                } else {
                    $stats['skipped']++;
                }
                continue;
            }

            $beforePublic = is_file($publicPath);
            $this->moveToPublic($privatePath, $publicPath);
            if (!$beforePublic && is_file($publicPath)) {
                $stats['moved']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Re-enforce private storage for the variants of EVERY protected album.
     * Single home of the "which albums are protected" predicate + loop, shared
     * by the one-time upgrade quarantine and the daily reconciliation in
     * VariantMaintenanceService.
     *
     * @return array{moved:int, deleted:int, skipped:int}
     */
    public function relocateAllProtectedAlbums(): array
    {
        $albumIds = $this->db->pdo()->query(
            "SELECT id FROM albums
             WHERE is_nsfw = 1
                OR (password_hash IS NOT NULL AND password_hash <> '')"
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $summary = ['moved' => 0, 'deleted' => 0, 'skipped' => 0];
        foreach ($albumIds as $albumId) {
            $stats = $this->relocateAlbumVariants((int)$albumId, true);
            foreach (array_keys($summary) as $key) {
                $summary[$key] += $stats[$key];
            }
        }
        return $summary;
    }

    /**
     * One-time upgrade quarantine for variants created before private storage.
     */
    public function quarantineExistingProtectedVariants(): void
    {
        $marker = $this->root . self::MIGRATION_MARKER;
        if (is_file($marker)) {
            return;
        }

        $lockPath = $this->root . self::MIGRATION_LOCK;
        if (!is_dir(dirname($lockPath))) {
            @mkdir(dirname($lockPath), 0775, true);
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }

        try {
            if (is_file($marker)) {
                return;
            }
            $summary = $this->relocateAllProtectedAlbums();

            if (@file_put_contents($marker, gmdate('c'), LOCK_EX) === false) {
                Logger::warning('Unable to write protected media migration marker', ['marker' => $marker], 'security');
            }
            Logger::info('Protected media quarantine completed', $summary, 'security');
        } catch (\Throwable $e) {
            Logger::warning('Protected media quarantine failed', ['error' => $e->getMessage()], 'security');
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function deleteVariantCopies(string $dbPath): bool
    {
        // Attached images can share one physical variant. Delete the bytes only
        // after the last DB reference has gone.
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM image_variants WHERE path = :path LIMIT 1');
        $stmt->execute([':path' => $dbPath]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        $basename = $this->mediaBasename($dbPath);
        if ($basename === null) {
            return false;
        }
        $this->confinedUnlink($this->publicDir . '/' . $basename);
        $this->confinedUnlink($this->privateDir . '/' . $basename);
        return !is_file($this->publicDir . '/' . $basename)
            && !is_file($this->privateDir . '/' . $basename);
    }

    private function hasPublicReference(string $dbPath): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
             FROM image_variants iv
             JOIN images i ON i.id = iv.image_id
             JOIN albums a ON a.id = i.album_id
             WHERE iv.path = :path
               AND a.is_nsfw = 0
               AND (a.password_hash IS NULL OR a.password_hash = '')
             LIMIT 1"
        );
        $stmt->execute([':path' => $dbPath]);
        return $stmt->fetchColumn() !== false;
    }

    private function quarantineFile(string $publicPath, string $privatePath): void
    {
        if (is_file($privatePath)) {
            $this->confinedUnlink($publicPath);
            return;
        }
        if (!is_file($publicPath)) {
            return;
        }

        if (!is_dir($this->privateDir)) {
            @mkdir($this->privateDir, 0775, true);
        }

        $moved = @rename($publicPath, $privatePath);
        if (!$moved) {
            $moved = @copy($publicPath, $privatePath);
            if ($moved) {
                $this->confinedUnlink($publicPath);
            }
        }

        // Security takes precedence over availability. Never leave a sharp
        // protected variant under public/ after a failed relocation.
        if (!$moved || !is_file($privatePath)) {
            $this->confinedUnlink($publicPath);
        }
    }

    private function moveToPublic(string $privatePath, string $publicPath): void
    {
        if (is_file($publicPath)) {
            $this->confinedUnlink($privatePath);
            return;
        }
        if (!is_file($privatePath)) {
            return;
        }
        if (!is_dir($this->publicDir)) {
            @mkdir($this->publicDir, 0775, true);
        }
        if (!@rename($privatePath, $publicPath) && @copy($privatePath, $publicPath)) {
            $this->confinedUnlink($privatePath);
        }
    }

    /**
     * Delete a file only when it resolves INSIDE one of this service's media
     * roots (public/media or storage/protected-media). Defence-in-depth on top
     * of mediaBasename()'s filename whitelist: every deletion path is realpath-
     * resolved and containment-checked, so neither a crafted DB value nor a
     * future caller that builds a path some other way can ever delete a file
     * outside the media directories.
     */
    private function confinedUnlink(string $path): void
    {
        $real = realpath($path);
        if ($real === false) {
            return; // nothing on disk to remove
        }
        $pub  = realpath($this->publicDir);
        $priv = realpath($this->privateDir);
        $inPublic  = $pub !== false && str_starts_with($real, $pub . DIRECTORY_SEPARATOR);
        $inPrivate = $priv !== false && str_starts_with($real, $priv . DIRECTORY_SEPARATOR);
        if (!$inPublic && !$inPrivate) {
            return;
        }
        // nosemgrep: $real is realpath-resolved and verified to live under the
        // public/media or storage/protected-media root immediately above — it
        // cannot traverse outside the media dirs, and is never raw user input.
        @unlink($real); // nosemgrep
    }

    private function mediaBasename(string $dbPath): ?string
    {
        $normalized = str_replace('\\', '/', trim($dbPath));
        if (
            !str_starts_with($normalized, '/media/')
            && !str_starts_with($normalized, 'media/')
            && !str_starts_with($normalized, '/public/media/')
            && !str_starts_with($normalized, 'public/media/')
        ) {
            return null;
        }
        if (str_contains($normalized, '..')) {
            return null;
        }

        $basename = basename($normalized);
        return preg_match('/^\d+_[a-z0-9_-]+\.(?:jpg|jpeg|webp|avif|jxl|png)$/i', $basename)
            ? $basename
            : null;
    }
}
