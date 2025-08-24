<?php

namespace App\Repositories;

use App\Support\Database;

class LocationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM locations ORDER BY name ASC');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM locations WHERE id = ?');
        $stmt->execute([$id]);
        $location = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $location ?: null;
    }

    public function getBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM locations WHERE slug = ?');
        $stmt->execute([$slug]);
        $location = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $location ?: null;
    }

    public function create(array $data): int
    {
        // Rely on DEFAULT CURRENT_TIMESTAMP for created_at (portable for MySQL/SQLite)
        $stmt = $this->db->pdo()->prepare('INSERT INTO locations (name, slug, description) VALUES (?, ?, ?)');
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'] ?? null
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare('
            UPDATE locations 
            SET name = ?, slug = ?, description = ? 
            WHERE id = ?
        ');
        $result = $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $id
        ]);
        return $result && $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        // First remove references from albums and images
        $this->db->pdo()->prepare('UPDATE albums SET location_id = NULL WHERE location_id = ?')->execute([$id]);
        $this->db->pdo()->prepare('UPDATE images SET location_id = NULL WHERE location_id = ?')->execute([$id]);
        
        // Then delete the location
        $stmt = $this->db->pdo()->prepare('DELETE FROM locations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function search(string $query): array
    {
        $stmt = $this->db->pdo()->prepare('
            SELECT * FROM locations 
            WHERE name LIKE ? OR description LIKE ? 
            ORDER BY name ASC
        ');
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
