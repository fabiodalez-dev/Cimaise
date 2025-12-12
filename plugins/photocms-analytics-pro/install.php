<?php
/**
 * Installation script for photoCMS Analytics Pro
 * This script runs when the plugin is installed
 */

declare(strict_types=1);

echo "Installing photoCMS Analytics Pro...\n";

// Le tabelle vengono create automaticamente dalla classe AnalyticsPro
// al primo utilizzo tramite il metodo ensureTables()

// Qui possiamo aggiungere dati iniziali o configurazioni

try {
    // Esempio: Crea un funnel predefinito
    $db = \App\Support\Database::getInstance();

    if ($db && $db->pdo()) {
        // Crea funnel di esempio
        $stmt = $db->pdo()->prepare("
            INSERT OR IGNORE INTO analytics_pro_funnels (name, description, steps, is_active)
            VALUES (?, ?, ?, 1)
        ");

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
    }

    echo "✓ photoCMS Analytics Pro installed successfully!\n";
    echo "  - Database tables created\n";
    echo "  - Default funnels configured\n";
    echo "  - Plugin ready to use\n";

} catch (\Throwable $e) {
    echo "⚠ Warning during installation: " . $e->getMessage() . "\n";
    echo "  Plugin will still work, but some features may need manual setup.\n";
}
