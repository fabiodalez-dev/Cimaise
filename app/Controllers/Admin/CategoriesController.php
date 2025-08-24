<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CategoriesController
{
    public function __construct(private Database $db, private Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->query('SELECT id, name, slug, sort_order, COALESCE(parent_id, 0) AS parent_id FROM categories ORDER BY sort_order ASC, name ASC');
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            // Fallback for pre-migration DBs (no parent_id column)
            $stmt = $pdo->query('SELECT id, name, slug, sort_order FROM categories ORDER BY sort_order ASC, name ASC');
            $rows = array_map(function(array $r){ $r['parent_id'] = 0; return $r; }, $stmt->fetchAll() ?: []);
        }
        // Group on server for efficiency
        $byParent = [];
        foreach ($rows as $r) {
            $pid = (int)($r['parent_id'] ?? 0);
            if (!isset($byParent[$pid])) $byParent[$pid] = [];
            $byParent[$pid][] = $r;
        }
        return $this->view->render($response, 'admin/categories/index.twig', [
            'byParent' => $byParent,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function reorder(Request $request, Response $response): Response
    {
        $data = json_decode((string)$request->getBody(), true) ?: [];
        $tree = $data['tree'] ?? [];
        if (!is_array($tree)) {
            return $response->withStatus(400);
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE categories SET parent_id = :parent, sort_order = :sort WHERE id = :id');
            $apply = function(array $nodes, int $parentId) use (&$apply, $update): void {
                $sort = 0;
                foreach ($nodes as $n) {
                    $id = (int)($n['id'] ?? 0);
                    if ($id <= 0) continue;
                    $update->execute([':parent' => $parentId ?: null, ':sort' => $sort++, ':id' => $id]);
                    if (!empty($n['children']) && is_array($n['children'])) {
                        $apply($n['children'], $id);
                    }
                }
            };
            $apply($tree, 0);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
        $response->getBody()->write(json_encode(['ok'=>true]));
        return $response->withHeader('Content-Type','application/json');
    }

    public function create(Request $request, Response $response): Response
    {
        // Get parent categories for dropdown
        $pdo = $this->db->pdo();
        $stmt = $pdo->query('SELECT id, name FROM categories WHERE parent_id IS NULL OR parent_id = 0 ORDER BY sort_order ASC, name ASC');
        $parents = $stmt->fetchAll() ?: [];
        
        return $this->view->render($response, 'admin/categories/create.twig', [
            'parents' => $parents,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $sort = (int)($data['sort_order'] ?? 0);
        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome obbligatorio'];
            return $response->withHeader('Location', '/admin/categories/create')->withStatus(302);
        }
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }
        // Handle parent_id
        $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        
        // Handle image upload
        $imagePath = null;
        $uploadedFiles = $request->getUploadedFiles();
        if (isset($uploadedFiles['image']) && $uploadedFiles['image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['image'];
            $extension = strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
            
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = $slug . '_' . time() . '.' . $extension;
                $uploadPath = '/media/categories/' . $filename;
                
                // Create directory if it doesn't exist
                $fullPath = 'public' . $uploadPath;
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                try {
                    $uploadedFile->moveTo($fullPath);
                    $imagePath = $uploadPath;
                } catch (\Throwable $e) {
                    $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Errore caricamento immagine: ' . $e->getMessage()];
                }
            } else {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Formato immagine non supportato. Usa JPG, PNG o WebP.'];
            }
        }

        $stmt = $this->db->pdo()->prepare('INSERT INTO categories(name, slug, sort_order, parent_id, image_path) VALUES(:n, :s, :o, :p, :i)');
        try {
            $stmt->execute([':n' => $name, ':s' => $slug, ':o' => $sort, ':p' => $parentId, ':i' => $imagePath]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Categoria creata'];
            return $response->withHeader('Location', '/admin/categories')->withStatus(302);
        } catch (\Throwable $e) {
            // Clean up uploaded file if database insert fails
            if ($imagePath && file_exists('public' . $imagePath)) {
                unlink('public' . $imagePath);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
            return $response->withHeader('Location', '/admin/categories/create')->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $cat = $stmt->fetch();
        if (!$cat) {
            $response->getBody()->write('Categoria non trovata');
            return $response->withStatus(404);
        }
        // Get parent categories for dropdown (exclude self to prevent circular reference)
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE (parent_id IS NULL OR parent_id = 0) AND id != :id ORDER BY sort_order ASC, name ASC');
        $stmt->execute([':id' => $id]);
        $parents = $stmt->fetchAll() ?: [];
        
        return $this->view->render($response, 'admin/categories/edit.twig', [
            'item' => $cat,
            'parents' => $parents,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $sort = (int)($data['sort_order'] ?? 0);
        $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        
        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome obbligatorio'];
            return $response->withHeader('Location', '/admin/categories/'.$id.'/edit')->withStatus(302);
        }
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }
        
        // Get current category data for image handling
        $stmt = $this->db->pdo()->prepare('SELECT image_path FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $currentCategory = $stmt->fetch();
        $imagePath = $currentCategory['image_path'] ?? null;
        
        // Handle image upload
        $uploadedFiles = $request->getUploadedFiles();
        if (isset($uploadedFiles['image']) && $uploadedFiles['image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['image'];
            $extension = strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
            
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = $slug . '_' . time() . '.' . $extension;
                $uploadPath = '/media/categories/' . $filename;
                
                // Create directory if it doesn't exist
                $fullPath = 'public' . $uploadPath;
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                try {
                    $uploadedFile->moveTo($fullPath);
                    // Delete old image if exists
                    if ($imagePath && file_exists('public' . $imagePath)) {
                        unlink('public' . $imagePath);
                    }
                    $imagePath = $uploadPath;
                } catch (\Throwable $e) {
                    $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Errore caricamento immagine: ' . $e->getMessage()];
                }
            } else {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Formato immagine non supportato. Usa JPG, PNG o WebP.'];
            }
        }
        
        // Handle image removal if requested
        if (isset($data['remove_image']) && $data['remove_image'] === '1') {
            if ($imagePath && file_exists('public' . $imagePath)) {
                unlink('public' . $imagePath);
            }
            $imagePath = null;
        }
        
        $stmt = $this->db->pdo()->prepare('UPDATE categories SET name=:n, slug=:s, sort_order=:o, parent_id=:p, image_path=:i WHERE id=:id');
        try {
            $stmt->execute([':n' => $name, ':s' => $slug, ':o' => $sort, ':p' => $parentId, ':i' => $imagePath, ':id' => $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Categoria aggiornata'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/categories')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('DELETE FROM categories WHERE id = :id');
        try {
            $stmt->execute([':id' => $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Categoria eliminata'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/categories')->withStatus(302);
    }
}
