<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;
use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GalleryController extends BaseController
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

        // Resolve album (published only)
        if ($albumParam !== null) {
            if (ctype_digit((string)$albumParam)) {
                $stmt = $pdo->prepare('SELECT a.*, a.template_id, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.id = :id AND a.is_published = 1');
                $stmt->execute([':id' => (int)$albumParam]);
            } else {
                $stmt = $pdo->prepare('SELECT a.*, a.template_id, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.slug = :slug AND a.is_published = 1');
                $stmt->execute([':slug' => (string)$albumParam]);
            }
        } else {
            // default to latest published
            $stmt = $pdo->query('SELECT a.*, a.template_id, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.is_published = 1 ORDER BY a.published_at DESC, a.sort_order ASC LIMIT 1');
        }
        $album = $stmt->fetch();
        if (!$album) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Album non trovato',
                'meta_description' => 'Album non trovato o non pubblicato'
            ]);
        }
        // Password protection
        if (!empty($album['password_hash'])) {
            $allowed = isset($_SESSION['album_access']) && !empty($_SESSION['album_access'][$album['id']]);
            if (!$allowed) {
                // Categories for header menu
                $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
                $navStmt->execute();
                $navCategories = $navStmt->fetchAll();
                $query = $request->getQueryParams();
                $error = isset($query['error']);
                return $this->view->render($response, 'frontend/album_password.twig', [
                    'album' => $album,
                    'categories' => $navCategories,
                    'page_title' => $album['title'] . ' — Protetto',
                    'error' => $error,
                    'csrf' => $_SESSION['csrf'] ?? ''
                ]);
            }
        }
        $albumRef = $album['slug'] ?? (string)$album['id'];

        // Use selected template or album template as default
        if ($templateId === null && !empty($album['template_id'])) {
            $templateId = (int)$album['template_id'];
        }
        if (!$templateId) {
            // Use default template from settings
            $settingsService = new SettingsService($this->db);
            $defaultTemplateId = $settingsService->get('gallery.default_template_id');
            
            if ($defaultTemplateId) {
                // Use the predefined template from settings
                $templateId = (int)$defaultTemplateId;
                $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
                $tplStmt->execute([':id' => $templateId]);
                $template = $tplStmt->fetch();
                
                if ($template) {
                    $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];
                } else {
                    // No template found, use basic grid fallback
                    $template = ['name' => 'Grid Semplice', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                    $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
                }
            } else {
                // No default template set, use basic grid fallback
                $template = ['name' => 'Grid Semplice', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
            }
        } else {
            $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
            $tplStmt->execute([':id' => $templateId]);
            $template = $tplStmt->fetch();
            if (!$template) {
                // Fallback to basic grid
                $template = ['name' => 'Grid Semplice', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
            } else {
                $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];
                // Fix deeply nested column structure
                $templateSettings = $this->normalizeTemplateSettings($templateSettings);
            }
        }

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
        } catch (\Throwable) {}

        // Equipment (album-level pivot lists)
        $equipment = [ 'cameras'=>[], 'lenses'=>[], 'film'=>[], 'developers'=>[], 'labs'=>[], 'locations'=>[] ];
        
        // Load equipment: first try custom fields, then relationships, then images as fallback
        try {
            // Try custom equipment fields first (if present, they take priority)
            if (!empty($album['custom_cameras'])) {
                $equipment['cameras'] = array_filter(array_map('trim', explode("\n", $album['custom_cameras'])));
            } else {
                $cameraStmt = $pdo->prepare('SELECT c.make, c.model FROM cameras c JOIN album_camera ac ON c.id = ac.camera_id WHERE ac.album_id = :a');
                $cameraStmt->execute([':a' => $album['id']]);
                $cameras = $cameraStmt->fetchAll();
                $equipment['cameras'] = array_map(fn($c) => trim(($c['make'] ?? '') . ' ' . ($c['model'] ?? '')), $cameras);
            }
            
            if (!empty($album['custom_lenses'])) {
                $equipment['lenses'] = array_filter(array_map('trim', explode("\n", $album['custom_lenses'])));
            } else {
                $lensStmt = $pdo->prepare('SELECT l.brand, l.model FROM lenses l JOIN album_lens al ON l.id = al.lens_id WHERE al.album_id = :a');
                $lensStmt->execute([':a' => $album['id']]);
                $lenses = $lensStmt->fetchAll();
                $equipment['lenses'] = array_map(fn($l) => trim(($l['brand'] ?? '') . ' ' . ($l['model'] ?? '')), $lenses);
            }
            
            if (!empty($album['custom_films'])) {
                $equipment['film'] = array_filter(array_map('trim', explode("\n", $album['custom_films'])));
            } else {
                $filmStmt = $pdo->prepare('SELECT f.brand, f.name FROM films f JOIN album_film af ON f.id = af.film_id WHERE af.album_id = :a');
                $filmStmt->execute([':a' => $album['id']]);
                $films = $filmStmt->fetchAll();
                $equipment['film'] = array_map(fn($f) => trim(($f['brand'] ?? '') . ' ' . ($f['name'] ?? '')), $films);
            }
            
            if (!empty($album['custom_developers'])) {
                $equipment['developers'] = array_filter(array_map('trim', explode("\n", $album['custom_developers'])));
            } else {
                $devStmt = $pdo->prepare('SELECT d.name FROM developers d JOIN album_developer ad ON d.id = ad.developer_id WHERE ad.album_id = :a');
                $devStmt->execute([':a' => $album['id']]);
                $developers = $devStmt->fetchAll();
                $equipment['developers'] = array_map(fn($d) => $d['name'], $developers);
            }
            
            if (!empty($album['custom_labs'])) {
                $equipment['labs'] = array_filter(array_map('trim', explode("\n", $album['custom_labs'])));
            } else {
                $labStmt = $pdo->prepare('SELECT l.name FROM labs l JOIN album_lab al ON l.id = al.lab_id WHERE al.album_id = :a');
                $labStmt->execute([':a' => $album['id']]);
                $labs = $labStmt->fetchAll();
                $equipment['labs'] = array_map(fn($l) => $l['name'], $labs);
            }
            
            // Locations
            $locStmt = $pdo->prepare('SELECT l.name FROM locations l JOIN album_location al ON l.id = al.location_id WHERE al.album_id = :a ORDER BY l.name');
            $locStmt->execute([':a' => $album['id']]);
            $locations = $locStmt->fetchAll();
            $equipment['locations'] = array_map(fn($l) => $l['name'], $locations);
        } catch (\Throwable) {
            // Equipment tables might not exist or have issues, continue with empty equipment
        }
        
        // If empty, fallback from images (populated below)

        // Images with per-photo metadata
        $imgStmt = $pdo->prepare('SELECT * FROM images WHERE album_id = :id ORDER BY sort_order ASC, id ASC');
        $imgStmt->execute([':id' => $album['id']]);
        $imagesRows = $imgStmt->fetchAll() ?: [];
        foreach ($imagesRows as &$ir) {
            try {
                if (!empty($ir['camera_id'])) {
                    $s = $pdo->prepare('SELECT make, model FROM cameras WHERE id = :id');
                    $s->execute([':id' => $ir['camera_id']]);
                    $cr = $s->fetch();
                    if ($cr) { $ir['camera_name'] = trim(($cr['make'] ?? '') . ' ' . ($cr['model'] ?? '')); }
                }
                if (!empty($ir['lens_id'])) {
                    $s = $pdo->prepare('SELECT brand, model FROM lenses WHERE id = :id');
                    $s->execute([':id' => $ir['lens_id']]);
                    $lr = $s->fetch();
                    if ($lr) { $ir['lens_name'] = trim(($lr['brand'] ?? '') . ' ' . ($lr['model'] ?? '')); }
                }
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

        // Build gallery items for the template, preferring public variants
        $images = [];
        foreach ($imagesRows as $img) {
            $bestUrl = $img['original_path'];
            $lightboxUrl = $img['original_path']; // Default to original for lightbox
            
            $sources = ['avif'=>[], 'webp'=>[], 'jpg'=>[]];
            try {
                // Get best variant for gallery grid  
                $v = $pdo->prepare("SELECT path, width, height FROM image_variants WHERE image_id = :id AND format='jpg' ORDER BY CASE variant WHEN 'lg' THEN 1 WHEN 'md' THEN 2 WHEN 'sm' THEN 3 ELSE 9 END LIMIT 1");
                $v->execute([':id' => $img['id']]);
                $vr = $v->fetch();
                if ($vr && !empty($vr['path'])) { $bestUrl = $vr['path']; }
                
                // Always use original for lightbox for best quality
                // $lightboxUrl is already set to $img['original_path'] above
                
                // Build responsive sources for <picture>
                $srcStmt = $pdo->prepare("SELECT format, path, width FROM image_variants WHERE image_id = :id AND path NOT LIKE '/storage/%' ORDER BY width ASC");
                $srcStmt->execute([':id' => $img['id']]);
                $rows = $srcStmt->fetchAll() ?: [];
                foreach ($rows as $r) {
                    $fmt = $r['format'];
                    if (isset($sources[$fmt])) {
                        $sources[$fmt][] = $r['path'] . ' ' . (int)$r['width'] . 'w';
                    }
                }
            } catch (\Throwable) {}

            // Ensure we never leak /storage/originals (not publicly served)
            if (str_starts_with((string)$bestUrl, '/storage/')) {
                // If no public variants available, try to find any jpg variant
                $fallbackStmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format='jpg' AND path NOT LIKE '/storage/%' ORDER BY width DESC LIMIT 1");
                $fallbackStmt->execute([':id' => $img['id']]);
                $fallback = $fallbackStmt->fetchColumn();
                $bestUrl = $fallback ?: '/media/placeholder.jpg'; // Use placeholder if no variants
            }
            if (str_starts_with((string)$lightboxUrl, '/storage/')) {
                // Same logic for lightbox URL
                $fallbackStmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format IN ('jpg','webp','avif') AND path NOT LIKE '/storage/%' ORDER BY width DESC LIMIT 1");
                $fallbackStmt->execute([':id' => $img['id']]);
                $fallback = $fallbackStmt->fetchColumn();
                $lightboxUrl = $fallback ?: $bestUrl; // Use bestUrl as fallback
            }

            // Build an enhanced caption combining caption + basic metadata
            $metaParts = [];
            $cameraDisp = $img['camera_name'] ?? ($img['custom_camera'] ?? null);
            $lensDisp = $img['lens_name'] ?? ($img['custom_lens'] ?? null);
            $filmDisp = $img['film_name'] ?? ($img['custom_film'] ?? null);
            if (!empty($cameraDisp)) { $metaParts[] = '📷 ' . $cameraDisp; }
            if (!empty($lensDisp)) { $metaParts[] = '🔭 ' . $lensDisp; }
            if (!empty($filmDisp)) { $metaParts[] = '🎞️ ' . $filmDisp; }
            if (!empty($img['iso'])) { $metaParts[] = 'ISO ' . (int)$img['iso']; }
            if (!empty($img['shutter_speed'])) { $metaParts[] = (string)$img['shutter_speed']; }
            if (!empty($img['aperture'])) { $metaParts[] = 'f/' . number_format((float)$img['aperture'], 1); }

            $enhancedCaption = trim(($img['caption'] ?? '') . (count($metaParts) ? ( ($img['caption'] ?? '') !== '' ? ' — ' : '' ) . implode(' • ', $metaParts) : ''));

            // HTML caption with FA icons for lightbox UIs that support HTML (fallback uses this)
            $equipBits = [];
            if (!empty($cameraDisp)) { $equipBits[] = '<i class="fa-solid fa-camera mr-1"></i>' . htmlspecialchars($cameraDisp, ENT_QUOTES); }
            if (!empty($lensDisp)) { $equipBits[] = '<i class="fa-solid fa-dot-circle mr-1"></i>' . htmlspecialchars($lensDisp, ENT_QUOTES); }
            if (!empty($filmDisp)) { $equipBits[] = '<i class="fa-solid fa-film mr-1"></i>' . htmlspecialchars($filmDisp, ENT_QUOTES); }
            if (!empty($img['developer_name'])) { $equipBits[] = '<i class="fa-solid fa-flask mr-1"></i>' . htmlspecialchars((string)$img['developer_name'], ENT_QUOTES); }
            if (!empty($img['lab_name'])) { $equipBits[] = '<i class="fa-solid fa-industry mr-1"></i>' . htmlspecialchars((string)$img['lab_name'], ENT_QUOTES); }
            if (!empty($img['iso'])) { $equipBits[] = '<i class="fa-solid fa-signal mr-1"></i>ISO ' . (int)$img['iso']; }
            if (!empty($img['shutter_speed'])) { $equipBits[] = '<i class="fa-regular fa-clock mr-1"></i>' . htmlspecialchars((string)$img['shutter_speed'], ENT_QUOTES); }
            if (!empty($img['aperture'])) { $equipBits[] = '<i class="fa-solid fa-circle-half-stroke mr-1"></i>f/' . number_format((float)$img['aperture'], 1); }
            $captionHtml = '';
            if (!empty($img['caption'])) {
                $captionHtml .= '<div class="mb-2">' . htmlspecialchars((string)$img['caption'], ENT_QUOTES) . '</div>';
            }
            if ($equipBits) {
                $captionHtml .= '<div class="flex flex-wrap gap-x-3 gap-y-1 justify-center text-sm">' . implode(' ', array_map(fn($x)=>'<span class="inline-flex items-center">'.$x.'</span>', $equipBits)) . '</div>';
            }

            $images[] = [
                'id' => (int)$img['id'],
                'url' => $bestUrl,
                'lightbox_url' => $lightboxUrl, // High quality for lightbox
                'alt' => $img['alt_text'] ?: $album['title'],
                'width' => (int)($img['width'] ?? 1200),
                'height' => (int)($img['height'] ?? 800),
                'caption' => $enhancedCaption,
                'caption_html' => $captionHtml,
                // Display values
                'camera' => $cameraDisp ?? '',
                'lens' => $lensDisp ?? '',
                // Raw fields for consumers
                'camera_db' => $img['camera_name'] ?? null,
                'lens_db' => $img['lens_name'] ?? null,
                'film_db' => $img['film_name'] ?? null,
                'developer_db' => $img['developer_name'] ?? null,
                'lab_db' => $img['lab_name'] ?? null,
                'camera_custom' => $img['custom_camera'] ?? null,
                'lens_custom' => $img['custom_lens'] ?? null,
                'film_custom' => $img['custom_film'] ?? null,
                'iso' => isset($img['iso']) ? (int)$img['iso'] : null,
                'shutter_speed' => $img['shutter_speed'] ?? null,
                'aperture' => isset($img['aperture']) ? (float)$img['aperture'] : null,
                'process' => $img['process'] ?? null,
                'settings' => '',
                'sources' => $sources, // Add sources array with base_path prepended
                'fallback_src' => $lightboxUrl ?: $bestUrl
            ];
        }

        // Fallback equipment aggregation from images if album-level empty
        if (!$equipment['cameras']) { $equipment['cameras'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['custom_camera'] ?? null, $imagesRows)))); }
        if (!$equipment['lenses']) { $equipment['lenses'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['custom_lens'] ?? null, $imagesRows)))); }
        if (!$equipment['film'])   { $equipment['film'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['custom_film'] ?? ($r['film_name'] ?? null), $imagesRows)))); }
        if (!$equipment['developers']) { $equipment['developers'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['developer_name'] ?? null, $imagesRows)))); }
        if (!$equipment['labs']) { $equipment['labs'] = array_values(array_unique(array_filter(array_map(fn($r) => $r['lab_name'] ?? null, $imagesRows)))); }

        // Gallery meta mapped from album
        $galleryMeta = [
            'title' => $album['title'],
            'category' => ['name' => $album['category_name'], 'slug' => $album['category_slug']],
            'categories' => $cats,
            'excerpt' => $album['excerpt'] ?? '',
            'body' => $album['body'] ?? '',
            'shoot_date' => $album['shoot_date'] ?? '',
            'show_date' => (int)($album['show_date'] ?? 1),
            'tags' => $tags,
            'equipment' => $equipment,
        ];

        // Available templates for icon switcher
        try {
            $list = $pdo->query('SELECT id, name, slug, settings, libs FROM templates ORDER BY name ASC')->fetchAll() ?: [];
            foreach ($list as &$tpl) {
                $tpl['settings'] = json_decode($tpl['settings'] ?? '{}', true) ?: [];
                $tpl['libs'] = json_decode($tpl['libs'] ?? '[]', true) ?: [];
            }
        } catch (\Throwable) { $list = []; }

        // Nav categories for header
        $navCats = [];
        try {
            $navCats = $pdo->query('SELECT id, name, slug FROM categories ORDER BY sort_order, name')->fetchAll() ?: [];
        } catch (\Throwable) {}

        return $this->view->render($response, 'frontend/gallery.twig', [
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

    public function template(Request $request, Response $response): Response
    {
        try {
            // Ensure session is started for album access checks
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $params = $request->getQueryParams();
            $albumParam = $params['album'] ?? null; // slug or id
            $templateId = isset($params['template']) ? (int)$params['template'] : null;

            if (!$templateId || !$albumParam) {
                $response->getBody()->write('Template or album parameter missing');
                return $response->withStatus(400);
            }

            $pdo = $this->db->pdo();

            // Resolve album (published only)
            if (ctype_digit((string)$albumParam)) {
                $stmt = $pdo->prepare('SELECT a.*, a.template_id, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.id = :id AND a.is_published = 1');
                $stmt->execute([':id' => (int)$albumParam]);
            } else {
                $stmt = $pdo->prepare('SELECT a.*, a.template_id, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.slug = :slug AND a.is_published = 1');
                $stmt->execute([':slug' => (string)$albumParam]);
            }
            
            $album = $stmt->fetch();
            if (!$album) {
                $response->getBody()->write('Album not found');
                return $response->withStatus(404);
            }
            if (!empty($album['password_hash'])) {
                $allowed = isset($_SESSION['album_access']) && !empty($_SESSION['album_access'][$album['id']]);
                if (!$allowed) {
                    $response->getBody()->write('Album locked');
                    return $response->withStatus(403);
                }
            }

            // Load template
            $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
            $tplStmt->execute([':id' => $templateId]);
            $template = $tplStmt->fetch();
            if (!$template) {
                $response->getBody()->write('Template not found');
                return $response->withStatus(404);
            }
            
            $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];
            $templateSettings = $this->normalizeTemplateSettings($templateSettings);

            // Images with per-photo metadata
            $imgStmt = $pdo->prepare('SELECT * FROM images WHERE album_id = :id ORDER BY sort_order ASC, id ASC');
            $imgStmt->execute([':id' => $album['id']]);
            $imagesRows = $imgStmt->fetchAll() ?: [];
            
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
                } catch (\Throwable $e) {
                    // Continue processing even if metadata lookup fails
                    error_log('Error fetching image metadata: ' . $e->getMessage());
                }
            }

            // Build gallery items for the template, preferring public variants
            $images = [];
            foreach ($imagesRows as $img) {
                $bestUrl = $img['original_path'];
                $lightboxUrl = $img['original_path'];
                try {
                    // Grid: prefer largest public variant (avif > webp > jpg)
                    $vg = $pdo->prepare("SELECT path, width, height FROM image_variants 
                        WHERE image_id = :id AND path NOT LIKE '/storage/%' 
                        ORDER BY CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END, width DESC LIMIT 1");
                    $vg->execute([':id' => $img['id']]);
                    if ($vgr = $vg->fetch()) { if (!empty($vgr['path'])) { $bestUrl = $vgr['path']; } }
                    // Lightbox: same
                    $vl = $pdo->prepare("SELECT path FROM image_variants 
                        WHERE image_id = :id AND path NOT LIKE '/storage/%' 
                        ORDER BY CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END, width DESC LIMIT 1");
                    $vl->execute([':id' => $img['id']]);
                    if ($vlr = $vl->fetch()) { if (!empty($vlr['path'])) { $lightboxUrl = $vlr['path']; } }
                    // Build responsive sources for <picture>
                    $srcStmt = $pdo->prepare("SELECT format, path, width FROM image_variants WHERE image_id = :id AND path NOT LIKE '/storage/%' ORDER BY width ASC");
                    $srcStmt->execute([':id' => $img['id']]);
                    $rows = $srcStmt->fetchAll() ?: [];
                    $sources = ['avif'=>[], 'webp'=>[], 'jpg'=>[]];
                    foreach ($rows as $r) {
                        $fmt = $r['format'];
                        if (isset($sources[$fmt])) {
                            $sources[$fmt][] = $r['path'] . ' ' . (int)$r['width'] . 'w';
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('Error fetching image variants: ' . $e->getMessage());
                }
                // Fallbacks - ensure we never serve /storage/ paths
                if (str_starts_with((string)$bestUrl, '/storage/')) {
                    // Try to find any public variant
                    $fallbackStmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format='jpg' AND path NOT LIKE '/storage/%' ORDER BY width DESC LIMIT 1");
                    $fallbackStmt->execute([':id' => $img['id']]);
                    $fallback = $fallbackStmt->fetchColumn();
                    $bestUrl = $fallback ?: '/media/placeholder.jpg';
                }
                if (str_starts_with((string)$lightboxUrl, '/storage/')) { 
                    $lightboxUrl = $bestUrl; 
                }
                

                $images[] = [
                    'id' => (int)$img['id'],
                    'url' => $bestUrl,
                    'lightbox_url' => $lightboxUrl,
                    'alt' => $img['alt_text'] ?: $album['title'],
                    'width' => (int)($img['width'] ?? 1200),
                    'height' => (int)($img['height'] ?? 800),
                    'caption' => $img['caption'] ?? '',
                    'custom_camera' => $img['custom_camera'] ?? '',
                    'camera_name' => $img['camera_name'] ?? '',
                    'custom_lens' => $img['custom_lens'] ?? '',
                    'lens_name' => $img['lens_name'] ?? '',
                    'custom_film' => $img['custom_film'] ?? '',
                    'film_name' => $img['film_name'] ?? '',
                    'developer_name' => $img['developer_name'] ?? '',
                    'lab_name' => $img['lab_name'] ?? '',
                    'iso' => isset($img['iso']) ? (int)$img['iso'] : null,
                    'shutter_speed' => $img['shutter_speed'] ?? null,
                    'aperture' => isset($img['aperture']) ? (float)$img['aperture'] : null,
                    'sources' => $sources ?? ['avif'=>[], 'webp'=>[], 'jpg'=>[]],
                    'fallback_src' => $lightboxUrl ?: $bestUrl
                ];
            }

            // Render the appropriate gallery partial based on template
            $templateFile = 'frontend/_gallery_content.twig';
            $templateData = [
                'images' => $images,
                'template_settings' => $templateSettings
            ];
            
            // Use Magazine template for template ID 9 or layout 'magazine'
            if ($templateId === 9 || ($templateSettings['layout'] ?? '') === 'magazine') {
                $templateFile = 'frontend/_gallery_magazine_content.twig';
                $templateData['album'] = $album; // Magazine template needs album data
                $templateData['base_path'] = $this->basePath;
            }
            
            return $this->view->render($response, $templateFile, $templateData);
            
        } catch (\Throwable $e) {
            // Log the actual error for debugging
            error_log('Template API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            $response->getBody()->write('Internal server error: ' . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    private function normalizeTemplateSettings(array $templateSettings): array
    {
        // Fix deeply nested column structures like {"desktop":{"desktop":{"desktop":3}}}
        if (isset($templateSettings['columns']) && is_array($templateSettings['columns'])) {
            $normalizedColumns = [];
            
            foreach (['desktop', 'tablet', 'mobile'] as $device) {
                if (isset($templateSettings['columns'][$device])) {
                    $value = $templateSettings['columns'][$device];
                    
                    // Keep digging until we find the actual numeric value
                    while (is_array($value) && isset($value[$device])) {
                        $value = $value[$device];
                    }
                    
                    // Ensure we have a reasonable numeric value
                    if (is_numeric($value) && $value > 0 && $value <= 12) {
                        $normalizedColumns[$device] = (int)$value;
                    } else {
                        // Fallback values
                        $normalizedColumns[$device] = match($device) {
                            'desktop' => 3,
                            'tablet' => 2, 
                            'mobile' => 1
                        };
                    }
                }
            }
            
            $templateSettings['columns'] = $normalizedColumns;
        }
        
        return $templateSettings;
    }
}
