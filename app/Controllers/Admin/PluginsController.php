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
     * Show the plugin management page
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
     * Install a plugin
     */
    public function install(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin not specified'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->installPlugin($slug, $this->pluginsDir);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
    }

    /**
     * Uninstall a plugin
     */
    public function uninstall(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin not specified'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->uninstallPlugin($slug, $this->pluginsDir);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
    }

    /**
     * Activate a plugin
     */
    public function activate(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin not specified'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->activatePlugin($slug);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
    }

    /**
     * Deactivate a plugin
     */
    public function deactivate(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Plugin not specified'];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->deactivatePlugin($slug);

        $_SESSION['flash'][] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];

        return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
    }

    /**
     * Upload a plugin ZIP file
     */
    public function upload(Request $request, Response $response): Response
    {
        $response = $response->withHeader('Content-Type', 'application/json');

        // Verify CSRF with timing-safe comparison
        $csrf = $request->getHeaderLine('X-CSRF-Token');
        $sessionCsrf = $_SESSION['csrf'] ?? '';
        if (empty($csrf) || !is_string($sessionCsrf) || !hash_equals($sessionCsrf, $csrf)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
            return $response->withStatus(403);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'No file uploaded or upload error']));
            return $response->withStatus(400);
        }

        // Check file type
        $filename = $file->getClientFilename();
        if (!str_ends_with(strtolower($filename), '.zip')) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'The file must be a ZIP archive']));
            return $response->withStatus(400);
        }

        // Check file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'The file is too large (max 10MB)']));
            return $response->withStatus(400);
        }

        // Create temp directory
        $tempDir = sys_get_temp_dir() . '/cimaise_plugin_' . uniqid();
        mkdir($tempDir, 0755, true);

        $tempZip = $tempDir . '/plugin.zip';
        $file->moveTo($tempZip);

        // Extract ZIP
        $zip = new \ZipArchive();
        if ($zip->open($tempZip) !== true) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Unable to open ZIP file']));
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
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'plugin.json not found in package']));
            return $response->withStatus(400);
        }

        // Validate plugin.json
        $pluginData = json_decode(file_get_contents($pluginJson), true);
        if (!$pluginData || empty($pluginData['name'])) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'plugin.json invalid or missing "name" field']));
            return $response->withStatus(400);
        }

        // Security validation: scan for dangerous code patterns
        $securityCheck = $this->validatePluginSecurity($pluginDir);
        if (!$securityCheck['valid']) {
            $this->cleanupTemp($tempDir);
            $errorMessage = 'Plugin rejected due to security concerns: ' . implode('; ', array_slice($securityCheck['errors'], 0, 3));
            if (count($securityCheck['errors']) > 3) {
                $errorMessage .= ' (and ' . (count($securityCheck['errors']) - 3) . ' more issues)';
            }
            $response->getBody()->write(json_encode(['success' => false, 'message' => $errorMessage]));
            return $response->withStatus(400);
        }

        // Create slug from name
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($pluginData['name']));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));

        $targetDir = $this->pluginsDir . '/' . $slug;

        // Check if plugin already exists
        if (is_dir($targetDir)) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'A plugin with this name already exists']));
            return $response->withStatus(400);
        }

        // Ensure plugins directory exists
        if (!is_dir($this->pluginsDir)) {
            mkdir($this->pluginsDir, 0755, true);
        }

        // Move plugin to plugins directory
        if (!rename($pluginDir, $targetDir)) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Unable to install plugin']));
            return $response->withStatus(500);
        }

        // Cleanup temp
        $this->cleanupTemp($tempDir);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Plugin "' . htmlspecialchars($pluginData['name'], ENT_QUOTES, 'UTF-8') . '" uploaded successfully',
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

    /**
     * Validate plugin code for security issues
     *
     * @param string $pluginDir Directory containing extracted plugin files
     * @return array{valid: bool, errors: string[]}
     */
    private function validatePluginSecurity(string $pluginDir): array
    {
        $errors = [];

        // Dangerous functions that could allow code execution
        $dangerousPatterns = [
            // Direct code execution
            '/\beval\s*\(/i' => 'eval() function detected - arbitrary code execution risk',
            '/\bcreate_function\s*\(/i' => 'create_function() detected - arbitrary code execution risk',
            '/\bassert\s*\([^)]*\$[^)]*\)/i' => 'assert() with variable input detected - code execution risk',

            // Shell command execution
            '/\bexec\s*\(/i' => 'exec() function detected - shell command execution risk',
            '/\bshell_exec\s*\(/i' => 'shell_exec() function detected - shell command execution risk',
            '/\bsystem\s*\(/i' => 'system() function detected - shell command execution risk',
            '/\bpassthru\s*\(/i' => 'passthru() function detected - shell command execution risk',
            '/\bpopen\s*\(/i' => 'popen() function detected - shell command execution risk',
            '/\bproc_open\s*\(/i' => 'proc_open() function detected - shell command execution risk',
            '/`[^`]*\$[^`]*`/' => 'Backtick operator with variable detected - shell execution risk',

            // Obfuscation patterns
            '/\bbase64_decode\s*\([^)]*\beval\b/i' => 'base64_decode + eval pattern detected - obfuscated code risk',
            '/\bgzinflate\s*\([^)]*\beval\b/i' => 'gzinflate + eval pattern detected - obfuscated code risk',
            '/\bstr_rot13\s*\([^)]*\beval\b/i' => 'str_rot13 + eval pattern detected - obfuscated code risk',

            // File inclusion with variables (potential RFI/LFI)
            '/\b(include|require|include_once|require_once)\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i' => 'File inclusion with user input detected - RFI/LFI risk',

            // Superglobal abuse in dangerous contexts
            '/\bpreg_replace\s*\([^)]*\/[^\/]*e[^\/]*\//i' => 'preg_replace with /e modifier detected - code execution risk',
        ];

        // Find all PHP files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

            // Check for suspicious file extensions
            if (in_array($extension, ['phtml', 'phar'])) {
                $errors[] = "Suspicious file type detected: {$file->getFilename()}";
                continue;
            }

            // Check for .htaccess and similar Apache config dotfiles
            $filename = $file->getFilename();
            if (preg_match('/^\.ht/', $filename)) {
                $errors[] = "Apache config file detected: {$filename}";
                continue;
            }

            // Only scan PHP files for code patterns
            if ($extension !== 'php') continue;

            $content = file_get_contents($file->getRealPath());
            if ($content === false) continue;

            // Remove comments and strings for more accurate pattern matching
            $strippedContent = $this->stripCommentsAndStrings($content);

            foreach ($dangerousPatterns as $pattern => $message) {
                if (preg_match($pattern, $strippedContent)) {
                    $relativePath = str_replace($pluginDir . '/', '', $file->getRealPath());
                    $errors[] = "{$relativePath}: {$message}";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Strip comments and string literals from PHP code for pattern matching
     */
    private function stripCommentsAndStrings(string $code): string
    {
        // Use token_get_all to properly parse PHP
        try {
            $tokens = @token_get_all($code);
            $result = '';

            foreach ($tokens as $token) {
                if (is_array($token)) {
                    // Skip comments and strings
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                        continue;
                    }
                    $result .= $token[1];
                } else {
                    $result .= $token;
                }
            }

            return $result;
        } catch (\Throwable) {
            // If parsing fails, return original code
            return $code;
        }
    }
}
