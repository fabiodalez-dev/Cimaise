<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SocialController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function show(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);

        // Get current social settings
        $enabledSocials = $svc->get('social.enabled', []);
        if (!is_array($enabledSocials)) {
            $enabledSocials = $this->getDefaultEnabledSocials();
        }

        // Get social order
        $socialOrder = $svc->get('social.order', []);
        if (!is_array($socialOrder)) {
            $socialOrder = $enabledSocials;
        }

        // Get all available social networks
        $availableSocials = $this->getAvailableSocials();

        // Get photographer's social profiles
        $photographerProfiles = $svc->get('social.profiles', []);
        if (!is_array($photographerProfiles)) {
            $photographerProfiles = [];
        }

        // Get available profile networks (subset suitable for profiles)
        $profileNetworks = $this->getProfileNetworks();

        return $this->view->render($response, 'admin/social.twig', [
            'enabled_socials' => $enabledSocials,
            'social_order' => $socialOrder,
            'available_socials' => $availableSocials,
            'photographer_profiles' => $photographerProfiles,
            'profile_networks' => $profileNetworks,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        // Support both form-encoded and JSON payloads
        $data = (array)($request->getParsedBody() ?? []);
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        // For JSON payloads, parse the body first to get CSRF token
        if (str_contains($contentType, 'application/json')) {
            try {
                $raw = (string)$request->getBody();
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            } catch (\Throwable) {
                // fall back to parsed body if JSON decoding fails
            }
        }

        // CSRF validation (check from parsed data or header)
        $token = $data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        if (!\is_string($token) || !isset($_SESSION['csrf']) || !\hash_equals($_SESSION['csrf'], $token)) {
            if ($this->isAjaxRequest($request)) {
                return $this->csrfErrorJson($response);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/social'))->withStatus(302);
        }

        $svc = new SettingsService($this->db);
        
        // Determine enabled socials
        $enabledSocials = [];
        $availableSocials = $this->getAvailableSocials();
        $availableKeys = array_keys($availableSocials);

        // Case 1: JSON payload with explicit enabled list
        if (isset($data['enabled']) && is_array($data['enabled'])) {
            // sanitize: keep only valid keys and preserve order
            foreach ($data['enabled'] as $key) {
                if (in_array($key, $availableKeys, true)) {
                    $enabledSocials[] = $key;
                }
            }
        } else {
            // Case 2: form fields social_<key>=on
            foreach ($availableSocials as $social => $config) {
                if (isset($data['social_' . $social])) {
                    $enabledSocials[] = $social;
                }
            }
        }
        
        // Get social order from form data (if provided)
        $socialOrder = [];
        if (isset($data['order']) && is_array($data['order'])) {
            $orderData = array_values(array_filter($data['order'], fn($k) => in_array($k, $availableKeys, true)));
            // Filter order to only include enabled socials, preserve provided order
            $socialOrder = array_values(array_intersect($orderData, $enabledSocials));
            // Add any enabled socials not in the order to the end
            $socialOrder = array_values(array_merge($socialOrder, array_diff($enabledSocials, $socialOrder)));
        } elseif (isset($data['social_order']) && is_string($data['social_order'])) {
            $orderData = json_decode($data['social_order'], true);
            if (is_array($orderData)) {
                $orderData = array_values(array_filter($orderData, fn($k) => in_array($k, $availableKeys, true)));
                $socialOrder = array_values(array_intersect($orderData, $enabledSocials));
                $socialOrder = array_values(array_merge($socialOrder, array_diff($enabledSocials, $socialOrder)));
            }
        }
        
        // If no order provided, use enabled socials in default order
        if (empty($socialOrder)) {
            $socialOrder = $enabledSocials;
        }
        
        // Save settings
        $svc->set('social.enabled', $enabledSocials);
        $svc->set('social.order', $socialOrder);
        
        // AJAX request support: return JSON instead of redirect
        if ($this->isAjaxRequest($request)) {
            return $this->jsonResponse($response, [
                'ok' => true,
                'enabled' => $enabledSocials,
                'order' => $socialOrder,
            ]);
        }

        $_SESSION['flash'][] = ['type'=>'success','message'=>trans('admin.flash.social_saved')];
        return $response->withHeader('Location', $this->redirect('/admin/social'))->withStatus(302);
    }

    private function getDefaultEnabledSocials(): array
    {
        return ['behance','whatsapp','facebook','x','deviantart','instagram','pinterest','telegram','threads','bluesky'];
    }

    /**
     * Get all available social networks from database
     */
    private function getAvailableSocials(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT slug, name, icon, color, share_url FROM social_networks WHERE is_active = 1 ORDER BY sort_order ASC'
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $socials = [];
        foreach ($rows as $row) {
            $socials[$row['slug']] = [
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'url' => $row['share_url'] ?? '#',
            ];
        }

        return $socials;
    }

    /**
     * Get networks suitable for photographer profile links
     */
    private function getProfileNetworks(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT slug, name, icon, color FROM social_networks WHERE is_profile_network = 1 AND is_active = 1 ORDER BY sort_order ASC'
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $networks = [];
        foreach ($rows as $row) {
            $networks[$row['slug']] = [
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
            ];
        }

        return $networks;
    }

    /**
     * Save photographer profiles
     */
    public function saveProfiles(Request $request, Response $response): Response
    {
        $data = (array)($request->getParsedBody() ?? []);
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        // For JSON payloads
        if (str_contains($contentType, 'application/json')) {
            try {
                $raw = (string)$request->getBody();
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            } catch (\Throwable) {
                // fall back to parsed body
            }
        }

        // CSRF validation
        $token = $data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        if (!\is_string($token) || !isset($_SESSION['csrf']) || !\hash_equals($_SESSION['csrf'], $token)) {
            if ($this->isAjaxRequest($request)) {
                return $this->csrfErrorJson($response);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/social'))->withStatus(302);
        }

        $svc = new SettingsService($this->db);
        $profileNetworks = $this->getProfileNetworks();
        $validNetworks = array_keys($profileNetworks);

        // Process profiles from form
        $profiles = [];
        if (isset($data['profiles']) && is_array($data['profiles'])) {
            foreach ($data['profiles'] as $profile) {
                if (!is_array($profile)) {
                    continue;
                }
                $network = $profile['network'] ?? '';
                $url = trim($profile['url'] ?? '');

                // Validate network and URL (only http/https protocols allowed)
                if (in_array($network, $validNetworks, true) && $url !== '' && $this->isValidProfileUrl($url)) {
                    $profiles[] = [
                        'network' => $network,
                        'url' => $url,
                    ];
                }
            }
        }

        $svc->set('social.profiles', $profiles);

        if ($this->isAjaxRequest($request)) {
            return $this->jsonResponse($response, ['ok' => true, 'profiles' => $profiles]);
        }

        $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.social_profiles_saved')];
        return $response->withHeader('Location', $this->redirect('/admin/social'))->withStatus(302);
    }

    /**
     * Validate profile URL - only http/https protocols allowed
     */
    private function isValidProfileUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return \in_array(strtolower($scheme ?? ''), ['http', 'https'], true);
    }
}
