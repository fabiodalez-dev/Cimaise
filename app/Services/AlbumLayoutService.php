<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * Storage and validation for the optional visual album layouts.
 *
 * One row per album holds a private draft and the published layout as JSON,
 * plus a revision counter used for optimistic locking between editor tabs.
 * Layouts only reference image ids; they are resolved against the album's
 * already-authorized images at render time.
 *
 * @phpstan-type LayoutBlock array{
 *     id: string,
 *     type: 'photo'|'grid'|'text',
 *     width: int,
 *     max_width: int,
 *     align: 'left'|'center'|'right',
 *     top: int,
 *     bottom: int,
 *     text?: string,
 *     columns?: int,
 *     gap?: int,
 *     images?: list<int>
 * }
 * @phpstan-type Layout array{version: 1, include_remaining: bool, blocks: list<LayoutBlock>}
 * @phpstan-type LayoutState array{
 *     revision: int,
 *     draft: Layout|null,
 *     published: Layout|null,
 *     photos_removed: bool,
 *     updated_at: string|null,
 *     published_at: string|null
 * }
 */
final class AlbumLayoutService
{
    public const MAX_BLOCKS = 200;
    public const MAX_GRID_IMAGES = 2000;
    public const MAX_TEXT_CHARS = 20000;

    /**
     * Upper bound for one stored layout (encoded JSON). Well below MySQL
     * MEDIUMTEXT (16 MiB): without it, 200 text blocks of escaped control
     * characters pass validation but overflow the column, which fails in
     * strict mode and silently truncates (and so loses the layout) otherwise.
     */
    public const MAX_JSON_BYTES = 1048576;

    private const BLOCK_TYPES = ['photo', 'grid', 'text'];
    private const ALIGNMENTS = ['left', 'center', 'right'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<int>|null $allowedImageIds ids currently in the album; null skips pruning
     * @return LayoutState
     */
    public function state(int $albumId, ?array $allowedImageIds = null): array
    {
        $row = $this->db->query(
            'SELECT draft_json, published_json, revision, updated_at, published_at FROM album_layouts WHERE album_id = ?',
            [$albumId]
        )->fetch();
        if (!is_array($row)) {
            $row = [];
        }
        $photosRemoved = false;

        return [
            'revision' => (int) ($row['revision'] ?? 0),
            'draft' => $this->decode($this->stringOrNull($row['draft_json'] ?? null), $allowedImageIds, $photosRemoved),
            'published' => $this->decode($this->stringOrNull($row['published_json'] ?? null), $allowedImageIds, $photosRemoved),
            'photos_removed' => $photosRemoved,
            'updated_at' => $this->stringOrNull($row['updated_at'] ?? null),
            'published_at' => $this->stringOrNull($row['published_at'] ?? null),
        ];
    }

    /** @return Layout|null */
    public function published(int $albumId): ?array
    {
        $json = $this->db->query('SELECT published_json FROM album_layouts WHERE album_id = ?', [$albumId])->fetchColumn();
        $photosRemoved = false;

        return $this->decode($this->stringOrNull($json), null, $photosRemoved);
    }

    /**
     * Validate a layout and return its canonical form. Ids outside
     * $allowedImageIds are dropped (and reported through $photosRemoved);
     * photo and grid blocks left without images are dropped as well.
     *
     * @param array<mixed> $layout
     * @param list<int>|null $allowedImageIds
     * @return Layout
     */
    public function normalize(array $layout, ?array $allowedImageIds, bool &$photosRemoved = false): array
    {
        $blocks = $layout['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > self::MAX_BLOCKS) {
            throw new InvalidArgumentException('A layout must contain at most 200 blocks.');
        }
        $includeRemaining = $layout['include_remaining'] ?? true;
        if (!is_bool($includeRemaining)) {
            throw new InvalidArgumentException('Invalid remaining photos setting.');
        }
        $allowed = $allowedImageIds === null ? null : array_fill_keys($allowedImageIds, true);
        $seen = [];
        $result = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                throw new InvalidArgumentException('Unknown layout block.');
            }
            $type = $block['type'] ?? null;
            if (!in_array($type, self::BLOCK_TYPES, true)) {
                throw new InvalidArgumentException('Unknown layout block.');
            }
            $id = $block['id'] ?? null;
            if (!is_string($id) || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $id) !== 1 || isset($seen[$id])) {
                throw new InvalidArgumentException('Each block needs a unique ID.');
            }
            $seen[$id] = true;
            $align = $block['align'] ?? 'center';
            $item = [
                'id' => $id,
                'type' => $type,
                'width' => $this->integer($block, 'width', 100, 10, 100),
                'max_width' => $this->integer($block, 'max_width', 0, 0, 3840),
                'align' => in_array($align, self::ALIGNMENTS, true) ? $align : 'center',
                'top' => $this->integer($block, 'top', 0, 0, 500),
                'bottom' => $this->integer($block, 'bottom', 20, 0, 500),
            ];
            if ($type === 'text') {
                $text = $block['text'] ?? '';
                if (!is_string($text) || mb_strlen($text) > self::MAX_TEXT_CHARS) {
                    throw new InvalidArgumentException('Text blocks must be at most 20,000 characters.');
                }
                $item['text'] = trim($text);
                $result[] = $item;
                continue;
            }

            $images = $block['images'] ?? [];
            if (!is_array($images) || !array_is_list($images) || count($images) > self::MAX_GRID_IMAGES
                || ($type === 'photo' && count($images) !== 1)) {
                throw new InvalidArgumentException('Select one image for a photo or up to 2,000 for a grid.');
            }
            $item['columns'] = $this->integer($block, 'columns', 3, 1, 8);
            $item['gap'] = $this->integer($block, 'gap', 8, 0, 100);
            $kept = [];
            foreach ($images as $imageId) {
                if (!is_int($imageId) || $imageId < 1) {
                    throw new InvalidArgumentException('Photo IDs must be positive integers.');
                }
                if ($allowed !== null && !isset($allowed[$imageId])) {
                    $photosRemoved = true;
                    continue;
                }
                $kept[$imageId] = $imageId;
            }
            if ($kept === []) {
                continue;
            }
            $item['images'] = array_values($kept);
            $result[] = $item;
        }

        return ['version' => 1, 'include_remaining' => $includeRemaining, 'blocks' => $result];
    }

    /**
     * Save a draft, publish, or switch the album back to the default gallery.
     * The layout is always re-normalized here, so only valid layouts reach the
     * database. Returns the new revision.
     *
     * @param array<mixed>|null $layout
     * @throws InvalidArgumentException on an invalid or oversized layout
     * @throws RuntimeException (code 409) when $revision is stale
     */
    public function save(int $albumId, int $revision, ?array $layout, string $action): int
    {
        if (!in_array($action, ['draft', 'publish', 'default'], true)) {
            throw new InvalidArgumentException('Unknown save action.');
        }
        $json = null;
        if ($action !== 'default') {
            if ($layout === null) {
                throw new InvalidArgumentException('A layout is required.');
            }
            $json = json_encode($this->normalize($layout, null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($json) > self::MAX_JSON_BYTES) {
                throw new InvalidArgumentException('This layout is too large to save.');
            }
        }

        $now = $this->db->nowExpression();
        [$assignments, $params] = match ($action) {
            'publish' => ["draft_json = ?, published_json = ?, published_at = {$now},", [$json, $json]],
            'draft' => ['draft_json = ?,', [$json]],
            'default' => ['published_json = NULL, published_at = NULL,', []],
        };
        if ($revision === 0) {
            $this->db->execute($this->db->insertIgnoreKeyword() . ' INTO album_layouts (album_id) VALUES (?)', [$albumId]);
        }
        $params[] = $albumId;
        $params[] = $revision;
        $count = $this->db->execute(
            "UPDATE album_layouts SET {$assignments} updated_at = {$now}, revision = revision + 1 WHERE album_id = ? AND revision = ?",
            $params
        );
        if ($count !== 1) {
            throw new RuntimeException('This layout changed in another tab. Reload before saving.', 409);
        }

        return $revision + 1;
    }

    /**
     * Resolve a layout against the album's already-authorized (and already
     * privacy-masked) images. Deleted photos disappear, and new uploads join
     * the remaining-photos grid automatically.
     *
     * @param Layout $layout
     * @param list<array<string, mixed>> $images rows with at least an `id` key
     * @return array{blocks: list<array<string, mixed>>, remaining: list<array<string, mixed>>}
     */
    public function resolve(array $layout, array $images): array
    {
        $index = [];
        foreach ($images as $image) {
            $index[(int) ($image['id'] ?? 0)] = $image;
        }
        $used = [];
        $blocks = [];
        foreach ($layout['blocks'] as $block) {
            if ($block['type'] !== 'text') {
                $selected = [];
                foreach ($block['images'] ?? [] as $id) {
                    if (isset($index[$id]) && !isset($used[$id])) {
                        $selected[] = $index[$id];
                        $used[$id] = true;
                    }
                }
                if ($selected === []) {
                    continue;
                }
                $block['photos'] = $selected;
            }
            $blocks[] = $block;
        }

        $remaining = [];
        if ($layout['include_remaining']) {
            foreach ($images as $image) {
                if (!isset($used[(int) ($image['id'] ?? 0)])) {
                    $remaining[] = $image;
                }
            }
        }

        return ['blocks' => $blocks, 'remaining' => $remaining];
    }

    /**
     * @return Layout|null
     * @param list<int>|null $allowedImageIds
     */
    private function decode(?string $json, ?array $allowedImageIds, bool &$photosRemoved): ?array
    {
        $layout = $json === null ? null : json_decode($json, true);
        if (!is_array($layout) || ($layout['version'] ?? null) !== 1) {
            return null;
        }

        $removed = false;
        try {
            $normalized = $this->normalize($layout, $allowedImageIds, $removed);
        } catch (InvalidArgumentException) {
            return null;
        }
        $photosRemoved = $photosRemoved || $removed;

        return $normalized;
    }

    /**
     * @param array<mixed> $block
     */
    private function integer(array $block, string $key, int $default, int $min, int $max): int
    {
        $value = $block[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("Invalid {$key} value.");
        }
        return $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
