<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GalleriesController
{
    public function __construct(private Database $db, private Twig $view) {}

    public function index(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $params = $request->getQueryParams();
        
        // Get filter settings from database
        $filterSettings = $this->getFilterSettings();
        
        // Build filter parameters
        $filters = $this->buildFilters($params, $filterSettings);
        
        // Get albums with filters applied
        $albums = $this->getFilteredAlbums($filters);
        
        // Get filter options for dropdowns
        $filterOptions = $this->getFilterOptions();
        
        // Get navigation categories
        $parentCategories = $this->getParentCategoriesForNavigation();
        
        return $this->view->render($response, 'frontend/galleries.twig', [
            'albums' => $albums,
            'filter_settings' => $filterSettings,
            'filter_options' => $filterOptions,
            'active_filters' => $filters,
            'parent_categories' => $parentCategories,
            'page_title' => 'All Galleries',
            'meta_description' => 'Browse all photography galleries with advanced filtering options'
        ]);
    }

    public function filter(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        // Get filter settings
        $filterSettings = $this->getFilterSettings();
        
        // Build filters from request
        $filters = $this->buildFilters($params, $filterSettings);
        
        // Get filtered albums
        $albums = $this->getFilteredAlbums($filters);
        
        // Return JSON response for AJAX
        $response->getBody()->write(json_encode([
            'success' => true,
            'albums' => $albums,
            'total' => count($albums),
            'filters' => $filters
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getFilterSettings(): array
    {
        $pdo = $this->db->pdo();
        
        // Get filter settings from database
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM filter_settings ORDER BY sort_order ASC');
        $stmt->execute();
        $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        // Default settings if not found
        $defaults = [
            'enabled' => true,
            'show_categories' => true,
            'show_tags' => true,
            'show_cameras' => true,
            'show_lenses' => true,
            'show_films' => true,
            'show_developers' => false,
            'show_labs' => false,
            'show_locations' => true,
            'show_year' => true,
            'grid_columns_desktop' => 3,
            'grid_columns_tablet' => 2,
            'grid_columns_mobile' => 1,
            'grid_gap' => 'normal',
            'animation_enabled' => true,
            'animation_duration' => '0.6'
        ];
        
        return array_merge($defaults, $settings);
    }

    private function buildFilters(array $params, array $settings): array
    {
        $filters = [];
        
        // Category filter
        if (!empty($params['category']) && $settings['show_categories']) {
            $filters['category'] = is_array($params['category']) ? $params['category'] : [$params['category']];
        }
        
        // Tag filter
        if (!empty($params['tags']) && $settings['show_tags']) {
            $filters['tags'] = is_array($params['tags']) ? $params['tags'] : [$params['tags']];
        }
        
        // Camera filter
        if (!empty($params['cameras']) && $settings['show_cameras']) {
            $filters['cameras'] = is_array($params['cameras']) ? $params['cameras'] : [$params['cameras']];
        }
        
        // Lens filter
        if (!empty($params['lenses']) && $settings['show_lenses']) {
            $filters['lenses'] = is_array($params['lenses']) ? $params['lenses'] : [$params['lenses']];
        }
        
        // Film filter
        if (!empty($params['films']) && $settings['show_films']) {
            $filters['films'] = is_array($params['films']) ? $params['films'] : [$params['films']];
        }
        
        // Developer filter
        if (!empty($params['developers']) && $settings['show_developers']) {
            $filters['developers'] = is_array($params['developers']) ? $params['developers'] : [$params['developers']];
        }
        
        // Lab filter
        if (!empty($params['labs']) && $settings['show_labs']) {
            $filters['labs'] = is_array($params['labs']) ? $params['labs'] : [$params['labs']];
        }
        
        // Location filter
        if (!empty($params['locations']) && $settings['show_locations']) {
            $filters['locations'] = is_array($params['locations']) ? $params['locations'] : [$params['locations']];
        }
        
        // Year filter
        if (!empty($params['year']) && $settings['show_year']) {
            $filters['year'] = (int)$params['year'];
        }
        
        // Search filter
        if (!empty($params['search'])) {
            $filters['search'] = trim($params['search']);
        }
        
        // Sort filter
        $filters['sort'] = $params['sort'] ?? 'published_desc';
        
        return $filters;
    }

    private function getFilteredAlbums(array $filters): array
    {
        $pdo = $this->db->pdo();
        
        // Base query
        $sql = '
            SELECT DISTINCT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            LEFT JOIN categories c ON c.id = a.category_id 
            LEFT JOIN album_category ac ON ac.album_id = a.id
            LEFT JOIN categories cat ON cat.id = ac.category_id
            LEFT JOIN album_tag at ON at.album_id = a.id
            LEFT JOIN tags t ON t.id = at.tag_id
            LEFT JOIN album_camera acam ON acam.album_id = a.id
            LEFT JOIN cameras cam ON cam.id = acam.camera_id
            LEFT JOIN album_lens al ON al.album_id = a.id
            LEFT JOIN lenses l ON l.id = al.lens_id
            LEFT JOIN album_film af ON af.album_id = a.id
            LEFT JOIN films f ON f.id = af.film_id
            LEFT JOIN album_developer ad ON ad.album_id = a.id
            LEFT JOIN developers d ON d.id = ad.developer_id
            LEFT JOIN album_lab alab ON alab.album_id = a.id
            LEFT JOIN labs lab ON lab.id = alab.lab_id
            LEFT JOIN album_location aloc ON aloc.album_id = a.id
            LEFT JOIN locations loc ON loc.id = aloc.location_id
            WHERE a.is_published = 1
        ';
        
        $params = [];
        $conditions = [];
        
        // Apply filters
        if (!empty($filters['category'])) {
            $placeholders = str_repeat('?,', count($filters['category']) - 1) . '?';
            $conditions[] = "(cat.id IN ($placeholders) OR c.id IN ($placeholders))";
            $params = array_merge($params, $filters['category'], $filters['category']);
        }
        
        if (!empty($filters['tags'])) {
            $placeholders = str_repeat('?,', count($filters['tags']) - 1) . '?';
            $conditions[] = "t.id IN ($placeholders)";
            $params = array_merge($params, $filters['tags']);
        }
        
        if (!empty($filters['cameras'])) {
            $placeholders = str_repeat('?,', count($filters['cameras']) - 1) . '?';
            $conditions[] = "cam.id IN ($placeholders)";
            $params = array_merge($params, $filters['cameras']);
        }
        
        if (!empty($filters['lenses'])) {
            $placeholders = str_repeat('?,', count($filters['lenses']) - 1) . '?';
            $conditions[] = "l.id IN ($placeholders)";
            $params = array_merge($params, $filters['lenses']);
        }
        
        if (!empty($filters['films'])) {
            $placeholders = str_repeat('?,', count($filters['films']) - 1) . '?';
            $conditions[] = "f.id IN ($placeholders)";
            $params = array_merge($params, $filters['films']);
        }
        
        if (!empty($filters['developers'])) {
            $placeholders = str_repeat('?,', count($filters['developers']) - 1) . '?';
            $conditions[] = "d.id IN ($placeholders)";
            $params = array_merge($params, $filters['developers']);
        }
        
        if (!empty($filters['labs'])) {
            $placeholders = str_repeat('?,', count($filters['labs']) - 1) . '?';
            $conditions[] = "lab.id IN ($placeholders)";
            $params = array_merge($params, $filters['labs']);
        }
        
        if (!empty($filters['locations'])) {
            $placeholders = str_repeat('?,', count($filters['locations']) - 1) . '?';
            $conditions[] = "loc.id IN ($placeholders)";
            $params = array_merge($params, $filters['locations']);
        }
        
        if (!empty($filters['year'])) {
            $conditions[] = "strftime('%Y', a.shoot_date) = ?";
            $params[] = (string)$filters['year'];
        }
        
        if (!empty($filters['search'])) {
            $conditions[] = "(a.title LIKE ? OR a.excerpt LIKE ? OR a.body LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Add conditions to query
        if (!empty($conditions)) {
            $sql .= ' AND ' . implode(' AND ', $conditions);
        }
        
        // Add sorting
        switch ($filters['sort'] ?? 'published_desc') {
            case 'published_asc':
                $sql .= ' ORDER BY a.published_at ASC';
                break;
            case 'title_asc':
                $sql .= ' ORDER BY a.title ASC';
                break;
            case 'title_desc':
                $sql .= ' ORDER BY a.title DESC';
                break;
            case 'shoot_date_desc':
                $sql .= ' ORDER BY a.shoot_date DESC';
                break;
            case 'shoot_date_asc':
                $sql .= ' ORDER BY a.shoot_date ASC';
                break;
            default:
                $sql .= ' ORDER BY a.published_at DESC';
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $albums = $stmt->fetchAll();
        
        // Enrich albums with cover images and additional data
        foreach ($albums as &$album) {
            $album = $this->enrichAlbum($album);
        }
        
        return $albums;
    }

    private function getFilterOptions(): array
    {
        $pdo = $this->db->pdo();
        
        // Get categories
        $stmt = $pdo->prepare('
            SELECT c.*, COUNT(DISTINCT ac.album_id) as albums_count
            FROM categories c 
            LEFT JOIN album_category ac ON ac.category_id = c.id
            LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
            GROUP BY c.id
            HAVING albums_count > 0
            ORDER BY c.sort_order ASC, c.name ASC
        ');
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        // Get tags
        $stmt = $pdo->prepare('
            SELECT t.*, COUNT(DISTINCT at.album_id) as albums_count
            FROM tags t 
            LEFT JOIN album_tag at ON at.tag_id = t.id
            LEFT JOIN albums a ON a.id = at.album_id AND a.is_published = 1
            GROUP BY t.id
            HAVING albums_count > 0
            ORDER BY albums_count DESC, t.name ASC
        ');
        $stmt->execute();
        $tags = $stmt->fetchAll();
        
        // Get cameras
        $stmt = $pdo->prepare('
            SELECT cam.*, COUNT(DISTINCT ac.album_id) as albums_count
            FROM cameras cam
            LEFT JOIN album_camera ac ON ac.camera_id = cam.id
            LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
            GROUP BY cam.id
            HAVING albums_count > 0
            ORDER BY albums_count DESC, cam.make ASC, cam.model ASC
        ');
        $stmt->execute();
        $cameras = $stmt->fetchAll();
        
        // Get lenses
        $stmt = $pdo->prepare('
            SELECT l.*, COUNT(DISTINCT al.album_id) as albums_count
            FROM lenses l
            LEFT JOIN album_lens al ON al.lens_id = l.id
            LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
            GROUP BY l.id
            HAVING albums_count > 0
            ORDER BY albums_count DESC, l.brand ASC, l.model ASC
        ');
        $stmt->execute();
        $lenses = $stmt->fetchAll();
        
        // Get films
        $stmt = $pdo->prepare('
            SELECT f.*, COUNT(DISTINCT af.album_id) as albums_count
            FROM films f
            LEFT JOIN album_film af ON af.film_id = f.id
            LEFT JOIN albums a ON a.id = af.album_id AND a.is_published = 1
            GROUP BY f.id
            HAVING albums_count > 0
            ORDER BY albums_count DESC, f.brand ASC, f.name ASC
        ');
        $stmt->execute();
        $films = $stmt->fetchAll();
        
        // Get developers
        $stmt = $pdo->prepare('
            SELECT d.*, COUNT(DISTINCT ad.album_id) as albums_count
            FROM developers d
            LEFT JOIN album_developer ad ON ad.developer_id = d.id
            LEFT JOIN albums a ON a.id = ad.album_id AND a.is_published = 1
            GROUP BY d.id
            HAVING albums_count > 0
            ORDER BY albums_count DESC, d.name ASC
        ');
        $stmt->execute();
        $developers = $stmt->fetchAll();
        
        // Get labs
        $stmt = $pdo->prepare('
            SELECT lab.*, COUNT(DISTINCT al.album_id) as albums_count
            FROM labs lab
            LEFT JOIN album_lab al ON al.lab_id = lab.id
            LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
            GROUP BY lab.id
            HAVING albums_count > 0
            ORDER BY albums_count DESC, lab.name ASC
        ');
        $stmt->execute();
        $labs = $stmt->fetchAll();
        
        // Get locations
        $stmt = $pdo->prepare('
            SELECT loc.*, COUNT(DISTINCT al.album_id) as albums_count
            FROM locations loc
            LEFT JOIN album_location al ON al.location_id = loc.id
            LEFT JOIN albums a ON a.id = al.album_id AND a.is_published = 1
            GROUP BY loc.id
            HAVING albums_count > 0
            ORDER BY albums_count DESC, loc.name ASC
        ');
        $stmt->execute();
        $locations = $stmt->fetchAll();
        
        // Get years
        $stmt = $pdo->prepare("
            SELECT DISTINCT strftime('%Y', shoot_date) as year, COUNT(*) as albums_count
            FROM albums 
            WHERE is_published = 1 AND shoot_date IS NOT NULL
            GROUP BY year
            ORDER BY year DESC
        ");
        $stmt->execute();
        $years = $stmt->fetchAll();
        
        return [
            'categories' => $categories,
            'tags' => $tags,
            'cameras' => $cameras,
            'lenses' => $lenses,
            'films' => $films,
            'developers' => $developers,
            'labs' => $labs,
            'locations' => $locations,
            'years' => $years
        ];
    }

    private function enrichAlbum(array $album): array
    {
        $pdo = $this->db->pdo();
        
        // Get cover image
        if (!empty($album['cover_image_id'])) {
            $stmt = $pdo->prepare('
                SELECT i.*, COALESCE(iv.path, i.original_path) AS preview_path
                FROM images i
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = "sm" AND iv.format = "jpg"
                WHERE i.id = :id
            ');
            $stmt->execute([':id' => $album['cover_image_id']]);
            $cover = $stmt->fetch();
            if ($cover) {
                $album['cover_image'] = $cover;
            }
        }
        
        // If no cover image, get first image
        if (empty($album['cover_image'])) {
            $stmt = $pdo->prepare('
                SELECT i.*, COALESCE(iv.path, i.original_path) AS preview_path
                FROM images i
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = "sm" AND iv.format = "jpg"
                WHERE i.album_id = :album_id 
                ORDER BY i.sort_order ASC, i.id ASC 
                LIMIT 1
            ');
            $stmt->execute([':album_id' => $album['id']]);
            $cover = $stmt->fetch();
            if ($cover) {
                $album['cover_image'] = $cover;
            }
        }
        
        // Get images count
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE album_id = :album_id');
        $stmt->execute([':album_id' => $album['id']]);
        $album['images_count'] = $stmt->fetchColumn();
        
        // Get tags
        $stmt = $pdo->prepare('
            SELECT t.* FROM tags t 
            JOIN album_tag at ON at.tag_id = t.id 
            WHERE at.album_id = :album_id 
            ORDER BY t.name ASC
        ');
        $stmt->execute([':album_id' => $album['id']]);
        $album['tags'] = $stmt->fetchAll();
        
        return $album;
    }

    private function getParentCategoriesForNavigation(): array
    {
        $pdo = $this->db->pdo();
        
        // Get parent categories with children and album counts
        $stmt = $pdo->prepare('
            SELECT c.*, COUNT(DISTINCT a.id) as albums_count
            FROM categories c
            LEFT JOIN album_category ac ON ac.category_id = c.id
            LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
            WHERE c.parent_id IS NULL
            GROUP BY c.id
            ORDER BY c.sort_order ASC, c.name ASC
        ');
        $stmt->execute();
        $parents = $stmt->fetchAll();
        
        // Get children for each parent
        foreach ($parents as &$parent) {
            $stmt = $pdo->prepare('
                SELECT c.*, COUNT(DISTINCT a.id) as albums_count
                FROM categories c
                LEFT JOIN album_category ac ON ac.category_id = c.id
                LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
                WHERE c.parent_id = :parent_id
                GROUP BY c.id
                ORDER BY c.sort_order ASC, c.name ASC
            ');
            $stmt->execute([':parent_id' => $parent['id']]);
            $parent['children'] = $stmt->fetchAll();
        }
        
        return $parents;
    }
}