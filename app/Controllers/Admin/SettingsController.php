<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\SettingsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SettingsController
{
    public function __construct(private Database $db, private Twig $view) {}

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
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
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
        // default template - only save template_id now, remove legacy settings
        $defaultTemplateId = !empty($data['default_template_id']) ? (int)$data['default_template_id'] : null;
        
        // Debug: Check if we have template_id in the request
        if (isset($data['default_template_id'])) {
            error_log("Settings form received template_id: " . $data['default_template_id']);
        } else {
            error_log("Settings form did NOT receive default_template_id field");
        }
        
        // Site settings
        $siteSettings = [
            'title' => trim((string)($data['site_title'] ?? '')),
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
        
        // Save all settings
        $svc->set('image.formats', $formats);
        $svc->set('image.quality', $quality);
        $svc->set('image.preview', $preview);
        $svc->set('image.breakpoints', $breakpoints);
        $svc->set('gallery.default_template_id', $defaultTemplateId);
        $svc->set('site.title', $siteSettings['title']);
        $svc->set('site.description', $siteSettings['description']);  
        $svc->set('site.copyright', $siteSettings['copyright']);
        $svc->set('site.email', $siteSettings['email']);
        $svc->set('performance.compression', $performanceSettings['compression']);
        $svc->set('pagination.limit', $paginationLimit);
        $svc->set('cache.ttl', $cacheTtl);
        
        // Debug log
        error_log("Settings saved - default_template_id: " . ($defaultTemplateId ?? 'null'));
        
        $_SESSION['flash'][] = ['type'=>'success','message'=>'Impostazioni salvate correttamente'];
        return $response->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    public function generateImages(Request $request, Response $response): Response
    {
        try {
            $consolePath = dirname(__DIR__, 3) . '/bin/console';
            if (!is_executable($consolePath)) {
                throw new \RuntimeException("Console script not executable");
            }

            $cmd = "php $consolePath images:generate --missing 2>&1";
            $startTime = microtime(true);
            
            ob_start();
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            
            $duration = round(microtime(true) - $startTime, 2);
            $outputText = implode("\n", $output);
            
            if ($exitCode === 0) {
                $_SESSION['flash'][] = [
                    'type' => 'success', 
                    'message' => "Immagini generate con successo in {$duration}s"
                ];
            } else {
                $_SESSION['flash'][] = [
                    'type' => 'danger', 
                    'message' => "Errore nella generazione: " . $outputText
                ];
            }
            
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = [
                'type' => 'danger', 
                'message' => 'Errore: ' . $e->getMessage()
            ];
        }
        
        return $response->withHeader('Location', '/admin/settings')->withStatus(302);
    }
}

