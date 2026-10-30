<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Finished-HTML cache for cookie-less visitors (first visits, crawlers).
 *
 * page_cache stores the page DATA, so every anonymous view still paid for the
 * full Twig render. This stores the rendered HTML of home / galleries / album
 * pages, keyed by URL and validated against the page's content ETag computed
 * by CacheMiddleware (page_cache hash + settings + release + image-generation
 * state, texts, header tags): when any of those change the entry simply
 * stops matching, so there is no separate invalidation to keep in sync.
 * Entries also expire after MAX_AGE, which bounds staleness from layout
 * inputs that are not part of the validator (e.g. category counts).
 *
 * The key comes from the request (Host header, path variants of the same
 * slug), so the directory is capped at MAX_ENTRIES: once full, new pages are
 * simply not stored until "clear cache" or until expired entries are pruned.
 *
 * The per-request CSP nonce is replaced by a placeholder when storing and by
 * the current request's nonce when serving, so nonces are never reused.
 */
final class HtmlPageCache
{
    private const NONCE_PLACEHOLDER = '__CIMAISE_CSP_NONCE__';
    private const FORMAT = 1;
    private const MAX_AGE = 600;
    private const MAX_ENTRIES = 2000;

    /** Response headers that are per-request or recomputed by CacheMiddleware. */
    private const SKIPPED_HEADERS = ['set-cookie', 'x-csrf-token', 'cache-control', 'expires', 'etag', 'vary', 'pragma', 'content-length', 'content-encoding', 'last-modified'];

    public function __construct(
        private readonly string $dir,
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly int $maxAge = self::MAX_AGE
    ) {
    }

    public static function forApp(): self
    {
        return new self(dirname(__DIR__, 2) . '/storage/cache/html');
    }

    public static function key(string $scheme, string $host, ?int $port, string $path): string
    {
        return hash('sha256', strtolower($scheme) . '://' . strtolower($host) . ':' . ($port ?? 0) . $path);
    }

    /**
     * @return array{body: string, headers: array<string, string[]>}|null
     */
    public function get(string $key, string $etag, string $nonce): ?array
    {
        $raw = @file_get_contents($this->file($key));
        if ($raw === false || $raw === '') {
            return null;
        }
        // Format: one JSON metadata line, then the gzipped HTML (no unserialize).
        $newline = strpos($raw, "\n");
        if ($newline === false) {
            return null;
        }
        $data = json_decode(substr($raw, 0, $newline), true);
        if (!is_array($data) || ($data['v'] ?? null) !== self::FORMAT || ($data['etag'] ?? null) !== $etag
            || !is_array($data['headers'] ?? null) || (int) ($data['t'] ?? 0) < time() - $this->maxAge) {
            return null;
        }
        $html = @gzdecode(substr($raw, $newline + 1));
        if (!is_string($html)) {
            return null;
        }

        return [
            'body' => str_replace(self::NONCE_PLACEHOLDER, $nonce, $html),
            'headers' => $data['headers'],
        ];
    }

    /**
     * @param array<string, string[]> $headers
     */
    public function put(string $key, string $etag, string $nonce, string $html, array $headers): bool
    {
        if ($nonce === '' || $html === '') {
            return false;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return false;
        }

        $kept = [];
        foreach ($headers as $name => $values) {
            if (!in_array(strtolower((string) $name), self::SKIPPED_HEADERS, true)) {
                $kept[(string) $name] = array_values(array_map('strval', $values));
            }
        }

        $compressed = gzencode(str_replace($nonce, self::NONCE_PLACEHOLDER, $html), 6);
        if ($compressed === false) {
            return false;
        }
        $file = $this->file($key);
        if (!is_file($file) && !$this->hasRoom()) {
            return false;
        }

        $meta = json_encode(['v' => self::FORMAT, 'etag' => $etag, 't' => time(), 'headers' => $kept], JSON_UNESCAPED_SLASHES);
        if ($meta === false) {
            return false;
        }
        $payload = $meta . "\n" . $compressed;

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * True while the directory holds fewer than maxEntries entries. When it is
     * full, expired entries are pruned once before giving up.
     */
    private function hasRoom(): bool
    {
        $files = glob($this->dir . '/*.cache') ?: [];
        if (count($files) < $this->maxEntries) {
            return true;
        }
        $cutoff = time() - $this->maxAge;
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
        clearstatcache();
        return count(glob($this->dir . '/*.cache') ?: []) < $this->maxEntries;
    }

    public function clear(): void
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . $key . '.cache';
    }
}
