<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Hooks;

class CustomFieldService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all field types (built-in + custom)
     */
    public function getFieldTypes(bool $includeSystem = true): array
    {
        $sql = 'SELECT * FROM custom_field_types';
        if (!$includeSystem) {
            $sql .= ' WHERE is_system = 0';
        }
        $sql .= ' ORDER BY sort_order, label';
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a single field type by ID
     */
    public function getFieldType(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM custom_field_types WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get a field type by name
     */
    public function getFieldTypeByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM custom_field_types WHERE name = ?');
        $stmt->execute([$name]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create a new field type
     */
    public function createFieldType(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO custom_field_types (name, label, icon, field_type, description, show_in_lightbox, show_in_gallery, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['name'],
            $data['label'],
            $data['icon'] ?? 'fa-tag',
            $data['field_type'] ?? 'select',
            $data['description'] ?? null,
            $data['show_in_lightbox'] ?? 1,
            $data['show_in_gallery'] ?? 1,
            $data['sort_order'] ?? 0
        ]);

        $id = (int)$this->db->lastInsertId();
        Hooks::doAction('custom_field_type_created', $id, $data);
        return $id;
    }

    /**
     * Update a field type
     */
    public function updateFieldType(int $id, array $data): void
    {
        $stmt = $this->db->prepare('
            UPDATE custom_field_types
            SET label = ?, icon = ?, field_type = ?, description = ?, show_in_lightbox = ?, show_in_gallery = ?, sort_order = ?
            WHERE id = ? AND is_system = 0
        ');
        $stmt->execute([
            $data['label'],
            $data['icon'] ?? 'fa-tag',
            $data['field_type'] ?? 'select',
            $data['description'] ?? null,
            $data['show_in_lightbox'] ?? 1,
            $data['show_in_gallery'] ?? 1,
            $data['sort_order'] ?? 0,
            $id
        ]);

        Hooks::doAction('custom_field_type_updated', $id, $data);
    }

    /**
     * Delete a field type (only non-system types)
     */
    public function deleteFieldType(int $id): bool
    {
        // Check if it's a system type
        $stmt = $this->db->prepare('SELECT is_system FROM custom_field_types WHERE id = ?');
        $stmt->execute([$id]);
        $type = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$type || $type['is_system']) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM custom_field_types WHERE id = ? AND is_system = 0');
        $stmt->execute([$id]);

        Hooks::doAction('custom_field_type_deleted', $id);
        return true;
    }

    /**
     * Get available values for a field type
     */
    public function getFieldValues(int $fieldTypeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM custom_field_values
             WHERE field_type_id = ?
             ORDER BY sort_order, value'
        );
        $stmt->execute([$fieldTypeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a new field value
     */
    public function createFieldValue(int $fieldTypeId, string $value, ?string $extraData = null, int $sortOrder = 0): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO custom_field_values (field_type_id, value, extra_data, sort_order)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$fieldTypeId, $value, $extraData, $sortOrder]);

        $id = (int)$this->db->lastInsertId();
        Hooks::doAction('custom_field_value_created', $id, $fieldTypeId, $value);
        return $id;
    }

    /**
     * Delete a field value
     */
    public function deleteFieldValue(int $id): void
    {
        $stmt = $this->db->prepare('SELECT field_type_id FROM custom_field_values WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Return early if value doesn't exist
        if (!$value) {
            return;
        }

        $fieldTypeId = (int) $value['field_type_id'];

        $stmt = $this->db->prepare('DELETE FROM custom_field_values WHERE id = ?');
        $stmt->execute([$id]);

        Hooks::doAction('custom_field_value_deleted', $id, $fieldTypeId);
    }

    /**
     * Get custom metadata for an image
     * Includes inheritance from album if no override
     */
    public function getImageMetadata(int $imageId, int $albumId): array
    {
        // 1. Get image-specific metadata
        $stmt = $this->db->prepare('
            SELECT icf.*, cft.name as type_name, cft.label as type_label,
                   cft.icon, cft.show_in_lightbox, cft.show_in_gallery, cfv.value as selected_value
            FROM image_custom_fields icf
            JOIN custom_field_types cft ON icf.field_type_id = cft.id
            LEFT JOIN custom_field_values cfv ON icf.field_value_id = cfv.id
            WHERE icf.image_id = ?
        ');
        $stmt->execute([$imageId]);
        $imageFields = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 2. Get album metadata
        $stmt = $this->db->prepare('
            SELECT acf.*, cft.name as type_name, cft.label as type_label,
                   cft.icon, cft.show_in_lightbox, cft.show_in_gallery, cfv.value as selected_value
            FROM album_custom_fields acf
            JOIN custom_field_types cft ON acf.field_type_id = cft.id
            LEFT JOIN custom_field_values cfv ON acf.field_value_id = cfv.id
            WHERE acf.album_id = ?
        ');
        $stmt->execute([$albumId]);
        $albumFields = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 3. Merge with override logic
        $result = $this->mergeWithOverride($imageFields, $albumFields);

        // Apply filter for plugins
        return Hooks::applyFilter('image_custom_fields', $result, $imageId, $albumId);
    }

    /**
     * Merge image metadata with album metadata, respecting overrides
     */
    public function mergeWithOverride(array $imageFields, array $albumFields): array
    {
        $result = [];
        $imageTypeIds = [];

        // First add image-specific fields
        foreach ($imageFields as $field) {
            $typeId = $field['field_type_id'];
            if (!isset($result[$typeId])) {
                $result[$typeId] = [
                    'type_name' => $field['type_name'],
                    'type_label' => $field['type_label'],
                    'icon' => $field['icon'],
                    'show_in_lightbox' => $field['show_in_lightbox'] ?? 1,
                    'show_in_gallery' => $field['show_in_gallery'] ?? 1,
                    'values' => [],
                    'is_override' => (bool)($field['is_override'] ?? false)
                ];
            }
            $result[$typeId]['values'][] = $field['custom_value'] ?? $field['selected_value'];
            $imageTypeIds[$typeId] = true;
        }

        // Then add album fields only if no override for that type
        foreach ($albumFields as $field) {
            $typeId = $field['field_type_id'];

            // If image has override for this type, skip album values
            if (isset($imageTypeIds[$typeId]) && isset($result[$typeId]['is_override']) && $result[$typeId]['is_override']) {
                continue;
            }

            // If no override, add album values
            if (!isset($result[$typeId])) {
                $result[$typeId] = [
                    'type_name' => $field['type_name'],
                    'type_label' => $field['type_label'],
                    'icon' => $field['icon'],
                    'show_in_lightbox' => $field['show_in_lightbox'] ?? 1,
                    'show_in_gallery' => $field['show_in_gallery'] ?? 1,
                    'values' => [],
                    'is_override' => false
                ];
            }
            $value = $field['custom_value'] ?? $field['selected_value'];
            if (!in_array($value, $result[$typeId]['values'])) {
                $result[$typeId]['values'][] = $value;
            }
        }

        return $result;
    }

    /**
     * Assign metadata to an image
     */
    public function setImageMetadata(int $imageId, int $fieldTypeId, array $values, bool $isOverride = false): void
    {
        // Remove existing values for this type
        $stmt = $this->db->prepare(
            'DELETE FROM image_custom_fields WHERE image_id = ? AND field_type_id = ?'
        );
        $stmt->execute([$imageId, $fieldTypeId]);

        // Insert new values
        $stmt = $this->db->prepare('
            INSERT INTO image_custom_fields (image_id, field_type_id, field_value_id, custom_value, is_override)
            VALUES (?, ?, ?, ?, ?)
        ');

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $stmt->execute([$imageId, $fieldTypeId, (int)$value, null, $isOverride ? 1 : 0]);
            } else {
                $stmt->execute([$imageId, $fieldTypeId, null, $value, $isOverride ? 1 : 0]);
            }
        }

        // If override, propagate to album
        if ($isOverride) {
            $this->propagateToAlbum($imageId, $fieldTypeId, $values);
        }
    }

    /**
     * Propagate image override to album (auto-add)
     */
    private function propagateToAlbum(int $imageId, int $fieldTypeId, array $values): void
    {
        // Get album_id from image
        $stmt = $this->db->prepare('SELECT album_id FROM images WHERE id = ?');
        $stmt->execute([$imageId]);
        $albumId = $stmt->fetchColumn();

        if (!$albumId) return;

        // For each value, add to album if doesn't exist
        $checkStmt = $this->db->prepare('
            SELECT COUNT(*) FROM album_custom_fields
            WHERE album_id = ? AND field_type_id = ? AND (field_value_id = ? OR custom_value = ?)
        ');

        $insertStmt = $this->db->prepare('
            INSERT INTO album_custom_fields (album_id, field_type_id, field_value_id, custom_value, auto_added)
            VALUES (?, ?, ?, ?, 1)
        ');

        foreach ($values as $value) {
            $fieldValueId = is_numeric($value) ? (int)$value : null;
            $customValue = is_numeric($value) ? null : $value;

            $checkStmt->execute([$albumId, $fieldTypeId, $fieldValueId, $customValue]);
            if ($checkStmt->fetchColumn() == 0) {
                $insertStmt->execute([$albumId, $fieldTypeId, $fieldValueId, $customValue]);
            }
        }
    }

    /**
     * Assign multiple metadata values to an album
     */
    public function setAlbumMetadata(int $albumId, int $fieldTypeId, array $values): void
    {
        // Remove only non-auto-added values for this type
        $stmt = $this->db->prepare(
            'DELETE FROM album_custom_fields WHERE album_id = ? AND field_type_id = ? AND auto_added = 0'
        );
        $stmt->execute([$albumId, $fieldTypeId]);

        // Insert new values
        $stmt = $this->db->prepare('
            INSERT INTO album_custom_fields (album_id, field_type_id, field_value_id, custom_value, auto_added)
            VALUES (?, ?, ?, ?, 0)
        ');

        foreach ($values as $value) {
            if ($value === '' || $value === null) continue;

            if (is_numeric($value)) {
                $stmt->execute([$albumId, $fieldTypeId, (int)$value, null]);
            } else {
                $stmt->execute([$albumId, $fieldTypeId, null, $value]);
            }
        }
    }

    /**
     * Clear all custom field data for an album
     */
    public function clearAlbumMetadata(int $albumId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM album_custom_fields WHERE album_id = ?'
        );
        $stmt->execute([$albumId]);
    }

    /**
     * Get all metadata for an album (for display)
     */
    public function getAlbumMetadata(int $albumId): array
    {
        $stmt = $this->db->prepare('
            SELECT acf.*, cft.name as type_name, cft.label as type_label,
                   cft.icon, cft.show_in_lightbox, cft.show_in_gallery,
                   cfv.value as selected_value
            FROM album_custom_fields acf
            JOIN custom_field_types cft ON acf.field_type_id = cft.id
            LEFT JOIN custom_field_values cfv ON acf.field_value_id = cfv.id
            WHERE acf.album_id = ?
            ORDER BY cft.sort_order, cft.label
        ');
        $stmt->execute([$albumId]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $typeId = $row['field_type_id'];
            if (!isset($result[$typeId])) {
                $result[$typeId] = [
                    'type_name' => $row['type_name'],
                    'type_label' => $row['type_label'],
                    'icon' => $row['icon'],
                    'show_in_lightbox' => $row['show_in_lightbox'],
                    'show_in_gallery' => $row['show_in_gallery'],
                    'values' => [],
                    'value_ids' => [],
                    'auto_values' => []
                ];
            }

            $value = $row['custom_value'] ?? $row['selected_value'];
            $valueId = $row['field_value_id'];

            $result[$typeId]['values'][] = [
                'value' => $value,
                'auto_added' => (bool)$row['auto_added']
            ];

            if ($valueId) {
                $result[$typeId]['value_ids'][] = $valueId;
            }

            if ($row['auto_added']) {
                $result[$typeId]['auto_values'][] = $value;
            }
        }

        // Apply filter for plugins
        return Hooks::applyFilter('album_custom_fields', $result, $albumId);
    }

    /**
     * Get raw album metadata for form editing (returns selected value IDs)
     */
    public function getAlbumMetadataForForm(int $albumId): array
    {
        $stmt = $this->db->prepare('
            SELECT field_type_id, field_value_id, custom_value, auto_added
            FROM album_custom_fields
            WHERE album_id = ?
        ');
        $stmt->execute([$albumId]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $typeId = $row['field_type_id'];
            if (!isset($result[$typeId])) {
                $result[$typeId] = [
                    'value_ids' => [],
                    'custom_values' => [],
                    'auto_values' => []
                ];
            }

            if ($row['field_value_id']) {
                $result[$typeId]['value_ids'][] = $row['field_value_id'];
            }
            if ($row['custom_value']) {
                $result[$typeId]['custom_values'][] = $row['custom_value'];
            }
            if ($row['auto_added']) {
                $result[$typeId]['auto_values'][] = $row['field_value_id'] ?? $row['custom_value'];
            }
        }

        return $result;
    }

    /**
     * Get available FontAwesome icons for custom fields
     */
    public function getAvailableIcons(): array
    {
        return [
            // Photography & Media
            'fa-camera' => 'Camera',
            'fa-camera-retro' => 'Retro Camera',
            'fa-film' => 'Film',
            'fa-image' => 'Image',
            'fa-images' => 'Images',
            'fa-photo-video' => 'Photo Video',
            'fa-video' => 'Video',
            'fa-aperture' => 'Aperture',
            'fa-circle-half-stroke' => 'Aperture Alt',
            'fa-dot-circle' => 'Dot Circle',
            'fa-expand' => 'Expand',
            'fa-compress' => 'Compress',
            'fa-crop' => 'Crop',
            'fa-crop-alt' => 'Crop Alt',
            'fa-adjust' => 'Adjust',
            'fa-sliders-h' => 'Sliders',

            // Equipment & Tools
            'fa-flask' => 'Flask',
            'fa-vial' => 'Vial',
            'fa-print' => 'Print',
            'fa-palette' => 'Palette',
            'fa-brush' => 'Brush',
            'fa-paint-brush' => 'Paint Brush',
            'fa-pen' => 'Pen',
            'fa-pencil-alt' => 'Pencil',
            'fa-tools' => 'Tools',
            'fa-wrench' => 'Wrench',
            'fa-cog' => 'Cog',
            'fa-cogs' => 'Cogs',
            'fa-industry' => 'Industry',
            'fa-warehouse' => 'Warehouse',

            // Location & Nature
            'fa-map-marker-alt' => 'Location',
            'fa-map-pin' => 'Map Pin',
            'fa-map' => 'Map',
            'fa-globe' => 'Globe',
            'fa-globe-europe' => 'Globe Europe',
            'fa-compass' => 'Compass',
            'fa-mountain' => 'Mountain',
            'fa-tree' => 'Tree',
            'fa-leaf' => 'Leaf',
            'fa-seedling' => 'Seedling',
            'fa-water' => 'Water',
            'fa-sun' => 'Sun',
            'fa-moon' => 'Moon',
            'fa-cloud' => 'Cloud',
            'fa-cloud-sun' => 'Cloud Sun',
            'fa-snowflake' => 'Snowflake',
            'fa-umbrella' => 'Umbrella',

            // Places & Buildings
            'fa-city' => 'City',
            'fa-building' => 'Building',
            'fa-home' => 'Home',
            'fa-store' => 'Store',
            'fa-church' => 'Church',
            'fa-landmark' => 'Landmark',
            'fa-monument' => 'Monument',

            // Transport
            'fa-car' => 'Car',
            'fa-bicycle' => 'Bicycle',
            'fa-motorcycle' => 'Motorcycle',
            'fa-plane' => 'Plane',
            'fa-ship' => 'Ship',
            'fa-train' => 'Train',
            'fa-bus' => 'Bus',

            // People & Social
            'fa-user' => 'User',
            'fa-users' => 'Users',
            'fa-user-friends' => 'Friends',
            'fa-portrait' => 'Portrait',
            'fa-child' => 'Child',
            'fa-baby' => 'Baby',
            'fa-male' => 'Male',
            'fa-female' => 'Female',

            // Objects & Misc
            'fa-tag' => 'Tag',
            'fa-tags' => 'Tags',
            'fa-bookmark' => 'Bookmark',
            'fa-folder' => 'Folder',
            'fa-folder-open' => 'Folder Open',
            'fa-book' => 'Book',
            'fa-book-open' => 'Book Open',
            'fa-calendar' => 'Calendar',
            'fa-calendar-alt' => 'Calendar Alt',
            'fa-clock' => 'Clock',
            'fa-hourglass' => 'Hourglass',
            'fa-stopwatch' => 'Stopwatch',
            'fa-star' => 'Star',
            'fa-heart' => 'Heart',
            'fa-thumbs-up' => 'Thumbs Up',
            'fa-award' => 'Award',
            'fa-trophy' => 'Trophy',
            'fa-medal' => 'Medal',
            'fa-certificate' => 'Certificate',
            'fa-gift' => 'Gift',
            'fa-gem' => 'Gem',
            'fa-crown' => 'Crown',

            // Animals
            'fa-dog' => 'Dog',
            'fa-cat' => 'Cat',
            'fa-horse' => 'Horse',
            'fa-crow' => 'Bird',
            'fa-fish' => 'Fish',
            'fa-spider' => 'Spider',
            'fa-paw' => 'Paw',

            // Food & Drink
            'fa-utensils' => 'Food',
            'fa-coffee' => 'Coffee',
            'fa-wine-glass' => 'Wine',
            'fa-beer' => 'Beer',
            'fa-cocktail' => 'Cocktail',
            'fa-birthday-cake' => 'Cake',
            'fa-apple-alt' => 'Apple',

            // Music & Arts
            'fa-music' => 'Music',
            'fa-guitar' => 'Guitar',
            'fa-drum' => 'Drum',
            'fa-microphone' => 'Microphone',
            'fa-headphones' => 'Headphones',
            'fa-theater-masks' => 'Theater',
            'fa-paint-roller' => 'Paint Roller',

            // Sports & Activities
            'fa-futbol' => 'Football',
            'fa-basketball-ball' => 'Basketball',
            'fa-volleyball-ball' => 'Volleyball',
            'fa-football-ball' => 'American Football',
            'fa-running' => 'Running',
            'fa-swimmer' => 'Swimmer',
            'fa-skiing' => 'Skiing',
            'fa-hiking' => 'Hiking',
            'fa-campground' => 'Camping',

            // Shapes & Symbols
            'fa-circle' => 'Circle',
            'fa-square' => 'Square',
            'fa-cube' => 'Cube',
            'fa-shapes' => 'Shapes',
            'fa-infinity' => 'Infinity',
            'fa-bolt' => 'Bolt',
            'fa-fire' => 'Fire',
            'fa-flag' => 'Flag',
            'fa-anchor' => 'Anchor',
            'fa-eye' => 'Eye',
            'fa-lightbulb' => 'Lightbulb',
            'fa-magic' => 'Magic',
            'fa-feather' => 'Feather',
            'fa-feather-alt' => 'Feather Alt',

            // Tech & Digital
            'fa-signal' => 'Signal',
            'fa-wifi' => 'WiFi',
            'fa-bluetooth' => 'Bluetooth',
            'fa-laptop' => 'Laptop',
            'fa-desktop' => 'Desktop',
            'fa-mobile-alt' => 'Mobile',
            'fa-tablet-alt' => 'Tablet',
            'fa-sd-card' => 'SD Card',
            'fa-memory' => 'Memory',
            'fa-microchip' => 'Microchip'
        ];
    }

    /**
     * Enrich array of images with custom fields (batch query)
     */
    public function enrichImagesWithCustomFields(array $images, int $albumId): array
    {
        if (empty($images)) return $images;

        $imageIds = array_column($images, 'id');
        $placeholders = implode(',', array_fill(0, count($imageIds), '?'));

        // Batch fetch image custom fields
        $stmt = $this->db->prepare("
            SELECT icf.image_id, icf.field_type_id, icf.field_value_id, icf.custom_value, icf.is_override,
                   cft.name as type_name, cft.label as type_label, cft.icon,
                   cft.show_in_lightbox, cft.show_in_gallery,
                   cfv.value as selected_value
            FROM image_custom_fields icf
            JOIN custom_field_types cft ON icf.field_type_id = cft.id
            LEFT JOIN custom_field_values cfv ON icf.field_value_id = cfv.id
            WHERE icf.image_id IN ($placeholders)
        ");
        $stmt->execute($imageIds);

        $customFields = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $imgId = $row['image_id'];
            if (!isset($customFields[$imgId])) {
                $customFields[$imgId] = [];
            }
            $customFields[$imgId][] = $row;
        }

        // Get album fields for merge
        $albumMetadata = $this->getAlbumMetadata($albumId);

        // Convert album metadata to flat array format for merge
        $albumFields = [];
        foreach ($albumMetadata as $typeId => $data) {
            foreach ($data['values'] as $valueData) {
                $albumFields[] = [
                    'field_type_id' => $typeId,
                    'type_name' => $data['type_name'],
                    'type_label' => $data['type_label'],
                    'icon' => $data['icon'],
                    'show_in_lightbox' => $data['show_in_lightbox'],
                    'show_in_gallery' => $data['show_in_gallery'],
                    'selected_value' => $valueData['value'],
                    'custom_value' => null
                ];
            }
        }

        // Merge into each image
        foreach ($images as &$image) {
            $imgId = $image['id'];
            $imgFields = $customFields[$imgId] ?? [];
            $image['custom_fields'] = $this->mergeWithOverride($imgFields, $albumFields);
        }

        return $images;
    }
}
