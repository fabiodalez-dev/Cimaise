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
        $stmt = $this->db->pdo()->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
