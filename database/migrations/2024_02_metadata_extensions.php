<?php
/**
 * Migration: Metadata Extensions
 * Allows plugins to add custom data to built-in metadata (cameras, lenses, etc.)
 */

return new class {
    public function up(\PDO $pdo): void
    {
        // Detect database driver
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $intPrimary = $driver === 'mysql'
            ? 'INT AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        // Table: metadata_extensions
        // Stores arbitrary plugin data for built-in metadata entities
        $updatedAt = $driver === 'mysql'
            ? 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            : 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS metadata_extensions (
                id $intPrimary,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT NOT NULL,
                extension_key VARCHAR(100) NOT NULL,
                extension_value TEXT NULL,
                plugin_id VARCHAR(100) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                $updatedAt,
                UNIQUE(entity_type, entity_id, extension_key)
            )
        ");

        // Create indexes (MySQL doesn't support IF NOT EXISTS for indexes)
        if ($driver === 'mysql') {
            // Check if indexes exist before creating - use separate statements to avoid cursor issues
            $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'metadata_extensions' AND index_name = ?");
            $stmt1->execute(['idx_meta_ext_entity']);
            $idx1Exists = (int)$stmt1->fetchColumn() !== 0;
            $stmt1->closeCursor();
            if (!$idx1Exists) {
                $pdo->exec("CREATE INDEX idx_meta_ext_entity ON metadata_extensions(entity_type, entity_id)");
            }

            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'metadata_extensions' AND index_name = ?");
            $stmt2->execute(['idx_meta_ext_plugin']);
            $idx2Exists = (int)$stmt2->fetchColumn() !== 0;
            $stmt2->closeCursor();
            if (!$idx2Exists) {
                $pdo->exec("CREATE INDEX idx_meta_ext_plugin ON metadata_extensions(plugin_id)");
            }
        } else {
            // SQLite supports IF NOT EXISTS
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meta_ext_entity ON metadata_extensions(entity_type, entity_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meta_ext_plugin ON metadata_extensions(plugin_id)");
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS metadata_extensions");
    }
};
