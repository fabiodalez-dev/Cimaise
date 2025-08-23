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
        $pages = [
            [
                'slug' => 'about',
                'title' => 'About',
                'description' => 'Pagina di presentazione: bio, foto, social, contatti',
                'edit_url' => '/admin/pages/about',
                'public_url' => '/' . $aboutSlug,
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
}
