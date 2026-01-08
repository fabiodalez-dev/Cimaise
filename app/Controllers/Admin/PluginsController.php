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
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => trans('admin.flash.plugin_not_specified')];
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
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => trans('admin.flash.plugin_not_specified')];
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
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => trans('admin.flash.plugin_not_specified')];
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
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $slug = (string)($data['slug'] ?? '');

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => trans('admin.flash.plugin_not_specified')];
            return $response->withHeader('Location', $this->redirect('/admin/plugins'))->withStatus(302);
        }

        $pluginManager = PluginManager::getInstance();
        $result = $pluginManager->deactivatePlugin($slug, $this->pluginsDir);

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
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.csrf_invalid')]));
            return $response->withStatus(403);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_no_file')]));
            return $response->withStatus(400);
        }

        // Check file type
        $filename = $file->getClientFilename();
        if (!str_ends_with(strtolower($filename), '.zip')) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_must_be_zip')]));
            return $response->withStatus(400);
        }

        // Check file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_file_too_large')]));
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
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_invalid_zip')]));
            return $response->withStatus(400);
        }

        $extractDir = $tempDir . '/extracted';
        mkdir($extractDir, 0755, true);

        // Security: Validate all ZIP entry names before extraction to prevent path traversal
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            // Check for path traversal sequences
            if (str_contains($entryName, '../') || str_contains($entryName, '..\\')) {
                $zip->close();
                $this->cleanupTemp($tempDir);
                $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_invalid_zip')]));
                return $response->withStatus(400);
            }

            // Check for absolute paths (Unix or Windows)
            if (str_starts_with($entryName, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $entryName)) {
                $zip->close();
                $this->cleanupTemp($tempDir);
                $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_invalid_zip')]));
                return $response->withStatus(400);
            }

            // Check for null bytes or other dangerous characters
            if (str_contains($entryName, "\0")) {
                $zip->close();
                $this->cleanupTemp($tempDir);
                $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_invalid_zip')]));
                return $response->withStatus(400);
            }
        }

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
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_no_json')]));
            return $response->withStatus(400);
        }

        // Validate plugin.json
        $pluginData = json_decode(file_get_contents($pluginJson), true);
        if (!$pluginData || empty($pluginData['name'])) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_invalid_json')]));
            return $response->withStatus(400);
        }

        // Security validation: scan for dangerous code patterns
        $securityCheck = $this->validatePluginSecurity($pluginDir);
        if (!$securityCheck['valid']) {
            $this->cleanupTemp($tempDir);
            $errorsSummary = implode('; ', array_slice($securityCheck['errors'], 0, 3));
            if (count($securityCheck['errors']) > 3) {
                $errorsSummary .= ' (+' . (count($securityCheck['errors']) - 3) . ')';
            }
            $errorMessage = str_replace('{errors}', $errorsSummary, trans('admin.flash.plugin_security_rejected'));
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
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_already_exists')]));
            return $response->withStatus(400);
        }

        // Ensure plugins directory exists
        if (!is_dir($this->pluginsDir)) {
            mkdir($this->pluginsDir, 0755, true);
        }

        // Move plugin to plugins directory
        if (!rename($pluginDir, $targetDir)) {
            $this->cleanupTemp($tempDir);
            $response->getBody()->write(json_encode(['success' => false, 'message' => trans('admin.flash.plugin_install_failed')]));
            return $response->withStatus(500);
        }

        // Cleanup temp
        $this->cleanupTemp($tempDir);

        $successMessage = str_replace('{name}', htmlspecialchars($pluginData['name'], ENT_QUOTES, 'UTF-8'), trans('admin.flash.plugin_uploaded'));
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => $successMessage,
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
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    private function validatePluginSecurity(string $pluginDir): array
    {
        $errors = [];
        $warnings = [];

        // CRITICAL: Dangerous functions that BLOCK upload (high-risk code execution)
        $dangerousPatterns = [
            // ============================================
            // DIRECT CODE EXECUTION (CRITICAL)
            // ============================================
            '/\beval\s*\(/i' => 'eval() function detected - arbitrary code execution risk',
            '/\bcreate_function\s*\(/i' => 'create_function() detected - arbitrary code execution risk',
            '/\bassert\s*\([^)]*\$/i' => 'assert() with variable detected - code execution risk',

            // ============================================
            // SHELL COMMAND EXECUTION (CRITICAL)
            // ============================================
            '/\bexec\s*\(/i' => 'exec() function detected - shell command execution risk',
            '/\bshell_exec\s*\(/i' => 'shell_exec() function detected - shell command execution risk',
            '/\bsystem\s*\(/i' => 'system() function detected - shell command execution risk',
            '/\bpassthru\s*\(/i' => 'passthru() function detected - shell command execution risk',
            '/\bpopen\s*\(/i' => 'popen() function detected - shell command execution risk',
            '/\bproc_open\s*\(/i' => 'proc_open() function detected - shell command execution risk',
            '/\bpcntl_exec\s*\(/i' => 'pcntl_exec() function detected - process execution risk',
            '/`[^`]+`/' => 'Backtick operator detected - shell execution risk',

            // ============================================
            // DANGEROUS DYNAMIC CALLS (CRITICAL)
            // ============================================
            '/\bcall_user_func\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval|assert)[\'"]?/i' => 'call_user_func with dangerous callback detected',
            '/\bcall_user_func_array\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval|assert)[\'"]?/i' => 'call_user_func_array with dangerous callback detected',

            // ============================================
            // CALLBACK FUNCTIONS WITH DANGEROUS CALLBACKS (CRITICAL)
            // ============================================
            '/\barray_map\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval|assert)[\'"]?/i' => 'array_map with dangerous callback detected',
            '/\barray_filter\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval|assert)[\'"]?/i' => 'array_filter with dangerous callback detected',
            '/\barray_walk\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval|assert)[\'"]?/i' => 'array_walk with dangerous callback detected',
            '/\barray_reduce\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval|assert)[\'"]?/i' => 'array_reduce with dangerous callback detected',
            '/\b(usort|uasort|uksort)\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval|assert)[\'"]?/i' => 'usort/uasort/uksort with dangerous callback detected',
            '/\bpreg_replace_callback\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval)[\'"]?/i' => 'preg_replace_callback with dangerous callback detected',
            '/\bregister_shutdown_function\s*\([^)]*[\'"]?(exec|system|passthru|shell_exec|eval)[\'"]?/i' => 'register_shutdown_function with dangerous callback detected',

            // ============================================
            // OBFUSCATION PATTERNS (CRITICAL - common malware)
            // ============================================
            '/\bgzinflate\s*\(\s*base64_decode/i' => 'gzinflate(base64_decode()) detected - obfuscation pattern',
            '/\bstr_rot13\s*\(\s*base64_decode/i' => 'str_rot13(base64_decode()) detected - obfuscation pattern',
            '/\bconvert_uudecode\s*\(/i' => 'convert_uudecode() detected - potential obfuscation',
            '/\bchr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(/i' => 'chr() concatenation detected - obfuscation pattern',
            '/\\\\x[0-9a-fA-F]{2}.*\\\\x[0-9a-fA-F]{2}.*\\\\x[0-9a-fA-F]{2}/' => 'Multiple hex escape sequences detected - obfuscation pattern',
            '/\bpack\s*\([\'"]H\*[\'"].*\beval/i' => 'pack() with eval detected - obfuscation pattern',

            // ============================================
            // FILE OPERATIONS WITH PHP CODE (CRITICAL)
            // ============================================
            '/\bfile_put_contents\s*\([^)]*\.php/i' => 'file_put_contents() to PHP file detected - code injection risk',
            '/\bfwrite\s*\([^)]*<\?php/i' => 'fwrite() with PHP code detected - code injection risk',
            '/\bcopy\s*\([^)]*\.php/i' => 'copy() to PHP file detected - code injection risk',

            // ============================================
            // FILE INCLUSION RISKS (CRITICAL - RFI/LFI)
            // ============================================
            '/\b(include|require|include_once|require_once)\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i' => 'Dynamic file inclusion with user input - RFI/LFI risk',
            '/\b(include|require|include_once|require_once)\s*\(\s*\$[^)]*\.\s*\$/i' => 'Dynamic file inclusion with concatenation - RFI/LFI risk',

            // ============================================
            // DANGEROUS REGEX (CRITICAL)
            // ============================================
            '/\bpreg_replace\s*\([^)]*\/[^\/]*e[^\/]*\//i' => 'preg_replace with /e modifier detected - code execution risk',

            // ============================================
            // SERIALIZATION ATTACKS (CRITICAL)
            // ============================================
            '/\bunserialize\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i' => 'unserialize with user input - object injection risk',

            // ============================================
            // INFORMATION DISCLOSURE (CRITICAL)
            // ============================================
            '/\bphpinfo\s*\(/i' => 'phpinfo() detected - information disclosure risk',

            // ============================================
            // DATABASE RISKS (CRITICAL)
            // ============================================
            '/\bmysql_query\s*\(/i' => 'mysql_query() detected (deprecated, use PDO) - SQL injection risk',
            '/\bmysqli_query\s*\([^)]*\$_(GET|POST|REQUEST)/i' => 'mysqli_query with user input - SQL injection risk',

            // ============================================
            // PROCESS MANIPULATION (CRITICAL)
            // ============================================
            '/\bdl\s*\(/i' => 'dl() detected - dynamic extension loading risk',

            // ============================================
            // DYNAMIC INSTANTIATION (CRITICAL)
            // ============================================
            '/\bnew\s+\$\w+\s*\(/i' => 'Dynamic class instantiation (new $var()) detected - arbitrary object risk',
        ];

        // WARNING: Patterns that may be legitimate but warrant review (do not block upload)
        $warningPatterns = [
            // ============================================
            // DYNAMIC CALLS (may be legitimate)
            // ============================================
            '/\bcall_user_func\s*\(/i' => 'call_user_func() detected - review callback source',
            '/\bcall_user_func_array\s*\(/i' => 'call_user_func_array() detected - review callback source',
            '/\$\w+\s*\(\s*\$/' => 'Variable function call with variable argument detected - review usage',
            '/\$\$\w+/' => 'Variable variable ($$var) detected - review usage',

            // ============================================
            // REFLECTION (may be legitimate)
            // ============================================
            '/\bnew\s+ReflectionClass\b/i' => 'ReflectionClass detected - review usage',
            '/->invoke\s*\(/i' => 'ReflectionMethod::invoke() detected - review usage',

            // ============================================
            // CALLBACK FUNCTIONS (legitimate but review)
            // ============================================
            '/\bregister_shutdown_function\s*\(/i' => 'register_shutdown_function() detected - review callback',
            '/\bspl_autoload_register\s*\(/i' => 'spl_autoload_register() detected - review autoloader',

            // ============================================
            // ENCODING (may be legitimate)
            // ============================================
            '/\bbase64_decode\s*\(/i' => 'base64_decode() detected - verify not used for obfuscation',
            '/\bgzinflate\s*\(/i' => 'gzinflate() detected - verify not used for obfuscation',
            '/\bgzuncompress\s*\(/i' => 'gzuncompress() detected - verify not used for obfuscation',
            '/\bgzdecode\s*\(/i' => 'gzdecode() detected - verify not used for obfuscation',

            // ============================================
            // FILE OPERATIONS (may be legitimate)
            // ============================================
            '/\bfputs\s*\(/i' => 'fputs() detected - review file write usage',
            '/\bmove_uploaded_file\s*\(/i' => 'move_uploaded_file() detected - review file handling',

            // ============================================
            // NETWORK (may be legitimate)
            // ============================================
            '/\bfile_get_contents\s*\([^)]*https?:/i' => 'Remote file_get_contents() detected - review external requests',
            '/\bcurl_exec\s*\(/i' => 'curl_exec() detected - review external requests',
            '/\bfsockopen\s*\(/i' => 'fsockopen() detected - review network usage',
            '/\bstream_socket_client\s*\(/i' => 'stream_socket_client() detected - review network usage',

            // ============================================
            // CONFIGURATION (may be legitimate)
            // ============================================
            '/\bini_set\s*\(/i' => 'ini_set() detected - review configuration changes',
            '/\bputenv\s*\(/i' => 'putenv() detected - review environment changes',
            '/\bset_include_path\s*\(/i' => 'set_include_path() detected - review path changes',
            '/\bgetenv\s*\(/i' => 'getenv() detected - review environment access',

            // ============================================
            // SUPERGLOBALS (may be legitimate)
            // ============================================
            '/\b\$_ENV\b/' => '$_ENV access detected - review environment variable usage',
            '/\b\$_SERVER\s*\[\s*[\'"]HTTP_/' => '$_SERVER[HTTP_*] access detected - review header usage',

            // ============================================
            // SERIALIZATION (may be legitimate)
            // ============================================
            '/\bunserialize\s*\(\s*\$/i' => 'unserialize with variable - ensure input is trusted',
        ];

        // Find all PHP files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

            // Check for suspicious/dangerous file extensions
            $dangerousExtensions = [
                'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
                'inc', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd',
                'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'shtml'
            ];
            if (in_array($extension, $dangerousExtensions)) {
                $errors[] = "Dangerous file type detected: {$file->getFilename()}";
                continue;
            }

            // Check for double extensions (e.g., file.php.jpg)
            $basename = $file->getFilename();
            if (preg_match('/\.(php|phar|phtml)[^a-z]/i', $basename)) {
                $errors[] = "Double extension with PHP detected: {$basename}";
                continue;
            }

            // Check for .htaccess and similar Apache config dotfiles
            $filename = $file->getFilename();
            if (preg_match('/^\.ht/', $filename)) {
                $errors[] = "Apache config file detected: {$filename}";
                continue;
            }

            // Check for other dangerous config/hidden files
            $dangerousFilenames = [
                'web.config', '.user.ini', 'php.ini', '.php.ini', '.env',
                '.git', '.gitignore', '.svn', '.hg'
            ];
            $lowercaseFilename = strtolower($filename);
            if (in_array($lowercaseFilename, $dangerousFilenames)) {
                $errors[] = "Dangerous config/hidden file detected: {$filename}";
                continue;
            }

            // Check for null byte injection attempts in filename
            if (strpos($filename, "\0") !== false || strpos($filename, '%00') !== false) {
                $errors[] = "Null byte injection attempt in filename: {$filename}";
                continue;
            }

            // Only scan PHP files for code patterns
            if ($extension !== 'php') continue;

            $content = file_get_contents($file->getRealPath());
            if ($content === false) continue;

            // Remove comments and strings for more accurate pattern matching
            $strippedContent = $this->stripCommentsAndStrings($content);
            $relativePath = str_replace($pluginDir . '/', '', $file->getRealPath());

            // Check for CRITICAL patterns (block upload)
            foreach ($dangerousPatterns as $pattern => $message) {
                if (preg_match($pattern, $strippedContent)) {
                    $errors[] = "{$relativePath}: {$message}";
                }
            }

            // Check for WARNING patterns (allow but inform)
            foreach ($warningPatterns as $pattern => $message) {
                if (preg_match($pattern, $strippedContent)) {
                    $warnings[] = "{$relativePath}: {$message}";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
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

            // Token types to skip (comments and strings)
            $skipTokens = [
                T_COMMENT,
                T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
            ];

            // Add heredoc/nowdoc tokens if they exist (PHP version dependent)
            if (defined('T_START_HEREDOC')) {
                $skipTokens[] = T_START_HEREDOC;
                $skipTokens[] = T_END_HEREDOC;
            }

            $inHeredoc = false;
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    // Track heredoc/nowdoc blocks
                    if (defined('T_START_HEREDOC') && $token[0] === T_START_HEREDOC) {
                        $inHeredoc = true;
                        continue;
                    }
                    if (defined('T_END_HEREDOC') && $token[0] === T_END_HEREDOC) {
                        $inHeredoc = false;
                        continue;
                    }

                    // Skip content inside heredoc
                    if ($inHeredoc) {
                        continue;
                    }

                    // Skip comments and strings
                    if (in_array($token[0], $skipTokens)) {
                        continue;
                    }
                    $result .= $token[1];
                } else {
                    if (!$inHeredoc) {
                        $result .= $token;
                    }
                }
            }

            return $result;
        } catch (\Throwable) {
            // If parsing fails, return original code
            return $code;
        }
    }
}
