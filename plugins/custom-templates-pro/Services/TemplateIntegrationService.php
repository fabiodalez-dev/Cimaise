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
            $template['metadata'] = json_decode($template['metadata'], true);
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
                'css_paths' => json_decode($template['css_paths'] ?? '[]', true),
                'js_paths' => json_decode($template['js_paths'] ?? '[]', true),
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
    public function loadTemplateCSS(int $templateId): string
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

        $paths = json_decode($cssPaths, true);
        $output = '';

        foreach ($paths as $path) {
            $fullPath = $this->pluginDir . '/' . $path;
            if (file_exists($fullPath)) {
                $output .= '<link rel="stylesheet" href="{{ base_path }}/plugins/custom-templates-pro/' . $path . '">' . "\n";
            }
        }

        return $output;
    }

    /**
     * Carica assets JS di un template
     */
    public function loadTemplateJS(int $templateId, string $nonce = ''): string
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

        $paths = json_decode($jsPaths, true);
        $output = '';

        foreach ($paths as $path) {
            $fullPath = $this->pluginDir . '/' . $path;
            if (file_exists($fullPath)) {
                $nonceAttr = $nonce ? ' nonce="' . $nonce . '"' : '';
                $output .= '<script src="{{ base_path }}/plugins/custom-templates-pro/' . $path . '"' . $nonceAttr . '></script>' . "\n";
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

        $template['metadata'] = json_decode($template['metadata'], true);
        $template['css_paths'] = json_decode($template['css_paths'] ?? '[]', true);
        $template['js_paths'] = json_decode($template['js_paths'] ?? '[]', true);

        return $template;
    }
}
