<?php
/**
 * Migration: Add frontend settings (dark_mode, custom_css)
 * Enables dark mode theming and custom CSS injection for frontend
 */

return new class {
    public function up(\PDO $pdo): void
    {
        // Settings to add with their default values
        $settings = [
            ['frontend.dark_mode', 'false', 'boolean'],
            ['frontend.custom_css', '', 'string'],
        ];

        foreach ($settings as [$key, $value, $type]) {
            // Check if setting already exists
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = :key');
            $stmt->execute([':key' => $key]);
            $exists = (int)$stmt->fetchColumn() > 0;
            $stmt->closeCursor();

            if (!$exists) {
                $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`, `type`) VALUES (:key, :value, :type)');
                $stmt->execute([
                    ':key' => $key,
                    ':value' => $value,
                    ':type' => $type,
                ]);
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM settings WHERE `key` IN ('frontend.dark_mode', 'frontend.custom_css')");
    }
};
