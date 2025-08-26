<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiController extends BaseController
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    public function tags(Request $request, Response $response): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $pdo = $this->db->pdo();
        if ($q !== '') {
            $stmt = $pdo->prepare('SELECT id, name FROM tags WHERE name LIKE :q ORDER BY name LIMIT 20');
            $stmt->execute([':q' => '%' . $q . '%']);
        } else {
            $stmt = $pdo->query('SELECT id, name FROM tags ORDER BY name LIMIT 20');
        }
        $rows = $stmt->fetchAll();
        $response->getBody()->write(json_encode($rows));
        return $response->withHeader('Content-Type','application/json');
    }

    public function category(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->prepare('SELECT id, name, slug, sort_order, image_path, COALESCE(parent_id, 0) AS parent_id FROM categories WHERE id = :id');
        } catch (\Throwable) {
            // Fallback if parent_id does not exist
            $stmt = $pdo->prepare('SELECT id, name, slug, sort_order, image_path FROM categories WHERE id = :id');
        }
        $stmt->execute([':id'=>$id]);
        $row = $stmt->fetch() ?: null;
        if ($row && !array_key_exists('parent_id', $row)) { $row['parent_id'] = 0; }
        if (!$row) return $response->withStatus(404);
        $response->getBody()->write(json_encode($row));
        return $response->withHeader('Content-Type','application/json');
    }
}
