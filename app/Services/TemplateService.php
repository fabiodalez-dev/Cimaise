<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

class TemplateService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Gallery templates for dropdowns (core + custom).
     */
    public function getGalleryTemplatesForDropdown(): array
    {
        $templates = [];

        try {
            $templates = $this->db->pdo()
                ->query('SELECT id, name FROM templates ORDER BY name')
                ->fetchAll() ?: [];
        } catch (\Throwable) {
            $templates = [];
        }

        foreach ($this->getCustomGalleryTemplateRows(['id', 'name']) as $row) {
            $templates[] = [
                'id' => 1000 + (int)$row['id'],
                'name' => $row['name'] . ' (Custom)',
            ];
        }

        return $templates;
    }

    /**
     * Full gallery templates list (core + custom).
     */
    public function getGalleryTemplates(): array
    {
        $templates = [];

        try {
            $templates = $this->db->pdo()
                ->query('SELECT * FROM templates ORDER BY name ASC')
                ->fetchAll() ?: [];
        } catch (\Throwable) {
            $templates = [];
        }

        foreach ($templates as &$template) {
            $template['settings'] = json_decode($template['settings'] ?? '{}', true) ?: [];
            $template['libs'] = json_decode($template['libs'] ?? '[]', true) ?: [];
        }
        unset($template);

        return array_merge($templates, $this->getCustomGalleryTemplates());
    }

    /**
     * Resolve a gallery template by ID (core or custom).
     */
    public function getGalleryTemplateById(int $templateId): ?array
    {
        if ($templateId >= 1000) {
            return $this->getCustomGalleryTemplateById($templateId - 1000);
        }

        try {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM templates WHERE id = :id');
            $stmt->execute([':id' => $templateId]);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }

        if (!$template) {
            return null;
        }

        $template['settings'] = json_decode($template['settings'] ?? '{}', true) ?: [];
        $template['libs'] = json_decode($template['libs'] ?? '[]', true) ?: [];

        return $template;
    }

    private function getCustomGalleryTemplates(): array
    {
        $rows = $this->getCustomGalleryTemplateRows(['id', 'name', 'slug', 'description', 'metadata']);
        $templates = [];

        foreach ($rows as $row) {
            $metadata = json_decode($row['metadata'] ?? '{}', true) ?: [];
            $templates[] = [
                'id' => 1000 + (int)$row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => $row['description'] ?? '',
                'settings' => $metadata['settings'] ?? [],
                'libs' => $metadata['libraries'] ?? [],
                'is_custom' => true,
                'custom_id' => (int)$row['id'],
            ];
        }

        return $templates;
    }

    private function getCustomGalleryTemplateById(int $customId): ?array
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, name, slug, description, metadata
                 FROM custom_templates
                 WHERE id = :id AND type = :type AND is_active = 1'
            );
            $stmt->execute([':id' => $customId, ':type' => 'gallery']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $metadata = json_decode($row['metadata'] ?? '{}', true) ?: [];

        return [
            'id' => 1000 + (int)$row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'] ?? '',
            'settings' => $metadata['settings'] ?? [],
            'libs' => $metadata['libraries'] ?? [],
            'is_custom' => true,
            'custom_id' => (int)$row['id'],
        ];
    }

    private function getCustomGalleryTemplateRows(array $fields): array
    {
        try {
            $columns = implode(', ', $fields);
            $stmt = $this->db->pdo()->query(
                "SELECT {$columns}
                 FROM custom_templates
                 WHERE type = 'gallery' AND is_active = 1
                 ORDER BY name ASC"
            );
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }
}
