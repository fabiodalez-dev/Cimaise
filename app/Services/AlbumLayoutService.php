<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use InvalidArgumentException;
use RuntimeException;

final class AlbumLayoutService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function state(int $albumId, ?array $allowedImageIds = null): array
    {
        $row = $this->db->query(
            'SELECT draft_json, published_json, revision FROM album_layouts WHERE album_id = ?', [$albumId]
        )->fetch();
        $photosRemoved = false;

        return [
            'revision' => (int) ($row['revision'] ?? 0),
            'draft' => $this->decode($row['draft_json'] ?? null, $allowedImageIds, $photosRemoved),
            'published' => $this->decode($row['published_json'] ?? null, $allowedImageIds, $photosRemoved),
            'photos_removed' => $photosRemoved,
        ];
    }

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

    public function normalize(array $layout, ?array $allowedImageIds, bool &$photosRemoved = false): array
    {
        $blocks = $layout['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > 200) {
            throw new InvalidArgumentException('A layout must contain at most 200 blocks.');
        }
        $allowed = $allowedImageIds === null ? null : array_fill_keys($allowedImageIds, true);
        $seen = [];
        $result = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || !in_array($block['type'] ?? '', ['photo', 'grid', 'text'], true)) {
                throw new InvalidArgumentException('Unknown layout block.');
            }
            $id = $block['id'] ?? '';
            if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $id) || isset($seen[$id])) {
                throw new InvalidArgumentException('Each block needs a unique ID.');
            }
            $seen[$id] = true;
            $item = [
                'id' => $id,
                'type' => $block['type'],
                'width' => $this->integer($block, 'width', 100, 10, 100),
                'max_width' => $this->integer($block, 'max_width', 0, 0, 3840),
                'align' => in_array($block['align'] ?? 'center', ['left', 'center', 'right'], true) ? ($block['align'] ?? 'center') : 'center',
                'top' => $this->integer($block, 'top', 0, 0, 500),
                'bottom' => $this->integer($block, 'bottom', 20, 0, 500),
            ];
            if ($block['type'] === 'text') {
                if (!is_string($block['text'] ?? '') || mb_strlen($block['text'] ?? '') > 20000) {
                    throw new InvalidArgumentException('Text blocks must be at most 20,000 characters.');
                }
                $item['text'] = trim($block['text'] ?? '');
            } else {
                $images = $block['images'] ?? [];
                if (!is_array($images) || !array_is_list($images) || count($images) > 2000
                    || ($block['type'] === 'photo' && count($images) !== 1)) {
                    throw new InvalidArgumentException('Select one image for a photo or up to 2,000 for a grid.');
                }
                $item['columns'] = $this->integer($block, 'columns', 3, 1, 8);
                $item['gap'] = $this->integer($block, 'gap', 8, 0, 100);
                $item['images'] = [];
                foreach ($images as $imageId) {
                    if (!is_int($imageId) || $imageId < 1) {
                        throw new InvalidArgumentException('Photo IDs must be positive integers.');
                    }
                    if ($allowed !== null && !isset($allowed[$imageId])) {
                        $photosRemoved = true;
                        continue;
                    }
                    $item['images'][$imageId] = $imageId;
                }
                if ($item['images'] === []) {
                    continue;
                }
                $item['images'] = array_values($item['images']);
            }
            $result[] = $item;
        }
        if (!is_bool($layout['include_remaining'] ?? true)) {
            throw new InvalidArgumentException('Invalid remaining photos setting.');
        }
        return ['version' => 1, 'include_remaining' => $layout['include_remaining'] ?? true, 'blocks' => $result];
    }

    private function integer(array $block, string $key, int $default, int $min, int $max): int
    {
        $value = $block[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("Invalid {$key} value.");
        }
        return $value;
    }

    public function save(int $albumId, int $revision, ?array $layout, string $action): int
    {
        if (!in_array($action, ['draft', 'publish', 'default'], true)) {
            throw new InvalidArgumentException('Unknown save action.');
        }
        $json = $layout === null ? null : json_encode($layout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($action !== 'default' && $json === null) {
            throw new InvalidArgumentException('A layout is required.');
        }
        $assignments = match ($action) {
            'publish' => 'draft_json = ?, published_json = ?,',
            'draft' => 'draft_json = ?,',
            'default' => 'published_json = NULL,',
        };
        $params = match ($action) {
            'publish' => [$json, $json],
            'draft' => [$json],
            'default' => [],
        };
        if ($revision === 0) {
            $this->db->execute($this->db->insertIgnoreKeyword() . ' INTO album_layouts (album_id) VALUES (?)', [$albumId]);
        }
        $params[] = $albumId;
        $params[] = $revision;
        $count = $this->db->execute("UPDATE album_layouts SET {$assignments} revision = revision + 1 WHERE album_id = ? AND revision = ?", $params);
        if ($count !== 1) {
            throw new RuntimeException('This layout changed in another tab. Reload before saving.', 409);
        }
        return $revision + 1;
    }

    // Resolve against already-authorized album images. Deleted photos disappear,
    // and new uploads can join the remaining-photos grid automatically.
    public function resolve(array $layout, array $images): array
    {
        $index = array_column($images, null, 'id');
        $used = [];
        $blocks = [];
        foreach ($layout['blocks'] as $block) {
            if ($block['type'] !== 'text') {
                $selected = [];
                foreach ($block['images'] as $id) {
                    if (isset($index[$id])) {
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
        return [
            'blocks' => $blocks,
            'remaining' => $layout['include_remaining']
                ? array_values(array_filter($images, static fn (array $image): bool => !isset($used[$image['id']])))
                : [],
        ];
    }
}
