<?php
/**
 * Installation script for Cimaise Analytics Pro
 * This script runs when the plugin is installed
 */

declare(strict_types=1);

use App\Support\Database;

return function (Database $db): array {
    try {
        // Database::pdo() returns a live PDO or throws — a failed connection is
        // caught by the surrounding catch (Throwable) below.

        // PluginManager runs install.php BEFORE plugin.php, so AnalyticsPro::ensureTables()
        // hasn't necessarily created this table yet — create it here so seeding can't fail.
        $db->pdo()->exec($db->isSqlite()
            ? 'CREATE TABLE IF NOT EXISTS analytics_pro_funnels (
                   id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT,
                   steps TEXT NOT NULL, is_active INTEGER DEFAULT 1,
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
            : 'CREATE TABLE IF NOT EXISTS analytics_pro_funnels (
                   id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, description TEXT NULL,
                   steps TEXT NOT NULL, is_active TINYINT(1) DEFAULT 1,
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                   updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                   PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Default funnels (compatible with both SQLite and MySQL)
        $insertSql = $db->isSqlite()
            ? 'INSERT OR IGNORE INTO analytics_pro_funnels (name, description, steps, is_active) VALUES (?, ?, ?, 1)'
            : 'INSERT IGNORE INTO analytics_pro_funnels (name, description, steps, is_active) VALUES (?, ?, ?, 1)';

        $stmt = $db->pdo()->prepare($insertSql);

        $defaultFunnels = [
            [
                'name' => 'Album Purchase Funnel',
                'description' => 'Track user journey from homepage to album purchase',
                'steps' => json_encode(['page_view', 'album_view', 'lightbox_open', 'image_download'])
            ],
            [
                'name' => 'User Engagement Funnel',
                'description' => 'Track user engagement with content',
                'steps' => json_encode(['page_view', 'album_view', 'search', 'image_download'])
            ],
            [
                'name' => 'Content Creation Funnel',
                'description' => 'Track admin content creation workflow',
                'steps' => json_encode(['user_login', 'album_created', 'image_uploaded'])
            ]
        ];

        foreach ($defaultFunnels as $funnel) {
            $stmt->execute([$funnel['name'], $funnel['description'], $funnel['steps']]);
        }

        return [
            'success' => true,
            'message' => 'Cimaise Analytics Pro installed successfully'
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'Installation warning: ' . $e->getMessage()
        ];
    }
};
