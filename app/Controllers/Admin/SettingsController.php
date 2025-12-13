<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Support\Database;
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
        $data = (array)$request->getParsedBody();
        $svc = new SettingsService($this->db);
        
        // Debug: Log the incoming data
        error_log("Settings save request data: " . print_r($data, true));
        
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
        
        // Debug: Log the processed default template ID
        error_log("Processed default_template_id: " . var_export($defaultTemplateId, true));
        error_log("Raw default_template_id from data: " . var_export($data['default_template_id'] ?? 'NOT SET', true));
        
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
        $svc->set('performance.compression', $performanceSettings['compression']);
        $svc->set('pagination.limit', $paginationLimit);
        $svc->set('cache.ttl', $cacheTtl);
        
        // Debug: Verify the saved value
        $savedValue = $svc->get('gallery.default_template_id');
        error_log("Saved gallery.default_template_id: " . var_export($savedValue, true));
        
        $_SESSION['flash'][] = ['type'=>'success','message'=>'Settings saved successfully'];
        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }

    public function generateImages(Request $request, Response $response): Response
    {
        try {
            $consolePath = dirname(__DIR__, 3) . '/bin/console';
            if (!is_executable($consolePath)) {
                throw new \RuntimeException("Console script not executable");
            }

            // Run the command in the background to prevent timeouts
            $cmd = "nohup php $consolePath images:generate --missing > /tmp/image_generation.log 2>&1 &";
            exec($cmd);
            
            $_SESSION['flash'][] = [
                'type' => 'info',
                'message' => 'Image variant generation started. This process may take a few minutes. You will receive a notification when it is complete.'
            ];
            
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = [
                'type' => 'danger', 
                'message' => 'Error starting generation: ' . $e->getMessage()
            ];
        }
        
        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }
}
