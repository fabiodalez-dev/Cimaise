<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

use App\Support\Database;
use PDO;

class TemplateIntegrationService
{
    private const CUSTOM_ID_OFFSET = 1000;
    private string $pluginDir;
    private PDO $pdo;

    public function __construct(Database|PDO $db)
    {
        $this->pluginDir = dirname(__DIR__);
        $this->pdo = $db instanceof Database ? $db->pdo() : $db;
    }

    /**
     * Ottiene template custom per tipo (solo attivi)
     */
    public function getActiveTemplatesByType(string $type): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM custom_templates WHERE type = :type AND is_active = 1 ORDER BY name ASC'
        );
        $stmt->execute([':type' => $type]);
        $templates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Decodifica metadata JSON
        foreach ($templates as &$template) {
            $template['metadata'] = $this->decodeJson($template['metadata'] ?? null, []);
        }

        return $templates;
    }

    /**
     * Converte template custom in formato compatibile con core (per gallerie)
     */
    public function getGalleryTemplatesForCore(): array
    {
        $customTemplates = $this->getActiveTemplatesByType('gallery');
        $coreFormatted = [];

        foreach ($customTemplates as $template) {
            $metadata = $template['metadata'];
            $cssPaths = $this->sanitizeAssetPaths(
                $this->decodeJson($template['css_paths'] ?? '[]', [])
            );
            $jsPaths = $this->sanitizeAssetPaths(
                $this->decodeJson($template['js_paths'] ?? '[]', [])
            );

            $coreFormatted[] = [
                'id' => self::CUSTOM_ID_OFFSET + (int)$template['id'], // Offset per evitare conflitti con template core
                'name' => $template['name'],
                'slug' => $template['slug'],
                'description' => $template['description'],
                'settings' => $metadata['settings'] ?? [],
                'libs' => $metadata['libraries'] ?? [],
                'is_custom' => true,
                'custom_id' => $template['id'],
                'twig_path' => $template['twig_path'],
                'css_paths' => $cssPaths,
                'js_paths' => $jsPaths,
                'preview_path' => $template['preview_path']
            ];
        }

        return $coreFormatted;
    }

    /**
     * Ottiene template homepage custom
     */
    public function getHomepageTemplatesForCore(): array
    {
        $customTemplates = $this->getActiveTemplatesByType('homepage');
        $coreFormatted = [];

        foreach ($customTemplates as $template) {
            $coreFormatted[] = [
                'value' => 'custom_' . $template['slug'],
                'label' => $template['name'] . ' (Custom)',
                'description' => $template['description'],
                'custom_id' => $template['id'],
                'twig_path' => $template['twig_path']
            ];
        }

        return $coreFormatted;
    }

    /**
     * Ottiene template pagina album custom
     */
    public function getAlbumPageTemplatesForCore(): array
    {
        $customTemplates = $this->getActiveTemplatesByType('album_page');
        $coreFormatted = [];

        foreach ($customTemplates as $template) {
            $metadata = is_array($template['metadata'] ?? null)
                ? $template['metadata']
                : $this->decodeJson($template['metadata'] ?? '{}', []);
            $coreFormatted[] = [
                'value' => 'custom_' . $template['slug'],
                'label' => $template['name'] . ' (Custom)',
                'description' => $template['description'],
                'custom_id' => $template['id'],
                'twig_path' => $template['twig_path'],
                'settings' => is_array($metadata) ? ($metadata['settings'] ?? []) : []
            ];
        }

        return $coreFormatted;
    }

    /**
     * Ottiene il path Twig di un template custom gallery
     */
    public function getGalleryTemplatePath(int $templateId): ?string
    {
        // Rimuovi offset se presente
        $customId = $templateId >= self::CUSTOM_ID_OFFSET
            ? $templateId - self::CUSTOM_ID_OFFSET
            : $templateId;

        $stmt = $this->pdo->prepare(
            'SELECT twig_path FROM custom_templates WHERE id = :id AND type = :type AND is_active = 1'
        );
        $stmt->execute([':id' => $customId, ':type' => 'gallery']);
        $result = $stmt->fetchColumn();

        return $result ?: null;
    }

    /**
     * Carica assets CSS di un template
     */
    public function loadTemplateCSS(int $templateId, string $basePath = ''): string
    {
        $customId = $templateId >= self::CUSTOM_ID_OFFSET
            ? $templateId - self::CUSTOM_ID_OFFSET
            : $templateId;

        $stmt = $this->pdo->prepare(
            'SELECT css_paths FROM custom_templates WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([':id' => $customId]);
        $cssPaths = $stmt->fetchColumn();

        if (!$cssPaths) {
            return '';
        }

        $paths = $this->decodeJson($cssPaths, []);
        $output = '';
        $safeBasePath = htmlspecialchars(rtrim($basePath, '/'), ENT_QUOTES, 'UTF-8');

        foreach ($paths as $path) {
            $safePath = $this->validateAssetPath((string)$path);
            if ($safePath === null) {
                continue;
            }
            $fullPath = $this->pluginDir . '/' . $safePath;
            if (file_exists($fullPath)) {
                $output .= '<link rel="stylesheet" href="' . $safeBasePath . '/plugins/custom-templates-pro/' . htmlspecialchars($safePath, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }
        }

        return $output;
    }

    /**
     * Carica assets JS di un template
     */
    public function loadTemplateJS(int $templateId, string $basePath = '', string $nonce = ''): string
    {
        $customId = $templateId >= self::CUSTOM_ID_OFFSET
            ? $templateId - self::CUSTOM_ID_OFFSET
            : $templateId;

        $stmt = $this->pdo->prepare(
            'SELECT js_paths FROM custom_templates WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([':id' => $customId]);
        $jsPaths = $stmt->fetchColumn();

        if (!$jsPaths) {
            return '';
        }

        $paths = $this->decodeJson($jsPaths, []);
        $output = '';
        $safeBasePath = htmlspecialchars(rtrim($basePath, '/'), ENT_QUOTES, 'UTF-8');
        $safeNonce = $nonce ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        foreach ($paths as $path) {
            $safePath = $this->validateAssetPath((string)$path);
            if ($safePath === null) {
                continue;
            }
            $fullPath = $this->pluginDir . '/' . $safePath;
            if (file_exists($fullPath)) {
                $output .= '<script src="' . $safeBasePath . '/plugins/custom-templates-pro/' . htmlspecialchars($safePath, ENT_QUOTES, 'UTF-8') . '"' . $safeNonce . '></script>' . "\n";
            }
        }

        return $output;
    }

    /**
     * Ottiene tutti i path Twig da registrare
     */
    public function getTwigPaths(): array
    {
        return [
            $this->pluginDir . '/uploads/galleries',
            $this->pluginDir . '/uploads/albums',
            $this->pluginDir . '/uploads/homepages',
        ];
    }

    /**
     * Verifica se un template ID è custom
     */
    public function isCustomTemplate(int $templateId): bool
    {
        return $templateId >= self::CUSTOM_ID_OFFSET;
    }

    /**
     * Ottiene metadata completo di un template
     */
    public function getTemplateMetadata(int $customId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM custom_templates WHERE id = :id'
        );
        $stmt->execute([':id' => $customId]);
        $template = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $template['metadata'] = $this->decodeJson($template['metadata'] ?? null, []);
        $template['css_paths'] = $this->sanitizeAssetPaths($this->decodeJson($template['css_paths'] ?? '[]', []));
        $template['js_paths'] = $this->sanitizeAssetPaths($this->decodeJson($template['js_paths'] ?? '[]', []));

        return $template;
    }

    private function decodeJson(?string $json, array $default): array
    {
        if ($json === null || $json === '') {
            return $default;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $default;
        }

        return is_array($decoded) ? $decoded : $default;
    }

    public function resolveTwigTemplatePath(string $twigPath, string $type): ?string
    {
        $cleanPath = ltrim(str_replace('\\', '/', $twigPath), '/');
        if ($cleanPath === '' || str_contains($cleanPath, '..')) {
            return null;
        }

        $fullPath = $this->pluginDir . '/' . $cleanPath;
        $realPath = realpath($fullPath);
        if ($realPath === false) {
            return null;
        }

        $baseDir = realpath($this->pluginDir . '/uploads/' . $type);
        if ($baseDir === false) {
            return null;
        }
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $baseDir)) {
            return null;
        }

        $relative = substr($realPath, strlen($baseDir));
        return ltrim(str_replace('\\', '/', (string)$relative), '/');
    }

    private function validateAssetPath(string $path): ?string
    {
        $cleanPath = ltrim(str_replace('\\', '/', $path), '/');
        if ($cleanPath === '' || str_contains($cleanPath, '..')) {
            return null;
        }

        $fullPath = $this->pluginDir . '/' . $cleanPath;
        $realPath = realpath($fullPath);
        if ($realPath === false) {
            return null;
        }

        $uploadsDir = realpath($this->pluginDir . '/uploads');
        if ($uploadsDir === false) {
            return null;
        }
        $uploadsDir = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $uploadsDir)) {
            return null;
        }

        return $cleanPath;
    }

    private function sanitizeAssetPaths(array $paths): array
    {
        $clean = [];
        foreach ($paths as $path) {
            $safePath = $this->validateAssetPath((string)$path);
            if ($safePath !== null) {
                $clean[] = $safePath;
            }
        }
        return $clean;
    }
}
