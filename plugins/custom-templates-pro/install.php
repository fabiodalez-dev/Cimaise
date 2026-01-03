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
        // 1. Crea tabella custom_templates con sintassi appropriata
        if ($db->isSqlite()) {
            $createTable = <<<SQL
CREATE TABLE IF NOT EXISTS custom_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL CHECK(type IN ('gallery', 'album_page', 'homepage')),
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
  is_active INTEGER DEFAULT 1 CHECK(is_active IN (0, 1)),
  installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL;
        } else {
            $createTable = <<<SQL
CREATE TABLE IF NOT EXISTS custom_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(50) NOT NULL CHECK(type IN ('gallery', 'album_page', 'homepage')),
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  description TEXT NULL,
  version VARCHAR(50) NOT NULL,
  author VARCHAR(190) NULL,
  metadata JSON NULL,
  twig_path VARCHAR(255) NOT NULL,
  css_paths JSON NULL,
  js_paths JSON NULL,
  preview_path VARCHAR(255) NULL,
  is_active TINYINT(1) DEFAULT 1 CHECK(is_active IN (0, 1)),
  installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY idx_custom_templates_slug (slug),
  KEY idx_custom_templates_type (type),
  KEY idx_custom_templates_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        }

        $db->pdo()->exec($createTable);

        // Crea indici per performance (solo SQLite; MySQL li ha già)
        if ($db->isSqlite()) {
            $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_custom_templates_type ON custom_templates(type)');
            $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_custom_templates_active ON custom_templates(is_active)');
        }

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

        // 4. Verifica guide template
        require_once $pluginDir . '/Services/GuidesGeneratorService.php';
        $guidesService = new GuidesGeneratorService();
        if (!$guidesService->guidesExist()) {
            throw new \RuntimeException('Guide template mancanti');
        }

        error_log('Custom Templates Pro: Plugin installed successfully');

        return [
            'success' => true,
            'message' => 'Custom Templates Pro installato con successo!'
        ];

    } catch (\Throwable $e) {
        error_log('Custom Templates Pro installation error: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Errore durante l\'installazione: ' . $e->getMessage()
        ];
    }
};
