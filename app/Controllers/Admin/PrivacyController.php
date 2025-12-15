<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PrivacyController extends BaseController
{
    private SettingsService $settings;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        $this->settings = new SettingsService($db);
    }

    public function index(Request $request, Response $response): Response
    {
        $cookieSettings = [
            'enabled' => (bool)$this->settings->get('cookie.banner_enabled', false),
            'essential_scripts' => (string)$this->settings->get('cookie.essential_scripts', ''),
            'analytics_scripts' => (string)$this->settings->get('cookie.analytics_scripts', ''),
            'marketing_scripts' => (string)$this->settings->get('cookie.marketing_scripts', ''),
            'banner_position' => (string)$this->settings->get('cookie.banner_position', 'bottom'),
            'banner_style' => (string)$this->settings->get('cookie.banner_style', 'bar'),
        ];

        return $this->view->render($response, 'admin/privacy/index.twig', [
            'cookie_settings' => $cookieSettings,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array)($request->getParsedBody() ?? []);

        // CSRF validation
        if (empty($data['csrf']) || $data['csrf'] !== ($_SESSION['csrf'] ?? '')) {
            return $response->withHeader('Location', $this->redirect('/admin/privacy?error=csrf'))->withStatus(302);
        }

        // Save cookie settings
        $this->settings->set('cookie.banner_enabled', !empty($data['banner_enabled']));
        $this->settings->set('cookie.essential_scripts', trim((string)($data['essential_scripts'] ?? '')));
        $this->settings->set('cookie.analytics_scripts', trim((string)($data['analytics_scripts'] ?? '')));
        $this->settings->set('cookie.marketing_scripts', trim((string)($data['marketing_scripts'] ?? '')));
        $this->settings->set('cookie.banner_position', in_array($data['banner_position'] ?? '', ['top', 'bottom']) ? $data['banner_position'] : 'bottom');
        $this->settings->set('cookie.banner_style', in_array($data['banner_style'] ?? '', ['bar', 'popup']) ? $data['banner_style'] : 'bar');

        // Rotate CSRF
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        return $response->withHeader('Location', $this->redirect('/admin/privacy?saved=1'))->withStatus(302);
    }
}
