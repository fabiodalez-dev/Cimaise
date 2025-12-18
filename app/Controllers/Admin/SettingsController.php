<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Support\Database;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SettingsController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function show(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);
        $settings = $svc->all();
        
        // Load templates for dropdown
        $templates = [];
        try {
            $templates = $this->db->pdo()->query('SELECT id, name FROM templates ORDER BY name')->fetchAll();
        } catch (\Throwable $e) {
            // Templates table doesn't exist yet
        }
        
        
        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $settings,
            'templates' => $templates,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $svc = new SettingsService($this->db);

        // formats
        $formats = [
            'avif' => isset($data['fmt_avif']),
            'webp' => isset($data['fmt_webp']),
            'jpg'  => isset($data['fmt_jpg']),
        ];
        $quality = [
            'avif' => max(1, min(100, (int)($data['q_avif'] ?? 50))),
            'webp' => max(1, min(100, (int)($data['q_webp'] ?? 75))),
            'jpg'  => max(1, min(100, (int)($data['q_jpg'] ?? 85))),
        ];
        $preview = [
            'width' => max(64, (int)($data['preview_w'] ?? 480)),
            'height' => null,
        ];
        
        // breakpoints from textarea JSON
        $breakpoints = json_decode((string)($data['breakpoints'] ?? ''), true);
        if (!is_array($breakpoints)) {
            $breakpoints = $svc->defaults()['image.breakpoints'];
        }
        
        // default template - handle both empty string and actual values
        $defaultTemplateId = null;
        if (isset($data['default_template_id']) && $data['default_template_id'] !== '' && $data['default_template_id'] !== '0') {
            $defaultTemplateId = (int)$data['default_template_id'];
        }
        
        // Site settings
        $siteSettings = [
            'title' => trim((string)($data['site_title'] ?? '')),
            'logo' => ($data['site_logo'] ?? '') !== '' ? (string)$data['site_logo'] : null,
            'description' => trim((string)($data['site_description'] ?? '')),
            'copyright' => trim((string)($data['site_copyright'] ?? '')),
            'email' => trim((string)($data['site_email'] ?? ''))
        ];
        
        // Performance settings  
        $performanceSettings = [
            'compression' => isset($data['enable_compression'])
        ];
        
        $paginationLimit = max(1, min(100, (int)($data['pagination_limit'] ?? 12)));
        $cacheTtl = max(1, min(168, (int)($data['cache_ttl'] ?? 24)));

        // Lightbox settings
        $showExif = isset($data['show_exif_lightbox']);

        // Save all settings
        $svc->set('image.formats', $formats);
        $svc->set('image.quality', $quality);
        $svc->set('image.preview', $preview);
        $svc->set('image.breakpoints', $breakpoints);
        $svc->set('lightbox.show_exif', $showExif);
        
        $galleryPageTemplate = $data['gallery_page_template'] ?? 'classic';
        if (!in_array($galleryPageTemplate, ['classic', 'hero', 'magazine'])) {
            $galleryPageTemplate = 'classic';
        }
        $svc->set('gallery.page_template', $galleryPageTemplate);

        $svc->set('gallery.default_template_id', $defaultTemplateId);
        $svc->set('site.title', $siteSettings['title']);
        $svc->set('site.logo', $siteSettings['logo']);
        $svc->set('site.description', $siteSettings['description']);  
        $svc->set('site.copyright', $siteSettings['copyright']);
        $svc->set('site.email', $siteSettings['email']);

        // Date format setting
        $dateFormat = in_array($data['date_format'] ?? 'Y-m-d', ['Y-m-d', 'd-m-Y'], true)
            ? $data['date_format']
            : 'Y-m-d';
        $svc->set('date.format', $dateFormat);

        // Site language setting
        $siteLanguage = preg_replace('/[^a-z0-9_-]/i', '', (string)($data['site_language'] ?? 'en')) ?: 'en';
        $svc->set('site.language', $siteLanguage);

        // reCAPTCHA settings
        $recaptchaSiteKey = trim((string)($data['recaptcha_site_key'] ?? ''));
        $recaptchaSecretKey = trim((string)($data['recaptcha_secret_key'] ?? ''));
        $recaptchaEnabled = isset($data['recaptcha_enabled']);

        // Validate reCAPTCHA key format (alphanumeric, underscore, hyphen only)
        $recaptchaKeyPattern = '/^[A-Za-z0-9_-]+$/';
        if ($recaptchaSiteKey !== '' && !preg_match($recaptchaKeyPattern, $recaptchaSiteKey)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid reCAPTCHA Site Key format'];
            $recaptchaSiteKey = ''; // Reject invalid key
        }
        if ($recaptchaSecretKey !== '' && !preg_match($recaptchaKeyPattern, $recaptchaSecretKey)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid reCAPTCHA Secret Key format'];
            $recaptchaSecretKey = ''; // Reject invalid key
        }

        // Get existing keys to check if they're set
        $existingSiteKey = (string)$svc->get('recaptcha.site_key', '');
        $existingSecretKey = (string)$svc->get('recaptcha.secret_key', '');

        // Determine final keys (new value or existing)
        $finalSiteKey = $recaptchaSiteKey !== '' ? $recaptchaSiteKey : $existingSiteKey;
        $finalSecretKey = $recaptchaSecretKey !== '' ? $recaptchaSecretKey : $existingSecretKey;

        // Validate: cannot enable reCAPTCHA without both keys
        if ($recaptchaEnabled && ($finalSiteKey === '' || $finalSecretKey === '')) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Cannot enable reCAPTCHA without both Site Key and Secret Key'];
            $recaptchaEnabled = false;
        }

        $svc->set('recaptcha.site_key', $finalSiteKey);
        // Only update secret key if a new value is provided (never expose existing key to client)
        if ($recaptchaSecretKey !== '') {
            $svc->set('recaptcha.secret_key', $recaptchaSecretKey);
        }
        $svc->set('recaptcha.enabled', $recaptchaEnabled);

        $svc->set('performance.compression', $performanceSettings['compression']);
        $svc->set('pagination.limit', $paginationLimit);
        $svc->set('cache.ttl', $cacheTtl);

        $_SESSION['flash'][] = ['type'=>'success','message'=>'Settings saved successfully'];
        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }

    public function generateImages(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            if ($this->isAjaxRequest($request)) {
                return $this->csrfErrorJson($response);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
        }

        try {
            $consolePath = dirname(__DIR__, 3) . '/bin/console';
            if (!is_executable($consolePath)) {
                throw new \RuntimeException('Console script not executable');
            }

            // Run the command in the background to prevent timeouts
            $cmd = "nohup php $consolePath images:generate --missing > /tmp/image_generation.log 2>&1 &";
            exec($cmd);

            if ($this->isAjaxRequest($request)) {
                $payload = json_encode([
                    'success' => true,
                    'message' => 'Image variant generation started.'
                ]);
                $response->getBody()->write($payload !== false ? $payload : '{"success":true}');
                return $response->withHeader('Content-Type', 'application/json');
            }

            $_SESSION['flash'][] = [
                'type' => 'info',
                'message' => 'Image variant generation started.'
            ];

        } catch (\Throwable $e) {
            Logger::error('Error starting image variant generation', [
                'error' => $e->getMessage(),
            ], 'images');

            if ($this->isAjaxRequest($request)) {
                $payload = json_encode([
                    'success' => false,
                    'error' => 'Generation failed',
                ]);
                $response->getBody()->write($payload !== false ? $payload : '{"success":false}');
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => 'Error starting generation'
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }

    public function generateFavicons(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
        }

        try {
            // Get logo path from settings
            $svc = new SettingsService($this->db);
            $logoPath = (string)$svc->get('site.logo', '');

            if ($logoPath === '') {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Please upload a logo first before generating favicons'];
                return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
            }

            // Convert relative path to absolute path
            $publicPath = dirname(__DIR__, 3) . '/public';
            $absoluteLogoPath = $publicPath . $logoPath;

            if (!file_exists($absoluteLogoPath)) {
                Logger::warning('Logo file missing during favicon generation', [
                    'logoPath' => $logoPath,
                    'absoluteLogoPath' => $absoluteLogoPath,
                ], 'favicon');
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Logo file not found'];
                return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
            }

            // Validate file is readable
            if (!is_readable($absoluteLogoPath)) {
                Logger::warning('Logo file not readable during favicon generation', [
                    'logoPath' => $logoPath,
                    'absoluteLogoPath' => $absoluteLogoPath,
                ], 'favicon');
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Logo file is not readable'];
                return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
            }

            // Validate MIME type
            $mimeType = mime_content_type($absoluteLogoPath);
            if (!$mimeType || !str_starts_with($mimeType, 'image/')) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Logo file must be an image'];
                return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
            }

            // Generate favicons
            $faviconService = new \App\Services\FaviconService($publicPath);
            $result = $faviconService->generateFavicons($absoluteLogoPath);

            if ($result['success']) {
                $generatedCount = count($result['generated']);
                $message = "Successfully generated {$generatedCount} favicon file(s): " . implode(', ', $result['generated']);

                if (!empty($result['errors'])) {
                    $message .= '. Errors: ' . implode(', ', $result['errors']);
                    $_SESSION['flash'][] = ['type' => 'warning', 'message' => $message];
                } else {
                    $_SESSION['flash'][] = ['type' => 'success', 'message' => $message];
                }
            } else {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Failed to generate favicons: ' . ($result['error'] ?? 'Unknown error')];
            }

        } catch (\Throwable $e) {
            Logger::error('Error generating favicons', [
                'error' => $e->getMessage(),
            ], 'favicon');
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => 'Error generating favicons'
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }
}
