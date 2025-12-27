<?php
/**
 * Migration: Add allow_template_switch column to albums
 * Allows admin to lock gallery display to a specific template
 */

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // Check if column already exists
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'albums' AND column_name = 'allow_template_switch'");
            $stmt->execute();
            $exists = (int)$stmt->fetchColumn() > 0;
            $stmt->closeCursor();
        } else {
            // SQLite
            $result = $pdo->query("PRAGMA table_info(albums)");
            $exists = false;
            foreach ($result as $col) {
                if ($col['name'] === 'allow_template_switch') {
                    $exists = true;
                    break;
                }
            }
        }

        if (!$exists) {
            $colType = $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';
            $pdo->exec("ALTER TABLE albums ADD COLUMN allow_template_switch $colType NOT NULL DEFAULT 0");
        }
    }

    public function down(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec("ALTER TABLE albums DROP COLUMN allow_template_switch");
        } else {
            // SQLite doesn't support DROP COLUMN in older versions
            // For simplicity, we leave the column (it won't cause issues)
        }
    }
};
