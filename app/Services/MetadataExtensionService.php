<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Hooks;

class MetadataExtensionService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all extensions for an entity
     */
    public function getExtensions(string $entityType, int $entityId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM metadata_extensions WHERE entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$entityType, $entityId]);

        $extensions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $extensions[$row['extension_key']] = [
                'value' => $this->decodeValue($row['extension_value']),
                'plugin_id' => $row['plugin_id']
            ];
        }

        return $extensions;
    }

    /**
     * Get a single extension value
     */
    public function getExtension(string $entityType, int $entityId, string $key): mixed
    {
        $stmt = $this->db->prepare(
            'SELECT extension_value FROM metadata_extensions WHERE entity_type = ? AND entity_id = ? AND extension_key = ?'
        );
        $stmt->execute([$entityType, $entityId, $key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? $this->decodeValue($value) : null;
    }

    /**
     * Set an extension value
     */
    public function setExtension(
        string $entityType,
        int $entityId,
        string $key,
        mixed $value,
        ?string $pluginId = null
    ): void {
        $encodedValue = $this->encodeValue($value);

        // Check database driver for proper upsert syntax
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->db->prepare('
                INSERT INTO metadata_extensions (entity_type, entity_id, extension_key, extension_value, plugin_id, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE extension_value = VALUES(extension_value), updated_at = NOW()
            ');
        } else {
            // SQLite
            $stmt = $this->db->prepare('
                INSERT INTO metadata_extensions (entity_type, entity_id, extension_key, extension_value, plugin_id, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(entity_type, entity_id, extension_key)
                DO UPDATE SET extension_value = excluded.extension_value, updated_at = CURRENT_TIMESTAMP
            ');
        }
        $stmt->execute([$entityType, $entityId, $key, $encodedValue, $pluginId]);

        Hooks::doAction('metadata_extension_saved', $entityType, $entityId, $key, $value);
    }

    /**
     * Remove an extension
     */
    public function removeExtension(string $entityType, int $entityId, string $key): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM metadata_extensions WHERE entity_type = ? AND entity_id = ? AND extension_key = ?'
        );
        $stmt->execute([$entityType, $entityId, $key]);

        Hooks::doAction('metadata_extension_removed', $entityType, $entityId, $key);
    }

    /**
     * Remove all extensions for an entity
     */
    public function removeAllExtensions(string $entityType, int $entityId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM metadata_extensions WHERE entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$entityType, $entityId]);
    }

    /**
     * Remove all extensions created by a plugin
     */
    public function removePluginExtensions(string $pluginId): void
    {
        $stmt = $this->db->prepare('DELETE FROM metadata_extensions WHERE plugin_id = ?');
        $stmt->execute([$pluginId]);
    }

    /**
     * Enrich an entity with its extensions
     */
    public function enrichEntity(string $entityType, array $entity): array
    {
        if (!isset($entity['id'])) {
            return $entity;
        }

        $extensions = $this->getExtensions($entityType, (int)$entity['id']);
        $entity['extensions'] = $extensions;

        // Hook for additional enrichment
        return Hooks::applyFilter("metadata_{$entityType}_enriched", $entity);
    }

    /**
     * Enrich array of entities (batch query for performance)
     */
    public function enrichEntities(string $entityType, array $entities): array
    {
        if (empty($entities)) return $entities;

        $ids = array_column($entities, 'id');
        if (empty($ids)) return $entities;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare("
            SELECT * FROM metadata_extensions
            WHERE entity_type = ? AND entity_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$entityType], $ids));

        $extensionsMap = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entityId = $row['entity_id'];
            if (!isset($extensionsMap[$entityId])) {
                $extensionsMap[$entityId] = [];
            }
            $extensionsMap[$entityId][$row['extension_key']] = [
                'value' => $this->decodeValue($row['extension_value']),
                'plugin_id' => $row['plugin_id']
            ];
        }

        foreach ($entities as &$entity) {
            if (!isset($entity['id'])) continue;

            $entity['extensions'] = $extensionsMap[$entity['id']] ?? [];
            $entity = Hooks::applyFilter("metadata_{$entityType}_enriched", $entity);
        }

        return $entities;
    }

    /**
     * Check if an entity has a specific extension
     */
    public function hasExtension(string $entityType, int $entityId, string $key): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM metadata_extensions WHERE entity_type = ? AND entity_id = ? AND extension_key = ?'
        );
        $stmt->execute([$entityType, $entityId, $key]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get all entities of a type that have a specific extension key
     */
    public function getEntitiesWithExtension(string $entityType, string $key): array
    {
        $stmt = $this->db->prepare(
            'SELECT entity_id, extension_value FROM metadata_extensions WHERE entity_type = ? AND extension_key = ?'
        );
        $stmt->execute([$entityType, $key]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['entity_id']] = $this->decodeValue($row['extension_value']);
        }
        return $result;
    }

    /**
     * Encode value for storage
     */
    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value) || is_object($value)) {
            try {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return '';
            }
        }
        return (string)$value;
    }

    /**
     * Decode value from storage
     */
    private function decodeValue(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
