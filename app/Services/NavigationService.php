<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

class NavigationService
{
    public function __construct(private Database $db)
    {
    }

    public function getNavigationCategories(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, slug FROM categories ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, name ASC');
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get parent categories with children and album counts for mega menu navigation
     */
    public function getParentCategoriesForNavigation(): array
    {
        $pdo = $this->db->pdo();

        // Get parent categories with album counts (using album_category junction table)
        $stmt = $pdo->prepare('
            SELECT c.id, c.name, c.slug, c.sort_order, c.parent_id, c.image_path, c.created_at,
                   COUNT(DISTINCT a.id) as albums_count
            FROM categories c
            LEFT JOIN album_category ac ON ac.category_id = c.id
            LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
            WHERE c.parent_id IS NULL
            GROUP BY c.id, c.name, c.slug, c.sort_order, c.parent_id, c.image_path, c.created_at
            ORDER BY c.sort_order ASC, c.name ASC
        ');
        $stmt->execute();
        $parents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get children for each parent
        foreach ($parents as &$parent) {
            $childStmt = $pdo->prepare('
                SELECT c.id, c.name, c.slug, c.sort_order, c.parent_id, c.image_path, c.created_at,
                       COUNT(DISTINCT a.id) as albums_count
                FROM categories c
                LEFT JOIN album_category ac ON ac.category_id = c.id
                LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
                WHERE c.parent_id = :parent_id
                GROUP BY c.id, c.name, c.slug, c.sort_order, c.parent_id, c.image_path, c.created_at
                ORDER BY c.sort_order ASC, c.name ASC
            ');
            $childStmt->execute([':parent_id' => $parent['id']]);
            $parent['children'] = $childStmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $parents;
    }
}
