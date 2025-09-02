<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Installer\Installer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class InstallerController
{
    private Installer $installer;
    private Twig $view;
    private string $rootPath;
    private string $basePath;
    
    public function __construct(Twig $view)
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->installer = new Installer($this->rootPath);
        $this->view = $view;
        // Get base path for redirects
        $this->basePath = dirname($_SERVER['SCRIPT_NAME']);
        $this->basePath = $this->basePath === '/' ? '' : $this->basePath;
        
        // Remove /public from the path if present (since document root should be public/)
        if (str_ends_with($this->basePath, '/public')) {
            $this->basePath = substr($this->basePath, 0, -7); // Remove '/public'
        }
    }
    
    /**
     * Show installer welcome page
     */
    public function index(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            // Redirect to admin login
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        // Verify requirements
        $requirements = $this->checkRequirements();
        
        return $this->view->render($response, 'installer/index.twig', [
            'requirements' => $requirements,
            'allGood' => empty($requirements['errors'])
        ]);
    }
    
    /**
     * Show database configuration form
     */
    public function showDatabaseConfig(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        $requirements = $this->checkRequirements();
        if (!empty($requirements['errors'])) {
            return $response->withHeader('Location', $this->basePath . '/install')->withStatus(302);
        }
        
        return $this->view->render($response, 'installer/database.twig', [
            'db_connection' => 'sqlite',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => $this->rootPath . '/database/database.sqlite',
            'db_username' => 'root',
            'db_charset' => 'utf8mb4',
            'db_collation' => 'utf8mb4_unicode_ci',
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }
    
    /**
     * Process database configuration
     */
    public function processDatabaseConfig(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        $data = (array)$request->getParsedBody();
        
        // Verify CSRF token
        $csrf = (string)($data['csrf'] ?? '');
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token. Please try again.'];
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
        
        // Store database config in session for later use
        $_SESSION['install_db_config'] = $data;
        
        // Test database connection
        try {
            $testResult = $this->testDatabaseConnection($data);
            if ($testResult['success']) {
                return $response->withHeader('Location', $this->basePath . '/install/admin')->withStatus(302);
            } else {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => $testResult['error']];
                return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Database connection failed: ' . $e->getMessage()];
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
    }
    
    /**
     * Show admin user configuration form
     */
    public function showAdminConfig(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        // Check if we have database config
        if (!isset($_SESSION['install_db_config'])) {
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
        
        return $this->view->render($response, 'installer/admin.twig', [
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }
    
    /**
     * Process admin user configuration
     */
    public function processAdminConfig(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        // Check if we have database config
        if (!isset($_SESSION['install_db_config'])) {
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
        
        $data = (array)$request->getParsedBody();
        
        // Verify CSRF token
        $csrf = (string)($data['csrf'] ?? '');
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token. Please try again.'];
            return $response->withHeader('Location', $this->basePath . '/install/admin')->withStatus(302);
        }
        
        // Validate admin data
        $errors = $this->validateAdminData($data);
        if (!empty($errors)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Please correct the errors below'];
            $_SESSION['install_admin_errors'] = $errors;
            $_SESSION['install_admin_data'] = $data;
            return $response->withHeader('Location', $this->basePath . '/install/admin')->withStatus(302);
        }
        
        // Store admin config in session
        $_SESSION['install_admin_config'] = $data;
        
        // Proceed to settings step to collect site data before install
        return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
    }
    
    /**
     * Show application settings form
     */
    public function showSettingsConfig(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        // Check if we have database and admin config
        if (!isset($_SESSION['install_db_config']) || !isset($_SESSION['install_admin_config'])) {
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
        
        // Get previous data if returning from confirmation step
        $previousData = $_SESSION['install_settings_config'] ?? [];
        
        return $this->view->render($response, 'installer/settings.twig', [
            'site_title' => $previousData['site_title'] ?? 'photoCMS',
            'site_description' => $previousData['site_description'] ?? 'Professional Photography Portfolio',
            'site_copyright' => $previousData['site_copyright'] ?? '© ' . date('Y') . ' Photography Portfolio',
            'site_email' => $previousData['site_email'] ?? '',
            // Prefer slug going forward (IDs can vary by seed/db)
            'default_template_slug' => $previousData['default_template_slug'] ?? '',
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }
    
    /**
     * Process application settings
     */
    public function processSettingsConfig(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        // Check if we have database and admin config
        if (!isset($_SESSION['install_db_config']) || !isset($_SESSION['install_admin_config'])) {
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
        
        $data = (array)$request->getParsedBody();

        // Handle optional logo upload (no Uppy). Store as '/media/site/<file>'
        try {
            $files = $request->getUploadedFiles();
            $logo = $files['site_logo'] ?? null;
            if ($logo && $logo->getError() === UPLOAD_ERR_OK) {
                $stream = $logo->getStream();
                if (method_exists($stream, 'rewind')) { $stream->rewind(); }
                $contents = (string)$stream->getContents();
                if ($contents !== '') {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->buffer($contents) ?: '';
                    $allowed = ['image/png'=>'.png','image/jpeg'=>'.jpg','image/webp'=>'.webp'];
                    if (isset($allowed[$mime])) {
                        $info = @getimagesizefromstring($contents);
                        if ($info !== false) {
                            $hash = sha1($contents) ?: bin2hex(random_bytes(20));
                            $ext = $allowed[$mime];
                            $destDir = dirname(__DIR__, 2) . '/public/media';
                            if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
                            $destPath = $destDir . '/logo-' . $hash . $ext;
                            if (@file_put_contents($destPath, $contents) === false) {
                                throw new \RuntimeException('Failed to write uploaded logo');
                            }
                            $data['site_logo'] = '/media/' . basename($destPath);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore logo errors during install; proceed without a logo
        }
        
        // Verify CSRF token
        $csrf = (string)($data['csrf'] ?? '');
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token. Please try again.'];
            return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
        }
        
        // Normalize: convert legacy numeric selection to slug for robustness
        $normalized = $data;
        if (!isset($normalized['default_template_slug'])) {
            $idToSlug = [
                '1' => 'grid-classica',
                '2' => 'masonry-portfolio',
                '3' => 'slideshow-minimal',
                '4' => 'gallery-fullscreen',
                '5' => 'grid-compatta',
                '6' => 'grid-ampia',
            ];
            $idStr = isset($normalized['default_template_id']) ? (string)$normalized['default_template_id'] : '';
            if (isset($idToSlug[$idStr])) {
                $normalized['default_template_slug'] = $idToSlug[$idStr];
            }
        }
        $_SESSION['install_settings_config'] = $normalized;
        
        return $response->withHeader('Location', $this->basePath . '/install/confirm')->withStatus(302);
    }
    
    /**
     * Show installation confirmation
     */
    public function showConfirm(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        // Check if we have all config
        if (!isset($_SESSION['install_db_config']) || 
            !isset($_SESSION['install_admin_config']) || 
            !isset($_SESSION['install_settings_config'])) {
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
        
        // Template list (slug-based) for confirmation view
        $templates = [
            ['slug' => 'grid-classica', 'name' => 'Grid Classica'],
            ['slug' => 'masonry-portfolio', 'name' => 'Masonry Portfolio'],
            ['slug' => 'slideshow-minimal', 'name' => 'Slideshow Minimal'],
            ['slug' => 'gallery-fullscreen', 'name' => 'Gallery Fullscreen'],
            ['slug' => 'grid-compatta', 'name' => 'Grid Compatta'],
            ['slug' => 'grid-ampia', 'name' => 'Grid Ampia'],
        ];

        return $this->view->render($response, 'installer/confirm.twig', [
            'db_config' => $_SESSION['install_db_config'],
            'admin_config' => $_SESSION['install_admin_config'],
            'settings_config' => $_SESSION['install_settings_config'],
            'templates' => $templates,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }
    
    /**
     * Run the installation
     */
    public function runInstall(Request $request, Response $response): Response
    {
        error_log('runInstall: Starting installation process');
        
        // Check if already installed
        if ($this->installer->isInstalled()) {
            error_log('runInstall: Already installed, redirecting to /admin/login');
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        }
        
        // Check if we have all config
        if (!isset($_SESSION['install_db_config']) || 
            !isset($_SESSION['install_admin_config']) || 
            !isset($_SESSION['install_settings_config'])) {
            error_log('runInstall: Missing configuration data, redirecting to /install/database');
            return $response->withHeader('Location', $this->basePath . '/install/database')->withStatus(302);
        }
        
        $data = (array)$request->getParsedBody();
        error_log('runInstall: Received data: ' . print_r($data, true));
        
        // Verify CSRF token
        $csrf = (string)($data['csrf'] ?? '');
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            error_log('runInstall: Invalid CSRF token');
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token. Please try again.'];
            return $response->withHeader('Location', $this->basePath . '/install/confirm')->withStatus(302);
        }
        
        try {
            // Combine all configuration data
            $installData = array_merge(
                $_SESSION['install_db_config'],
                $_SESSION['install_admin_config'],
                $_SESSION['install_settings_config']
            );
            
            error_log('runInstall: Starting installation process with data: ' . print_r($installData, true));
            
            // Run installation
            $result = $this->installer->install($installData);
            error_log('runInstall: Installation result: ' . ($result ? 'true' : 'false'));
            
            // Clear installation session data
            unset($_SESSION['install_db_config']);
            unset($_SESSION['install_admin_config']);
            unset($_SESSION['install_settings_config']);
            unset($_SESSION['install_admin_errors']);
            unset($_SESSION['install_admin_data']);
            
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Installation completed successfully!'];
            
            // Force session write to ensure flash message is saved
            session_write_close();
            
            error_log('runInstall: Redirecting to /install/post-setup');
            return $response->withHeader('Location', $this->basePath . '/install/post-setup')->withStatus(302);
        } catch (\Throwable $e) {
            error_log('runInstall: Installation failed: ' . $e->getMessage());
            error_log('runInstall: Stack trace: ' . $e->getTraceAsString());
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Installation failed: ' . $e->getMessage()];
            return $response->withHeader('Location', $this->basePath . '/install/confirm')->withStatus(302);
        }
    }

    /**
     * Post-install setup: site settings after DB and seeds are ready
     */
    public function showPostSetup(Request $request, Response $response): Response
    {
        // If not installed yet, go to installer
        if (!$this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/install')->withStatus(302);
        }
        
        // Fetch available templates from DB, if any
        $templates = [];
        try {
            $dbi = $this->resolveDb();
            $stmt = $dbi->pdo()->query('SELECT id, slug, name FROM templates ORDER BY id ASC');
            $templates = $stmt->fetchAll();
        } catch (\Throwable) {}
        
        return $this->view->render($response, 'installer/post_setup.twig', [
            'site_title' => 'photoCMS',
            'site_description' => 'Professional Photography Portfolio',
            'site_copyright' => '© ' . date('Y') . ' Photography Portfolio',
            'site_email' => '',
            'templates' => $templates,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function processPostSetup(Request $request, Response $response): Response
    {
        if (!$this->installer->isInstalled()) {
            return $response->withHeader('Location', $this->basePath . '/install')->withStatus(302);
        }
        $data = (array)$request->getParsedBody();
        // Verify CSRF token
        $csrf = (string)($data['csrf'] ?? '');
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token. Please try again.'];
            return $response->withHeader('Location', $this->basePath . '/install/post-setup')->withStatus(302);
        }
        
        // Optional logo upload
        try {
            $files = $request->getUploadedFiles();
            $logo = $files['site_logo'] ?? null;
            if ($logo && $logo->getError() === UPLOAD_ERR_OK) {
                $stream = $logo->getStream(); if (method_exists($stream,'rewind')) $stream->rewind();
                $contents = (string)$stream->getContents();
                if ($contents !== '') {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->buffer($contents) ?: '';
                    $allowed = ['image/png'=>'.png','image/jpeg'=>'.jpg','image/webp'=>'.webp'];
                    if (isset($allowed[$mime])) {
                        $info = @getimagesizefromstring($contents);
                        if ($info !== false) {
                            $hash = sha1($contents) ?: bin2hex(random_bytes(20));
                            $ext = $allowed[$mime];
                            $destDir = dirname(__DIR__, 2) . '/public/media';
                            if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
                            $destPath = $destDir . '/logo-' . $hash . $ext;
                            @file_put_contents($destPath, $contents);
                            $data['site_logo'] = '/media/' . basename($destPath);
                        }
                    }
                }
            }
        } catch (\Throwable) {}
        
        // Persist settings via direct DB writes (same schema as SettingsService)
        $toSet = [
            'site.title' => (string)($data['site_title'] ?? 'photoCMS'),
            'site.logo' => $data['site_logo'] ?? null,
            'site.description' => (string)($data['site_description'] ?? 'Professional Photography Portfolio'),
            'site.copyright' => (string)($data['site_copyright'] ?? ('© ' . date('Y') . ' Photography Portfolio')),
            'site.email' => (string)($data['site_email'] ?? ''),
        ];
        if (!empty($data['default_template_slug'])) {
            try {
                $dbi2 = $this->resolveDb();
                $stmt2 = $dbi2->pdo()->prepare('SELECT id FROM templates WHERE slug = :slug LIMIT 1');
                $stmt2->execute([':slug' => (string)$data['default_template_slug']]);
                $row2 = $stmt2->fetch(\PDO::FETCH_ASSOC);
                if ($row2 && isset($row2['id'])) {
                    $toSet['gallery.default_template_id'] = (int)$row2['id'];
                }
            } catch (\Throwable) {}
        } elseif (!empty($data['default_template_id'])) {
            // legacy fallback if some old form posts numeric id
            $toSet['gallery.default_template_id'] = (int)$data['default_template_id'];
        }
        try {
            $dbi = $this->resolveDb();
            $pdo = $dbi->pdo();
            $isSqlite = $dbi->isSqlite();
            foreach ($toSet as $key => $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
                $type = is_null($value) ? 'null' : (is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : 'string'));
                if ($isSqlite) {
                    $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings(`key`,`value`,`type`,`created_at`,`updated_at`) VALUES(:k,:v,:t,datetime(\'now\'),datetime(\'now\'))');
                    $stmt->execute([':k'=>$key, ':v'=>$encoded, ':t'=>$type]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO settings(`key`,`value`,`type`,`created_at`,`updated_at`) VALUES(:k,:v,:t,NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`=:v2, `type`=:t2, `updated_at`=NOW()');
                    $stmt->execute([':k'=>$key, ':v'=>$encoded, ':t'=>$type, ':v2'=>$encoded, ':t2'=>$type]);
                }
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Failed to save settings: ' . $e->getMessage()];
            return $response->withHeader('Location', $this->basePath . '/install/post-setup')->withStatus(302);
        }
        
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Setup completed! You can now log in.'];
        return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
    }

    private function resolveDb(): \App\Support\Database
    {
        $root = dirname(__DIR__, 2);
        // Try to read .env manually
        $env = @file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $vars = [];
        foreach ($env as $line) {
            if (strpos($line, '=') !== false && strpos(ltrim($line), '#') !== 0) {
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v);
            }
        }
        $conn = $vars['DB_CONNECTION'] ?? 'sqlite';
        if ($conn === 'sqlite') {
            $dbPath = $vars['DB_DATABASE'] ?? 'database/database.sqlite';
            if ($dbPath[0] !== '/') { $dbPath = $root . '/' . $dbPath; }
            return new \App\Support\Database(database: $dbPath, isSqlite: true);
        }
        return new \App\Support\Database(
            host: $vars['DB_HOST'] ?? '127.0.0.1',
            port: (int)($vars['DB_PORT'] ?? 3306),
            database: $vars['DB_DATABASE'] ?? 'photocms',
            username: $vars['DB_USERNAME'] ?? 'root',
            password: $vars['DB_PASSWORD'] ?? '',
            charset: $vars['DB_CHARSET'] ?? 'utf8mb4',
            collation: $vars['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
        );
    }
    
    /**
     * Check system requirements
     */
    private function checkRequirements(): array
    {
        $errors = [];
        $warnings = [];
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $errors[] = 'PHP 8.0 or higher is required. Current version: ' . PHP_VERSION;
        }
        
        // Check required extensions
        $requiredExtensions = ['pdo', 'gd', 'mbstring', 'openssl'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "Required PHP extension '{$ext}' is not installed";
            }
        }
        
        // Check if either PDO MySQL or PDO SQLite is available
        if (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_sqlite')) {
            $errors[] = 'Either PDO MySQL or PDO SQLite extension is required';
        }
        
        // Check write permissions
        $writablePaths = [
            $this->rootPath . '/.env',
            $this->rootPath . '/database',
            $this->rootPath . '/storage',
            $this->rootPath . '/public/media'
        ];
        
        foreach ($writablePaths as $path) {
            if (!is_writable(dirname($path))) {
                $errors[] = "Directory '" . dirname($path) . "' is not writable";
            }
        }
        
        return [
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Test database connection
     */
    private function testDatabaseConnection(array $data): array
    {
        try {
            $connection = $data['db_connection'] ?? 'sqlite';
            
            if ($connection === 'sqlite') {
                $dbPath = $data['db_database'] ?? $this->rootPath . '/database/database.sqlite';
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                // Test SQLite connection
                $pdo = new \PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA foreign_keys = ON');
                
                // Test simple query
                $pdo->query('SELECT sqlite_version()')->fetch();
            } else {
                // Test MySQL connection
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', 
                    $data['db_host'] ?? '127.0.0.1',
                    (int)($data['db_port'] ?? 3306),
                    $data['db_database'] ?? 'photocms',
                    $data['db_charset'] ?? 'utf8mb4'
                );
                
                $pdo = new \PDO($dsn, 
                    $data['db_username'] ?? 'root',
                    $data['db_password'] ?? '',
                    [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                
                // Test simple query
                $pdo->query('SELECT VERSION()')->fetch();
            }
            
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Validate admin user data
     */
    private function validateAdminData(array $data): array
    {
        $errors = [];
        
        // Validate name
        if (empty($data['admin_name'])) {
            $errors['admin_name'] = 'Name is required';
        } elseif (strlen($data['admin_name']) < 2) {
            $errors['admin_name'] = 'Name must be at least 2 characters';
        }
        
        // Validate email
        if (empty($data['admin_email'])) {
            $errors['admin_email'] = 'Email is required';
        } elseif (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Invalid email format';
        }
        
        // Validate password
        if (empty($data['admin_password'])) {
            $errors['admin_password'] = 'Password is required';
        } elseif (strlen($data['admin_password']) < 8) {
            $errors['admin_password'] = 'Password must be at least 8 characters';
        } elseif ($data['admin_password'] !== ($data['admin_password_confirm'] ?? '')) {
            $errors['admin_password'] = 'Passwords do not match';
        }
        
        return $errors;
    }
}
