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

        // Ensure session is started for CSRF and flash messages
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Generate CSRF token if not exists
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
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
            'sqlite_path' => 'database/database.sqlite',
            'mysql_database' => 'cimaise',
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
        
        return $this->view->render($response, 'installer/settings.twig', [
            'site_title' => 'Cimaise',
            'site_description' => 'Professional Photography Portfolio',
            'site_copyright' => '© {year} Photography Portfolio',
            'site_email' => '',
            'site_language' => 'en',
            'admin_language' => 'en',
            'date_format' => 'Y-m-d',
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

        // Verify CSRF token
        $csrf = (string)($data['csrf'] ?? '');
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token. Please try again.'];
            return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
        }

        // Validate required fields
        $siteTitle = trim((string)($data['site_title'] ?? ''));
        if ($siteTitle === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Site title is required.'];
            return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
        }

        $siteEmail = trim((string)($data['site_email'] ?? ''));
        if ($siteEmail === '' || !filter_var($siteEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'A valid contact email is required.'];
            return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
        }

        // Handle logo upload
        $uploadedFiles = $request->getUploadedFiles();
        $logoPath = null;

        if (isset($uploadedFiles['site_logo']) && $uploadedFiles['site_logo']->getError() === UPLOAD_ERR_OK) {
            $logo = $uploadedFiles['site_logo'];
            $allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
            $mediaType = $logo->getClientMediaType();

            if (in_array($mediaType, $allowedTypes, true)) {
                // Validate actual file content (magic bytes + size + dimensions)
                $tmpPath = $logo->getStream()->getMetadata('uri');
                if (!is_string($tmpPath) || !is_file($tmpPath)) {
                    $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid logo upload'];
                    return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
                }

                if (filesize($tmpPath) > 10 * 1024 * 1024) { // 10MB limit
                    $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Logo file too large'];
                    return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $actualType = $finfo->file($tmpPath);
                if (!in_array($actualType, $allowedTypes, true)) {
                    $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid image file'];
                    return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
                }

                $imgInfo = @getimagesize($tmpPath);
                if ($imgInfo === false || ($imgInfo[0] ?? 0) <= 0 || ($imgInfo[1] ?? 0) <= 0) {
                    $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Corrupted image file'];
                    return $response->withHeader('Location', $this->basePath . '/install/settings')->withStatus(302);
                }

                // Create media directory if it doesn't exist
                $mediaDir = $this->rootPath . '/public/media';
                if (!is_dir($mediaDir)) {
                    mkdir($mediaDir, 0755, true);
                }

                // Generate unique filename
                $extension = match ($mediaType) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $filename = 'logo_' . bin2hex(random_bytes(8)) . '.' . $extension;
                $targetPath = $mediaDir . '/' . $filename;

                $logo->moveTo($targetPath);
                $logoPath = '/media/' . $filename;
            }
        }

        // Store settings config in session
        $data['site_logo_path'] = $logoPath;
        $_SESSION['install_settings_config'] = $data;

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
        
        return $this->view->render($response, 'installer/confirm.twig', [
            'db_config' => $_SESSION['install_db_config'],
            'admin_config' => $_SESSION['install_admin_config'],
            'settings_config' => $_SESSION['install_settings_config'],
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
            
            error_log('runInstall: Redirecting to /admin/login');
            return $response->withHeader('Location', $this->basePath . '/admin/login')->withStatus(302);
        } catch (\Throwable $e) {
            error_log('runInstall: Installation failed: ' . $e->getMessage());
            error_log('runInstall: Stack trace: ' . $e->getTraceAsString());
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Installation failed: ' . $e->getMessage()];
            return $response->withHeader('Location', $this->basePath . '/install/confirm')->withStatus(302);
        }
    }

    /**
     * Check system requirements - delegates to Installer for consistency
     */
    private function checkRequirements(): array
    {
        // Pass db config from session if available for connection-specific checks
        $dbConfig = $_SESSION['install_db_config'] ?? [];
        $errors = $this->installer->getRequirementsErrors($dbConfig);
        $warnings = $this->installer->getRequirementsWarnings();

        return [
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * AJAX endpoint to test MySQL connection and detect charset/collation
     */
    public function testMySQLConnection(Request $request, Response $response): Response
    {
        // Check if already installed
        if ($this->installer->isInstalled()) {
            $payload = json_encode([
                'success' => false,
                'error' => 'Installation already completed'
            ]);
            $response->getBody()->write($payload !== false ? $payload : '{"success":false}');
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $data = (array)$request->getParsedBody();

        // Verify CSRF token
        $csrf = (string)($data['csrf'] ?? '');
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $payload = json_encode([
                'success' => false,
                'error' => 'Invalid CSRF token'
            ]);
            $response->getBody()->write($payload !== false ? $payload : '{"success":false}');
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        try {
            // Connect without specifying database first to test credentials
            $dsn = sprintf('mysql:host=%s;port=%d',
                $data['db_host'] ?? '127.0.0.1',
                (int)($data['db_port'] ?? 3306)
            );

            $pdo = new \PDO($dsn,
                $data['db_username'] ?? 'root',
                $data['db_password'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );

            // Get server charset and collation
            $charsetResult = $pdo->query("SHOW VARIABLES LIKE 'character_set_server'")->fetch();
            $collationResult = $pdo->query("SHOW VARIABLES LIKE 'collation_server'")->fetch();

            $charset = $charsetResult['Value'] ?? 'utf8mb4';
            $collation = $collationResult['Value'] ?? 'utf8mb4_unicode_ci';

            // Check if database exists
            $dbName = $data['db_database'] ?? 'cimaise';
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$dbName]);
            $dbExists = $stmt->fetch() !== false;

            $payload = json_encode([
                'success' => true,
                'charset' => $charset,
                'collation' => $collation,
                'database_exists' => $dbExists,
                'message' => $dbExists
                    ? "Connection successful! Database '{$dbName}' exists."
                    : "Connection successful! Database '{$dbName}' will be created during installation."
            ]);

            $response->getBody()->write($payload !== false ? $payload : '{"success":false}');
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $payload = json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            $response->getBody()->write($payload !== false ? $payload : '{"success":false}');
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Test database connection
     */
    private function testDatabaseConnection(array $data): array
    {
        try {
            $connection = $data['db_connection'] ?? 'sqlite';
            
            if ($connection === 'sqlite') {
                $dbPath = $data['sqlite_path'] ?? 'database/database.sqlite';
                // Normalize relative paths against root directory (match Installer::setupDatabase behavior)
                if (!str_starts_with($dbPath, '/')) {
                    $dbPath = $this->rootPath . '/' . $dbPath;
                }
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
                    $data['db_database'] ?? 'cimaise',
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
