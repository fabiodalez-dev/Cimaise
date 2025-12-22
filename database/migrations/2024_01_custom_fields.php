<?php
/**
 * Migration: Custom Fields System
 * Creates tables for user-defined metadata types and values
 */

return new class {
    public function up(\PDO $pdo): void
    {
        // Detect database driver
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $autoIncrement = $driver === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT';
        $intPrimary = $driver === 'mysql'
            ? 'INT AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        // Table: custom_field_types
        // Defines metadata types (both built-in and custom)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS custom_field_types (
                id $intPrimary,
                name VARCHAR(100) NOT NULL,
                label VARCHAR(160) NOT NULL,
                icon VARCHAR(60) DEFAULT 'fa-tag',
                description TEXT NULL,
                field_type VARCHAR(20) DEFAULT 'select',
                is_system BOOLEAN DEFAULT 0,
                show_in_lightbox BOOLEAN DEFAULT 1,
                show_in_gallery BOOLEAN DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(name)
            )
        ");

        // Seed built-in types (for reference, not editable)
        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO custom_field_types (name, label, icon, is_system, sort_order)
            VALUES (?, ?, ?, 1, ?)
        ");

        // For MySQL, use INSERT IGNORE
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO custom_field_types (name, label, icon, is_system, sort_order)
                VALUES (?, ?, ?, 1, ?)
            ");
        }

        $builtIn = [
            ['camera', 'Camera', 'fa-camera', 1],
            ['lens', 'Lens', 'fa-dot-circle', 2],
            ['film', 'Film', 'fa-film', 3],
            ['developer', 'Developer', 'fa-flask', 4],
            ['lab', 'Lab', 'fa-industry', 5],
            ['location', 'Location', 'fa-map-marker-alt', 6]
        ];

        foreach ($builtIn as $row) {
            $stmt->execute($row);
        }

        // Table: custom_field_values
        // Stores possible values for each custom field type
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS custom_field_values (
                id $intPrimary,
                field_type_id INT NOT NULL,
                value VARCHAR(255) NOT NULL,
                extra_data TEXT NULL,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
                UNIQUE(field_type_id, value)
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cfv_type ON custom_field_values(field_type_id)");

        // Table: image_custom_fields
        // Junction table: associates custom fields with images
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS image_custom_fields (
                id $intPrimary,
                image_id INT NOT NULL,
                field_type_id INT NOT NULL,
                field_value_id INT NULL,
                custom_value VARCHAR(255) NULL,
                is_override BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
                FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
                FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_icf_image ON image_custom_fields(image_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_icf_type ON image_custom_fields(field_type_id)");

        // Table: album_custom_fields
        // Junction table: associates custom fields with albums (multiple values supported)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS album_custom_fields (
                id $intPrimary,
                album_id INT NOT NULL,
                field_type_id INT NOT NULL,
                field_value_id INT NULL,
                custom_value VARCHAR(255) NULL,
                auto_added BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
                FOREIGN KEY (field_type_id) REFERENCES custom_field_types(id) ON DELETE CASCADE,
                FOREIGN KEY (field_value_id) REFERENCES custom_field_values(id) ON DELETE SET NULL
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_acf_album ON album_custom_fields(album_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_acf_type ON album_custom_fields(field_type_id)");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS album_custom_fields");
        $pdo->exec("DROP TABLE IF EXISTS image_custom_fields");
        $pdo->exec("DROP TABLE IF EXISTS custom_field_values");
        $pdo->exec("DROP TABLE IF EXISTS custom_field_types");
    }
};
