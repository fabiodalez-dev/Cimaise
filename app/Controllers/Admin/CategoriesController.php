<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CategoriesController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->query('SELECT id, name, slug, sort_order, image_path, COALESCE(parent_id, 0) AS parent_id FROM categories ORDER BY sort_order ASC, name ASC');
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            // Fallback for pre-migration DBs (no parent_id column)
            $stmt = $pdo->query('SELECT id, name, slug, sort_order FROM categories ORDER BY sort_order ASC, name ASC');
            $rows = array_map(function(array $r){ $r['parent_id'] = 0; $r['image_path'] = null; return $r; }, $stmt->fetchAll() ?: []);
        }
        
        // Create flat structure with level information for WordPress-style interface
        $categories = $this->buildFlatHierarchy($rows);
        
        // Also keep the old structure for backward compatibility
        $byParent = [];
        foreach ($rows as $r) {
            $pid = (int)($r['parent_id'] ?? 0);
            if (!isset($byParent[$pid])) $byParent[$pid] = [];
            $byParent[$pid][] = $r;
        }
        
        return $this->view->render($response, 'admin/categories/index.twig', [
            'categories' => $categories,
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
            return $response->withHeader('Location', $this->redirect('/admin/categories/create'))->withStatus(302);
        }
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }
        // Handle parent_id
        $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        
        // Handle image upload with enhanced security validation
        $imagePath = null;
        $uploadedFiles = $request->getUploadedFiles();
        if (isset($uploadedFiles['image']) && $uploadedFiles['image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['image'];
            
            // SECURITY: Validate file using magic numbers and MIME type
            $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
            if (!$this->validateImageUpload($tmpPath)) {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'File di immagine non valido o potenzialmente pericoloso.'];
            } else {
                $extension = $this->getSecureFileExtension($tmpPath);
                if ($extension) {
                    $filename = $slug . '_' . time() . $extension;
                    $uploadPath = '/media/categories/' . $filename;
                    
                    // Create directory if it doesn't exist (absolute to project public dir)
                    $publicDir = dirname(__DIR__, 3) . '/public';
                    $fullPath = $publicDir . '/media/categories/' . $filename;
                    $dir = dirname($fullPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    
                    try {
                        $uploadedFile->moveTo($fullPath);
                        
                        // Re-validate after move for additional security
                        if ($this->validateImageUpload($fullPath)) {
                            $imagePath = $uploadPath;
                        } else {
                            @unlink($fullPath);
                            $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'File di immagine non valido dopo il caricamento.'];
                        }
                    } catch (\Throwable $e) {
                        $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Errore caricamento immagine: ' . $e->getMessage()];
                    }
                } else {
                    $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Formato immagine non supportato. Usa JPG, PNG o WebP.'];
                }
            }
        }

        $stmt = $this->db->pdo()->prepare('INSERT INTO categories(name, slug, sort_order, parent_id, image_path) VALUES(:n, :s, :o, :p, :i)');
        try {
            $stmt->execute([':n' => $name, ':s' => $slug, ':o' => $sort, ':p' => $parentId, ':i' => $imagePath]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Categoria creata'];
            return $response->withHeader('Location', $this->redirect('/admin/categories'))->withStatus(302);
        } catch (\Throwable $e) {
            // Clean up uploaded file if database insert fails
            $publicDir = dirname(__DIR__, 3) . '/public';
            if ($imagePath && file_exists($publicDir . $imagePath)) {
                unlink($publicDir . $imagePath);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
            return $response->withHeader('Location', $this->redirect('/admin/categories/create'))->withStatus(302);
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
            return $response->withHeader('Location', $this->redirect('/admin/categories/'.$id.'/edit'))->withStatus(302);
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
            
            // SECURITY: Validate file using magic numbers and MIME type
            $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
            if (!$this->validateImageUpload($tmpPath)) {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'File di immagine non valido o potenzialmente pericoloso.'];
            } else {
                $extension = $this->getSecureFileExtension($tmpPath);
                if ($extension) {
                    $filename = $slug . '_' . time() . $extension;
                    $uploadPath = '/media/categories/' . $filename;
                    
                    // Create directory if it doesn't exist (absolute to project public dir)
                    $publicDir = dirname(__DIR__, 3) . '/public';
                    $fullPath = $publicDir . '/media/categories/' . $filename;
                    $dir = dirname($fullPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    
                    try {
                        $uploadedFile->moveTo($fullPath);
                        
                        // Re-validate after move for additional security
                        if ($this->validateImageUpload($fullPath)) {
                            // Delete old image if exists
                            $publicDir = dirname(__DIR__, 3) . '/public';
                            if ($imagePath && file_exists($publicDir . $imagePath)) {
                                unlink($publicDir . $imagePath);
                            }
                            $imagePath = $uploadPath;
                        } else {
                            @unlink($fullPath);
                            $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'File di immagine non valido dopo il caricamento.'];
                        }
                    } catch (\Throwable $e) {
                        $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Errore caricamento immagine: ' . $e->getMessage()];
                    }
                } else {
                    $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Formato immagine non supportato. Usa JPG, PNG o WebP.'];
                }
            }
        }
        
        // Handle image removal if requested
        if (isset($data['remove_image']) && $data['remove_image'] === '1') {
            $publicDir = dirname(__DIR__, 3) . '/public';
            if ($imagePath && file_exists($publicDir . $imagePath)) {
                unlink($publicDir . $imagePath);
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
        return $response->withHeader('Location', $this->redirect('/admin/categories'))->withStatus(302);
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
        return $response->withHeader('Location', $this->redirect('/admin/categories'))->withStatus(302);
    }

    /**
     * WordPress-style reorder endpoint that handles flat hierarchy with levels
     */
    public function reorderWordPress(Request $request, Response $response): Response
    {
        $data = json_decode((string)$request->getBody(), true) ?: [];
        $hierarchy = $data['hierarchy'] ?? [];
        
        if (!is_array($hierarchy)) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid hierarchy data']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        
        try {
            $update = $pdo->prepare('UPDATE categories SET parent_id = :parent_id, sort_order = :sort_order WHERE id = :id');
            
            foreach ($hierarchy as $item) {
                $id = (int)($item['id'] ?? 0);
                $parentId = !empty($item['parent_id']) ? (int)$item['parent_id'] : null;
                $sortOrder = (int)($item['sort_order'] ?? 0);
                
                if ($id > 0) {
                    $update->execute([
                        ':id' => $id,
                        ':parent_id' => $parentId,
                        ':sort_order' => $sortOrder
                    ]);
                }
            }
            
            $pdo->commit();
            
            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Build flat hierarchy structure with level information for WordPress-style interface
     */
    private function buildFlatHierarchy(array $rows): array
    {
        // First, build the tree structure
        $byParent = [];
        foreach ($rows as $row) {
            $parentId = (int)($row['parent_id'] ?? 0);
            if (!isset($byParent[$parentId])) {
                $byParent[$parentId] = [];
            }
            $byParent[$parentId][] = $row;
        }
        
        // Then flatten it with level information
        $result = [];
        $this->addToFlatHierarchy($byParent, 0, 0, $result);
        
        return $result;
    }

    /**
     * Recursively add categories to flat structure with level information
     */
    private function addToFlatHierarchy(array $byParent, int $parentId, int $level, array &$result): void
    {
        if (!isset($byParent[$parentId])) {
            return;
        }
        
        foreach ($byParent[$parentId] as $category) {
            $category['level'] = $level;
            $result[] = $category;
            
            // Recursively add children
            $this->addToFlatHierarchy($byParent, (int)$category['id'], $level + 1, $result);
        }
    }

    /**
     * Validates image file using magic number verification and MIME type checking
     */
    private function validateImageUpload(string $filePath): bool
    {
        // Check if file exists and is readable
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }
        
        // Check file size (prevent DoS attacks)
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > 10 * 1024 * 1024) { // 10MB limit for category images
            return false;
        }
        
        if ($fileSize < 12) { // Minimum size for valid image headers
            return false;
        }
        
        // Detect MIME type using fileinfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return false;
        }
        
        $detectedMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!$detectedMime || !in_array($detectedMime, $allowedMimes, true)) {
            return false;
        }
        
        // Validate magic numbers (file header signatures)
        $fileHeader = file_get_contents($filePath, false, null, 0, 12);
        if ($fileHeader === false) {
            return false;
        }
        
        $magicNumbers = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
            'image/webp' => ["RIFF", "WEBP"] // RIFF...WEBP
        ];
        
        $isValidMagic = false;
        if (isset($magicNumbers[$detectedMime])) {
            foreach ($magicNumbers[$detectedMime] as $signature) {
                if ($detectedMime === 'image/webp') {
                    // WebP has RIFF at start and WEBP at offset 8
                    if (str_starts_with($fileHeader, 'RIFF') && str_contains($fileHeader, 'WEBP')) {
                        $isValidMagic = true;
                        break;
                    }
                } else {
                    if (str_starts_with($fileHeader, $signature)) {
                        $isValidMagic = true;
                        break;
                    }
                }
            }
        }
        
        if (!$isValidMagic) {
            return false;
        }
        
        // Additional validation: try to get image dimensions
        $imageInfo = getimagesize($filePath);
        if ($imageInfo === false) {
            return false;
        }
        
        // Validate image dimensions (prevent processing of malicious files)
        [$width, $height] = $imageInfo;
        if ($width <= 0 || $height <= 0 || $width > 10000 || $height > 10000) {
            return false;
        }
        
        return true;
    }

    /**
     * Get secure file extension based on validated MIME type
     */
    private function getSecureFileExtension(string $filePath): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return null;
        }
        
        $detectedMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        return match ($detectedMime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => null
        };
    }
}
