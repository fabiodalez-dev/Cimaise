<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\ImagesService;
use App\Services\SettingsService;
use App\Support\Database;
use finfo;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PagesController
{
    public function __construct(private Database $db, private Twig $view) {}

    public function index(Request $request, Response $response): Response
    {
        $settings = new SettingsService($this->db);
        $aboutSlug = (string)($settings->get('about.slug', 'about') ?? 'about');
        if ($aboutSlug === '') { $aboutSlug = 'about'; }
        $galleriesSlug = (string)($settings->get('galleries.slug', 'galleries') ?? 'galleries');
        if ($galleriesSlug === '') { $galleriesSlug = 'galleries'; }
        
        $pages = [
            [
                'slug' => 'about',
                'title' => 'About',
                'description' => 'Pagina di presentazione: bio, foto, social, contatti',
                'edit_url' => '/admin/pages/about',
                'public_url' => '/' . $aboutSlug,
            ],
            [
                'slug' => 'galleries',
                'title' => 'Galleries',
                'description' => 'Pagina gallerie con filtri avanzati e gestione testi',
                'edit_url' => '/admin/pages/galleries',
                'public_url' => '/' . $galleriesSlug,
            ],
        ];
        return $this->view->render($response, 'admin/pages/index.twig', [
            'pages' => $pages,
        ]);
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
            'about.contact_title' => (string)($svc->get('about.contact_title', 'Contatti') ?? 'Contatti'),
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
        $svc->set('about.contact_title', trim((string)($data['contact_title'] ?? 'Contatti')) ?: 'Contatti');
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
                // also create a resized web version (max 1600px width)
                $webPath = $mediaDir . '/' . $hash . '_w1600.jpg';
                ImagesService::generateJpegPreview($dest, $webPath, 1600);
                $rel = str_replace(dirname(__DIR__, 3) . '/public', '', (file_exists($webPath) ? $webPath : $dest));
                $svc->set('about.photo_url', $rel);
            }
        }

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Pagina About salvata'];
        return $response->withHeader('Location', '/admin/pages/about')->withStatus(302);
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
        ];
        
        // Get current filter settings for reference
        $filterSettings = $this->getFilterSettings();
        
        return $this->view->render($response, 'admin/pages/galleries.twig', [
            'settings' => $settings,
            'filter_settings' => $filterSettings,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function saveGalleries(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
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

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Pagina Galleries salvata'];
        return $response->withHeader('Location', '/admin/pages/galleries')->withStatus(302);
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
}
