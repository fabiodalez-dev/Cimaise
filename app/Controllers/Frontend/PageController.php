<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PageController
{
    public function __construct(private Database $db, private Twig $view) {}

    public function home(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        
        // Get latest published albums
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            WHERE a.is_published = 1 
            ORDER BY a.published_at DESC 
            LIMIT 12
        ');
        $stmt->execute();
        $albums = $stmt->fetchAll();
        
        // Enrich with cover images and tags
        foreach ($albums as &$album) {
            $album = $this->enrichAlbum($album);
        }
        
        // Get categories for navigation with hierarchy
        $stmt = $pdo->prepare('
            SELECT c.*, COUNT(a.id) as albums_count
            FROM categories c 
            LEFT JOIN albums a ON a.category_id = c.id AND a.is_published = 1
            GROUP BY c.id 
            ORDER BY c.sort_order ASC, c.name ASC
        ');
        $stmt->execute();
        $allCategories = $stmt->fetchAll();
        
        // Build hierarchy
        $categories = []; // flat list for backward compatibility
        $parentCategories = $this->buildCategoryHierarchy($allCategories);
        
        foreach ($allCategories as $cat) {
            $categories[] = $cat;
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
        
        return $this->view->render($response, 'frontend/home.twig', [
            'albums' => $albums,
            'categories' => $categories,
            'parent_categories' => $parentCategories,
            'tags' => $tags,
            'page_title' => 'Portfolio',
            'meta_description' => 'Photography portfolio showcasing analog and digital work'
        ]);
    }

    public function album(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
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
        
        // Enrich album data
        $album = $this->enrichAlbum($album);
        
        // Get album images
        $stmt = $pdo->prepare('
            SELECT i.*
            FROM images i
            WHERE i.album_id = :id
            ORDER BY i.sort_order ASC, i.id ASC
        ');
        $stmt->execute([':id' => $album['id']]);
        $images = $stmt->fetchAll();
        
        // Get variants for each image
        foreach ($images as &$image) {
            $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
            $variantsStmt->execute([':id' => $image['id']]);
            $image['variants'] = $variantsStmt->fetchAll();
            
            // Format EXIF for display
            if ($image['exif']) {
                $exif = json_decode($image['exif'], true) ?: [];
                $image['exif_display'] = $this->formatExifForDisplay($exif, $image);
            }

            // Lookup names for developer/lab/film/location if present
            try {
                if (!empty($image['developer_id'])) {
                    $s = $pdo->prepare('SELECT name FROM developers WHERE id = :id');
                    $s->execute([':id' => $image['developer_id']]);
                    $image['developer_name'] = $s->fetchColumn() ?: null;
                }
                if (!empty($image['lab_id'])) {
                    $s = $pdo->prepare('SELECT name FROM labs WHERE id = :id');
                    $s->execute([':id' => $image['lab_id']]);
                    $image['lab_name'] = $s->fetchColumn() ?: null;
                }
                if (!empty($image['film_id'])) {
                    $s = $pdo->prepare('SELECT brand, name FROM films WHERE id = :id');
                    $s->execute([':id' => $image['film_id']]);
                    $fr = $s->fetch();
                    if ($fr) { $image['film_name'] = trim(($fr['brand'] ?? '') . ' ' . ($fr['name'] ?? '')); }
                }
                if (!empty($image['location_id'])) {
                    $s = $pdo->prepare('SELECT name FROM locations WHERE id = :id');
                    $s->execute([':id' => $image['location_id']]);
                    $image['location_name'] = $s->fetchColumn() ?: null;
                }
            } catch (\Throwable) { /* ignore lookup errors */ }
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
        
        foreach ($relatedAlbums as &$related) {
            $related = $this->enrichAlbum($related);
        }
        
        // Process template settings
        $templateSettings = null;
        $templateLibs = [];
        if ($album['template_settings']) {
            $templateSettings = json_decode($album['template_settings'], true) ?: null;
        }
        if ($album['template_libs']) {
            $templateLibs = json_decode($album['template_libs'], true) ?: [];
        }
        
        // Categories for header menu
        $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
        $navStmt->execute();
        $navCategories = $navStmt->fetchAll();

        return $this->view->render($response, 'frontend/album.twig', [
            'album' => $album,
            'images' => $images,
            'related_albums' => $relatedAlbums,
            'template_settings' => $templateSettings,
            'template_libs' => $templateLibs,
            'categories' => $navCategories,
            'processes' => $processes,
            'cameras' => $cameras,
            'page_title' => $album['title'] . ' - Portfolio',
            'meta_description' => $album['excerpt'] ?: 'Photography album: ' . $album['title'],
            'meta_image' => $album['cover']['variants'][0]['path'] ?? null
        ]);
    }

    public function unlockAlbum(Request $request, Response $response, array $args): Response
    {
        $slug = (string)($args['slug'] ?? '');
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM albums WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $album = $stmt->fetch();
        if (!$album || empty($album['password_hash'])) {
            return $response->withHeader('Location', '/album/' . $slug)->withStatus(302);
        }
        $data = (array)($request->getParsedBody() ?? []);
        $password = (string)($data['password'] ?? '');
        if ($password !== '' && password_verify($password, (string)$album['password_hash'])) {
            if (!isset($_SESSION['album_access'])) $_SESSION['album_access'] = [];
            $_SESSION['album_access'][(int)$album['id']] = true;
            return $response->withHeader('Location', '/album/' . $slug)->withStatus(302);
        }
        return $response->withHeader('Location', '/album/' . $slug . '?error=1')->withStatus(302);
    }

    public function category(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();
        
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
        
        foreach ($albums as &$album) {
            $album = $this->enrichAlbum($album);
        }
        
        // Get all categories for navigation
        $stmt = $pdo->prepare('SELECT * FROM categories ORDER BY sort_order ASC, name ASC');
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        return $this->view->render($response, 'frontend/category.twig', [
            'category' => $category,
            'albums' => $albums,
            'categories' => $categories,
            'page_title' => $category['name'] . ' - Portfolio',
            'meta_description' => 'Photography albums in category: ' . $category['name']
        ]);
    }

    public function tag(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();
        
        // Get tag
        $stmt = $pdo->prepare('SELECT * FROM tags WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $tag = $stmt->fetch();
        
        if (!$tag) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Tag non trovato',
                'meta_description' => 'Tag non trovato'
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
        
        foreach ($albums as &$album) {
            $album = $this->enrichAlbum($album);
        }
        
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
        $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
        $navStmt->execute();
        $navCategories = $navStmt->fetchAll();

        return $this->view->render($response, 'frontend/tag.twig', [
            'tag' => $tag,
            'albums' => $albums,
            'tags' => $tags,
            'categories' => $navCategories,
            'page_title' => '#' . $tag['name'] . ' - Portfolio',
            'meta_description' => 'Photography albums tagged with: ' . $tag['name']
        ]);
    }

    public function about(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        // categories for header
        $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
        $navStmt->execute();
        $navCategories = $navStmt->fetchAll();

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

        $q = $request->getQueryParams();
        $contactSent = isset($q['sent']);
        $contactError = isset($q['error']);

        return $this->view->render($response, 'frontend/about.twig', [
            'categories' => $navCategories,
            'page_title' => $aboutTitle . ' — Portfolio',
            'meta_description' => 'About the photographer',
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
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function aboutContact(Request $request, Response $response): Response
    {
        $data = (array)($request->getParsedBody() ?? []);
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            return $response->withHeader('Location', '/about?error=1')->withStatus(302);
        }

        $settings = new \App\Services\SettingsService($this->db);
        $to = (string)($settings->get('about.contact_email', '') ?? '');
        if ($to === '') {
            $to = (string)(\envv('CONTACT_EMAIL', \envv('MAIL_TO', 'webmaster@localhost')));
        }
        $subjectPrefix = (string)($settings->get('about.contact_subject', 'Portfolio') ?? 'Portfolio');
        $subject = '[' . $subjectPrefix . '] Nuovo messaggio da ' . $name;
        // Basic header-safe sanitization
        $safeName = str_replace(["\r","\n"], ' ', $name);
        $safeEmail = str_replace(["\r","\n"], ' ', $email);

        $body = "Nome: {$safeName}\nEmail: {$safeEmail}\n\nMessaggio:\n{$message}\n";
        $headers = 'From: ' . $safeName . ' <' . $safeEmail . '>' . "\r\n" .
                   'Reply-To: ' . $safeEmail . "\r\n" .
                   'Content-Type: text/plain; charset=UTF-8';

        @mail($to, $subject, $body, $headers);
        $settings = new \App\Services\SettingsService($this->db);
        $slug = (string)($settings->get('about.slug', 'about') ?? 'about');
        if ($slug === '') { $slug = 'about'; }
        return $response->withHeader('Location', '/' . $slug . '?sent=1')->withStatus(302);
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
    
    private function buildCategoryHierarchy(array $allCategories): array
    {
        $byParent = [];
        foreach ($allCategories as $cat) {
            $parentId = $cat['parent_id'] ?? 0;
            if (!isset($byParent[$parentId])) {
                $byParent[$parentId] = [];
            }
            $byParent[$parentId][] = $cat;
        }
        
        $parentCategories = $byParent[0] ?? [];
        
        // Add children to parent categories
        foreach ($parentCategories as &$parent) {
            $parent['children'] = $byParent[$parent['id']] ?? [];
        }
        
        return $parentCategories;
    }
}
