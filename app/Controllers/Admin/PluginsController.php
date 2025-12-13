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

    /**
     * Upload a plugin ZIP file
     */
    public function upload(Request $request, Response $response): Response
    {
        $response = $response->withHeader('Content-Type', 'application/json');

        // Verify CSRF
        $csrf = $request->getHeaderLine('X-CSRF-Token');
        if (empty($csrf) || $csrf !== ($_SESSION['csrf'] ?? '')) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Token CSRF non valido']));
            return $response->withStatus(403);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Nessun file caricato o errore upload']));
            return $response->withStatus(400);
        }

        // Check file type
        $filename = $file->getClientFilename();
        if (!str_ends_with(strtolower($filename), '.zip')) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Il file deve essere un archivio ZIP']));
            return $response->withStatus(400);
        }

        // Check file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Il file è troppo grande (max 10MB)']));
            return $response->withStatus(400);
        }

        // Create temp directory
        $tempDir = sys_get_temp_dir() . '/photocms_plugin_' . uniqid();
        mkdir($tempDir, 0755, true);

        $tempZip = $tempDir . '/plugin.zip';
        $file->moveTo($tempZip);

        // Extract ZIP
        $zip = new \ZipArchive();
        if ($zip->open($tempZip) !== true) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Impossibile aprire il file ZIP']));
            return $response->withStatus(400);
        }

        $extractDir = $tempDir . '/extracted';
        mkdir($extractDir, 0755, true);
        $zip->extractTo($extractDir);
        $zip->close();

        // Find plugin.json - could be in root or in a subdirectory
        $pluginJson = null;
        $pluginDir = null;

        // Check root level
        if (file_exists($extractDir . '/plugin.json')) {
            $pluginJson = $extractDir . '/plugin.json';
            $pluginDir = $extractDir;
        } else {
            // Check one level deep (common when ZIP contains a folder)
            $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                if (file_exists($dir . '/plugin.json')) {
                    $pluginJson = $dir . '/plugin.json';
                    $pluginDir = $dir;
                    break;
                }
            }
        }

        if (!$pluginJson) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'plugin.json non trovato nel pacchetto']));
            return $response->withStatus(400);
        }

        // Validate plugin.json
        $pluginData = json_decode(file_get_contents($pluginJson), true);
        if (!$pluginData || empty($pluginData['name'])) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'plugin.json non valido o manca il campo "name"']));
            return $response->withStatus(400);
        }

        // Create slug from name
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($pluginData['name']));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));

        $targetDir = $this->pluginsDir . '/' . $slug;

        // Check if plugin already exists
        if (is_dir($targetDir)) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Un plugin con questo nome esiste già']));
            return $response->withStatus(400);
        }

        // Ensure plugins directory exists
        if (!is_dir($this->pluginsDir)) {
            mkdir($this->pluginsDir, 0755, true);
        }

        // Move plugin to plugins directory
        if (!rename($pluginDir, $targetDir)) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Impossibile installare il plugin']));
            return $response->withStatus(500);
        }

        // Cleanup temp
        $this->cleanupTemp($tempDir);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Plugin "' . htmlspecialchars($pluginData['name'], ENT_QUOTES, 'UTF-8') . '" caricato con successo',
            'plugin' => [
                'slug' => $slug,
                'name' => $pluginData['name'],
                'version' => $pluginData['version'] ?? '1.0.0'
            ]
        ]));
        return $response->withStatus(200);
    }

    /**
     * Cleanup temporary directory
     */
    private function cleanupTemp(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }
}
