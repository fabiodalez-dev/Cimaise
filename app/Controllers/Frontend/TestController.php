<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TestController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function gallery(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $albumParam = $params['album'] ?? null; // slug or id
        $templateId = isset($params['template']) ? (int)$params['template'] : null;

        $pdo = $this->db->pdo();

        // Resolve album
        if ($albumParam !== null) {
            if (ctype_digit((string)$albumParam)) {
                $stmt = $pdo->prepare('SELECT a.*, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.id = :id AND a.is_published = 1');
                $stmt->execute([':id' => (int)$albumParam]);
            } else {
                $stmt = $pdo->prepare('SELECT a.*, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.slug = :slug AND a.is_published = 1');
                $stmt->execute([':slug' => (string)$albumParam]);
            }
        } else {
            // default to latest published
            $stmt = $pdo->query('SELECT a.*, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.is_published = 1 ORDER BY a.published_at DESC, a.sort_order ASC LIMIT 1');
        }
        $album = $stmt->fetch();
        if (!$album) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Album not found',
                'meta_description' => 'Album not found or unpublished'
            ]);
        }
        $albumRef = $album['slug'] ?? (string)$album['id'];

        // Use selected template or album template as default
        if ($templateId === null && !empty($album['template_id'])) {
            $templateId = (int)$album['template_id'];
        }
        if (!$templateId) { $templateId = 1; }
        $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
        $tplStmt->execute([':id' => $templateId]);
        $template = $tplStmt->fetch() ?: ['name' => 'Classic Grid', 'settings' => '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":false}'];
        $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];

        // Tags
        $tagsStmt = $pdo->prepare('SELECT t.* FROM tags t JOIN album_tag at ON at.tag_id = t.id WHERE at.album_id = :id ORDER BY t.name ASC');
        $tagsStmt->execute([':id' => $album['id']]);
        $tags = $tagsStmt->fetchAll();

        // Categories (multiple)
        $cats = [];
        try {
            $cstmt = $pdo->prepare('SELECT c.id, c.name, c.slug FROM categories c JOIN album_category ac ON ac.category_id = c.id WHERE ac.album_id = :id ORDER BY c.sort_order, c.name');
            $cstmt->execute([':id' => $album['id']]);
            $cats = $cstmt->fetchAll() ?: [];
        } catch (\Throwable) {
            $cats = [];
        }

        // Equipment
        $equipment = [
            'cameras' => [],
            'lenses' => [],
            'film' => [],
            'developers' => [],
            'labs' => [],
        ];
        $q = $pdo->prepare('SELECT make, model FROM cameras c JOIN album_camera ac ON ac.camera_id = c.id WHERE ac.album_id = :id');
        $q->execute([':id' => $album['id']]);
        foreach ($q->fetchAll() as $r) { $equipment['cameras'][] = trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? '')); }
        $q = $pdo->prepare('SELECT brand, model FROM lenses l JOIN album_lens al ON al.lens_id = l.id WHERE al.album_id = :id');
        $q->execute([':id' => $album['id']]);
        foreach ($q->fetchAll() as $r) { $equipment['lenses'][] = trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? '')); }
        $q = $pdo->prepare('SELECT brand, name, iso FROM films f JOIN album_film af ON af.film_id = f.id WHERE af.album_id = :id');
        $q->execute([':id' => $album['id']]);
        foreach ($q->fetchAll() as $r) {
            $label = trim(($r['brand'] ?? '') . ' ' . ($r['name'] ?? ''));
            if (!empty($r['iso'])) { $label .= ' ' . (int)$r['iso']; }
            $equipment['film'][] = $label;
        }
        $q = $pdo->prepare('SELECT name FROM developers d JOIN album_developer ad ON ad.developer_id = d.id WHERE ad.album_id = :id');
        $q->execute([':id' => $album['id']]);
        foreach ($q->fetchAll() as $r) { $equipment['developers'][] = $r['name']; }
        $q = $pdo->prepare('SELECT name FROM labs lb JOIN album_lab alb ON alb.lab_id = lb.id WHERE alb.album_id = :id');
        $q->execute([':id' => $album['id']]);
        foreach ($q->fetchAll() as $r) { $equipment['labs'][] = $r['name']; }

        // Images
        $imgStmt = $pdo->prepare('SELECT * FROM images WHERE album_id = :id ORDER BY sort_order ASC, id ASC');
        $imgStmt->execute([':id' => $album['id']]);
        $imagesRows = $imgStmt->fetchAll() ?: [];
        // Enrich names for developer/lab/film for fallback equipment
        foreach ($imagesRows as &$ir) {
            try {
                if (!empty($ir['developer_id'])) {
                    $s = $pdo->prepare('SELECT name FROM developers WHERE id = :id');
                    $s->execute([':id' => $ir['developer_id']]);
                    $ir['developer_name'] = $s->fetchColumn() ?: null;
                }
                if (!empty($ir['lab_id'])) {
                    $s = $pdo->prepare('SELECT name FROM labs WHERE id = :id');
                    $s->execute([':id' => $ir['lab_id']]);
                    $ir['lab_name'] = $s->fetchColumn() ?: null;
                }
                if (!empty($ir['film_id'])) {
                    $s = $pdo->prepare('SELECT brand, name FROM films WHERE id = :id');
                    $s->execute([':id' => $ir['film_id']]);
                    $fr = $s->fetch();
                    if ($fr) { $ir['film_name'] = trim(($fr['brand'] ?? '') . ' ' . ($fr['name'] ?? '')); }
                }
            } catch (\Throwable) {}
        }
        $images = [];
        foreach ($imagesRows as $img) {
            $varStmt = $pdo->prepare("SELECT * FROM image_variants WHERE image_id = :id AND format='jpg' ORDER BY CASE variant WHEN 'md' THEN 1 WHEN 'lg' THEN 2 WHEN 'sm' THEN 3 ELSE 9 END LIMIT 1");
            $varStmt->execute([':id' => $img['id']]);
            $var = $varStmt->fetch();
            $url = $var['path'] ?? $img['original_path'];
            $images[] = [
                'id' => (int)$img['id'],
                'url' => $url,
                'alt' => $img['alt_text'] ?: $album['title'],
                'width' => (int)($var['width'] ?? $img['width'] ?? 1200),
                'height' => (int)($var['height'] ?? $img['height'] ?? 800),
                'caption' => $img['caption'] ?? '',
                'camera' => $img['custom_camera'] ?? null,
                'lens' => $img['custom_lens'] ?? null,
            ];
        }

        // Fallback equipment aggregation from images if album-level empty
        if (!$equipment['cameras']) {
            $equipment['cameras'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['custom_camera'] ?? null, $imagesRows))));
        }
        if (!$equipment['lenses']) {
            $equipment['lenses'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['custom_lens'] ?? null, $imagesRows))));
        }
        if (!$equipment['film']) {
            $equipment['film'] = array_values(array_unique(array_filter(array_map(function($r){
                return $r['custom_film'] ?? ($r['film_name'] ?? null);
            }, $imagesRows))));
        }
        if (!$equipment['developers']) {
            $equipment['developers'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['developer_name'] ?? null, $imagesRows))));
        }
        if (!$equipment['labs']) {
            $equipment['labs'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['lab_name'] ?? null, $imagesRows))));
        }

        // Gallery meta mapped from album
        $galleryMeta = [
            'title' => $album['title'],
            'category' => ['name' => $album['category_name'], 'slug' => $album['category_slug']],
            'categories' => $cats,
            'excerpt' => $album['excerpt'] ?? '',
            'body' => $album['body'] ?? '',
            'shoot_date' => $album['shoot_date'] ?? '',
            'tags' => $tags,
            'equipment' => $equipment,
        ];

        // Available templates for icon switcher
        try {
            $list = $pdo->query('SELECT id, name, settings FROM templates ORDER BY name ASC')->fetchAll() ?: [];
            foreach ($list as &$tpl) { $tpl['settings'] = json_decode($tpl['settings'] ?? '{}', true) ?: []; }
        } catch (\Throwable) { $list = []; }

        // Nav categories for header
        $navCats = [];
        try {
            $navCats = $pdo->query('SELECT id, name, slug FROM categories ORDER BY sort_order, name')->fetchAll() ?: [];
        } catch (\Throwable) {}

        return $this->view->render($response, 'frontend/test-gallery.twig', [
            'album' => $galleryMeta,
            'images' => $images,
            'template_name' => $template['name'],
            'template_settings' => $templateSettings,
            'available_templates' => $list,
            'current_template_id' => $templateId,
            'album_ref' => $albumRef,
            'categories' => $navCats,
            'page_title' => $galleryMeta['title'] . ' - ' . $template['name'],
            'meta_description' => $galleryMeta['excerpt'],
            'current_url' => $request->getUri()->__toString()
        ]);
    }
}
