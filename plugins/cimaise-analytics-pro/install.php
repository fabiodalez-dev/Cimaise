<?php
/**
 * Installation script for Cimaise Analytics Pro
 * This script runs when the plugin is installed
 */

declare(strict_types=1);

use App\Support\Database;

return function (Database $db): array {
    try {
        if (!$db->pdo()) {
            return [
                'success' => false,
                'message' => 'Database connection not available'
            ];
        }

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
