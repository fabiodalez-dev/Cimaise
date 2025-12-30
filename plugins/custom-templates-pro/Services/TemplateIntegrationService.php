<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

use App\Support\Database;

class TemplateIntegrationService
{
    private string $pluginDir;

    public function __construct(private Database $db)
    {
        $this->pluginDir = dirname(__DIR__);
    }

    /**
     * Ottiene template custom per tipo (solo attivi)
     */
    public function getActiveTemplatesByType(string $type): array
    {
        $stmt = $this->db->pdo()->prepare(
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

            $coreFormatted[] = [
                'id' => 1000 + (int)$template['id'], // Offset per evitare conflitti con template core
                'name' => $template['name'],
                'slug' => $template['slug'],
                'description' => $template['description'],
                'settings' => $metadata['settings'] ?? [],
                'libs' => $metadata['libraries'] ?? [],
                'is_custom' => true,
                'custom_id' => $template['id'],
                'twig_path' => $template['twig_path'],
                'css_paths' => $this->decodeJson($template['css_paths'] ?? '[]', []),
                'js_paths' => $this->decodeJson($template['js_paths'] ?? '[]', []),
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
     * Ottiene il path Twig di un template custom gallery
     */
    public function getGalleryTemplatePath(int $templateId): ?string
    {
        // Rimuovi offset se presente
        $customId = $templateId >= 1000 ? $templateId - 1000 : $templateId;

        $stmt = $this->db->pdo()->prepare(
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
        $customId = $templateId >= 1000 ? $templateId - 1000 : $templateId;

        $stmt = $this->db->pdo()->prepare(
            'SELECT css_paths FROM custom_templates WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([':id' => $customId]);
        $cssPaths = $stmt->fetchColumn();

        if (!$cssPaths) {
            return '';
        }

        $paths = $this->decodeJson($cssPaths, []);
        $output = '';

        foreach ($paths as $path) {
            $fullPath = $this->pluginDir . '/' . $path;
            if (file_exists($fullPath)) {
                $output .= '<link rel="stylesheet" href="' . rtrim($basePath, '/') . '/plugins/custom-templates-pro/' . $path . '">' . "\n";
            }
        }

        return $output;
    }

    /**
     * Carica assets JS di un template
     */
    public function loadTemplateJS(int $templateId, string $basePath = '', string $nonce = ''): string
    {
        $customId = $templateId >= 1000 ? $templateId - 1000 : $templateId;

        $stmt = $this->db->pdo()->prepare(
            'SELECT js_paths FROM custom_templates WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([':id' => $customId]);
        $jsPaths = $stmt->fetchColumn();

        if (!$jsPaths) {
            return '';
        }

        $paths = $this->decodeJson($jsPaths, []);
        $output = '';

        foreach ($paths as $path) {
            $fullPath = $this->pluginDir . '/' . $path;
            if (file_exists($fullPath)) {
                $nonceAttr = $nonce ? ' nonce="' . $nonce . '"' : '';
                $output .= '<script src="' . rtrim($basePath, '/') . '/plugins/custom-templates-pro/' . $path . '"' . $nonceAttr . '></script>' . "\n";
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
        return $templateId >= 1000;
    }

    /**
     * Ottiene metadata completo di un template
     */
    public function getTemplateMetadata(int $customId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM custom_templates WHERE id = :id'
        );
        $stmt->execute([':id' => $customId]);
        $template = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $template['metadata'] = $this->decodeJson($template['metadata'] ?? null, []);
        $template['css_paths'] = $this->decodeJson($template['css_paths'] ?? '[]', []);
        $template['js_paths'] = $this->decodeJson($template['js_paths'] ?? '[]', []);

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
}
