<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;
use Throwable;

/**
 * Public full-text search across albums and their photos.
 *
 * Result unit is the album: photo matches are folded into the album that
 * contains them (their thumbnails are surfaced in the UI). Ranking is computed
 * per source and summed per album, with the album's own text weighted above its
 * photos so a title hit outranks an incidental caption hit.
 *
 * Backends, selected at runtime via {@see SearchIndexer}:
 *   - SQLite with FTS5  -> MATCH + bm25() ranking
 *   - MySQL / MariaDB    -> MATCH ... AGAINST (natural language)
 *   - SQLite without FTS5 -> LIKE fallback over the source columns
 *
 * Only published, non password-protected albums are ever returned.
 */
final class SearchService
{
    private const MAX_TERMS = 10;
    private const CANDIDATE_LIMIT = 500; // safety cap per source query
    private const IMAGE_SCORE_WEIGHT = 0.5;

    private SearchIndexer $indexer;

    public function __construct(private Database $db)
    {
        $this->indexer = new SearchIndexer($db);
    }

    /**
     * @return array{
     *   query: string, total: int, page: int, per_page: int,
     *   albums: array<int, array<string, mixed>>
     * } Each album row carries `matched_image_ids` (int[]) and `search_score` (float).
     */
    public function search(string $rawQuery, int $page = 1, int $perPage = 12): array
    {
        $rawQuery = trim($rawQuery);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $empty = ['query' => $rawQuery, 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'albums' => []];

        if ($rawQuery === '' || mb_strlen($rawQuery) < 2) {
            return $empty;
        }

        $this->indexer->ensureReady();

        try {
            if ($this->db->isMySQL()) {
                $albumHits = $this->mysqlAlbumHits($rawQuery);
                $imageHits = $this->mysqlImageHits($rawQuery);
            } elseif ($this->indexer->ftsAvailable()) {
                $albumHits = $this->ftsAlbumHits($rawQuery);
                $imageHits = $this->ftsImageHits($rawQuery);
            } else {
                $albumHits = $this->likeAlbumHits($rawQuery);
                $imageHits = $this->likeImageHits($rawQuery);
            }
        } catch (Throwable $e) {
            error_log('[SearchService] query failed, falling back to LIKE: ' . $e->getMessage());
            $albumHits = $this->likeAlbumHits($rawQuery);
            $imageHits = $this->likeImageHits($rawQuery);
        }

        // Aggregate to album level.
        $scores = [];          // albumId => float
        $matchedImages = [];   // albumId => int[]
        foreach ($albumHits as $row) {
            $id = (int) $row['album_id'];
            $scores[$id] = ($scores[$id] ?? 0.0) + (float) $row['score'];
        }
        foreach ($imageHits as $row) {
            $id = (int) $row['album_id'];
            $scores[$id] = ($scores[$id] ?? 0.0) + self::IMAGE_SCORE_WEIGHT * (float) $row['score'];
            $matchedImages[$id][] = (int) $row['image_id'];
        }

        if (empty($scores)) {
            return $empty;
        }

        // Rank: score desc, stable by album id for determinism.
        uksort($scores, static function (int $a, int $b) use ($scores): int {
            return ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b);
        });

        $total = count($scores);
        $pageIds = array_slice(array_keys($scores), ($page - 1) * $perPage, $perPage);
        if (empty($pageIds)) {
            return ['query' => $rawQuery, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'albums' => []];
        }

        $albums = $this->loadAlbums($pageIds);
        foreach ($albums as &$album) {
            $id = (int) $album['id'];
            $album['matched_image_ids'] = array_values(array_unique($matchedImages[$id] ?? []));
            $album['search_score'] = round($scores[$id] ?? 0.0, 4);
        }
        unset($album);

        return [
            'query' => $rawQuery,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'albums' => $albums,
        ];
    }

    // --- FTS5 (SQLite) ------------------------------------------------------

    /** @return array<int, array{album_id:int, score:float}> */
    private function ftsAlbumHits(string $raw): array
    {
        $match = $this->ftsMatchExpression($raw);
        if ($match === '') {
            return [];
        }
        $sql = "SELECT a.id AS album_id, -bm25(albums_fts) AS score
                FROM albums_fts
                JOIN albums a ON a.id = albums_fts.rowid
                WHERE albums_fts MATCH :q
                  AND a.is_published = 1
                  AND (a.password_hash IS NULL OR a.password_hash = '')
                ORDER BY score DESC
                LIMIT " . self::CANDIDATE_LIMIT;
        return $this->fetchScored($sql, [':q' => $match]);
    }

    /** @return array<int, array{album_id:int, image_id:int, score:float}> */
    private function ftsImageHits(string $raw): array
    {
        $match = $this->ftsMatchExpression($raw);
        if ($match === '') {
            return [];
        }
        $sql = "SELECT i.album_id AS album_id, i.id AS image_id, -bm25(images_fts) AS score
                FROM images_fts
                JOIN images i ON i.id = images_fts.rowid
                JOIN albums a ON a.id = i.album_id
                WHERE images_fts MATCH :q
                  AND a.is_published = 1
                  AND (a.password_hash IS NULL OR a.password_hash = '')
                ORDER BY score DESC
                LIMIT " . self::CANDIDATE_LIMIT;
        return $this->fetchScored($sql, [':q' => $match], true);
    }

    /**
     * Build a safe FTS5 MATCH expression: strip everything that is not a letter
     * or digit, prefix-match each token (token*), AND-combine. Bound as a param.
     */
    private function ftsMatchExpression(string $raw): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $raw) ?? '';
        $tokens = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_slice($tokens, 0, self::MAX_TERMS);
        if (empty($tokens)) {
            return '';
        }
        return implode(' ', array_map(static fn(string $t): string => $t . '*', $tokens));
    }

    // --- MySQL FULLTEXT -----------------------------------------------------

    /** @return array<int, array{album_id:int, score:float}> */
    private function mysqlAlbumHits(string $raw): array
    {
        // Native prepares (EMULATE_PREPARES=false) require one placeholder per
        // occurrence, so the SELECT and WHERE MATCH use distinct names.
        $sql = "SELECT a.id AS album_id,
                       MATCH(a.title, a.excerpt, a.body) AGAINST(:score_q IN NATURAL LANGUAGE MODE) AS score
                FROM albums a
                WHERE MATCH(a.title, a.excerpt, a.body) AGAINST(:where_q IN NATURAL LANGUAGE MODE)
                  AND a.is_published = 1
                  AND (a.password_hash IS NULL OR a.password_hash = '')
                ORDER BY score DESC
                LIMIT " . self::CANDIDATE_LIMIT;
        return $this->fetchScored($sql, [':score_q' => $raw, ':where_q' => $raw]);
    }

    /** @return array<int, array{album_id:int, image_id:int, score:float}> */
    private function mysqlImageHits(string $raw): array
    {
        $sql = "SELECT i.album_id AS album_id, i.id AS image_id,
                       MATCH(i.caption, i.alt_text, i.custom_camera, i.custom_lens, i.custom_film)
                         AGAINST(:score_q IN NATURAL LANGUAGE MODE) AS score
                FROM images i
                JOIN albums a ON a.id = i.album_id
                WHERE MATCH(i.caption, i.alt_text, i.custom_camera, i.custom_lens, i.custom_film)
                        AGAINST(:where_q IN NATURAL LANGUAGE MODE)
                  AND a.is_published = 1
                  AND (a.password_hash IS NULL OR a.password_hash = '')
                ORDER BY score DESC
                LIMIT " . self::CANDIDATE_LIMIT;
        return $this->fetchScored($sql, [':score_q' => $raw, ':where_q' => $raw], true);
    }

    // --- LIKE fallback ------------------------------------------------------

    /** @return array<int, array{album_id:int, score:float}> */
    private function likeAlbumHits(string $raw): array
    {
        $like = '%' . $raw . '%';
        $sql = "SELECT a.id AS album_id, 1.0 AS score
                FROM albums a
                WHERE a.is_published = 1
                  AND (a.password_hash IS NULL OR a.password_hash = '')
                  AND (a.title LIKE :q OR a.excerpt LIKE :q OR a.body LIKE :q)
                LIMIT " . self::CANDIDATE_LIMIT;
        return $this->fetchScored($sql, [':q' => $like]);
    }

    /** @return array<int, array{album_id:int, image_id:int, score:float}> */
    private function likeImageHits(string $raw): array
    {
        $like = '%' . $raw . '%';
        $sql = "SELECT i.album_id AS album_id, i.id AS image_id, 1.0 AS score
                FROM images i
                JOIN albums a ON a.id = i.album_id
                WHERE a.is_published = 1
                  AND (a.password_hash IS NULL OR a.password_hash = '')
                  AND (i.caption LIKE :q OR i.alt_text LIKE :q
                       OR i.custom_camera LIKE :q OR i.custom_lens LIKE :q OR i.custom_film LIKE :q)
                LIMIT " . self::CANDIDATE_LIMIT;
        return $this->fetchScored($sql, [':q' => $like], true);
    }

    // --- shared helpers -----------------------------------------------------

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetchScored(string $sql, array $params, bool $withImage = false): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Load full album rows (with category) for the given ids, preserving the
     * ranked order of $ids.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadAlbums(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT a.*, c.name AS category_name, c.slug AS category_slug
                FROM albums a
                JOIN categories c ON c.id = a.category_id
                WHERE a.id IN ($placeholders)";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }
}
