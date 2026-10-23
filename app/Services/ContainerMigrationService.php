<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Updater;
use RuntimeException;

/**
 * Apply the image's migrations to a database retained by a Docker volume.
 *
 * Two values are kept in `settings`:
 *  - docker.schema_version: the newest migration applied to this database. It
 *    only moves when a migration runs, so rolling back to an image with the
 *    same schema is allowed and only a real schema downgrade is refused.
 *  - docker.image_version: the image that last started on this database, used
 *    to discard the previous image's cached page data exactly once.
 */
final class ContainerMigrationService
{
    private const SCHEMA_KEY = 'docker.schema_version';
    private const IMAGE_KEY = 'docker.image_version';
    // Official images started at 1.4.14, whose schema includes 1.4.13.
    // Do not replay older table rebuilds on an unversioned Docker database.
    private const LEGACY_VERSION = '1.4.13';
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';
    private const IMAGE_VERSION_PATTERN = '/^(\d+\.\d+\.\d+)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D';

    public function __construct(private readonly Database $db, private readonly string $migrationsPath)
    {
    }

    /** @return string[] Applied migration filenames. */
    public function migrate(string $targetVersion): array
    {
        if (!preg_match(self::IMAGE_VERSION_PATTERN, $targetVersion, $versionParts)) {
            throw new RuntimeException('Invalid image version');
        }
        $migrationVersion = $versionParts[1];
        $schemaVersion = $this->stored(self::SCHEMA_KEY) ?? self::LEGACY_VERSION;
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException('Image migration directory is missing');
        }
        $imageSchemaVersion = $this->newestImageMigration($migrationVersion);
        if (version_compare($schemaVersion, $imageSchemaVersion, '>')) {
            throw new RuntimeException('The database schema is newer than this image; use the newer image');
        }

        $executed = [];
        if ($schemaVersion !== $imageSchemaVersion) {
            $result = (new Updater($this->db))->runMigrations($schemaVersion, $migrationVersion, $this->migrationsPath);
            if (!$result['success']) {
                throw new RuntimeException('Database migration failed: ' . (string) $result['error']);
            }
            $executed = $result['executed'];
        }

        if (version_compare($imageSchemaVersion, '1.4.24', '>=')) {
            $this->migrateLegacyEquipmentPreferences();
        }

        // Discard presentation data from the previous image before marking the
        // start complete. File/query/Twig caches are cleared by the entrypoint.
        if ($this->stored(self::IMAGE_KEY) !== $targetVersion) {
            $this->db->execute('DELETE FROM page_cache');
        }
        $this->store(self::SCHEMA_KEY, $imageSchemaVersion);
        $this->store(self::IMAGE_KEY, $targetVersion);

        return $executed;
    }

    /** Newest migration this image ships for the current engine, up to its own version. */
    private function newestImageMigration(string $targetVersion): string
    {
        $newest = self::LEGACY_VERSION;
        $engine = $this->db->isSqlite() ? 'sqlite' : 'mysql';
        foreach (glob($this->migrationsPath . "/migrate_*_{$engine}.sql") ?: [] as $file) {
            if (!preg_match('/^migrate_(\d+\.\d+\.\d+)_/', basename($file), $m)) {
                continue;
            }
            if (version_compare($m[1], $targetVersion, '<=') && version_compare($m[1], $newest, '>')) {
                $newest = $m[1];
            }
        }
        return $newest;
    }

    private function stored(string $key): ?string
    {
        $stmt = $this->db->query('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        $raw = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($raw === false) {
            return null;
        }
        $value = json_decode((string) $raw, true);
        $pattern = $key === self::IMAGE_KEY ? self::IMAGE_VERSION_PATTERN : self::VERSION_PATTERN;
        if (!is_string($value) || !preg_match($pattern, $value)) {
            throw new RuntimeException('Invalid stored Docker version (' . $key . ')');
        }
        return $value;
    }

    private function migrateLegacyEquipmentPreferences(): void
    {
        $marker = 'docker.fork_equipment_preferences_migrated';
        $stmt = $this->db->query('SELECT `value` FROM settings WHERE `key` = ?', [$marker]);
        $complete = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($complete !== false) {
            return;
        }
        $stmt = $this->db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'album.%.show_equipment'");
        $preferences = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        foreach ($preferences as $preference) {
            if (!preg_match('/^album\.([1-9][0-9]*)\.show_equipment$/D', (string)$preference['key'], $match)) {
                continue;
            }
            $value = json_decode((string)$preference['value'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }
            $this->db->execute('UPDATE albums SET show_equipment = ? WHERE id = ?',
                [SettingsService::boolean($value, true) ? 1 : 0, (int)$match[1]]);
        }
        $this->db->execute($this->db->replaceKeyword() . ' INTO settings (`key`, `value`, `type`) VALUES (?, ?, ?)',
            [$marker, 'true', 'boolean']);
    }

    private function store(string $key, string $version): void
    {
        $replace = $this->db->replaceKeyword();
        $this->db->execute(
            "{$replace} INTO settings (`key`, `value`, `type`) VALUES (?, ?, ?)",
            [$key, json_encode($version), 'string']
        );
    }
}
