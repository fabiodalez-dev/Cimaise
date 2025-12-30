<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

use App\Support\Database;
use ZipArchive;

class TemplateUploadService
{
    private string $pluginDir;

    public function __construct(
        private Database $db,
        private TemplateValidationService $validator
    ) {
        $this->pluginDir = dirname(__DIR__);
    }

    /**
     * Processa upload di un template ZIP (singolo o multiplo)
     */
    public function processUpload(array $uploadedFile, string $type): array
    {
        // Verifica errori upload
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'error' => $this->getUploadErrorMessage($uploadedFile['error'])
            ];
        }

        $tmpPath = $uploadedFile['tmp_name'];

        // Validazione ZIP base
        if (!$this->validator->validateZip($tmpPath, $type)) {
            return [
                'success' => false,
                'error' => $this->validator->getFirstError()
            ];
        }

        // Controlla se è un ZIP multi-template
        $templateFolders = $this->detectTemplateFolders($tmpPath);

        if (count($templateFolders) > 1) {
            // Multi-template ZIP
            return $this->processMultiTemplateZip($tmpPath, $type, $templateFolders);
        }

        // Single template ZIP (comportamento originale)
        return $this->processSingleTemplate($tmpPath, $type);
    }

    /**
     * Rileva le cartelle template nel ZIP
     * Cerca cartelle che contengono metadata.json
     */
    private function detectTemplateFolders(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }

        $folders = [];
        $hasRootMetadata = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // metadata.json alla root = singolo template
            if ($name === 'metadata.json') {
                $hasRootMetadata = true;
                break;
            }

            // metadata.json in una sottocartella
            if (preg_match('#^([^/]+)/metadata\.json$#', $name, $matches)) {
                $folders[] = $matches[1];
            }
        }

        $zip->close();

        // Se c'è metadata alla root, è un singolo template
        if ($hasRootMetadata) {
            return [''];
        }

        return $folders;
    }

    /**
     * Processa ZIP con template multipli
     */
    private function processMultiTemplateZip(string $zipPath, string $type, array $folders): array
    {
        $results = [
            'success' => true,
            'templates' => [],
            'errors' => [],
            'total' => count($folders),
            'installed' => 0
        ];

        // Estrai ZIP in una directory temporanea
        $tempDir = sys_get_temp_dir() . '/ctp_multi_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            return ['success' => false, 'error' => 'Impossibile creare directory temporanea'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            rmdir($tempDir);
            return ['success' => false, 'error' => 'Impossibile aprire il file ZIP'];
        }
        $zip->extractTo($tempDir);
        $zip->close();

        // Processa ogni cartella template
        foreach ($folders as $folder) {
            $folderPath = $tempDir . '/' . $folder;
            $metadataPath = $folderPath . '/metadata.json';

            if (!file_exists($metadataPath)) {
                $results['errors'][] = "Template '{$folder}': metadata.json non trovato";
                continue;
            }

            try {
                $metadata = json_decode(file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $results['errors'][] = "Template '{$folder}': metadata.json non valido";
                continue;
            }
            if (!$metadata || !isset($metadata['slug'])) {
                $results['errors'][] = "Template '{$folder}': metadata.json non valido";
                continue;
            }

            // Verifica slug univoco
            if ($this->slugExists($metadata['slug'])) {
                $results['errors'][] = "Template '{$metadata['slug']}': slug già esistente, saltato";
                continue;
            }

            // Copia nella directory di destinazione
            $extractPath = $this->getExtractPath($type, $metadata['slug']);
            if (!$this->copyDirectory($folderPath, $extractPath)) {
                $results['errors'][] = "Template '{$metadata['slug']}': errore durante la copia";
                continue;
            }

            // Valida contenuti
            if (!$this->validateExtractedContent($extractPath, $metadata, $type)) {
                $this->cleanup($extractPath);
                $results['errors'][] = "Template '{$metadata['slug']}': " . $this->validator->getFirstError();
                continue;
            }

            // Salva nel database
            $templateId = $this->saveTemplateWithTransaction($metadata, $type, $extractPath);
            if (!$templateId) {
                $this->cleanup($extractPath);
                $results['errors'][] = "Template '{$metadata['slug']}': errore salvataggio database";
                continue;
            }

            $results['templates'][] = [
                'id' => $templateId,
                'name' => $metadata['name'],
                'slug' => $metadata['slug']
            ];
            $results['installed']++;
        }

        // Pulizia directory temporanea
        $this->cleanup($tempDir);

        // Se nessun template installato, segnala errore
        if ($results['installed'] === 0) {
            $results['success'] = false;
            $results['error'] = 'Nessun template installato. ' . implode('; ', $results['errors']);
        }

        return $results;
    }

    /**
     * Processa singolo template (comportamento originale)
     */
    private function processSingleTemplate(string $tmpPath, string $type): array
    {
        // Estrai metadata
        $metadata = $this->extractMetadata($tmpPath);
        if (!$metadata) {
            return [
                'success' => false,
                'error' => 'Impossibile estrarre metadata dal ZIP'
            ];
        }

        // Verifica slug univoco
        if ($this->slugExists($metadata['slug'])) {
            return [
                'success' => false,
                'error_code' => 'slug_exists',
                'slug' => $metadata['slug']
            ];
        }

        // Estrai ZIP
        $extractPath = $this->getExtractPath($type, $metadata['slug']);
        if (!$this->extractZip($tmpPath, $extractPath)) {
            return [
                'success' => false,
                'error' => 'Errore durante l\'estrazione del ZIP'
            ];
        }

        // Valida contenuti estratti (usa il tipo dal form, non dal metadata)
        if (!$this->validateExtractedContent($extractPath, $metadata, $type)) {
            $this->cleanup($extractPath);
            return [
                'success' => false,
                'error' => $this->validator->getFirstError()
            ];
        }

        // Salva nel database
        $templateId = $this->saveTemplateWithTransaction($metadata, $type, $extractPath);
        if (!$templateId) {
            $this->cleanup($extractPath);
            return [
                'success' => false,
                'error' => 'Errore nel salvataggio del template nel database'
            ];
        }

        return [
            'success' => true,
            'template_id' => $templateId,
            'metadata' => $metadata
        ];
    }

    /**
     * Copia una directory ricorsivamente
     */
    private function copyDirectory(string $source, string $dest): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($dest)) {
            if (!mkdir($dest, 0755, true)) {
                return false;
            }
        }

        $dir = opendir($source);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $source . '/' . $file;
            $destPath = $dest . '/' . $file;

            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $destPath)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!copy($srcPath, $destPath)) {
                    closedir($dir);
                    return false;
                }
            }
        }
        closedir($dir);

        return true;
    }

    /**
     * Estrae metadata.json dal ZIP
     */
    private function extractMetadata(string $zipPath): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        // Cerca metadata.json alla root o dentro una cartella
        $metadataContent = $zip->getFromName('metadata.json');

        if ($metadataContent === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^[^/]+/metadata\.json$#', $name)) {
                    $metadataContent = $zip->getFromName($name);
                    break;
                }
            }
        }

        $zip->close();

        if ($metadataContent === false) {
            return null;
        }

        try {
            return json_decode($metadataContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Verifica se uno slug esiste già
     */
    private function slugExists(string $slug): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM custom_templates WHERE slug = :slug'
        );
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Ottiene il path di estrazione
     */
    private function getExtractPath(string $type, string $slug): string
    {
        $typeDir = match ($type) {
            'gallery' => 'galleries',
            'album_page' => 'albums',
            'homepage' => 'homepages',
            default => throw new \InvalidArgumentException("Invalid type: {$type}")
        };

        return $this->pluginDir . "/uploads/{$typeDir}/{$slug}";
    }

    /**
     * Estrae il ZIP nella directory di destinazione
     */
    private function extractZip(string $zipPath, string $extractPath): bool
    {
        // Crea directory se non esiste
        if (!is_dir($extractPath)) {
            if (!mkdir($extractPath, 0755, true)) {
                return false;
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        // Estrai tutti i file
        $result = $zip->extractTo($extractPath);
        $zip->close();

        return $result;
    }

    /**
     * Valida il contenuto estratto
     */
    private function validateExtractedContent(string $extractPath, array $metadata, string $type): bool
    {
        // Determina file template principale in base al tipo selezionato nel form
        $templateFile = $this->getMainTemplateFile($type);
        $templatePath = $extractPath . '/' . $templateFile;

        if (!file_exists($templatePath)) {
            $this->validator->addError("File template principale non trovato: {$templateFile}");
            return false;
        }

        // Valida template Twig
        $twigContent = file_get_contents($templatePath);
        if (!$this->validator->validateTwigSyntax($twigContent)) {
            return false;
        }

        // Valida CSS se presente
        if (isset($metadata['assets']['css'])) {
            foreach ($metadata['assets']['css'] as $cssFile) {
                $cssPath = $extractPath . '/' . $cssFile;
                if (file_exists($cssPath)) {
                    $cssContent = file_get_contents($cssPath);
                    if (!$this->validator->validateCSS($cssContent)) {
                        return false;
                    }
                }
            }
        }

        // Valida JS se presente
        if (isset($metadata['assets']['js'])) {
            foreach ($metadata['assets']['js'] as $jsFile) {
                $jsPath = $extractPath . '/' . $jsFile;
                if (file_exists($jsPath)) {
                    $jsContent = file_get_contents($jsPath);
                    if (!$this->validator->validateJavaScript($jsContent)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Ottiene il nome del file template principale in base al tipo
     */
    private function getMainTemplateFile(string $type): string
    {
        return match ($type) {
            'gallery' => 'template.twig',
            'album_page' => 'page.twig',
            'homepage' => 'home.twig',
            default => 'template.twig'
        };
    }

    /**
     * Salva il template nel database
     */
    private function saveToDatabase(array $metadata, string $type, string $extractPath): ?int
    {
        $templateFile = $this->getMainTemplateFile($type);
        $relativePath = str_replace($this->pluginDir . '/', '', $extractPath);

        $cssPaths = isset($metadata['assets']['css'])
            ? json_encode(array_map(fn($f) => $relativePath . '/' . $f, $metadata['assets']['css']))
            : null;

        $jsPaths = isset($metadata['assets']['js'])
            ? json_encode(array_map(fn($f) => $relativePath . '/' . $f, $metadata['assets']['js']))
            : null;

        $previewPath = null;
        if (file_exists($extractPath . '/preview.jpg')) {
            $previewPath = $relativePath . '/preview.jpg';
        } elseif (file_exists($extractPath . '/preview.png')) {
            $previewPath = $relativePath . '/preview.png';
        }

        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO custom_templates
                (type, name, slug, description, version, author, metadata,
                 twig_path, css_paths, js_paths, preview_path, is_active,
                 installed_at, updated_at)
                VALUES
                (:type, :name, :slug, :description, :version, :author, :metadata,
                 :twig_path, :css_paths, :js_paths, :preview_path, 1,
                 :installed_at, :updated_at)';

        $stmt = $this->db->pdo()->prepare($sql);

        $result = $stmt->execute([
            ':type' => $type,
            ':name' => $metadata['name'],
            ':slug' => $metadata['slug'],
            ':description' => $metadata['description'] ?? null,
            ':version' => $metadata['version'],
            ':author' => $metadata['author'] ?? null,
            ':metadata' => json_encode($metadata),
            ':twig_path' => $relativePath . '/' . $templateFile,
            ':css_paths' => $cssPaths,
            ':js_paths' => $jsPaths,
            ':preview_path' => $previewPath,
            ':installed_at' => $now,
            ':updated_at' => $now
        ]);

        if (!$result) {
            return null;
        }

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Salva il template con transazione DB per evitare stati inconsistenti
     */
    private function saveTemplateWithTransaction(array $metadata, string $type, string $extractPath): ?int
    {
        $pdo = $this->db->pdo();
        $started = false;

        if (method_exists($pdo, 'inTransaction') && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        try {
            $templateId = $this->saveToDatabase($metadata, $type, $extractPath);
            if (!$templateId) {
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return null;
            }

            if ($started && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return $templateId;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ottiene tutti i template per tipo
     */
    public function getTemplatesByType(string $type): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM custom_templates WHERE type = :type ORDER BY name ASC'
        );
        $stmt->execute([':type' => $type]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Ottiene un template per ID
     */
    public function getTemplateById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM custom_templates WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Attiva/disattiva un template
     */
    public function toggleTemplate(int $id): bool
    {
        $template = $this->getTemplateById($id);
        if (!$template) {
            return false;
        }

        $newStatus = $template['is_active'] ? 0 : 1;
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->pdo()->prepare(
            'UPDATE custom_templates SET is_active = :status, updated_at = :updated_at WHERE id = :id'
        );

        return $stmt->execute([
            ':status' => $newStatus,
            ':updated_at' => $now,
            ':id' => $id
        ]);
    }

    /**
     * Elimina un template
     */
    public function deleteTemplate(int $id): bool
    {
        $template = $this->getTemplateById($id);
        if (!$template) {
            return false;
        }

        // Elimina file fisici
        $extractPath = $this->pluginDir . '/' . dirname($template['twig_path']);
        $realExtractPath = realpath($extractPath);
        $uploadsDir = realpath($this->pluginDir . '/uploads');

        if (!$realExtractPath || !$uploadsDir || !str_starts_with($realExtractPath, $uploadsDir)) {
            return false;
        }

        $this->cleanup($realExtractPath);

        // Elimina dal database
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM custom_templates WHERE id = :id'
        );

        return $stmt->execute([':id' => $id]);
    }

    /**
     * Pulisce una directory ricorsivamente
     */
    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $realDir = realpath($dir);
        $uploadsDir = realpath($this->pluginDir . '/uploads');
        $tempDir = realpath(sys_get_temp_dir());

        $allowed = false;
        if ($realDir && $uploadsDir && str_starts_with($realDir, $uploadsDir)) {
            $allowed = true;
        }
        if ($realDir && $tempDir && str_starts_with($realDir, $tempDir)) {
            $allowed = true;
        }

        if (!$allowed) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->cleanup($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Ottiene il messaggio di errore per errori di upload
     */
    private function getUploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Il file supera la dimensione massima consentita',
            UPLOAD_ERR_PARTIAL => 'Il file è stato caricato solo parzialmente',
            UPLOAD_ERR_NO_FILE => 'Nessun file è stato caricato',
            UPLOAD_ERR_NO_TMP_DIR => 'Directory temporanea mancante',
            UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file sul disco',
            UPLOAD_ERR_EXTENSION => 'Upload bloccato da un\'estensione PHP',
            default => 'Errore sconosciuto durante l\'upload'
        };
    }

    /**
     * Ottiene statistiche sui template
     */
    public function getStats(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT type, COUNT(*) as count, SUM(is_active) as active
             FROM custom_templates
             GROUP BY type'
        );

        $stats = [
            'gallery' => ['total' => 0, 'active' => 0],
            'album_page' => ['total' => 0, 'active' => 0],
            'homepage' => ['total' => 0, 'active' => 0],
        ];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $stats[$row['type']] = [
                'total' => (int) $row['count'],
                'active' => (int) $row['active']
            ];
        }

        return $stats;
    }
}
