<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;
use App\Controllers\BaseController;
use App\Services\AnalyticsService;
use App\Services\ImagesService;
use App\Services\NavigationService;
use App\Support\Database;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PageController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    private function buildSeo(Request $request, string $title, string $description = '', ?string $imagePath = null): array
    {
        $svc = new \App\Services\SettingsService($this->db);
        $siteName = (string)($svc->get('seo.site_title', 'Portfolio') ?? 'Portfolio');
        $ogSite = (string)($svc->get('seo.og_site_name', $siteName) ?? $siteName);
        $robots = (string)($svc->get('seo.robots_default', 'index,follow') ?? 'index,follow');
        $canonicalBase = (string)($svc->get('seo.canonical_base_url', '') ?? '');
        $logo = (string)($svc->get('site.logo', '') ?? '');
        // Schema-related settings
        $schema = [
            'author_name' => (string)($svc->get('seo.author_name', '') ?? ''),
            'author_url' => (string)($svc->get('seo.author_url', '') ?? ''),
            'organization_name' => (string)($svc->get('seo.organization_name', '') ?? ''),
            'organization_url' => (string)($svc->get('seo.organization_url', '') ?? ''),
            'photographer_job_title' => (string)($svc->get('seo.photographer_job_title', 'Professional Photographer') ?? 'Professional Photographer'),
            'photographer_services' => (string)($svc->get('seo.photographer_services', 'Professional Photography Services') ?? 'Professional Photography Services'),
            'photographer_same_as' => (string)($svc->get('seo.photographer_same_as', '') ?? ''),
        ];

        $uri = $request->getUri();
        $path = $uri->getPath();
        $base = rtrim($canonicalBase !== '' ? $canonicalBase : ($uri->getScheme() . '://' . $uri->getHost() . ($this->basePath ?: '')), '/');
        $canonicalUrl = $base . $path;

        $pageTitle = trim($title) !== '' ? ($title . ' — ' . $siteName) : $siteName;
        $desc = trim(strip_tags($description));
        if ($desc !== '') { $desc = mb_substr($desc, 0, 160); }

        $metaImg = $imagePath ?: $logo;
        if ($metaImg) {
            if (!str_starts_with((string)$metaImg, 'http')) {
                if (str_starts_with((string)$metaImg, '/')) { $metaImg = $base . $metaImg; }
                else { $metaImg = $base . '/' . ltrim((string)$metaImg, '/'); }
            }
        } else {
            $metaImg = '';
        }

        return [
            'page_title' => $pageTitle,
            'meta_description' => $desc,
            'meta_image' => $metaImg,
            'canonical_url' => $canonicalUrl,
            'canonical_base' => $base,
            'og_site_name' => $ogSite,
            'robots' => $robots,
            'current_url' => $uri->__toString(),
            'schema' => $schema,
        ];
    }

    private function normalizeTemplateSettings(array $templateSettings): array
    {
        // Normalize possibly nested columns structure to flat integers
        if (isset($templateSettings['columns']) && is_array($templateSettings['columns'])) {
            $normalizedColumns = [];
            foreach (['desktop', 'tablet', 'mobile'] as $device) {
                if (isset($templateSettings['columns'][$device])) {
                    $value = $templateSettings['columns'][$device];
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

    public function home(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();

        // Fetch home page settings
        $svc = new \App\Services\SettingsService($this->db);
        $homeTemplate = (string)($svc->get('home.template', 'classic') ?? 'classic');
        $homeSettings = [
            'template' => $homeTemplate,
            'hero_title' => (string)($svc->get('home.hero_title', 'Portfolio') ?? 'Portfolio'),
            'hero_subtitle' => (string)($svc->get('home.hero_subtitle', 'A collection of analog and digital photography exploring light, form, and the beauty of everyday moments.') ?? 'A collection of analog and digital photography exploring light, form, and the beauty of everyday moments.'),
            'albums_title' => (string)($svc->get('home.albums_title', 'Latest Albums') ?? 'Latest Albums'),
            'albums_subtitle' => (string)($svc->get('home.albums_subtitle', 'Discover my recent photographic work, from analog experiments to digital explorations.') ?? 'Discover my recent photographic work, from analog experiments to digital explorations.'),
            'empty_title' => (string)($svc->get('home.empty_title', 'No albums yet') ?? 'No albums yet'),
            'empty_text' => (string)($svc->get('home.empty_text', 'Check back soon for new work.') ?? 'Check back soon for new work.'),
            'gallery_scroll_direction' => (string)($svc->get('home.gallery_scroll_direction', 'vertical') ?? 'vertical'),
            'gallery_text_title' => (string)($svc->get('home.gallery_text_title', '') ?? ''),
            'gallery_text_content' => (string)($svc->get('home.gallery_text_content', '') ?? ''),
            // Masonry settings (with defensive clamping)
            'masonry_gap_h' => max(0, min(40, (int)($svc->get('home.masonry_gap_h', 0) ?? 0))),
            'masonry_gap_v' => max(0, min(40, (int)($svc->get('home.masonry_gap_v', 0) ?? 0))),
            'masonry_col_desktop' => max(2, min(8, (int)($svc->get('home.masonry_col_desktop', 5) ?? 5))),
            'masonry_col_tablet' => max(2, min(6, (int)($svc->get('home.masonry_col_tablet', 3) ?? 3))),
            'masonry_col_mobile' => max(1, min(4, (int)($svc->get('home.masonry_col_mobile', 2) ?? 2))),
        ];

        // Pagination parameters
        $perPage = 12;
        
        // Get total count of published albums
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM albums a WHERE a.is_published = 1');
        $countStmt->execute();
        $totalAlbums = (int)$countStmt->fetchColumn();
        
        // Get latest published albums
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            WHERE a.is_published = 1 
            ORDER BY a.published_at DESC 
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->execute();
        $albums = $stmt->fetchAll();
        
        // Enrich with cover images and tags + apply access filters
        $albums = $this->filterAlbumsByAccess($albums, $isAdmin, $nsfwConsent);
        
        // Calculate pagination info
        $hasMore = $totalAlbums > $perPage;
        
        // Get categories for navigation with hierarchy
        $parentCategories = $this->getParentCategoriesForNavigation();
        
        // Keep flat list for backward compatibility
        $categories = [];
        foreach ($parentCategories as $parent) {
            if ($parent['albums_count'] > 0) {
                $categories[] = $parent;
            }
            foreach ($parent['children'] as $child) {
                if ($child['albums_count'] > 0) {
                    $categories[] = $child;
                }
            }
        }
        
        // Get popular tags
        $stmt = $pdo->prepare('
            SELECT t.*, COUNT(at.album_id) as albums_count
            FROM tags t 
            JOIN album_tag at ON at.tag_id = t.id
            JOIN albums a ON a.id = at.album_id AND a.is_published = 1
            GROUP BY t.id 
            ORDER BY albums_count DESC, t.name ASC 
            LIMIT 20
        ');
        $stmt->execute();
        $tags = $stmt->fetchAll();
        
        // Get random images from all published albums for infinite scroll
        // Include NSFW albums if user has global consent, always exclude password-protected albums
        $includeNsfw = ($isAdmin || $nsfwConsent) ? 1 : 0;
        $stmt = $pdo->prepare("
            SELECT i.*, a.title as album_title, a.slug as album_slug, a.id as album_id,
                   a.excerpt as album_description,
                   c.slug as category_slug, c.name as category_name
            FROM images i
            JOIN albums a ON a.id = i.album_id
            JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
            ORDER BY RANDOM()
            LIMIT 500
        ");
        $stmt->execute([':include_nsfw' => $includeNsfw]);
        $allImages = $stmt->fetchAll();
        
        // Process images with responsive sources
        foreach ($allImages as &$image) {
            $image = $this->processImageSources($image);
        }
        
        $seo = $this->buildSeo($request, 'Home', 'Photography portfolio showcasing analog and digital work');

        // Select template based on home.template setting
        $templateMap = [
            'modern' => 'frontend/home_modern.twig',
            'parallax' => 'frontend/home_parallax.twig',
            'masonry' => 'frontend/home_masonry.twig',
            'snap' => 'frontend/home_snap.twig',
            'gallery' => 'frontend/home_gallery.twig',
        ];
        $templateFile = $templateMap[$homeTemplate] ?? 'frontend/home.twig';

        // Get galleries slug for JSON-LD SearchAction
        $galleriesSlug = (string)($svc->get('galleries.slug', 'galleries') ?? 'galleries');

        return $this->view->render($response, $templateFile, [
            'albums' => $albums,
            'categories' => $categories,
            'parent_categories' => $parentCategories,
            'tags' => $tags,
            'all_images' => $allImages,
            'has_more' => $hasMore,
            'total_albums' => $totalAlbums,
            'home_settings' => $homeSettings,
            'galleries_slug' => $galleriesSlug,
            'page_title' => $seo['page_title'],
            'meta_description' => $seo['meta_description'],
            'meta_image' => $seo['meta_image'],
            'current_url' => $seo['current_url'],
            'canonical_url' => $seo['canonical_url'],
            'og_site_name' => $seo['og_site_name'],
            'robots' => $seo['robots'],
            'nsfw_consent' => $nsfwConsent,
            'is_admin' => $isAdmin
        ]);
    }

    public function album(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $params = $request->getQueryParams();
        $templateId = isset($params['template']) ? (int)$params['template'] : null;
        $pdo = $this->db->pdo();
        
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug,
                   t.name as template_name, t.slug as template_slug, t.settings as template_settings, t.libs as template_libs
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            LEFT JOIN templates t ON t.id = a.template_id
            WHERE a.slug = :slug AND a.is_published = 1
        ');
        $stmt->execute([':slug' => $slug]);
        $album = $stmt->fetch();
        
        if (!$album) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Album not found',
                'meta_description' => 'Album not found or unpublished'
            ]);
        }

        // Check if user is admin (admins bypass password/NSFW protection)
        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();

        // Password protection with session timeout - skip for admins
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
                    'csrf' => $_SESSION['csrf'] ?? ''
                ]);
            }
        }

        // NSFW server-side enforcement for albums WITHOUT password protection
        // Albums with password already handle NSFW through the unlock flow
        $isNsfw = !empty($album['is_nsfw']);
        $nsfwConfirmed = $this->hasNsfwAlbumConsent((int)$album['id']);

        if ($isNsfw && !$isAdmin && empty($album['password_hash']) && !$nsfwConfirmed) {
            // Show NSFW gate page - user must confirm they're 18+ to view
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

        $templateIdFromUrl = (int)($params['template'] ?? 0);
        $finalTemplateId = null;
        $template = null;
        $templateSettings = [];

        // 1. Check for a valid template ID from the URL
        if ($templateIdFromUrl > 0) {
            $stmt = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
            $stmt->execute([$templateIdFromUrl]);
            $templateFromUrl = $stmt->fetch() ?: null;
            if ($templateFromUrl) {
                $template = $templateFromUrl;
                $finalTemplateId = $templateIdFromUrl;
            }
        }

        // 2. If no valid URL template, use the one assigned to the album
        if ($finalTemplateId === null && !empty($album['template_id'])) {
            $finalTemplateId = (int)$album['template_id'];
            // The template data might already be joined in the main album query
            if (!empty($album['template_name'])) {
                $template = [
                    'id' => $album['template_id'],
                    'name' => $album['template_name'],
                    'slug' => $album['template_slug'],
                    'settings' => $album['template_settings'],
                    'libs' => $album['template_libs'],
                ];
            }
        }

        // 3. If still no template, use the site-wide default
        if ($finalTemplateId === null) {
            $settingsService = new \App\Services\SettingsService($this->db);
            $defaultTemplateId = $settingsService->get('gallery.default_template_id');
            if ($defaultTemplateId) {
                $finalTemplateId = (int)$defaultTemplateId;
            }
        }

        // 4. Fetch the template data if we have an ID but no data yet
        if ($finalTemplateId > 0 && $template === null) {
            $stmt = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
            $stmt->execute([$finalTemplateId]);
            $template = $stmt->fetch() ?: null;
        }

        // 5. Decode settings or use a fallback
        if ($template && !empty($template['settings'])) {
            $templateSettings = json_decode($template['settings'], true) ?: [];
        } else {
            // Final fallback to a basic grid if no template could be resolved at all
            $template = [
                'id' => 0,
                'name' => 'Simple Grid',
                'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])
            ];
            $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
        }

        // Normalize settings
        $templateSettings = $this->normalizeTemplateSettings($templateSettings);
        $templateId = $finalTemplateId;

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

        // Load equipment: each type in its own try-catch to avoid one failure breaking all
        // 1. Cameras
        try {
            if (!empty($album['custom_cameras'])) {
                $equipment['cameras'] = array_filter(array_map('trim', explode("\n", $album['custom_cameras'])));
            } else {
                $cameraStmt = $pdo->prepare('SELECT c.make, c.model FROM cameras c JOIN album_camera ac ON c.id = ac.camera_id WHERE ac.album_id = :a');
                $cameraStmt->execute([':a' => $album['id']]);
                $cameras = $cameraStmt->fetchAll();
                $equipment['cameras'] = array_map(fn($c) => trim(($c['make'] ?? '') . ' ' . ($c['model'] ?? '')), $cameras);
            }
        } catch (\Throwable) {}

        // 2. Lenses
        try {
            if (!empty($album['custom_lenses'])) {
                $equipment['lenses'] = array_filter(array_map('trim', explode("\n", $album['custom_lenses'])));
            } else {
                $lensStmt = $pdo->prepare('SELECT l.brand, l.model FROM lenses l JOIN album_lens al ON l.id = al.lens_id WHERE al.album_id = :a');
                $lensStmt->execute([':a' => $album['id']]);
                $lenses = $lensStmt->fetchAll();
                $equipment['lenses'] = array_map(fn($l) => trim(($l['brand'] ?? '') . ' ' . ($l['model'] ?? '')), $lenses);
            }
        } catch (\Throwable) {}

        // 3. Films
        try {
            if (!empty($album['custom_films'])) {
                $equipment['film'] = array_filter(array_map('trim', explode("\n", $album['custom_films'])));
            } else {
                $filmStmt = $pdo->prepare('SELECT f.brand, f.name, f.iso, f.format FROM films f JOIN album_film af ON f.id = af.film_id WHERE af.album_id = :a');
                $filmStmt->execute([':a' => $album['id']]);
                $films = $filmStmt->fetchAll();
                $equipment['film'] = array_map(function($f) {
                    $name = trim(($f['brand'] ?? '') . ' ' . ($f['name'] ?? ''));
                    return [
                        'name' => $name,
                        'iso' => $f['iso'] ?? null,
                        'format' => $f['format'] ?? null
                    ];
                }, $films);
            }
        } catch (\Throwable) {}

        // 4. Developers
        try {
            if (!empty($album['custom_developers'])) {
                $equipment['developers'] = array_filter(array_map('trim', explode("\n", $album['custom_developers'])));
            } else {
                $devStmt = $pdo->prepare('SELECT d.name FROM developers d JOIN album_developer ad ON d.id = ad.developer_id WHERE ad.album_id = :a');
                $devStmt->execute([':a' => $album['id']]);
                $developers = $devStmt->fetchAll();
                $equipment['developers'] = array_map(fn($d) => $d['name'], $developers);
            }
        } catch (\Throwable) {}

        // 5. Labs
        try {
            if (!empty($album['custom_labs'])) {
                $equipment['labs'] = array_filter(array_map('trim', explode("\n", $album['custom_labs'])));
            } else {
                $labStmt = $pdo->prepare('SELECT l.name FROM labs l JOIN album_lab al ON l.id = al.lab_id WHERE al.album_id = :a');
                $labStmt->execute([':a' => $album['id']]);
                $labs = $labStmt->fetchAll();
                $equipment['labs'] = array_map(fn($l) => $l['name'], $labs);
            }
        } catch (\Throwable) {}

        // 6. Locations
        try {
            $locStmt = $pdo->prepare('SELECT l.name FROM locations l JOIN album_location al ON l.id = al.location_id WHERE al.album_id = :a ORDER BY l.name');
            $locStmt->execute([':a' => $album['id']]);
            $locations = $locStmt->fetchAll();
            $equipment['locations'] = array_map(fn($l) => $l['name'], $locations);
        } catch (\Throwable) {}
        
        // Get album images
        $stmt = $pdo->prepare('
            SELECT i.*
            FROM images i
            WHERE i.album_id = :id
            ORDER BY i.sort_order ASC, i.id ASC
        ');
        $stmt->execute([':id' => $album['id']]);
        $images = $stmt->fetchAll();
        
        // Get variants for each image and format EXIF
        foreach ($images as &$image) {
            $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
            $variantsStmt->execute([':id' => $image['id']]);
            $image['variants'] = $variantsStmt->fetchAll();

            // Format EXIF for display
            if ($image['exif']) {
                $exif = json_decode($image['exif'], true) ?: [];
                $image['exif_display'] = $this->formatExifForDisplay($exif, $image);
            }
        }
        unset($image); // Break reference

        // Batch fetch metadata (camera, lens, film, developer, lab, location names)
        ImagesService::enrichWithMetadata($pdo, $images, 'album');

        // Fallback: if album has no equipment, extract unique values from images
        if (empty($equipment['cameras']) || empty($equipment['lenses']) || empty($equipment['film'])) {
            $imgCameras = [];
            $imgLenses = [];
            $imgFilms = [];
            foreach ($images as $img) {
                // Camera from image metadata
                $cam = $img['camera_name'] ?? ($img['custom_camera'] ?? null);
                if ($cam && !in_array($cam, $imgCameras, true)) {
                    $imgCameras[] = $cam;
                }
                // Lens from image metadata
                $lens = $img['lens_name'] ?? ($img['custom_lens'] ?? null);
                if ($lens && !in_array($lens, $imgLenses, true)) {
                    $imgLenses[] = $lens;
                }
                // Film from image metadata
                $film = $img['film_name'] ?? ($img['custom_film'] ?? null);
                if ($film && !in_array($film, $imgFilms, true)) {
                    $imgFilms[] = $film;
                }
            }
            // Use image data as fallback if album equipment is empty
            if (empty($equipment['cameras']) && !empty($imgCameras)) {
                $equipment['cameras'] = $imgCameras;
            }
            if (empty($equipment['lenses']) && !empty($imgLenses)) {
                $equipment['lenses'] = $imgLenses;
            }
            if (empty($equipment['film']) && !empty($imgFilms)) {
                $equipment['film'] = $imgFilms;
            }
        }

        // Enrich images with custom fields
        try {
            $customFieldService = new \App\Services\CustomFieldService($pdo);
            $images = $customFieldService->enrichImagesWithCustomFields($images, (int)$album['id']);
            $albumCustomFields = $customFieldService->getAlbumMetadata((int)$album['id']);
        } catch (\Throwable) {
            // Custom fields tables may not exist yet
            $albumCustomFields = [];
        }

        // Compute unique filter options for Twig (avoid unsupported Twig filters like |unique)
        $processes = [];
        $cameras = [];
        foreach ($images as $img) {
            if (!empty($img['process']) && !in_array($img['process'], $processes, true)) {
                $processes[] = $img['process'];
            }
            $cam = $img['custom_camera'] ?? ($img['exif_display']['camera'] ?? null);
            if ($cam && !in_array($cam, $cameras, true)) {
                $cameras[] = $cam;
            }
        }
        
        // Get related albums (same category, excluding current)
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            WHERE a.category_id = :category_id AND a.id != :album_id AND a.is_published = 1
            ORDER BY a.published_at DESC 
            LIMIT 4
        ');
        $stmt->execute([':category_id' => $album['category_id'], ':album_id' => $album['id']]);
        $relatedAlbums = $stmt->fetchAll();
        
        $relatedAlbums = $this->filterAlbumsByAccess($relatedAlbums, $isAdmin, $nsfwConsent);
        
        // Get template libraries from the resolved template
        $templateLibs = [];
        if ($template && !empty($template['libs'])) {
            $templateLibs = json_decode($template['libs'], true) ?: [];
        }
        
        // Categories for header menu
        $navCategories = (new NavigationService($this->db))->getNavigationCategories();

        // Determine if album is protected (requires session access validation)
        $isProtectedAlbum = !empty($album['password_hash']) || !empty($album['is_nsfw']);

        // Enrich images with metadata and build PhotoSwipe-compatible data
        foreach ($images as &$image) {
            // Choose best public variant for both grid and lightbox (largest available)
            $bestUrl = $image['original_path'];
            // Default lightbox URL; replaced below with largest available variant
            $lightboxUrl = $bestUrl;
            try {
                // Grid: prefer largest public variant (format priority avif > webp > jpg)
                $vg = $pdo->prepare("SELECT path, width, height, variant, format FROM image_variants
                    WHERE image_id = :id AND path NOT LIKE '/storage/%'
                    ORDER BY CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END, width DESC LIMIT 1");
                $vg->execute([':id' => $image['id']]);
                if ($vgr = $vg->fetch()) {
                    if (!empty($vgr['path'])) {
                        // For protected albums, use protected media URL instead of direct path
                        if ($isProtectedAlbum && !$isAdmin) {
                            $bestUrl = '/media/protected/' . $image['id'] . '/' . $vgr['variant'] . '.' . $vgr['format'];
                        } else {
                            $bestUrl = $vgr['path'];
                        }
                    }
                }

                // Lightbox: for protected albums, use protected original URL
                $chosen = $this->selectBestLightboxVariant((array)($image['variants'] ?? []));
                if ($chosen !== null) {
                    if ($isProtectedAlbum && !$isAdmin) {
                        $lightboxUrl = '/media/protected/' . $image['id'] . '/' . $chosen['variant'] . '.' . $chosen['format'];
                    } else {
                        $lightboxUrl = $chosen['path'];
                    }
                } else {
                    $lightboxUrl = $bestUrl;
                    // Always protect /storage/ paths for protected albums, regardless of allow_downloads
                    if ($isProtectedAlbum && !$isAdmin && (empty($bestUrl) || str_starts_with((string)$bestUrl, '/storage/'))) {
                        $lightboxUrl = '/media/protected/' . $image['id'] . '/original';
                    }
                }
            } catch (\Throwable $e) {
                Logger::warning('PageController: Error fetching image variants', ['image_id' => $image['id'] ?? null, 'error' => $e->getMessage()], 'frontend');
            }
            // Final fallback: if still pointing to /storage, use grid URL
            if (str_starts_with((string)$lightboxUrl, '/storage/')) { $lightboxUrl = $bestUrl; }

            // Build enhanced caption with equipment and location info
            // Use per-image data with album-level fallback for lightbox display
            $equipBits = [];
            $cameraDisp = $image['camera_name'] ?? ($image['custom_camera'] ?? null);
            $lensDisp = $image['lens_name'] ?? ($image['custom_lens'] ?? null);
            $filmDisp = $image['film_name'] ?? ($image['custom_film'] ?? null);
            $developerDisp = $image['developer_name'] ?? null;
            $labDisp = $image['lab_name'] ?? null;
            $locationDisp = $image['location_name'] ?? null;

            // Fallback to album-level equipment if per-image is empty
            if (empty($cameraDisp) && !empty($equipment['cameras'])) {
                $cameraDisp = is_array($equipment['cameras'][0] ?? null)
                    ? ($equipment['cameras'][0]['name'] ?? $equipment['cameras'][0])
                    : ($equipment['cameras'][0] ?? null);
            }
            if (empty($lensDisp) && !empty($equipment['lenses'])) {
                $lensDisp = is_array($equipment['lenses'][0] ?? null)
                    ? ($equipment['lenses'][0]['name'] ?? $equipment['lenses'][0])
                    : ($equipment['lenses'][0] ?? null);
            }
            if (empty($filmDisp) && !empty($equipment['film'])) {
                $filmDisp = is_array($equipment['film'][0] ?? null)
                    ? ($equipment['film'][0]['name'] ?? $equipment['film'][0])
                    : ($equipment['film'][0] ?? null);
            }
            if (empty($developerDisp) && !empty($equipment['developers'])) {
                $developerDisp = is_array($equipment['developers'][0] ?? null)
                    ? ($equipment['developers'][0]['name'] ?? $equipment['developers'][0])
                    : ($equipment['developers'][0] ?? null);
            }
            if (empty($labDisp) && !empty($equipment['labs'])) {
                $labDisp = is_array($equipment['labs'][0] ?? null)
                    ? ($equipment['labs'][0]['name'] ?? $equipment['labs'][0])
                    : ($equipment['labs'][0] ?? null);
            }

            // Store fallback values in image array for template data attributes
            $image['camera_name'] = $cameraDisp;
            $image['lens_name'] = $lensDisp;
            $image['film_name'] = $filmDisp;
            $image['developer_name'] = $developerDisp;
            $image['lab_name'] = $labDisp;
            $image['location_name'] = $locationDisp;
            
            if (!empty($cameraDisp)) { $equipBits[] = '<i class="fa-solid fa-camera mr-1"></i>' . htmlspecialchars((string)$cameraDisp, ENT_QUOTES); }
            if (!empty($lensDisp)) { $equipBits[] = '<i class="fa-solid fa-dot-circle mr-1"></i>' . htmlspecialchars((string)$lensDisp, ENT_QUOTES); }
            if (!empty($filmDisp)) { $equipBits[] = '<i class="fa-solid fa-film mr-1"></i>' . htmlspecialchars((string)$filmDisp, ENT_QUOTES); }
            
            // Add location information to PhotoSwipe caption
            if (!empty($image['location_name'])) { 
                $equipBits[] = '<i class="fa-solid fa-map-marker-alt mr-1"></i>' . htmlspecialchars((string)$image['location_name'], ENT_QUOTES); 
            }
            
            if (!empty($image['developer_name'])) { $equipBits[] = '<i class="fa-solid fa-flask mr-1"></i>' . htmlspecialchars((string)$image['developer_name'], ENT_QUOTES); }
            if (!empty($image['lab_name'])) { $equipBits[] = '<i class="fa-solid fa-industry mr-1"></i>' . htmlspecialchars((string)$image['lab_name'], ENT_QUOTES); }
            if (!empty($image['iso'])) { $equipBits[] = '<i class="fa-solid fa-signal mr-1"></i>ISO ' . (int)$image['iso']; }
            if (!empty($image['shutter_speed'])) { $equipBits[] = '<i class="fa-regular fa-clock mr-1"></i>' . htmlspecialchars((string)$image['shutter_speed'], ENT_QUOTES); }
            if (!empty($image['aperture']) && is_numeric($image['aperture'])) { $equipBits[] = '<i class="fa-solid fa-circle-half-stroke mr-1"></i>f/' . number_format((float)$image['aperture'], 1); }

            // Add custom fields to caption (with show_in_lightbox=1)
            if (!empty($image['custom_fields'])) {
                foreach ($image['custom_fields'] as $field) {
                    if (!empty($field['show_in_lightbox']) && !empty($field['values'])) {
                        $icon = preg_replace('/[^a-zA-Z0-9\-_\s]/', '', (string)($field['icon'] ?? 'fa-tag'));
                        $valuesStr = htmlspecialchars(implode(', ', $field['values']), ENT_QUOTES);
                        $equipBits[] = '<i class="fa-solid ' . $icon . ' mr-1"></i>' . $valuesStr;
                    }
                }
            }

            $captionHtml = '';
            if (!empty($image['caption'])) {
                $captionHtml .= '<div class="mb-2">' . htmlspecialchars((string)$image['caption'], ENT_QUOTES) . '</div>';
            }
            if ($equipBits) {
                $captionHtml .= '<div class="flex flex-wrap gap-x-3 gap-y-1 justify-center text-sm">' . implode(' ', array_map(fn($x)=>'<span class="inline-flex items-center">'.$x.'</span>', $equipBits)) . '</div>';
            }

            // Build responsive sources for <picture>
            $sources = ['avif'=>[], 'webp'=>[], 'jpg'=>[]];
            foreach (($image['variants'] ?? []) as $v) {
                if (!isset($sources[$v['format']])) continue;
                if (!empty($v['path']) && !str_starts_with((string)$v['path'], '/storage/')) {
                    // For protected albums, use protected media URLs
                    if ($isProtectedAlbum && !$isAdmin) {
                        $protectedUrl = '/media/protected/' . $image['id'] . '/' . $v['variant'] . '.' . $v['format'];
                        $sources[$v['format']][] = $protectedUrl . ' ' . (int)$v['width'] . 'w';
                    } else {
                        $sources[$v['format']][] = $v['path'] . ' ' . (int)$v['width'] . 'w';
                    }
                }
            }

            // Update image data for PhotoSwipe compatibility
            $image['url'] = $bestUrl;
            $image['lightbox_url'] = $lightboxUrl;
            $image['caption_html'] = $captionHtml;
            $image['alt'] = $image['alt_text'] ?: $album['title'];
            $image['sources'] = $sources;
            $image['fallback_src'] = $lightboxUrl ?: $bestUrl;
        }

        // Gallery meta mapped from album for consistency with gallery view
        $galleryMeta = [
            'title' => $album['title'],
            'category' => ['name' => $album['category_name'], 'slug' => $album['category_slug']],
            'categories' => $cats,
            'excerpt' => $album['excerpt'] ?? '',
            'slug' => $album['slug'],
            'body' => $album['body'] ?? '',
            'shoot_date' => $album['shoot_date'] ?? '',
            'show_date' => (int)($album['show_date'] ?? 1),
            'tags' => $tags,
            'equipment' => $equipment,
            'allow_downloads' => !empty($album['allow_downloads']),
            'cover' => $album['cover'] ?? null,
        ];

        // Ensure cover is available (for hero layouts and meta image)
        try {
            if (!empty($album['cover_image_id'])) {
                $stmtC = $pdo->prepare('SELECT * FROM images WHERE id = :id');
                $stmtC->execute([':id' => $album['cover_image_id']]);
                $cover = $stmtC->fetch();
                if ($cover) {
                    $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
                    $variantsStmt->execute([':id' => $cover['id']]);
                    $cover['variants'] = $variantsStmt->fetchAll();
                    $album['cover'] = $cover;
                }
            }
        } catch (\Throwable) {}

        // Available templates for switcher (only if allow_template_switch is enabled)
        $availableTemplates = [];
        if (!empty($album['allow_template_switch'])) {
            try {
                $list = $pdo->query('SELECT id, name, slug, settings FROM templates ORDER BY name ASC')->fetchAll() ?: [];
                foreach ($list as &$tpl) { $tpl['settings'] = json_decode($tpl['settings'] ?? '{}', true) ?: []; }
                $availableTemplates = $list;
            } catch (\Throwable) { $availableTemplates = []; }
        }

        // Choose page template (classic, hero, magazine)
        $settingsServiceForPage = new \App\Services\SettingsService($this->db);
        $pageTemplate = (string)($settingsServiceForPage->get('gallery.page_template', 'classic') ?? 'classic');
        $pageTemplate = in_array($pageTemplate, ['classic','hero','magazine'], true) ? $pageTemplate : 'classic';
        // If the selected template is magazine-split, force layout settings to magazine.
        if (($template['slug'] ?? '') === 'magazine-split') {
            $templateSettings['layout'] = 'magazine';
        }
        $twigTemplate = match ($pageTemplate) {
            'hero' => 'frontend/gallery_hero.twig',
            'magazine' => 'frontend/gallery_magazine.twig',
            default => 'frontend/gallery.twig',
        };

        // Get social sharing settings
        $settingsServiceForSocial = new \App\Services\SettingsService($this->db);
        $enabledSocials = $settingsServiceForSocial->get('social.enabled', []);
        if (!is_array($enabledSocials)) {
            $enabledSocials = ['behance', 'whatsapp', 'facebook', 'x', 'deviantart', 'instagram', 'pinterest', 'telegram', 'threads', 'bluesky'];
        }
        
        // Get social order
        $socialOrder = $settingsServiceForSocial->get('social.order', []);
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

        // Use selected page template
        // SEO for album page: use album title and excerpt; cover image as OG
        $coverPath = null;
        try { $coverPath = $album['cover']['variants'][0]['path'] ?? null; } catch (\Throwable) {}
        $seoMeta = $this->buildSeo($request, (string)$album['title'], (string)($album['excerpt'] ?? ''), $coverPath);

        // Compute album-specific robots directive from album fields (default both to true if null)
        // SECURITY: Force noindex for NSFW albums to prevent search engine indexing of adult content
        $isNsfwAlbum = !empty($album['is_nsfw']);
        $robotsIndex = $isNsfwAlbum ? 'noindex' : (($album['robots_index'] ?? 1) ? 'index' : 'noindex');
        $robotsFollow = $isNsfwAlbum ? 'nofollow' : (($album['robots_follow'] ?? 1) ? 'follow' : 'nofollow');
        $albumRobots = $robotsIndex . ',' . $robotsFollow;

        return $this->view->render($response, $twigTemplate, [
            'album' => $galleryMeta,
            'images' => $images,
            'template_name' => $template['name'],
            'template_settings' => $templateSettings,
            'available_templates' => $availableTemplates,
            'current_template_id' => $templateId,
            'album_ref' => $albumRef,
            'is_admin' => $isAdmin,
            'categories' => $navCategories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => $seoMeta['page_title'],
            'meta_description' => $seoMeta['meta_description'],
            'meta_image' => $seoMeta['meta_image'],
            'current_url' => $seoMeta['current_url'],
            'canonical_url' => $seoMeta['canonical_url'],
            'og_site_name' => $seoMeta['og_site_name'],
            'robots' => $albumRobots,
            'schema' => $seoMeta['schema'],
            'enabled_socials' => $orderedSocials,
            'available_socials' => $availableSocials,
            'csrf' => $_SESSION['csrf'] ?? '',
            'current_album' => ['id' => (int)$album['id']],
            'nsfw_consent' => $this->hasNsfwConsent(),
            'allow_downloads' => !empty($album['allow_downloads']),
            'album_custom_fields' => $albumCustomFields
        ]);
    }

    public function unlockAlbum(Request $request, Response $response, array $args): Response
    {
        $slug = (string)($args['slug'] ?? '');
        if (!$this->validateCsrf($request)) {
            return $response
                ->withHeader('Location', $this->redirect('/album/' . $slug . '?error=1'))
                ->withStatus(302);
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, password_hash, is_nsfw FROM albums WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $album = $stmt->fetch();
        if (!$album || empty($album['password_hash'])) {
            return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
        }
        $data = (array)($request->getParsedBody() ?? []);
        $password = (string)($data['password'] ?? '');

        // Verify password FIRST to avoid information disclosure about NSFW status
        if ($password !== '' && password_verify($password, (string)$album['password_hash'])) {
            // Password is correct - now check NSFW confirmation if needed
            $isNsfw = !empty($album['is_nsfw']);
            $nsfwConfirmed = !empty($data['nsfw_confirmed']);

            if ($isNsfw && !$nsfwConfirmed) {
                // NSFW confirmation required but not provided (only after valid password)
                return $response->withHeader('Location', $this->redirect('/album/' . $slug . '?error=nsfw'))->withStatus(302);
            }

            // SECURITY: Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            $this->grantAlbumPasswordAccess((int)$album['id']);

            // Store NSFW confirmation in session + cookie for server-side validation
            if ($isNsfw) {
                $this->grantNsfwConsent((int)$album['id']);
            }

            $sessionId = trim((string)($data['analytics_session_id'] ?? ''));
            if ($sessionId !== '') {
                $isValidSessionId = preg_match('/^sess_[0-9]{10,20}_[a-z0-9]+$/', $sessionId) === 1;
                if (!$isValidSessionId || strlen($sessionId) > 128) {
                    $sessionId = '';
                }
            }
            if ($sessionId !== '') {
                $analytics = new AnalyticsService($pdo);
                $analytics->trackEvent([
                    'session_id' => $sessionId,
                    'event_type' => 'album_password_unlock',
                    'event_category' => 'album',
                    'event_action' => 'password_unlock',
                    'event_label' => $slug,
                    'page_url' => '/album/' . $slug,
                    'album_id' => (int)$album['id'],
                ]);
            }

            return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
        }
        return $response->withHeader('Location', $this->redirect('/album/' . $slug . '?error=1'))->withStatus(302);
    }

    /**
     * Confirm NSFW age verification for albums without password protection.
     * Stores confirmation in session per-album.
     */
    public function confirmNsfw(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();

        // Validate CSRF token
        if (!$this->validateCsrf($request)) {
            return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
        }

        // Get album
        $stmt = $pdo->prepare('SELECT id, is_nsfw, password_hash FROM albums WHERE slug = :slug AND is_published = 1');
        $stmt->execute([':slug' => $slug]);
        $album = $stmt->fetch();

        if (!$album) {
            return $response->withHeader('Location', $this->redirect('/galleries'))->withStatus(302);
        }

        // Only allow NSFW confirmation for albums without password
        // (password-protected albums handle NSFW through unlock flow)
        if (!empty($album['password_hash'])) {
            return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
        }

        // Check if NSFW confirmation checkbox was checked
        $data = (array)$request->getParsedBody();
        $nsfwConfirmed = !empty($data['nsfw_confirmed']);

        if (!$nsfwConfirmed) {
            // Redirect back to album (will show gate again)
            return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
        }

        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        // Store NSFW confirmation in session for server-side validation (per-album)
        $this->grantNsfwConsent((int)$album['id']);

        return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
    }

    /**
     * Confirm global NSFW age verification (no album context).
     */
    public function confirmNsfwGlobal(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $response->withStatus(403);
        }

        $data = (array)$request->getParsedBody();
        $nsfwConfirmed = !empty($data['nsfw_confirmed']);
        if (!$nsfwConfirmed) {
            return $response->withStatus(400);
        }

        session_regenerate_id(true);
        $this->grantNsfwConsent(null);

        return $response->withStatus(204);
    }

    public function albumTemplate(Request $request, Response $response, array $args): Response
    {
        try {
            // Ensure session is started for album access checks
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $slug = $args['slug'] ?? '';
            $params = $request->getQueryParams();
            $templateId = isset($params['template']) ? (int)$params['template'] : null;

            if (!$templateId || !$slug) {
                $response->getBody()->write('Template or album parameter missing');
                return $response->withStatus(400);
            }

            $pdo = $this->db->pdo();

            // Resolve album (published only)
            $stmt = $pdo->prepare('SELECT a.*, a.template_id, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.slug = :slug AND a.is_published = 1');
            $stmt->execute([':slug' => $slug]);
            
            $album = $stmt->fetch();
            if (!$album) {
                $response->getBody()->write('Album not found');
                return $response->withStatus(404);
            }

            // Check if user is admin (admins bypass password protection)
            $isAdmin = $this->isAdmin();

            if (!empty($album['password_hash']) && !$isAdmin) {
                $allowed = $this->hasAlbumPasswordAccess((int)$album['id']);
                if (!$allowed) {
                    $response->getBody()->write('Album locked');
                    return $response->withStatus(403);
                }
            }

            // NSFW server-side enforcement: block API access for unconfirmed NSFW albums
            $isNsfw = !empty($album['is_nsfw']);
            if ($isNsfw && !$isAdmin) {
                $nsfwConfirmed = $this->hasNsfwAlbumConsent((int)$album['id']);
                if (!$nsfwConfirmed) {
                    $response->getBody()->write('Age verification required');
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
            if (($template['slug'] ?? '') === 'magazine-split') {
                $templateSettings['layout'] = 'magazine';
            }

            // Images with per-photo metadata
            $imgStmt = $pdo->prepare('SELECT * FROM images WHERE album_id = :id ORDER BY sort_order ASC, id ASC');
            $imgStmt->execute([':id' => $album['id']]);
            $imagesRows = $imgStmt->fetchAll() ?: [];

            // Enrich images with metadata from related tables
            \App\Services\ImagesService::enrichWithMetadata($pdo, $imagesRows, 'frontend');

            // Determine if album is protected (password or NSFW)
            $isProtectedAlbum = !empty($album['password_hash']) || $isNsfw;

            // Build gallery items for the template
            $images = [];
            foreach ($imagesRows as $img) {
                $bestUrl = $img['original_path'];
                $lightboxUrl = $img['original_path'];
                $sources = ['avif' => [], 'webp' => [], 'jpg' => []];

                try {
                    $v = $pdo->prepare("SELECT path, width, height, variant, format FROM image_variants WHERE image_id = :id AND format='jpg' ORDER BY CASE variant WHEN 'lg' THEN 1 WHEN 'md' THEN 2 WHEN 'sm' THEN 3 ELSE 9 END LIMIT 1");
                    $v->execute([':id' => $img['id']]);
                    $vr = $v->fetch();
                    if ($vr && !empty($vr['path'])) {
                        // For protected albums, use protected media URL
                        if ($isProtectedAlbum && !$isAdmin) {
                            $bestUrl = '/media/protected/' . $img['id'] . '/' . $vr['variant'] . '.' . $vr['format'];
                        } else {
                            $bestUrl = $vr['path'];
                        }
                    }

                    $variantsStmt = $pdo->prepare("SELECT variant, format, path, width FROM image_variants WHERE image_id = :id AND path NOT LIKE '/storage/%'");
                    $variantsStmt->execute([':id' => $img['id']]);
                    $variants = $variantsStmt->fetchAll() ?: [];

                    $chosen = $this->selectBestLightboxVariant($variants);
                    if ($chosen !== null) {
                        if ($isProtectedAlbum && !$isAdmin) {
                            $lightboxUrl = '/media/protected/' . $img['id'] . '/' . $chosen['variant'] . '.' . $chosen['format'];
                        } else {
                            $lightboxUrl = $chosen['path'];
                        }
                    } else {
                        $lightboxUrl = $bestUrl;
                        // Always protect /storage/ paths for protected albums, regardless of allow_downloads
                        if ($isProtectedAlbum && !$isAdmin && (empty($bestUrl) || str_starts_with((string)$bestUrl, '/storage/'))) {
                            $lightboxUrl = '/media/protected/' . $img['id'] . '/original';
                        }
                    }

                    foreach ($variants as $variant) {
                        $fmt = $variant['format'] ?? null;
                        if (!isset($sources[$fmt])) {
                            continue;
                        }
                        $srcPath = $variant['path'] ?? '';
                        if ($srcPath === '') {
                            continue;
                        }
                        if ($isProtectedAlbum && !$isAdmin) {
                            $srcPath = '/media/protected/' . $img['id'] . '/' . $variant['variant'] . '.' . $variant['format'];
                        }
                        $sources[$fmt][] = $srcPath . ' ' . (int)($variant['width'] ?? 0) . 'w';
                    }
                } catch (\Throwable $e) {
                    Logger::warning('PageController: Error fetching image variants', ['image_id' => $img['id'] ?? null, 'error' => $e->getMessage()], 'frontend');
                }

                if (str_starts_with((string)$bestUrl, '/storage/')) {
                    $bestUrl = $img['original_path'];
                }
                if (str_starts_with((string)$lightboxUrl, '/storage/')) {
                    $lightboxUrl = $bestUrl;
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
                    'film_display' => $img['film_display'] ?? ($img['film_name'] ?? ''),
                    'developer_name' => $img['developer_name'] ?? '',
                    'lab_name' => $img['lab_name'] ?? '',
                    'location_name' => $img['location_name'] ?? '',
                    'iso' => isset($img['iso']) ? (int)$img['iso'] : null,
                    'shutter_speed' => $img['shutter_speed'] ?? null,
                    'aperture' => isset($img['aperture']) && is_numeric($img['aperture']) ? (float)$img['aperture'] : null,
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
                    'sources' => $sources,
                    'fallback_src' => $lightboxUrl ?: $bestUrl,
                    'variants' => []
                ];
            }

            // Render only the gallery part (not the full page)
            $partial = 'frontend/_gallery_content.twig';
            try {
                if (($template['slug'] ?? '') === 'magazine-split') {
                    $partial = 'frontend/_gallery_magazine_content.twig';
                }
            } catch (\Throwable) {}
            return $this->view->render($response, $partial, [
                'images' => $images,
                'template_settings' => $templateSettings,
                'album' => [
                    'title' => $album['title'] ?? '',
                    'excerpt' => $album['excerpt'] ?? '',
                    'allow_downloads' => !empty($album['allow_downloads'])
                ],
                'base_path' => $this->basePath
            ]);
            
        } catch (\Throwable $e) {
            Logger::critical('PageController::albumTemplate error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 'frontend');

            $response->getBody()->write('Internal server error');
            return $response->withStatus(500);
        }
    }

    public function category(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();
        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();
        
        // Get category
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $category = $stmt->fetch();
        
        if (!$category) {
            return $response->withStatus(404);
        }
        
        // Get albums in category
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            WHERE c.slug = :slug AND a.is_published = 1
            ORDER BY a.published_at DESC
        ');
        $stmt->execute([':slug' => $slug]);
        $albums = $stmt->fetchAll();
        
        $albums = $this->filterAlbumsByAccess($albums, $isAdmin, $nsfwConsent);
        
        // Get all categories for navigation
        $stmt = $pdo->prepare('SELECT * FROM categories ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, name ASC');
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        $seo = $this->buildSeo($request, (string)$category['name'], 'Photography albums in category: ' . $category['name']);
        return $this->view->render($response, 'frontend/category.twig', [
            'category' => $category,
            'albums' => $albums,
            'categories' => $categories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => $seo['page_title'],
            'meta_description' => $seo['meta_description'],
            'meta_image' => $seo['meta_image'],
            'current_url' => $seo['current_url'],
            'canonical_url' => $seo['canonical_url'],
            'og_site_name' => $seo['og_site_name'],
            'robots' => $seo['robots'],
            'nsfw_consent' => $nsfwConsent,
            'is_admin' => $isAdmin
        ]);
    }

    public function tag(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();
        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();
        
        // Get tag
        $stmt = $pdo->prepare('SELECT * FROM tags WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $tag = $stmt->fetch();
        
        if (!$tag) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Tag not found',
                'meta_description' => 'Tag not found'
            ]);
        }
        
        // Get albums with this tag
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            JOIN album_tag at ON at.album_id = a.id
            JOIN tags t ON t.id = at.tag_id
            WHERE t.slug = :slug AND a.is_published = 1
            ORDER BY a.published_at DESC
        ');
        $stmt->execute([':slug' => $slug]);
        $albums = $stmt->fetchAll();
        
        $albums = $this->filterAlbumsByAccess($albums, $isAdmin, $nsfwConsent);
        
        // Get popular tags for navigation
        $stmt = $pdo->prepare('
            SELECT t.*, COUNT(at.album_id) as albums_count
            FROM tags t 
            JOIN album_tag at ON at.tag_id = t.id
            JOIN albums a ON a.id = at.album_id AND a.is_published = 1
            GROUP BY t.id 
            ORDER BY albums_count DESC, t.name ASC 
            LIMIT 30
        ');
        $stmt->execute();
        $tags = $stmt->fetchAll();
        
        // Categories for header menu
        $navCategories = (new NavigationService($this->db))->getNavigationCategories();

        $seo = $this->buildSeo($request, '#' . $tag['name'], 'Photography albums tagged with: ' . $tag['name']);
        return $this->view->render($response, 'frontend/tag.twig', [
            'tag' => $tag,
            'albums' => $albums,
            'tags' => $tags,
            'categories' => $navCategories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => $seo['page_title'],
            'meta_description' => $seo['meta_description'],
            'meta_image' => $seo['meta_image'],
            'current_url' => $seo['current_url'],
            'canonical_url' => $seo['canonical_url'],
            'og_site_name' => $seo['og_site_name'],
            'robots' => $seo['robots'],
            'nsfw_consent' => $nsfwConsent,
            'is_admin' => $isAdmin
        ]);
    }

    public function about(Request $request, Response $response): Response
    {
        // categories for header
        $navCategories = (new NavigationService($this->db))->getNavigationCategories();
        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();

        // settings
        $settings = new \App\Services\SettingsService($this->db);
        $aboutText = (string)($settings->get('about.text', '') ?? '');
        $aboutPhoto = (string)($settings->get('about.photo_url', '') ?? '');
        $aboutFooter = (string)($settings->get('about.footer_text', '') ?? '');
        $aboutSocials = (array)($settings->get('about.socials', []) ?? []);
        $aboutTitle = (string)($settings->get('about.title', 'About') ?? 'About');
        $aboutSubtitle = (string)($settings->get('about.subtitle', '') ?? '');
        $contactTitle = (string)($settings->get('about.contact_title', 'Contatti') ?? 'Contatti');
        $contactIntro = (string)($settings->get('about.contact_intro', '') ?? '');

        // reCAPTCHA settings
        $recaptchaEnabled = (bool)($settings->get('recaptcha.enabled', false) ?? false);
        $recaptchaSiteKey = (string)($settings->get('recaptcha.site_key', '') ?? '');

        $q = $request->getQueryParams();
        $contactSent = isset($q['sent']);
        $contactError = isset($q['error']);

        // About SEO: use about text trimmed, and about photo as OG; fallback to logo handled in builder
        $shortAbout = trim(strip_tags($aboutText));
        if ($shortAbout !== '') { $shortAbout = mb_substr($shortAbout, 0, 160); }
        $seo = $this->buildSeo($request, $aboutTitle, $shortAbout, $aboutPhoto ?: null);
        return $this->view->render($response, 'frontend/about.twig', [
            'categories' => $navCategories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => $seo['page_title'],
            'meta_description' => $seo['meta_description'],
            'meta_image' => $seo['meta_image'],
            'current_url' => $seo['current_url'],
            'canonical_url' => $seo['canonical_url'],
            'og_site_name' => $seo['og_site_name'],
            'robots' => $seo['robots'],
            'about_text' => $aboutText,
            'about_photo_url' => $aboutPhoto,
            'about_footer_text' => $aboutFooter,
            'about_socials' => $aboutSocials,
            'about_title' => $aboutTitle,
            'about_subtitle' => $aboutSubtitle,
            'contact_title' => $contactTitle,
            'contact_intro' => $contactIntro,
            'contact_sent' => $contactSent,
            'contact_error' => $contactError,
            'recaptcha_enabled' => $recaptchaEnabled,
            'recaptcha_site_key' => $recaptchaSiteKey,
            'csrf' => $_SESSION['csrf'] ?? '',
            'is_admin' => $isAdmin,
            'nsfw_consent' => $nsfwConsent
        ]);
    }

    public function aboutContact(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
        }

        $data = (array)($request->getParsedBody() ?? []);
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
        }

        // reCAPTCHA validation
        $settings = new \App\Services\SettingsService($this->db);
        $recaptchaEnabled = (bool)($settings->get('recaptcha.enabled', false) ?? false);
        $recaptchaSecretKey = (string)($settings->get('recaptcha.secret_key', '') ?? '');

        if ($recaptchaEnabled && $recaptchaSecretKey !== '') {
            $recaptchaToken = trim((string)($data['recaptcha_token'] ?? ''));

            if ($recaptchaToken === '') {
                return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
            }

            // Verify token with Google reCAPTCHA API
            try {
                $recaptcha = new \ReCaptcha\ReCaptcha($recaptchaSecretKey);
                $resp = $recaptcha->setExpectedAction('contact')
                    ->setScoreThreshold(0.5)
                    ->verify($recaptchaToken, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

                if (!$resp->isSuccess()) {
                    \App\Support\Logger::warning('reCAPTCHA verification failed', [
                        'errors' => $resp->getErrorCodes(),
                        'score' => $resp->getScore()
                    ], 'security');
                    return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
                }

                // Check score (v3 returns score 0.0-1.0, higher is more likely human)
                if ($resp->getScore() < 0.5) {
                    \App\Support\Logger::warning('reCAPTCHA score too low', [
                        'score' => $resp->getScore()
                    ], 'security');
                    return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
                }
            } catch (\Throwable $e) {
                \App\Support\Logger::error('reCAPTCHA verification error', [
                    'error' => $e->getMessage()
                ], 'security');
                return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
            }
        }

        $to = (string)($settings->get('about.contact_email', '') ?? '');
        if ($to === '') {
            $to = (string)(\envv('CONTACT_EMAIL', \envv('MAIL_TO', 'webmaster@localhost')));
        }
        $subjectPrefix = (string)($settings->get('about.contact_subject', 'Portfolio') ?? 'Portfolio');

        // Strict header injection prevention
        // Remove ALL control characters (including tabs) from name and subject
        $safeName = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $safeName = mb_substr($safeName, 0, 100); // Limit length

        // Additional email validation - must match the validated email exactly
        // FILTER_VALIDATE_EMAIL already passed, but double-check for header chars
        if (preg_match('/[\x00-\x1F\x7F]/', $email)) {
            return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
        }

        // Encode subject with =?UTF-8?B? to prevent header injection
        $subjectText = '[' . $subjectPrefix . '] Nuovo messaggio da ' . $safeName;
        $subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';

        $body = "Nome: {$safeName}\nEmail: {$email}\n\nMessaggio:\n{$message}\n";

        // SECURITY: Use system email as From, user email only in Reply-To
        // This prevents email spoofing and header injection via From header
        $systemFrom = (string)(\envv('MAIL_FROM', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $headers = 'From: ' . $systemFrom . "\r\n" .
                   'Reply-To: ' . $email . "\r\n" .
                   'Content-Type: text/plain; charset=UTF-8';

        @mail($to, $subject, $body, $headers);
        $slug = (string)($settings->get('about.slug', 'about') ?? 'about');
        if ($slug === '') { $slug = 'about'; }
        return $response->withHeader('Location', $this->redirect('/' . $slug . '?sent=1'))->withStatus(302);
    }

    private function enrichAlbum(array $album): array
    {
        $pdo = $this->db->pdo();
        
        // Cover image
        if ($album['cover_image_id']) {
            $stmt = $pdo->prepare('SELECT * FROM images WHERE id = :id');
            $stmt->execute([':id' => $album['cover_image_id']]);
            $cover = $stmt->fetch();
            
            if ($cover) {
                $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
                $variantsStmt->execute([':id' => $cover['id']]);
                $cover['variants'] = $variantsStmt->fetchAll();
                $album['cover'] = $cover;
            }
        }
        
        // Tags
        $stmt = $pdo->prepare('
            SELECT t.* FROM tags t 
            JOIN album_tag at ON at.tag_id = t.id 
            WHERE at.album_id = :id 
            ORDER BY t.name ASC
        ');
        $stmt->execute([':id' => $album['id']]);
        $album['tags'] = $stmt->fetchAll();
        // Locations (if present)
        try {
            $locStmt = $pdo->prepare('SELECT l.id, l.name, l.slug FROM album_location al JOIN locations l ON l.id = al.location_id WHERE al.album_id = :id ORDER BY l.name');
            $locStmt->execute([':id' => $album['id']]);
            $album['locations'] = $locStmt->fetchAll() ?: [];
        } catch (\Throwable) {
            $album['locations'] = [];
        }
        
        // Images count
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE album_id = :id');
        $stmt->execute([':id' => $album['id']]);
        $album['images_count'] = (int)$stmt->fetchColumn();
        
        return $album;
    }

    private function filterAlbumsByAccess(array $albums, bool $isAdmin, bool $nsfwConsent): array
    {
        $visibleAlbums = [];
        foreach ($albums as $album) {
            $album = $this->enrichAlbum($album);
            if (!$isAdmin && !empty($album['password_hash']) && !$this->hasAlbumPasswordAccess((int)$album['id'])) {
                continue;
            }
            $album = $this->sanitizeAlbumCoverForNsfw($album, $isAdmin, $nsfwConsent);
            $visibleAlbums[] = $album;
        }
        return $visibleAlbums;
    }

    private function selectBestLightboxVariant(array $variants): ?array
    {
        if ($variants === []) {
            return null;
        }

        $orderVariant = ['xxl' => 1, 'xl' => 2, 'lg' => 3, 'md' => 4, 'sm' => 5];
        $orderFormat = ['avif' => 1, 'webp' => 2, 'jpg' => 3, 'jpeg' => 3, 'png' => 4];
        $chosen = null;

        foreach ($variants as $variant) {
            if (!isset($variant['path']) || str_starts_with((string)$variant['path'], '/storage/')) {
                continue;
            }
            $vVar = strtolower((string)($variant['variant'] ?? ''));
            $vFmt = strtolower((string)($variant['format'] ?? ''));
            $width = (int)($variant['width'] ?? 0);
            $formatRank = $orderFormat[$vFmt] ?? 9;
            $variantRank = $orderVariant[$vVar] ?? 9;

            if ($chosen === null) {
                $chosen = [
                    'width' => $width,
                    'format_rank' => $formatRank,
                    'variant_rank' => $variantRank,
                    'variant' => $vVar,
                    'format' => $vFmt,
                    'path' => $variant['path']
                ];
                continue;
            }

            if ($width > $chosen['width']) {
                $chosen = [
                    'width' => $width,
                    'format_rank' => $formatRank,
                    'variant_rank' => $variantRank,
                    'variant' => $vVar,
                    'format' => $vFmt,
                    'path' => $variant['path']
                ];
                continue;
            }

            if ($width === $chosen['width']) {
                if ($formatRank < $chosen['format_rank']
                    || ($formatRank === $chosen['format_rank'] && $variantRank < $chosen['variant_rank'])
                ) {
                    $chosen = [
                        'width' => $width,
                        'format_rank' => $formatRank,
                        'variant_rank' => $variantRank,
                        'variant' => $vVar,
                        'format' => $vFmt,
                        'path' => $variant['path']
                    ];
                }
            }
        }

        return $chosen;
    }

    private function formatExifForDisplay(array $exif, array $image): array
    {
        $display = [];
        
        // Camera
        if (!empty($exif['Make']) && !empty($exif['Model'])) {
            $display['camera'] = trim($exif['Make'] . ' ' . $exif['Model']);
        } elseif ($image['custom_camera']) {
            $display['camera'] = $image['custom_camera'];
        }
        
        // Lens
        if (!empty($exif['LensModel'])) {
            $display['lens'] = $exif['LensModel'];
        } elseif ($image['custom_lens']) {
            $display['lens'] = $image['custom_lens'];
        }
        
        // Exposure
        if ($image['aperture']) {
            $display['aperture'] = 'f/' . number_format($image['aperture'], 1);
        }
        
        if ($image['shutter_speed']) {
            $display['shutter'] = $this->formatShutterSpeed($image['shutter_speed']);
        }
        
        if ($image['iso']) {
            $display['iso'] = 'ISO ' . $image['iso'];
        }
        
        // Film/Process
        if ($image['custom_film']) {
            $display['film'] = $image['custom_film'];
        }
        
        if ($image['process']) {
            $display['process'] = ucfirst($image['process']);
        }
        
        return $display;
    }

    private function formatShutterSpeed(string $speed): string
    {
        if (strpos($speed, '/') !== false) {
            return $speed;
        }
        
        $f = (float)$speed;
        if ($f >= 1) {
            return (int)$f . 's';
        } else {
            return '1/' . (int)round(1 / $f);
        }
    }

    private function processImageSources(array $image): array
    {
        $pdo = $this->db->pdo();
        
        // Get image variants for responsive sources
        try {
            $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
            $variantsStmt->execute([':id' => $image['id']]);
            $variants = $variantsStmt->fetchAll();
            
            // Build responsive sources (verify files exist, fallback to webp if jpg missing)
            // Go up 3 levels: Controllers/Frontend -> Controllers -> app -> project root
            $publicDir = dirname(__DIR__, 3) . '/public';
            $sources = ['avif' => [], 'webp' => [], 'jpg' => []];
            foreach ($variants as $variant) {
                $format = $variant['format'] ?? 'jpg';
                $path = $variant['path'] ?? '';
                if (isset($sources[$format]) && !empty($path) && !str_starts_with($path, '/storage/')) {
                    // Check if file exists
                    if (is_file($publicDir . $path)) {
                        $sources[$format][] = $path . ' ' . (int)$variant['width'] . 'w';
                    } elseif ($format === 'jpg') {
                        // Try webp alternative if jpg doesn't exist
                        $webpPath = preg_replace('/\.jpg$/i', '.webp', $path);
                        if (is_file($publicDir . $webpPath)) {
                            $sources['webp'][] = $webpPath . ' ' . (int)$variant['width'] . 'w';
                        }
                    }
                }
            }
            
            $image['sources'] = $sources;
            $image['variants'] = $variants;

            // Set fallback URL (best available variant that exists on disk)
            $fallbackUrl = $image['original_path'];

            // First try to find an existing variant
            foreach ($variants as $variant) {
                if (!empty($variant['path']) && !str_starts_with($variant['path'], '/storage/')) {
                    $fullPath = $publicDir . $variant['path'];
                    if (is_file($fullPath)) {
                        $fallbackUrl = $variant['path'];
                        break;
                    }
                    // Try webp alternative if jpg doesn't exist
                    if (($variant['format'] ?? '') === 'jpg') {
                        $webpPath = preg_replace('/\.jpg$/i', '.webp', $variant['path']);
                        if (is_file($publicDir . $webpPath)) {
                            $fallbackUrl = $webpPath;
                            break;
                        }
                    }
                }
            }
            $image['fallback_src'] = $fallbackUrl;
            
        } catch (\Throwable $e) {
            Logger::warning('PageController: Error processing image sources', ['image_id' => $image['id'] ?? null, 'error' => $e->getMessage()], 'frontend');
            // Fallback to basic image data
            $image['sources'] = ['avif' => [], 'webp' => [], 'jpg' => []];
            $image['variants'] = [];
            $image['fallback_src'] = $image['original_path'];
        }
        
        return $image;
    }
    
    /**
     * Get parent categories with album counts for navigation
     */
    private function getParentCategoriesForNavigation(): array
    {
        $pdo = $this->db->pdo();

        // Get parent categories with album counts (using album_category junction table)
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
            $childStmt = $pdo->prepare('
                SELECT c.*, COUNT(DISTINCT a.id) as albums_count
                FROM categories c
                LEFT JOIN album_category ac ON ac.category_id = c.id
                LEFT JOIN albums a ON a.id = ac.album_id AND a.is_published = 1
                WHERE c.parent_id = :parent_id
                GROUP BY c.id
                ORDER BY c.sort_order ASC, c.name ASC
            ');
            $childStmt->execute([':parent_id' => $parent['id']]);
            $parent['children'] = $childStmt->fetchAll();
        }

        return $parents;
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

    /**
     * Truncate string at word boundary for PWA short_name
     */
    private function truncateShortName(string $name, int $maxLength = 12): string
    {
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        // Find last space within limit
        $truncated = mb_substr($name, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            return mb_substr($name, 0, $lastSpace);
        }

        // No space found, fall back to hard truncation
        return $truncated;
    }

    /**
     * Generate dynamic web manifest for PWA
     */
    public function webManifest(Request $request, Response $response): Response
    {
        $svc = new \App\Services\SettingsService($this->db);

        $siteName = (string)($svc->get('seo.site_title', 'Photography Portfolio') ?? 'Photography Portfolio');
        $siteDescription = (string)($svc->get('seo.site_description', '') ?? '');
        $themeColor = (string)($svc->get('pwa.theme_color', '#ffffff') ?? '#ffffff');
        $backgroundColor = (string)($svc->get('pwa.background_color', '#ffffff') ?? '#ffffff');
        $basePath = $this->basePath ?: '';

        $manifest = [
            'name' => $siteName,
            'short_name' => $this->truncateShortName($siteName),
            'description' => $siteDescription,
            'icons' => [
                [
                    'src' => $basePath . '/android-chrome-192x192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ],
                [
                    'src' => $basePath . '/android-chrome-512x512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ]
            ],
            'start_url' => $basePath . '/',
            'scope' => $basePath . '/',
            'display' => 'standalone',
            'theme_color' => $themeColor,
            'background_color' => $backgroundColor
        ];

        $response->getBody()->write(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/manifest+json')
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
