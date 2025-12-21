<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\ImagesService;
use App\Services\SettingsService;
use App\Support\Database;
use finfo;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PagesController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $settings = new SettingsService($this->db);
        $aboutSlug = (string)($settings->get('about.slug', 'about') ?? 'about');
        if ($aboutSlug === '') { $aboutSlug = 'about'; }
        $galleriesSlug = (string)($settings->get('galleries.slug', 'galleries') ?? 'galleries');
        if ($galleriesSlug === '') { $galleriesSlug = 'galleries'; }
        
        $pages = [
            [
                'slug' => 'home',
                'title' => 'Home',
                'description' => 'Homepage: hero section, gallery and albums carousel',
                'edit_url' => '/admin/pages/home',
                'public_url' => '/',
            ],
            [
                'slug' => 'about',
                'title' => 'About',
                'description' => 'About page: bio, photo, social links, contacts',
                'edit_url' => '/admin/pages/about',
                'public_url' => '/' . $aboutSlug,
            ],
            [
                'slug' => 'galleries',
                'title' => 'Galleries',
                'description' => 'Galleries page with advanced filters and text management',
                'edit_url' => '/admin/pages/galleries',
                'public_url' => '/' . $galleriesSlug,
            ],
        ];
        return $this->view->render($response, 'admin/pages/index.twig', [
            'pages' => $pages,
        ]);
    }

    public function homeForm(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);
        $settings = [
            'home.template' => (string)($svc->get('home.template', 'classic') ?? 'classic'),
            'home.hero_title' => (string)($svc->get('home.hero_title', 'Portfolio') ?? 'Portfolio'),
            'home.hero_subtitle' => (string)($svc->get('home.hero_subtitle', 'A collection of analog and digital photography exploring light, form, and the beauty of everyday moments.') ?? 'A collection of analog and digital photography exploring light, form, and the beauty of everyday moments.'),
            'home.albums_title' => (string)($svc->get('home.albums_title', 'Latest Albums') ?? 'Latest Albums'),
            'home.albums_subtitle' => (string)($svc->get('home.albums_subtitle', 'Discover my recent photographic work, from analog experiments to digital explorations.') ?? 'Discover my recent photographic work, from analog experiments to digital explorations.'),
            'home.empty_title' => (string)($svc->get('home.empty_title', 'No albums yet') ?? 'No albums yet'),
            'home.empty_text' => (string)($svc->get('home.empty_text', 'Check back soon for new work.') ?? 'Check back soon for new work.'),
            'home.gallery_scroll_direction' => (string)($svc->get('home.gallery_scroll_direction', 'vertical') ?? 'vertical'),
            'home.gallery_text_title' => (string)($svc->get('home.gallery_text_title', '') ?? ''),
            'home.gallery_text_content' => (string)($svc->get('home.gallery_text_content', '') ?? ''),
        ];
        return $this->view->render($response, 'admin/pages/home.twig', [
            'settings' => $settings,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function saveHome(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/pages/home'))->withStatus(302);
        }

        $svc = new SettingsService($this->db);

        // Home template selection (classic or modern)
        $homeTemplate = (string)($data['home_template'] ?? 'classic');
        if (!in_array($homeTemplate, ['classic', 'modern'], true)) {
            $homeTemplate = 'classic';
        }
        $svc->set('home.template', $homeTemplate);

        // Hero section
        $svc->set('home.hero_title', trim((string)($data['hero_title'] ?? 'Portfolio')) ?: 'Portfolio');
        $svc->set('home.hero_subtitle', trim((string)($data['hero_subtitle'] ?? '')));

        // Albums carousel section
        $svc->set('home.albums_title', trim((string)($data['albums_title'] ?? 'Latest Albums')) ?: 'Latest Albums');
        $svc->set('home.albums_subtitle', trim((string)($data['albums_subtitle'] ?? '')));

        // Empty state
        $svc->set('home.empty_title', trim((string)($data['empty_title'] ?? 'No albums yet')) ?: 'No albums yet');
        $svc->set('home.empty_text', trim((string)($data['empty_text'] ?? 'Check back soon for new work.')) ?: 'Check back soon for new work.');

        // Gallery scroll direction
        $scrollDirection = (string)($data['gallery_scroll_direction'] ?? 'vertical');
        if (!in_array($scrollDirection, ['vertical', 'horizontal'], true)) {
            $scrollDirection = 'vertical';
        }
        $svc->set('home.gallery_scroll_direction', $scrollDirection);

        // Gallery text section (sanitize HTML content to prevent XSS)
        $svc->set('home.gallery_text_title', trim((string)($data['gallery_text_title'] ?? '')));
        $svc->set('home.gallery_text_content', \App\Support\Sanitizer::html((string)($data['gallery_text_content'] ?? '')));

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Home page saved successfully.'];
        return $response->withHeader('Location', $this->redirect('/admin/pages/home'))->withStatus(302);
    }

    public function aboutForm(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);
        $settings = [
            'about.text' => (string)($svc->get('about.text', '') ?? ''),
            'about.photo_url' => (string)($svc->get('about.photo_url', '') ?? ''),
            'about.title' => (string)($svc->get('about.title', 'About') ?? 'About'),
            'about.subtitle' => (string)($svc->get('about.subtitle', '') ?? ''),
            'about.slug' => (string)($svc->get('about.slug', 'about') ?? 'about'),
            'about.footer_text' => (string)($svc->get('about.footer_text', '') ?? ''),
            'about.contact_email' => (string)($svc->get('about.contact_email', '') ?? ''),
            'about.contact_subject' => (string)($svc->get('about.contact_subject', 'Portfolio') ?? 'Portfolio'),
            'about.contact_title' => (string)($svc->get('about.contact_title', 'Contact') ?? 'Contact'),
            'about.contact_intro' => (string)($svc->get('about.contact_intro', '') ?? ''),
            'about.socials' => (array)($svc->get('about.socials', []) ?? []),
        ];
        return $this->view->render($response, 'admin/pages/about.twig', [
            'settings' => $settings,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function saveAbout(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/pages/about'))->withStatus(302);
        }

        $svc = new SettingsService($this->db);

        $textRaw = (string)($data['about_text'] ?? '');
        $text = \App\Support\Sanitizer::html($textRaw);
        $svc->set('about.text', $text);
        $svc->set('about.title', trim((string)($data['about_title'] ?? 'About')) ?: 'About');
        $svc->set('about.subtitle', trim((string)($data['about_subtitle'] ?? '')));
        // Slug/permalink
        $rawSlug = strtolower(trim((string)($data['about_slug'] ?? 'about')));
        $cleanSlug = preg_replace('/[^a-z0-9\-]+/', '-', $rawSlug ?? 'about');
        $cleanSlug = trim($cleanSlug, '-') ?: 'about';
        $svc->set('about.slug', $cleanSlug);

        // Footer text and contact email/subject
        $footerRaw = (string)($data['about_footer_text'] ?? '');
        $svc->set('about.footer_text', \App\Support\Sanitizer::html($footerRaw));
        $contactEmail = trim((string)($data['contact_email'] ?? ''));
        if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $svc->set('about.contact_email', $contactEmail);
        }
        $contactSubject = trim((string)($data['contact_subject'] ?? 'Portfolio'));
        $svc->set('about.contact_subject', $contactSubject === '' ? 'Portfolio' : $contactSubject);
        $svc->set('about.contact_title', trim((string)($data['contact_title'] ?? 'Contact')) ?: 'Contact');
        $svc->set('about.contact_intro', \App\Support\Sanitizer::html((string)($data['contact_intro'] ?? '')));

        // Social links (only store non-empty valid URLs)
        $allowed = ['instagram','x','facebook','flickr','500px','behance'];
        $socials = [];
        foreach ($allowed as $key) {
            $val = trim((string)($data['social_'.$key] ?? ''));
            if ($val !== '' && filter_var($val, FILTER_VALIDATE_URL)) {
                $socials[$key] = $val;
            }
        }
        $svc->set('about.socials', $socials);

        // Handle optional photo upload
        $files = $request->getUploadedFiles();
        $photo = $files['about_photo'] ?? null;
        if ($photo && $photo->getError() === UPLOAD_ERR_OK) {
            $tmp = $photo->getStream()->getMetadata('uri') ?? '';
            
            // SECURITY: Comprehensive image validation with magic number checking
            if ($this->validateImageUpload($tmp)) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)($fi->file($tmp) ?: '');
                $ext = match ($mime) {
                    'image/jpeg' => '.jpg',
                    'image/png' => '.png',
                    default => null,
                };
                if ($ext) {
                    $hash = sha1_file($tmp) ?: bin2hex(random_bytes(20));
                    $mediaDir = dirname(__DIR__, 3) . '/public/media/about';
                    ImagesService::ensureDir($mediaDir);
                    $dest = $mediaDir . '/' . $hash . $ext;
                    // store original
                    @move_uploaded_file($tmp, $dest) || @rename($tmp, $dest);
                    
                    // Re-validate after move for additional security
                    if ($this->validateImageUpload($dest)) {
                        // also create a resized web version (max 1600px width)
                        $webPath = $mediaDir . '/' . $hash . '_w1600.jpg';
                        ImagesService::generateJpegPreview($dest, $webPath, 1600);
                        $rel = str_replace(dirname(__DIR__, 3) . '/public', '', (file_exists($webPath) ? $webPath : $dest));
                        $svc->set('about.photo_url', $rel);
                    } else {
                        @unlink($dest);
                        $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Invalid image file after upload.'];
                    }
                }
            } else {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Invalid image file or unsupported format.'];
            }
        }

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'About page saved successfully.'];
        return $response->withHeader('Location', $this->redirect('/admin/pages/about'))->withStatus(302);
    }

    public function galleriesForm(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);
        $settings = [
            'galleries.title' => (string)($svc->get('galleries.title', 'All Galleries') ?? 'All Galleries'),
            'galleries.subtitle' => (string)($svc->get('galleries.subtitle', 'Explore our complete collection of photography galleries') ?? 'Explore our complete collection of photography galleries'),
            'galleries.slug' => (string)($svc->get('galleries.slug', 'galleries') ?? 'galleries'),
            'galleries.description' => (string)($svc->get('galleries.description', '') ?? ''),
            'galleries.filter_button_text' => (string)($svc->get('galleries.filter_button_text', 'Filters') ?? 'Filters'),
            'galleries.clear_filters_text' => (string)($svc->get('galleries.clear_filters_text', 'Clear filters') ?? 'Clear filters'),
            'galleries.results_text' => (string)($svc->get('galleries.results_text', 'galleries') ?? 'galleries'),
            'galleries.no_results_title' => (string)($svc->get('galleries.no_results_title', 'No galleries found') ?? 'No galleries found'),
            'galleries.no_results_text' => (string)($svc->get('galleries.no_results_text', 'We couldn\'t find any galleries matching your current filters. Try adjusting your search criteria or clearing all filters.') ?? 'We couldn\'t find any galleries matching your current filters. Try adjusting your search criteria or clearing all filters.'),
            'galleries.view_button_text' => (string)($svc->get('galleries.view_button_text', 'View') ?? 'View'),
            // Global gallery page template (classic, hero, magazine)
            'gallery.page_template' => (string)($svc->get('gallery.page_template', 'classic') ?? 'classic'),
            // Default gallery template id (DB-driven layout)
            'gallery.default_template_id' => $svc->get('gallery.default_template_id'),
        ];
        // Load templates for dropdown (DB)
        $templates = [];
        try {
            $templates = $this->db->pdo()->query('SELECT id, name FROM templates ORDER BY name')->fetchAll();
        } catch (\Throwable) {
            $templates = [];
        }
        
        // Get current filter settings for reference
        $filterSettings = $this->getFilterSettings();
        
        return $this->view->render($response, 'admin/pages/galleries.twig', [
            'settings' => $settings,
            'filter_settings' => $filterSettings,
            'templates' => $templates,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function saveGalleries(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/pages/galleries'))->withStatus(302);
        }

        $svc = new SettingsService($this->db);

        // Save galleries page settings
        $svc->set('galleries.title', trim((string)($data['galleries_title'] ?? 'All Galleries')));
        $svc->set('galleries.subtitle', trim((string)($data['galleries_subtitle'] ?? 'Explore our complete collection of photography galleries')));
        $svc->set('galleries.slug', trim((string)($data['galleries_slug'] ?? 'galleries')));
        $svc->set('galleries.description', trim((string)($data['galleries_description'] ?? '')));
        $svc->set('galleries.filter_button_text', trim((string)($data['filter_button_text'] ?? 'Filters')));
        $svc->set('galleries.clear_filters_text', trim((string)($data['clear_filters_text'] ?? 'Clear filters')));
        $svc->set('galleries.results_text', trim((string)($data['results_text'] ?? 'galleries')));
        $svc->set('galleries.no_results_title', trim((string)($data['no_results_title'] ?? 'No galleries found')));
        $svc->set('galleries.no_results_text', trim((string)($data['no_results_text'] ?? 'We couldn\'t find any galleries matching your current filters.')));
        $svc->set('galleries.view_button_text', trim((string)($data['view_button_text'] ?? 'View')));

        // Save global page template
        $pageTemplate = (string)($data['page_template'] ?? 'classic');
        if (!in_array($pageTemplate, ['classic','hero','magazine'], true)) { $pageTemplate = 'classic'; }
        $svc->set('gallery.page_template', $pageTemplate);

        // Default gallery template selector moved to global Settings page.
        // Intentionally ignore any incoming default_template_id here to keep a single source of truth.

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Galleries page saved successfully.'];
        return $response->withHeader('Location', $this->redirect('/admin/pages/galleries'))->withStatus(302);
    }

    private function getFilterSettings(): array
    {
        $pdo = $this->db->pdo();
        
        try {
            $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM filter_settings ORDER BY sort_order ASC');
            $stmt->execute();
            $rawSettings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
            
            return [
                'enabled' => (bool)($rawSettings['enabled'] ?? true),
                'show_categories' => (bool)($rawSettings['show_categories'] ?? true),
                'show_tags' => (bool)($rawSettings['show_tags'] ?? true),
                'show_cameras' => (bool)($rawSettings['show_cameras'] ?? true),
                'show_lenses' => (bool)($rawSettings['show_lenses'] ?? true),
                'show_films' => (bool)($rawSettings['show_films'] ?? true),
                'show_locations' => (bool)($rawSettings['show_locations'] ?? true),
                'show_year' => (bool)($rawSettings['show_year'] ?? true),
                'grid_columns_desktop' => (int)($rawSettings['grid_columns_desktop'] ?? 3),
                'grid_columns_tablet' => (int)($rawSettings['grid_columns_tablet'] ?? 2),
                'grid_columns_mobile' => (int)($rawSettings['grid_columns_mobile'] ?? 1),
                'grid_gap' => $rawSettings['grid_gap'] ?? 'normal',
                'animation_enabled' => (bool)($rawSettings['animation_enabled'] ?? true),
                'animation_duration' => (float)($rawSettings['animation_duration'] ?? 0.6),
            ];
        } catch (\Exception $e) {
            return [
                'enabled' => true,
                'show_categories' => true,
                'show_tags' => true,
                'show_cameras' => true,
                'show_lenses' => true,
                'show_films' => true,
                'show_locations' => true,
                'show_year' => true,
                'grid_columns_desktop' => 3,
                'grid_columns_tablet' => 2,
                'grid_columns_mobile' => 1,
                'grid_gap' => 'normal',
                'animation_enabled' => true,
                'animation_duration' => 0.6,
            ];
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
        if ($fileSize === false || $fileSize > 5 * 1024 * 1024) { // 5MB limit for about photos
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
        
        $allowedMimes = ['image/jpeg', 'image/png'];
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
            'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"]
        ];
        
        $isValidMagic = false;
        if (isset($magicNumbers[$detectedMime])) {
            foreach ($magicNumbers[$detectedMime] as $signature) {
                if (str_starts_with($fileHeader, $signature)) {
                    $isValidMagic = true;
                    break;
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
        if ($width <= 0 || $height <= 0 || $width > 5000 || $height > 5000) {
            return false;
        }
        
        return true;
    }
}
