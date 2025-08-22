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
        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $settings,
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
        $svc->set('image.formats', $formats);
        $svc->set('image.quality', $quality);
        $svc->set('image.preview', $preview);
        $svc->set('image.breakpoints', $breakpoints);
        $_SESSION['flash'][] = ['type'=>'success','message'=>'Impostazioni salvate'];
        return $response->withHeader('Location', '/admin/settings')->withStatus(302);
    }
}

