<?php
/**
 * Custom Templates Pro - Uninstallation Script
 *
 * Questo script viene eseguito quando il plugin viene disinstallato
 */

declare(strict_types=1);

use App\Support\Database;

return function (Database $db): array {
    try {
        // 1. Elimina tabella custom_templates
        $db->pdo()->exec('DROP TABLE IF EXISTS custom_templates');

        // 2. Elimina directory uploads (con tutti i file)
        $pluginDir = __DIR__;
        $uploadDirs = [
            $pluginDir . '/uploads/galleries',
            $pluginDir . '/uploads/albums',
            $pluginDir . '/uploads/homepages',
        ];

        $cleanup = function ($dir) use (&$cleanup) {
            if (!is_dir($dir)) {
                return;
            }

            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                is_dir($path) ? $cleanup($path) : unlink($path);
            }
            rmdir($dir);
        };

        foreach ($uploadDirs as $dir) {
            $cleanup($dir);
        }

        // Elimina la directory uploads principale se vuota
        $uploadsDir = $pluginDir . '/uploads';
        if (is_dir($uploadsDir) && count(scandir($uploadsDir)) === 2) { // Solo . e ..
            rmdir($uploadsDir);
        }

        // 3. Elimina guide generate
        $guidesDir = $pluginDir . '/guides';
        $guideFiles = [
            $guidesDir . '/gallery-template-guide.txt',
            $guidesDir . '/album-page-guide.txt',
            $guidesDir . '/homepage-guide.txt',
        ];

        foreach ($guideFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        error_log('Custom Templates Pro: Plugin uninstalled successfully');

        return [
            'success' => true,
            'message' => 'Custom Templates Pro disinstallato con successo. Tutti i dati sono stati rimossi.'
        ];

    } catch (\Exception $e) {
        error_log('Custom Templates Pro uninstallation error: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Errore durante la disinstallazione: ' . $e->getMessage()
        ];
    }
};
