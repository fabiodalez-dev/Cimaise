<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Support\PluginManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PluginsController extends BaseController
{
    private string $pluginsDir;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        $this->pluginsDir = dirname(__DIR__, 3) . '/plugins';
    }

    /**
     * Mostra la pagina di gestione plugin
     */
    public function index(Request $request, Response $response): Response
    {
        $pluginManager = PluginManager::getInstance();
        $plugins = $pluginManager->getAllAvailablePlugins($this->pluginsDir);

        return $this->view->render($response, 'admin/plugins/index.twig', [
            'plugins' => $plugins,
            'stats' => $pluginManager->getStats(),
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Installa un plugin
     */
    public function install(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin non specificato'];
            return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->installPlugin($slug, $this->pluginsDir);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
    }

    /**
     * Disinstalla un plugin
     */
    public function uninstall(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin non specificato'];
            return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->uninstallPlugin($slug, $this->pluginsDir);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
    }

    /**
     * Attiva un plugin
     */
    public function activate(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin non specificato'];
            return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->activatePlugin($slug);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
    }

    /**
     * Disattiva un plugin
     */
    public function deactivate(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin non specificato'];
            return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->deactivatePlugin($slug);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', '/admin/plugins')->withStatus(302);
    }
}
