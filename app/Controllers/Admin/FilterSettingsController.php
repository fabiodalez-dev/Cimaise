<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class FilterSettingsController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    private function validateCsrf(Request $request): bool
    {
        $data = (array)$request->getParsedBody();
        $token = $data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        return \is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public function index(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        
        // Get current filter settings
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM filter_settings ORDER BY sort_order ASC');
        $stmt->execute();
        $rawSettings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        // Organize settings by category
        $settings = [
            'general' => [
                'enabled' => $rawSettings['enabled'] ?? '1',
                'animation_enabled' => $rawSettings['animation_enabled'] ?? '1',
                'animation_duration' => $rawSettings['animation_duration'] ?? '0.6'
            ],
            'filters' => [
                'show_categories' => $rawSettings['show_categories'] ?? '1',
                'show_tags' => $rawSettings['show_tags'] ?? '1',
                'show_cameras' => $rawSettings['show_cameras'] ?? '1',
                'show_lenses' => $rawSettings['show_lenses'] ?? '1',
                'show_films' => $rawSettings['show_films'] ?? '1',
                'show_developers' => $rawSettings['show_developers'] ?? '0',
                'show_labs' => $rawSettings['show_labs'] ?? '0',
                'show_locations' => $rawSettings['show_locations'] ?? '1',
                'show_year' => $rawSettings['show_year'] ?? '1'
            ],
            'grid' => [
                'grid_columns_desktop' => $rawSettings['grid_columns_desktop'] ?? '3',
                'grid_columns_tablet' => $rawSettings['grid_columns_tablet'] ?? '2',
                'grid_columns_mobile' => $rawSettings['grid_columns_mobile'] ?? '1',
                'grid_gap' => $rawSettings['grid_gap'] ?? 'normal'
            ]
        ];
        
        // Get filter statistics
        $stats = $this->getFilterStatistics();
        
        return $this->view->render($response, 'admin/filter_settings/index.twig', [
            'settings' => $settings,
            'stats' => $stats,
            'page_title' => 'Filter Settings',
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/filter-settings'))->withStatus(302);
        }

        $data = $request->getParsedBody();
        $pdo = $this->db->pdo();

        try {
            $pdo->beginTransaction();
            
            // Update each setting
            $settingsToUpdate = [
                'enabled' => $data['enabled'] ?? '0',
                'animation_enabled' => $data['animation_enabled'] ?? '0',
                'animation_duration' => $data['animation_duration'] ?? '0.6',
                'show_categories' => $data['show_categories'] ?? '0',
                'show_tags' => $data['show_tags'] ?? '0',
                'show_cameras' => $data['show_cameras'] ?? '0',
                'show_lenses' => $data['show_lenses'] ?? '0',
                'show_films' => $data['show_films'] ?? '0',
                'show_developers' => $data['show_developers'] ?? '0',
                'show_labs' => $data['show_labs'] ?? '0',
                'show_locations' => $data['show_locations'] ?? '0',
                'show_year' => $data['show_year'] ?? '0',
                'grid_columns_desktop' => $data['grid_columns_desktop'] ?? '3',
                'grid_columns_tablet' => $data['grid_columns_tablet'] ?? '2',
                'grid_columns_mobile' => $data['grid_columns_mobile'] ?? '1',
                'grid_gap' => $data['grid_gap'] ?? 'normal'
            ];
            
            $replaceKw = $this->db->replaceKeyword();
            $nowExpr = $this->db->nowExpression();
            $stmt = $pdo->prepare("
                {$replaceKw} INTO filter_settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, {$nowExpr})
            ");
            
            foreach ($settingsToUpdate as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            
            $pdo->commit();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Filter settings updated successfully!'
            ];
            
        } catch (\Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Failed to update filter settings: ' . $e->getMessage()
            ];
        }
        
        return $response->withHeader('Location', $this->redirect('/admin/filter-settings'))->withStatus(302);
    }

    public function preview(Request $request, Response $response): Response
    {
        // Return current filter settings as JSON for preview
        $pdo = $this->db->pdo();
        
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM filter_settings ORDER BY sort_order ASC');
        $stmt->execute();
        $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        // Convert string values to appropriate types
        $processedSettings = [];
        foreach ($settings as $key => $value) {
            if (in_array($key, ['enabled', 'animation_enabled', 'show_categories', 'show_tags', 'show_cameras', 'show_lenses', 'show_films', 'show_developers', 'show_labs', 'show_locations', 'show_year'])) {
                $processedSettings[$key] = (bool)$value;
            } elseif (in_array($key, ['grid_columns_desktop', 'grid_columns_tablet', 'grid_columns_mobile'])) {
                $processedSettings[$key] = (int)$value;
            } elseif ($key === 'animation_duration') {
                $processedSettings[$key] = (float)$value;
            } else {
                $processedSettings[$key] = $value;
            }
        }
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'settings' => $processedSettings
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function reset(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/filter-settings'))->withStatus(302);
        }

        $pdo = $this->db->pdo();

        try {
            // Delete all current settings
            $pdo->exec('DELETE FROM filter_settings');
            
            // Insert default settings
            $defaultSettings = [
                'enabled' => '1',
                'show_categories' => '1',
                'show_tags' => '1',
                'show_cameras' => '1',
                'show_lenses' => '1',
                'show_films' => '1',
                'show_developers' => '0',
                'show_labs' => '0',
                'show_locations' => '1',
                'show_year' => '1',
                'grid_columns_desktop' => '3',
                'grid_columns_tablet' => '2',
                'grid_columns_mobile' => '1',
                'grid_gap' => 'normal',
                'animation_enabled' => '1',
                'animation_duration' => '0.6'
            ];
            
            $stmt = $pdo->prepare('
                INSERT INTO filter_settings (setting_key, setting_value, sort_order) 
                VALUES (?, ?, ?)
            ');
            
            $sortOrder = 1;
            foreach ($defaultSettings as $key => $value) {
                $stmt->execute([$key, $value, $sortOrder++]);
            }
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Filter settings reset to defaults successfully!'
            ];
            
        } catch (\Exception $e) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Failed to reset filter settings: ' . $e->getMessage()
            ];
        }
        
        return $response->withHeader('Location', $this->redirect('/admin/filter-settings'))->withStatus(302);
    }

    private function getFilterStatistics(): array
    {
        $pdo = $this->db->pdo();
        
        try {
            // Get counts for each filter type
            $stats = [];
            
            // Categories
            $stmt = $pdo->prepare('
                SELECT COUNT(DISTINCT c.id) as count
                FROM categories c 
                LEFT JOIN album_category ac ON ac.category_id = c.id
                LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
                WHERE ac.album_id IS NOT NULL
            ');
            $stmt->execute();
            $stats['categories'] = $stmt->fetchColumn();
            
            // Tags
            $stmt = $pdo->prepare('
                SELECT COUNT(DISTINCT t.id) as count
                FROM tags t 
                LEFT JOIN album_tag at ON at.tag_id = t.id
                LEFT JOIN albums a ON a.id = at.album_id AND a.is_published = 1
                WHERE at.album_id IS NOT NULL
            ');
            $stmt->execute();
            $stats['tags'] = $stmt->fetchColumn();
            
            // Cameras
            $stmt = $pdo->prepare('
                SELECT COUNT(DISTINCT cam.id) as count
                FROM cameras cam
                LEFT JOIN album_camera ac ON ac.camera_id = cam.id
                LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
                WHERE ac.album_id IS NOT NULL
            ');
            $stmt->execute();
            $stats['cameras'] = $stmt->fetchColumn();
            
            // Lenses
            $stmt = $pdo->prepare('
                SELECT COUNT(DISTINCT l.id) as count
                FROM lenses l
                LEFT JOIN album_lens al ON al.lens_id = l.id
                LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
                WHERE al.album_id IS NOT NULL
            ');
            $stmt->execute();
            $stats['lenses'] = $stmt->fetchColumn();
            
            // Films
            $stmt = $pdo->prepare('
                SELECT COUNT(DISTINCT f.id) as count
                FROM films f
                LEFT JOIN album_film af ON af.film_id = f.id
                LEFT JOIN albums a ON a.id = af.album_id AND a.is_published = 1
                WHERE af.album_id IS NOT NULL
            ');
            $stmt->execute();
            $stats['films'] = $stmt->fetchColumn();
            
            // Locations
            $stmt = $pdo->prepare('
                SELECT COUNT(DISTINCT loc.id) as count
                FROM locations loc
                LEFT JOIN album_location al ON al.location_id = loc.id
                LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
                WHERE al.album_id IS NOT NULL
            ');
            $stmt->execute();
            $stats['locations'] = $stmt->fetchColumn();
            
            // Total published albums
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM albums WHERE is_published = 1');
            $stmt->execute();
            $stats['total_albums'] = $stmt->fetchColumn();
            
            return $stats;
            
        } catch (\Exception $e) {
            return [
                'categories' => 0,
                'tags' => 0,
                'cameras' => 0,
                'lenses' => 0,
                'films' => 0,
                'locations' => 0,
                'total_albums' => 0
            ];
        }
    }
}