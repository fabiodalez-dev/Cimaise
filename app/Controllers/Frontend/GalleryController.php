<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;
use App\Controllers\BaseController;
use App\Services\NavigationService;
use App\Services\SettingsService;
use App\Support\Database;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GalleryController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    /**
     * Format shutter speed for display, handling raw EXIF fractions.
     */
    private function formatShutterSpeed(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Already formatted (e.g., "1/100", "1/100s", "2s")
        if (preg_match('/^1\/\d{1,4}s?$/', $value) || preg_match('/^\d+s$/', $value)) {
            return str_ends_with($value, 's') ? $value : $value . 's';
        }

        // Raw fraction format from EXIF (e.g., "300000/10000000")
        if (str_contains($value, '/')) {
            $parts = explode('/', $value, 2);
            if (\count($parts) === 2) {
                $num = (float)$parts[0];
                $den = (float)$parts[1];
                if ($den > 0 && $num > 0) {
                    $speed = $num / $den;
                    if ($speed >= 1) {
                        return (int)round($speed) . 's';
                    } else {
                        return '1/' . (int)round(1 / $speed) . 's';
                    }
                }
            }
        }

        return $value;
    }

    private function applyTemplateOverrides(array $template, array $templateSettings): array
    {
        $templateSettings = $this->normalizeTemplateSettings($templateSettings);

        if (($template['slug'] ?? '') === 'magazine-split') {
            $templateSettings['layout'] = 'magazine';
        }

        return $templateSettings;
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
                'page_title' => '404 — Album not found',
                'meta_description' => 'Album not found or unpublished'
            ]);
        }
        // Check if user is admin (admins bypass password/NSFW protection)
        $isAdmin = $this->isAdmin();
        $isNsfwAlbum = !empty($album['is_nsfw']) && !$isAdmin;
        $isProtectedAlbum = $isNsfwAlbum || (!empty($album['password_hash']) && !$isAdmin);

        // Password protection with session timeout (24h) - skip for admins
        if (!empty($album['password_hash']) && !$isAdmin) {
            $allowed = $this->hasAlbumPasswordAccess((int)$album['id']);
            if (!$allowed) {
                // Categories for header menu
                $navCategories = (new NavigationService($this->db))->getNavigationCategories();
                $query = $request->getQueryParams();
                // Pass error type: '1' for wrong password, 'nsfw' for NSFW confirmation required
                $error = $query['error'] ?? null;
                return $this->view->render($response, 'frontend/album_password.twig', [
                    'album' => $album,
                    'categories' => $navCategories,
                    'page_title' => $album['title'] . ' — Protected',
                    'error' => $error,
                    'csrf' => $_SESSION['csrf'] ?? '',
                    'is_admin' => $isAdmin
                ]);
            }
        }
        // NSFW server-side enforcement for albums without password protection
        if ($isNsfwAlbum && !$this->hasNsfwAlbumConsent((int)$album['id'])) {
            $navCategories = (new NavigationService($this->db))->getNavigationCategories();
            return $this->view->render($response, 'frontend/nsfw_gate.twig', [
                'album' => $album,
                'categories' => $navCategories,
                'page_title' => $album['title'] . ' — Age Verification Required',
                'csrf' => $_SESSION['csrf'] ?? '',
                'robots' => 'noindex,nofollow'
            ]);
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
                    $template = ['name' => 'Simple Grid', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                    $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
                }
            } else {
                // No default template set, use basic grid fallback
                $template = ['name' => 'Simple Grid', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
            }
        } else {
            $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
            $tplStmt->execute([':id' => $templateId]);
            $template = $tplStmt->fetch();
            if (!$template) {
                // Fallback to basic grid
                $template = ['name' => 'Simple Grid', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
            } else {
                $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];
            }
        }
        // Normalize settings and align Magazine Split behavior with AJAX switcher
        $templateSettings = $this->applyTemplateOverrides($template, $templateSettings);

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

        // Enrich images with metadata from related tables
        \App\Services\ImagesService::enrichWithMetadata($pdo, $imagesRows, 'gallery');

        // Build gallery items for the template, preferring public variants
        $images = [];
        foreach ($imagesRows as $img) {
            $bestUrl = $img['original_path'];
            // Default lightbox URL (overridden below with largest variant if available)
            // Always use protected endpoint for protected albums, regardless of allow_downloads
            $lightboxUrl = $isProtectedAlbum
                ? $this->basePath . '/media/protected/' . $img['id'] . '/original'
                : $bestUrl;
            
            $sources = ['avif'=>[], 'webp'=>[], 'jpg'=>[]];
            try {
                // Get best variant for gallery grid
                $v = $pdo->prepare("SELECT path, width, height FROM image_variants WHERE image_id = :id AND format = 'jpg' AND path NOT LIKE '/storage/%' ORDER BY CASE variant WHEN 'lg' THEN 1 WHEN 'md' THEN 2 WHEN 'sm' THEN 3 ELSE 9 END LIMIT 1");
                $v->execute([':id' => $img['id']]);
                $vr = $v->fetch();
                if ($vr && !empty($vr['path'])) { $bestUrl = $vr['path']; }
                
                // Choose the largest available variant for lightbox (prefer size, then format)
                $lb = $pdo->prepare("
                    SELECT variant, format, path
                    FROM image_variants
                    WHERE image_id = :id AND path NOT LIKE '/storage/%'
                    ORDER BY
                        width DESC,
                        CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END,
                        CASE variant WHEN 'xxl' THEN 1 WHEN 'xl' THEN 2 WHEN 'lg' THEN 3 WHEN 'md' THEN 4 WHEN 'sm' THEN 5 ELSE 9 END
                    LIMIT 1
                ");
                $lb->execute([':id' => $img['id']]);
                $lbr = $lb->fetch();
                if ($lbr && !empty($lbr['path'])) {
                    // Always protect paths for protected albums, regardless of allow_downloads
                    if ($isProtectedAlbum) {
                        $lightboxUrl = $this->basePath . '/media/protected/' . $img['id'] . '/' . $lbr['variant'] . '.' . $lbr['format'];
                    } else {
                        $lightboxUrl = $lbr['path'];
                    }
                } else {
                    // Fallback: always use protected endpoint for protected albums
                    $lightboxUrl = $isProtectedAlbum
                        ? $this->basePath . '/media/protected/' . $img['id'] . '/original'
                        : $bestUrl;
                }
                
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
                $fallbackStmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format = 'jpg' AND path NOT LIKE '/storage/%' ORDER BY width DESC LIMIT 1");
                $fallbackStmt->execute([':id' => $img['id']]);
                $fallback = $fallbackStmt->fetchColumn();
                $bestUrl = $fallback ?: '/media/placeholder.jpg'; // Use placeholder if no variants
            }
            if (str_starts_with((string)$lightboxUrl, '/storage/')) {
                // For lightbox, prefer largest variant (size first, then format)
                $fallbackStmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format IN ('jpg','webp','avif') AND path NOT LIKE '/storage/%' ORDER BY width DESC, CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END LIMIT 1");
                $fallbackStmt->execute([':id' => $img['id']]);
                $fallback = $fallbackStmt->fetchColumn();
                // Always use protected endpoint for protected albums
                $lightboxUrl = $isProtectedAlbum
                    ? $this->basePath . '/media/protected/' . $img['id'] . '/original'
                    : ($fallback ?: $bestUrl); // Use bestUrl as fallback
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
            if (!empty($img['shutter_speed'])) { $metaParts[] = $this->formatShutterSpeed($img['shutter_speed']) ?? (string)$img['shutter_speed']; }
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
            if (!empty($img['shutter_speed'])) { $equipBits[] = '<i class="fa-regular fa-clock mr-1"></i>' . htmlspecialchars($this->formatShutterSpeed($img['shutter_speed']) ?? (string)$img['shutter_speed'], ENT_QUOTES); }
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
                'alt_text' => $img['alt_text'] ?? '',
                'width' => (int)($img['width'] ?? 1200),
                'height' => (int)($img['height'] ?? 800),
                'caption' => $enhancedCaption,
                'caption_html' => $captionHtml,
                'original_path' => $img['original_path'] ?? '',
                // Display values
                'camera' => $cameraDisp ?? '',
                'lens' => $lensDisp ?? '',
                // Raw fields for consumers
                'camera_db' => $img['camera_name'] ?? null,
                'camera_name' => $img['camera_name'] ?? null,
                'lens_db' => $img['lens_name'] ?? null,
                'lens_name' => $img['lens_name'] ?? null,
                'film_db' => $img['film_name'] ?? null,
                'film_name' => $img['film_name'] ?? null,
                'developer_db' => $img['developer_name'] ?? null,
                'developer_name' => $img['developer_name'] ?? null,
                'lab_db' => $img['lab_name'] ?? null,
                'lab_name' => $img['lab_name'] ?? null,
                'location_name' => $img['location_name'] ?? null,
                'camera_custom' => $img['custom_camera'] ?? null,
                'custom_camera' => $img['custom_camera'] ?? null,
                'lens_custom' => $img['custom_lens'] ?? null,
                'custom_lens' => $img['custom_lens'] ?? null,
                'film_custom' => $img['custom_film'] ?? null,
                'custom_film' => $img['custom_film'] ?? null,
                'iso' => isset($img['iso']) ? (int)$img['iso'] : null,
                'shutter_speed' => $this->formatShutterSpeed($img['shutter_speed'] ?? null),
                'aperture' => isset($img['aperture']) ? (float)$img['aperture'] : null,
                'process' => $img['process'] ?? null,
                // Extended EXIF fields
                'focal_length' => $img['focal_length'] ?? null,
                'exif_make' => $img['exif_make'] ?? null,
                'exif_model' => $img['exif_model'] ?? null,
                'exif_lens_model' => $img['exif_lens_model'] ?? null,
                'gps_lat' => $img['gps_lat'] ?? null,
                'gps_lng' => $img['gps_lng'] ?? null,
                'date_original' => $img['date_original'] ?? null,
                'artist' => $img['artist'] ?? null,
                'copyright' => $img['copyright'] ?? null,
                'settings' => '',
                'sources' => $sources, // Add sources array with base_path prepended
                'fallback_src' => $lightboxUrl ?: $bestUrl,
                'variants' => []
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
            'allow_downloads' => !empty($album['allow_downloads']),
        ];

        // Available templates for icon switcher (only if allow_template_switch is enabled)
        $list = [];
        if (!empty($album['allow_template_switch'])) {
            try {
                $list = $pdo->query('SELECT id, name, slug, settings, libs FROM templates ORDER BY name ASC')->fetchAll() ?: [];
                foreach ($list as &$tpl) {
                    $tpl['settings'] = json_decode($tpl['settings'] ?? '{}', true) ?: [];
                    $tpl['libs'] = json_decode($tpl['libs'] ?? '[]', true) ?: [];
                }
            } catch (\Throwable) { $list = []; }
        }

        // Nav categories for header
        $navCats = [];
        try {
            $navCats = (new NavigationService($this->db))->getNavigationCategories();
        } catch (\Throwable) {}

        // Get cover image for Open Graph
        $coverImage = null;
        if (!empty($album['cover_image_id'])) {
            // Use designated cover image
            try {
                $stmt = $pdo->prepare("
                    SELECT i.*, COALESCE(iv.path, i.original_path) AS preview_path
                    FROM images i
                    LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'lg' AND iv.format = 'jpg'
                    WHERE i.id = :id
                ");
                $stmt->execute([':id' => $album['cover_image_id']]);
                $cover = $stmt->fetch();
                if ($cover) {
                    $coverImage = $cover['preview_path'] ?: $cover['original_path'];
                }
            } catch (\Throwable) {}
        }
        
        // Fallback to first image if no cover image set
        if (!$coverImage && !empty($images)) {
            $coverImage = $images[0]['lightbox_url'] ?? $images[0]['url'] ?? null;
        }
        
        // Ensure cover image is a full URL for Open Graph
        $metaImage = null;
        if ($coverImage) {
            if (str_starts_with($coverImage, 'http')) {
                $metaImage = $coverImage;
            } else {
                // Build full URL
                $scheme = $request->getUri()->getScheme();
                $host = $request->getUri()->getHost();
                $port = $request->getUri()->getPort();
                $portStr = ($port && $port != 80 && $port != 443) ? ":$port" : '';
                
                $baseUrl = "{$scheme}://{$host}{$portStr}";
                $metaImage = $baseUrl . (str_starts_with($coverImage, '/') ? $coverImage : '/' . $coverImage);
            }
        }

        // Get social sharing settings
        $settingsService = new \App\Services\SettingsService($this->db);
        $enabledSocials = $settingsService->get('social.enabled', []);
        if (!is_array($enabledSocials)) {
            $enabledSocials = ['behance', 'whatsapp', 'facebook', 'x', 'deviantart', 'instagram', 'pinterest', 'telegram', 'threads', 'bluesky'];
        }
        
        // Get social order
        $socialOrder = $settingsService->get('social.order', []);
        if (!is_array($socialOrder)) {
            $socialOrder = $enabledSocials;
        }
        
        // Use order for enabled socials
        $orderedSocials = [];
        foreach ($socialOrder as $social) {
            if (in_array($social, $enabledSocials)) {
                $orderedSocials[] = $social;
            }
        }
        // Add any enabled socials not in the order
        foreach ($enabledSocials as $social) {
            if (!in_array($social, $orderedSocials)) {
                $orderedSocials[] = $social;
            }
        }
        
        $availableSocials = $this->getAvailableSocials();

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
            'meta_image' => $metaImage,
            'canonical_url' => $request->getUri()->__toString(),
            'current_url' => $request->getUri()->__toString(),
            'enabled_socials' => $orderedSocials,
            'available_socials' => $availableSocials,
            'is_admin' => $isAdmin,
            'current_album' => ['id' => (int)$album['id']],
            'nsfw_consent' => $this->hasNsfwConsent(),
            'allow_downloads' => !empty($album['allow_downloads'])
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

            // Check if user is admin (admins bypass password/NSFW protection)
            $isAdmin = $this->isAdmin();
            $isNsfwAlbum = !empty($album['is_nsfw']) && !$isAdmin;
            $isProtectedAlbum = $isNsfwAlbum || (!empty($album['password_hash']) && !$isAdmin);

            // Password protection with session timeout (24h) - skip for admins
            if (!empty($album['password_hash']) && !$isAdmin) {
                $allowed = $this->hasAlbumPasswordAccess((int)$album['id']);
                if (!$allowed) {
                    $response->getBody()->write('Album locked');
                    return $response->withStatus(403);
                }
            }
            if ($isNsfwAlbum && !$this->hasNsfwAlbumConsent((int)$album['id'])) {
                $response->getBody()->write('Age verification required');
                return $response->withStatus(403);
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
            $templateSettings = $this->applyTemplateOverrides($template, $templateSettings);

            // Images with per-photo metadata
            $imgStmt = $pdo->prepare('SELECT * FROM images WHERE album_id = :id ORDER BY sort_order ASC, id ASC');
            $imgStmt->execute([':id' => $album['id']]);
            $imagesRows = $imgStmt->fetchAll() ?: [];

            // Enrich images with metadata from related tables
            \App\Services\ImagesService::enrichWithMetadata($pdo, $imagesRows, 'gallery');

            // Build gallery items for the template, preferring public variants
            $images = [];
            foreach ($imagesRows as $img) {
                $bestUrl = $img['original_path'];
                // Always use protected endpoint for protected albums
                $lightboxUrl = $isProtectedAlbum
                    ? $this->basePath . '/media/protected/' . $img['id'] . '/original'
                    : $bestUrl;
                try {
                    // Grid: prefer largest public variant (avif > webp > jpg)
                    $vg = $pdo->prepare("SELECT path, width, height FROM image_variants
                        WHERE image_id = :id AND path NOT LIKE '/storage/%'
                        ORDER BY CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END, width DESC LIMIT 1");
                    $vg->execute([':id' => $img['id']]);
                    if ($vgr = $vg->fetch()) { if (!empty($vgr['path'])) { $bestUrl = $vgr['path']; } }
                    // Lightbox: choose the largest available variant (size first, then format)
                    $lb = $pdo->prepare("
                        SELECT variant, format, path
                        FROM image_variants
                        WHERE image_id = :id AND path NOT LIKE '/storage/%'
                        ORDER BY width DESC,
                                 CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END,
                                 CASE variant WHEN 'xxl' THEN 1 WHEN 'xl' THEN 2 WHEN 'lg' THEN 3 WHEN 'md' THEN 4 WHEN 'sm' THEN 5 ELSE 9 END
                        LIMIT 1
                    ");
                    $lb->execute([':id' => $img['id']]);
                    $lbr = $lb->fetch();
                    if ($lbr && !empty($lbr['path'])) {
                        // Always protect paths for protected albums
                        if ($isProtectedAlbum) {
                            $lightboxUrl = $this->basePath . '/media/protected/' . $img['id'] . '/' . $lbr['variant'] . '.' . $lbr['format'];
                        } else {
                            $lightboxUrl = $lbr['path'];
                        }
                    } elseif (!$isProtectedAlbum) {
                        $lightboxUrl = $bestUrl;
                    }

                    // Lightbox: only use variant if original is not publicly accessible
                    // Original paths like /media/originals/... are public, /storage/... are not
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
                    Logger::warning('GalleryController: Error fetching image variants', ['error' => $e->getMessage()], 'gallery');
                }
                // Fallbacks - ensure we never serve /storage/ paths
                if (str_starts_with((string)$bestUrl, '/storage/')) {
                    // Try to find any public variant
                    $fallbackStmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format = 'jpg' AND path NOT LIKE '/storage/%' ORDER BY width DESC LIMIT 1");
                    $fallbackStmt->execute([':id' => $img['id']]);
                    $fallback = $fallbackStmt->fetchColumn();
                    $bestUrl = $fallback ?: '/media/placeholder.jpg';
                }
                if (str_starts_with((string)$lightboxUrl, '/storage/')) {
                    // For lightbox, prefer largest variant (size first, then format)
                    $lbFallback = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format IN ('jpg','webp','avif') AND path NOT LIKE '/storage/%' ORDER BY width DESC, CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END LIMIT 1");
                    $lbFallback->execute([':id' => $img['id']]);
                    $fallbackPath = $lbFallback->fetchColumn();
                    // Always use protected endpoint for protected albums
                    if ($isProtectedAlbum) {
                        $lightboxUrl = $this->basePath . '/media/protected/' . $img['id'] . '/original';
                    } else {
                        $lightboxUrl = $fallbackPath ?: $bestUrl;
                    }
                }
                

                $images[] = [
                    'id' => (int)$img['id'],
                    'url' => $bestUrl,
                    'lightbox_url' => $lightboxUrl,
                    'alt' => $img['alt_text'] ?: $album['title'],
                    'alt_text' => $img['alt_text'] ?? '',
                    'width' => (int)($img['width'] ?? 1200),
                    'height' => (int)($img['height'] ?? 800),
                    'caption' => $img['caption'] ?? '',
                    'original_path' => $img['original_path'] ?? '',
                    'custom_camera' => $img['custom_camera'] ?? '',
                    'camera_name' => $img['camera_name'] ?? '',
                    'custom_lens' => $img['custom_lens'] ?? '',
                    'lens_name' => $img['lens_name'] ?? '',
                    'custom_film' => $img['custom_film'] ?? '',
                    'film_name' => $img['film_name'] ?? '',
                    'developer_name' => $img['developer_name'] ?? '',
                    'lab_name' => $img['lab_name'] ?? '',
                    'location_name' => $img['location_name'] ?? '',
                    'iso' => isset($img['iso']) ? (int)$img['iso'] : null,
                    'shutter_speed' => $this->formatShutterSpeed($img['shutter_speed'] ?? null),
                    'aperture' => isset($img['aperture']) ? (float)$img['aperture'] : null,
                    'process' => $img['process'] ?? null,
                    // Extended EXIF fields
                    'focal_length' => $img['focal_length'] ?? null,
                    'exif_make' => $img['exif_make'] ?? null,
                    'exif_model' => $img['exif_model'] ?? null,
                    'exif_lens_model' => $img['exif_lens_model'] ?? null,
                    'gps_lat' => $img['gps_lat'] ?? null,
                    'gps_lng' => $img['gps_lng'] ?? null,
                    'date_original' => $img['date_original'] ?? null,
                    'artist' => $img['artist'] ?? null,
                    'copyright' => $img['copyright'] ?? null,
                    'sources' => $sources ?? ['avif'=>[], 'webp'=>[], 'jpg'=>[]],
                    'fallback_src' => $lightboxUrl ?: $bestUrl,
                    'variants' => []
                ];
            }

            // Render the appropriate gallery partial based on template
            $templateFile = 'frontend/_gallery_content.twig';
            $templateData = [
                'images' => $images,
                'template_settings' => $templateSettings,
                'base_path' => $this->basePath,
                'album' => $album
            ];

            // Use Magazine template for magazine-split slug or layout 'magazine'
            if (($template['slug'] ?? '') === 'magazine-split' || ($templateSettings['layout'] ?? '') === 'magazine') {
                $templateFile = 'frontend/_gallery_magazine_content.twig';
                $templateData['album'] = $album; // Magazine template needs album data
            }
            
            return $this->view->render($response, $templateFile, $templateData);
            
        } catch (\Throwable $e) {
            // Log the actual error for debugging
            Logger::critical('GalleryController::template error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 'gallery');

            $response->getBody()->write('Internal server error');
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

                    $intValue = (int)$value;
                    $isValid = match ($device) {
                        'desktop' => $intValue >= 1 && $intValue <= 6,
                        'tablet' => $intValue >= 1 && $intValue <= 4,
                        'mobile' => $intValue >= 1 && $intValue <= 2,
                        default => false,
                    };
                    if ($isValid) {
                        $normalizedColumns[$device] = $intValue;
                    } else {
                        $normalizedColumns[$device] = match($device) {
                            'desktop' => 3,
                            'tablet' => 2,
                            'mobile' => 1,
                            default => 3,
                        };
                    }
                }
            }
            
            $templateSettings['columns'] = $normalizedColumns;
        }
        
        return $templateSettings;
    }

    private function getAvailableSocials(): array
    {
        return [
            'addtofavorites' => [
                'name' => 'Add to Favorites',
                'icon' => 'fa fa-star',
                'color' => '#F9A600',
                'url' => '#'
            ],
            'behance' => [
                'name' => 'Behance',
                'icon' => 'fab fa-behance',
                'color' => '#1769ff',
                'url' => 'https://www.behance.net/gallery/share?title={title}&url={url}'
            ],
            'bitbucket' => [
                'name' => 'Bitbucket',
                'icon' => 'fab fa-bitbucket',
                'color' => '#205081',
                'url' => '#'
            ],
            'blogger' => [
                'name' => 'Blogger',
                'icon' => 'fab fa-blogger-b',
                'color' => '#FF6501',
                'url' => 'https://www.blogger.com/blog_this.pyra?t&u={url}&n={title}'
            ],
            'bluesky' => [
                'name' => 'Bluesky',
                'icon' => 'fab fa-bluesky',
                'color' => '#1083fe',
                'url' => 'https://bsky.app/intent/compose?text={title} {url}'
            ],
            'codepen' => [
                'name' => 'CodePen',
                'icon' => 'fab fa-codepen',
                'color' => '#000',
                'url' => '#'
            ],
            'comments' => [
                'name' => 'Comments',
                'icon' => 'fa fa-comments',
                'color' => '#333',
                'url' => '#'
            ],
            'delicious' => [
                'name' => 'Delicious',
                'icon' => 'fab fa-delicious',
                'color' => '#3274D1',
                'url' => 'https://delicious.com/save?url={url}&title={title}'
            ],
            'deviantart' => [
                'name' => 'DeviantArt',
                'icon' => 'fab fa-deviantart',
                'color' => '#475c4d',
                'url' => 'https://www.deviantart.com/users/outgoing?{url}'
            ],
            'digg' => [
                'name' => 'Digg',
                'icon' => 'fab fa-digg',
                'color' => '#000',
                'url' => 'https://digg.com/submit?url={url}&title={title}'
            ],
            'discord' => [
                'name' => 'Discord',
                'icon' => 'fab fa-discord',
                'color' => '#7289da',
                'url' => '#'
            ],
            'dribbble' => [
                'name' => 'Dribbble',
                'icon' => 'fab fa-dribbble',
                'color' => '#ea4c89',
                'url' => '#'
            ],
            'email' => [
                'name' => 'Email',
                'icon' => 'fa fa-envelope',
                'color' => '#000',
                'url' => 'mailto:?subject={title}&body={url}'
            ],
            'etsy' => [
                'name' => 'Etsy',
                'icon' => 'fab fa-etsy',
                'color' => '#f1641e',
                'url' => '#'
            ],
            'facebook' => [
                'name' => 'Facebook',
                'icon' => 'fab fa-facebook-f',
                'color' => '#0866ff',
                'url' => 'https://www.facebook.com/sharer/sharer.php?u={url}'
            ],
            'fbmessenger' => [
                'name' => 'Facebook Messenger',
                'icon' => 'fab fa-facebook-messenger',
                'color' => '#0866ff',
                'url' => 'https://www.facebook.com/dialog/send?link={url}'
            ],
            'flickr' => [
                'name' => 'Flickr',
                'icon' => 'fab fa-flickr',
                'color' => '#1c9be9',
                'url' => '#'
            ],
            'flipboard' => [
                'name' => 'Flipboard',
                'icon' => 'fab fa-flipboard',
                'color' => '#F52828',
                'url' => 'https://share.flipboard.com/bookmarklet/popout?v=2&url={url}&title={title}'
            ],
            'github' => [
                'name' => 'GitHub',
                'icon' => 'fab fa-github',
                'color' => '#333',
                'url' => '#'
            ],
            'google' => [
                'name' => 'Google',
                'icon' => 'fab fa-google',
                'color' => '#3A7CEC',
                'url' => '#'
            ],
            'googleplus' => [
                'name' => 'Google+',
                'icon' => 'fab fa-google-plus-g',
                'color' => '#DB483B',
                'url' => 'https://plus.google.com/share?url={url}'
            ],
            'hackernews' => [
                'name' => 'Hacker News',
                'icon' => 'fab fa-hacker-news',
                'color' => '#FF6500',
                'url' => 'https://news.ycombinator.com/submitlink?u={url}&t={title}'
            ],
            'houzz' => [
                'name' => 'Houzz',
                'icon' => 'fab fa-houzz',
                'color' => '#4dbc15',
                'url' => '#'
            ],
            'instagram' => [
                'name' => 'Instagram',
                'icon' => 'fab fa-instagram',
                'color' => '#e23367',
                'url' => 'https://www.instagram.com/'
            ],
            'line' => [
                'name' => 'Line',
                'icon' => 'fab fa-line',
                'color' => '#00C300',
                'url' => 'https://lineit.line.me/share/ui?url={url}&text={title}'
            ],
            'linkedin' => [
                'name' => 'LinkedIn',
                'icon' => 'fab fa-linkedin-in',
                'color' => '#0274B3',
                'url' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}'
            ],
            'mastodon' => [
                'name' => 'Mastodon',
                'icon' => 'fab fa-mastodon',
                'color' => '#6364ff',
                'url' => '#'
            ],
            'medium' => [
                'name' => 'Medium',
                'icon' => 'fab fa-medium',
                'color' => '#02b875',
                'url' => 'https://medium.com/new-story?url={url}&title={title}'
            ],
            'mix' => [
                'name' => 'Mix',
                'icon' => 'fab fa-mix',
                'color' => '#ff8226',
                'url' => 'https://mix.com/add?url={url}&title={title}'
            ],
            'odnoklassniki' => [
                'name' => 'Odnoklassniki',
                'icon' => 'fab fa-odnoklassniki',
                'color' => '#F2720C',
                'url' => 'https://connect.ok.ru/dk?st.cmd=WidgetSharePreview&st.shareUrl={url}&st.comments={title}'
            ],
            'patreon' => [
                'name' => 'Patreon',
                'icon' => 'fab fa-patreon',
                'color' => '#e85b46',
                'url' => '#'
            ],
            'paypal' => [
                'name' => 'PayPal',
                'icon' => 'fab fa-paypal',
                'color' => '#0070ba',
                'url' => '#'
            ],
            'pdf' => [
                'name' => 'PDF',
                'icon' => 'fa fa-file-pdf',
                'color' => '#E61B2E',
                'url' => '#'
            ],
            'phone' => [
                'name' => 'Phone',
                'icon' => 'fa fa-phone',
                'color' => '#1A73E8',
                'url' => '#'
            ],
            'pinterest' => [
                'name' => 'Pinterest',
                'icon' => 'fab fa-pinterest',
                'color' => '#CB2027',
                'url' => 'https://pinterest.com/pin/create/button/?url={url}&description={title}'
            ],
            'pocket' => [
                'name' => 'Pocket',
                'icon' => 'fab fa-get-pocket',
                'color' => '#EF4056',
                'url' => 'https://getpocket.com/save?url={url}&title={title}'
            ],
            'podcast' => [
                'name' => 'Podcast',
                'icon' => 'fa fa-podcast',
                'color' => '#7224d8',
                'url' => '#'
            ],
            'print' => [
                'name' => 'Print',
                'icon' => 'fa fa-print',
                'color' => '#6D9F00',
                'url' => 'javascript:window.print()'
            ],
            'reddit' => [
                'name' => 'Reddit',
                'icon' => 'fab fa-reddit',
                'color' => '#FF5600',
                'url' => 'https://www.reddit.com/submit?url={url}&title={title}'
            ],
            'renren' => [
                'name' => 'Renren',
                'icon' => 'fab fa-renren',
                'color' => '#005EAC',
                'url' => 'https://www.connect.renren.com/share/sharer?url={url}&title={title}'
            ],
            'rss' => [
                'name' => 'RSS',
                'icon' => 'fa fa-rss',
                'color' => '#FF7B0A',
                'url' => '#'
            ],
            'shortlink' => [
                'name' => 'Short Link',
                'icon' => 'fa fa-link',
                'color' => '#333',
                'url' => '#'
            ],
            'skype' => [
                'name' => 'Skype',
                'icon' => 'fab fa-skype',
                'color' => '#00AFF0',
                'url' => 'https://web.skype.com/share?url={url}&text={title}'
            ],
            'sms' => [
                'name' => 'SMS',
                'icon' => 'fa fa-sms',
                'color' => '#35d54f',
                'url' => 'sms:?body={title} {url}'
            ],
            'snapchat' => [
                'name' => 'Snapchat',
                'icon' => 'fab fa-snapchat',
                'color' => '#FFFC00',
                'url' => '#'
            ],
            'soundcloud' => [
                'name' => 'SoundCloud',
                'icon' => 'fab fa-soundcloud',
                'color' => '#f50',
                'url' => '#'
            ],
            'stackoverflow' => [
                'name' => 'Stack Overflow',
                'icon' => 'fab fa-stack-overflow',
                'color' => '#F48024',
                'url' => '#'
            ],
            'quora' => [
                'name' => 'Quora',
                'icon' => 'fab fa-quora',
                'color' => '#b92b27',
                'url' => 'https://www.quora.com/share?url={url}&title={title}'
            ],
            'telegram' => [
                'name' => 'Telegram',
                'icon' => 'fab fa-telegram-plane',
                'color' => '#179cde',
                'url' => 'https://t.me/share/url?url={url}&text={title}'
            ],
            'threads' => [
                'name' => 'Threads',
                'icon' => 'fab fa-threads',
                'color' => '#000',
                'url' => 'https://www.threads.net/intent/post?text={title} {url}'
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'icon' => 'fab fa-tiktok',
                'color' => '#010101',
                'url' => '#'
            ],
            'tumblr' => [
                'name' => 'Tumblr',
                'icon' => 'fab fa-tumblr',
                'color' => '#314358',
                'url' => 'https://www.tumblr.com/widgets/share/tool?shareSource=legacy&canonicalUrl={url}&title={title}'
            ],
            'twitch' => [
                'name' => 'Twitch',
                'icon' => 'fab fa-twitch',
                'color' => '#4b367c',
                'url' => '#'
            ],
            'twitter' => [
                'name' => 'Twitter',
                'icon' => 'fab fa-twitter',
                'color' => '#1da1f2',
                'url' => 'https://twitter.com/intent/tweet?text={title}&url={url}'
            ],
            'viber' => [
                'name' => 'Viber',
                'icon' => 'fab fa-viber',
                'color' => '#574e92',
                'url' => 'viber://forward?text={title} {url}'
            ],
            'vimeo' => [
                'name' => 'Vimeo',
                'icon' => 'fab fa-vimeo',
                'color' => '#00ADEF',
                'url' => '#'
            ],
            'vkontakte' => [
                'name' => 'VKontakte',
                'icon' => 'fab fa-vk',
                'color' => '#4C75A3',
                'url' => 'https://vk.com/share.php?url={url}&title={title}'
            ],
            'wechat' => [
                'name' => 'WeChat',
                'icon' => 'fab fa-weixin',
                'color' => '#7BB32E',
                'url' => '#'
            ],
            'weibo' => [
                'name' => 'Weibo',
                'icon' => 'fab fa-weibo',
                'color' => '#E6162D',
                'url' => 'https://service.weibo.com/share/share.php?url={url}&title={title}'
            ],
            'whatsapp' => [
                'name' => 'WhatsApp',
                'icon' => 'fab fa-whatsapp',
                'color' => '#25d366',
                'url' => 'https://wa.me/?text={title} {url}'
            ],
            'x' => [
                'name' => 'X (Twitter)',
                'icon' => 'fab fa-x-twitter',
                'color' => '#000',
                'url' => 'https://twitter.com/intent/tweet?text={title}&url={url}'
            ],
            'xing' => [
                'name' => 'Xing',
                'icon' => 'fab fa-xing',
                'color' => '#006567',
                'url' => 'https://www.xing.com/app/user?op=share;url={url};title={title}'
            ],
            'yahoomail' => [
                'name' => 'Yahoo Mail',
                'icon' => 'fab fa-yahoo',
                'color' => '#4A00A1',
                'url' => 'https://compose.mail.yahoo.com/?to=&subject={title}&body={url}'
            ],
            'youtube' => [
                'name' => 'YouTube',
                'icon' => 'fab fa-youtube',
                'color' => '#ff0000',
                'url' => '#'
            ],
            'more' => [
                'name' => 'More',
                'icon' => 'fa fa-share-nodes',
                'color' => 'green',
                'url' => 'javascript:navigator.share({title: "{title}", url: "{url}"})'
            ]
        ];
    }
}
