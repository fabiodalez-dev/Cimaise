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

class PrivacyController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);

        $settings = [
            'cookie_banner_enabled' => $svc->get('privacy.cookie_banner_enabled', true),
            'custom_js_essential' => $svc->get('privacy.custom_js_essential', ''),
            'custom_js_analytics' => $svc->get('privacy.custom_js_analytics', ''),
            'custom_js_marketing' => $svc->get('privacy.custom_js_marketing', ''),
            'show_analytics' => $svc->get('cookie_banner.show_analytics', false),
            'show_marketing' => $svc->get('cookie_banner.show_marketing', false),
            'nsfw_global_warning' => $svc->get('privacy.nsfw_global_warning', false),
        ];

        return $this->view->render($response, 'admin/privacy/index.twig', [
            'settings' => $settings,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();

        // CSRF validation (timing-safe)
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid_try_again')];
            return $response->withHeader('Location', $this->redirect('/admin/privacy'))->withStatus(302);
        }

        $svc = new SettingsService($this->db);

        try {
            // Cookie banner enabled/disabled
            $svc->set('privacy.cookie_banner_enabled', isset($data['cookie_banner_enabled']));

            // NSFW global warning enabled/disabled
            $svc->set('privacy.nsfw_global_warning', isset($data['nsfw_global_warning']));

            // Custom JavaScript blocks
            $customJsEssential = trim((string)($data['custom_js_essential'] ?? ''));
            $customJsAnalytics = trim((string)($data['custom_js_analytics'] ?? ''));
            $customJsMarketing = trim((string)($data['custom_js_marketing'] ?? ''));

            $svc->set('privacy.custom_js_essential', $customJsEssential);
            $svc->set('privacy.custom_js_analytics', $customJsAnalytics);
            $svc->set('privacy.custom_js_marketing', $customJsMarketing);

            // Show analytics/marketing categories only if scripts are present
            $svc->set('cookie_banner.show_analytics', !empty($customJsAnalytics));
            $svc->set('cookie_banner.show_marketing', !empty($customJsMarketing));

            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.privacy_saved')];

        } catch (\Throwable $e) {
            Logger::error('PrivacyController::save error', ['error' => $e->getMessage()], 'admin');
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_saving')];
        }

        return $response->withHeader('Location', $this->redirect('/admin/privacy'))->withStatus(302);
    }
}
