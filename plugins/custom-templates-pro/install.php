<?php
/**
 * Custom Templates Pro - Installation Script
 *
 * Questo script viene eseguito quando il plugin viene installato
 */

declare(strict_types=1);

use App\Support\Database;
use CustomTemplatesPro\Services\GuidesGeneratorService;

return function (Database $db): array {
    try {
        // 1. Crea tabella custom_templates
        $createTable = <<<SQL
CREATE TABLE IF NOT EXISTS custom_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  name TEXT NOT NULL,
  slug TEXT UNIQUE NOT NULL,
  description TEXT,
  version TEXT NOT NULL,
  author TEXT,
  metadata TEXT,
  twig_path TEXT NOT NULL,
  css_paths TEXT,
  js_paths TEXT,
  preview_path TEXT,
  is_active INTEGER DEFAULT 1,
  installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL;

        $db->pdo()->exec($createTable);

        // Crea indici per performance
        $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_custom_templates_type ON custom_templates(type)');
        $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_custom_templates_slug ON custom_templates(slug)');
        $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_custom_templates_active ON custom_templates(is_active)');

        // 2. Crea directory uploads se non esistono
        $pluginDir = __DIR__;
        $uploadDirs = [
            $pluginDir . '/uploads/galleries',
            $pluginDir . '/uploads/albums',
            $pluginDir . '/uploads/homepages',
        ];

        foreach ($uploadDirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // 3. Crea directory guides se non esiste
        $guidesDir = $pluginDir . '/guides';
        if (!is_dir($guidesDir)) {
            mkdir($guidesDir, 0755, true);
        }

        // 4. Genera guide LLM
        require_once $pluginDir . '/Services/GuidesGeneratorService.php';
        $guidesService = new GuidesGeneratorService();
        $guidesService->generateAllGuides();

        error_log('Custom Templates Pro: Plugin installed successfully');

        return [
            'success' => true,
            'message' => 'Custom Templates Pro installato con successo! Le guide LLM sono state generate.'
        ];

    } catch (\Exception $e) {
        error_log('Custom Templates Pro installation error: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Errore durante l\'installazione: ' . $e->getMessage()
        ];
    }
};
