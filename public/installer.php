<?php
/**
 * photoCMS Universal Installer
 * 
 * Comprehensive multi-step installer supporting MySQL and SQLite
 * with the app's minimal black/white/silver design
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$rootPath = dirname(__DIR__);
$dbPath = $rootPath . '/database/database.sqlite';
$envPath = $rootPath . '/.env';
$templateDbPath = $rootPath . '/database/template.sqlite';

// Check if already installed
$installed = false;
if (file_exists($envPath) && file_exists($dbPath) && filesize($dbPath) > 0) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM users WHERE role = "admin"');
        $result = $stmt->fetch();
        if ($result['count'] > 0) {
            $installed = true;
        }
    } catch (Exception $e) {
        // Not installed
    }
}

if ($installed) {
    // Detect correct redirect URL
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = dirname($scriptPath); 
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }
    header('Location: ' . $basePath);
    exit;
}

// Get current step
$step = $_GET['step'] ?? 'requirements';
$errors = [];
$success = false;

// Helper functions
function checkRequirements() {
    $rootPath = dirname(__DIR__);
    $checks = [
        'php_version' => version_compare(PHP_VERSION, '8.2.0', '>='),
        'pdo' => extension_loaded('pdo'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'json' => extension_loaded('json'),
        'gd' => extension_loaded('gd'),
        'mbstring' => extension_loaded('mbstring'),
        'openssl' => extension_loaded('openssl'),
        'writable_database' => is_writable($rootPath . '/database'),
        'writable_root' => is_writable($rootPath),
        'writable_public' => is_writable($rootPath . '/public'),
        'template_db_exists' => file_exists($rootPath . '/database/template.sqlite')
    ];
    
    // Check if we can create storage directories
    $storageParent = $rootPath;
    $checks['can_create_storage'] = is_writable($storageParent);
    
    return $checks;
}

function testDatabaseConnection($config) {
    try {
        if ($config['type'] === 'sqlite') {
            $dbPath = dirname(__DIR__) . '/database/' . $config['database'];
            $pdo = new PDO('sqlite:' . $dbPath);
        } else {
            $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['username'], $config['password']);
            
            // Try to create database if it doesn't exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$config['database']}`");
        }
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return ['success' => true, 'pdo' => $pdo];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = dirname($scriptPath);
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }
    
    // Remove /public from the path if present (since document root should be public/)
    if (str_ends_with($basePath, '/public')) {
        $basePath = substr($basePath, 0, -7); // Remove '/public'
    }
    
    return $protocol . '://' . $host . $basePath;
}

// Create required storage directories
function createStorageDirectories($rootPath) {
    $directories = [
        $rootPath . '/storage',
        $rootPath . '/storage/originals',
        $rootPath . '/storage/tmp',
        $rootPath . '/public/media',
        $rootPath . '/public/media/categories',
        $rootPath . '/public/media/about',
        $rootPath . '/public/media/albums',
        $rootPath . '/public/media/thumbnails'
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception('Could not create directory: ' . $dir);
            }
        }
    }
    
    // Create .gitkeep files to preserve empty directories in git
    $keepFiles = [
        $rootPath . '/storage/originals/.gitkeep',
        $rootPath . '/storage/tmp/.gitkeep',
        $rootPath . '/public/media/.gitkeep'
    ];
    
    foreach ($keepFiles as $keepFile) {
        if (!file_exists($keepFile)) {
            file_put_contents($keepFile, '');
        }
    }
}

// Create security files to protect sensitive directories
function createSecurityFiles($rootPath) {
    // 1. Create main .htaccess in root (if not exists)
    $rootHtaccess = $rootPath . '/.htaccess';
    if (!file_exists($rootHtaccess)) {
        $rootHtaccessContent = <<<'HTACCESS'
<IfModule mod_rewrite.c>
  RewriteEngine On
  # If the request is not for an existing file or directory, route into /public
  RewriteCond %{REQUEST_URI} !^/public/
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ public/$0 [L]
</IfModule>
HTACCESS;
        file_put_contents($rootHtaccess, $rootHtaccessContent);
    }
    
    // 2. Create public/.htaccess (if not exists)
    $publicHtaccess = $rootPath . '/public/.htaccess';
    if (!file_exists($publicHtaccess)) {
        $publicHtaccessContent = <<<'HTACCESS'
<IfModule mod_rewrite.c>
  RewriteEngine On
  
  # Serve real files and directories directly
  RewriteCond %{REQUEST_FILENAME} -f [OR]
  RewriteCond %{REQUEST_FILENAME} -d
  RewriteRule ^ - [L]
  
  # Route all other requests to index.php
  RewriteRule ^ index.php [QSA,L]
</IfModule>
HTACCESS;
        file_put_contents($publicHtaccess, $publicHtaccessContent);
    }
    
    // 3. Protect database directory
    $databaseHtaccess = $rootPath . '/database/.htaccess';
    $databaseHtaccessContent = <<<'HTACCESS'
# Deny all access to database files
<Files "*">
    Order Allow,Deny
    Deny from all
</Files>
HTACCESS;
    file_put_contents($databaseHtaccess, $databaseHtaccessContent);
    
    // 4. Protect app directory
    $appHtaccess = $rootPath . '/app/.htaccess';
    $appHtaccessContent = <<<'HTACCESS'
# Deny all access to application files
<Files "*">
    Order Allow,Deny
    Deny from all
</Files>
HTACCESS;
    file_put_contents($appHtaccess, $appHtaccessContent);
    
    // 5. Protect vendor directory
    $vendorHtaccess = $rootPath . '/vendor/.htaccess';
    $vendorHtaccessContent = <<<'HTACCESS'
# Deny all access to vendor files
<Files "*">
    Order Allow,Deny
    Deny from all
</Files>
HTACCESS;
    file_put_contents($vendorHtaccess, $vendorHtaccessContent);
    
    // 6. Protect .env file specifically
    $envHtaccess = $rootPath . '/.htaccess';
    $envProtection = "

# Protect sensitive files
<Files \".env\">
    Order Allow,Deny
    Deny from all
</Files>

<Files \".env.*\">
    Order Allow,Deny
    Deny from all
</Files>

<Files \"composer.json\">
    Order Allow,Deny
    Deny from all
</Files>

<Files \"composer.lock\">
    Order Allow,Deny
    Deny from all
</Files>

<Files \"package.json\">
    Order Allow,Deny
    Deny from all
</Files>

<Files \"package-lock.json\">
    Order Allow,Deny
    Deny from all
</Files>";
    
    // Add protection to existing root .htaccess
    if (file_exists($rootHtaccess)) {
        $currentContent = file_get_contents($rootHtaccess);
        if (strpos($currentContent, '# Protect sensitive files') === false) {
            file_put_contents($rootHtaccess, $currentContent . $envProtection);
        }
    }
    
    // 7. Create robots.txt to prevent indexing of sensitive areas
    $robotsTxt = $rootPath . '/public/robots.txt';
    if (!file_exists($robotsTxt)) {
        $robotsContent = <<<'ROBOTS'
User-agent: *
Disallow: /app/
Disallow: /database/
Disallow: /vendor/
Disallow: /.env
Disallow: /composer.json
Disallow: /composer.lock
Disallow: /package.json
Disallow: /package-lock.json
Disallow: /installer.php
ROBOTS;
        file_put_contents($robotsTxt, $robotsContent);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'database') {
        $dbConfig = [
            'type' => $_POST['db_type'] ?? 'sqlite',
            'host' => trim($_POST['db_host'] ?? 'localhost'),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'database' => ($_POST['db_type'] ?? 'sqlite') === 'sqlite' ? 'database.sqlite' : (trim($_POST['db_database'] ?? 'photocms')),
            'username' => trim($_POST['db_username'] ?? ''),
            'password' => $_POST['db_password'] ?? ''
        ];
        
        // Validate
        if ($dbConfig['type'] === 'mysql') {
            if (empty($dbConfig['host'])) $errors['db_host'] = 'Host is required for MySQL';
            if (empty($dbConfig['database'])) $errors['db_database'] = 'Database name is required';
            if (empty($dbConfig['username'])) $errors['db_username'] = 'Username is required for MySQL';
        }
        // SQLite validation removed - we use fixed database name
        
        // Test connection
        if (empty($errors)) {
            $testResult = testDatabaseConnection($dbConfig);
            if (!$testResult['success']) {
                $errors['connection'] = 'Database connection failed: ' . $testResult['error'];
            } else {
                $_SESSION['db_config'] = $dbConfig;
                header('Location: installer.php?step=admin');
                exit;
            }
        }
        
        $_SESSION['db_errors'] = $errors;
        $_SESSION['db_form_data'] = $dbConfig;
    }
    
    if ($step === 'admin') {
        $adminData = [
            'name' => trim($_POST['admin_name'] ?? ''),
            'email' => trim($_POST['admin_email'] ?? ''),
            'password' => $_POST['admin_password'] ?? '',
            'password_confirm' => $_POST['admin_password_confirm'] ?? '',
        ];
        
        if (empty($adminData['name'])) $errors['admin_name'] = 'Full name is required';
        if (empty($adminData['email']) || !filter_var($adminData['email'], FILTER_VALIDATE_EMAIL)) $errors['admin_email'] = 'Valid email is required';
        if (strlen($adminData['password']) < 8) $errors['admin_password'] = 'Password must be at least 8 characters';
        if ($adminData['password'] !== $adminData['password_confirm']) $errors['admin_password'] = 'Passwords do not match';
        
        if (empty($errors)) {
            $_SESSION['admin_data'] = $adminData;
            header('Location: installer.php?step=settings');
            exit;
        }
        $_SESSION['admin_errors'] = $errors;
        $_SESSION['admin_form_data'] = $adminData;
    }
    
    if ($step === 'settings') {
        $settingsData = [
            'site_title' => trim($_POST['site_title'] ?? 'My Photography'),
            'site_description' => trim($_POST['site_description'] ?? 'A beautiful photography portfolio'),
            'site_copyright' => trim($_POST['site_copyright'] ?? '© ' . date('Y') . ' My Photography'),
            'site_email' => trim($_POST['site_email'] ?? ''),
            'timezone' => $_POST['timezone'] ?? 'Europe/Rome'
        ];
        
        if (empty($settingsData['site_title'])) $errors['site_title'] = 'Site title is required';
        
        if (empty($errors)) {
            $_SESSION['settings_data'] = $settingsData;
            header('Location: installer.php?step=install');
            exit;
        }
        $_SESSION['settings_errors'] = $errors;
        $_SESSION['settings_form_data'] = $settingsData;
    }
    
    if ($step === 'install') {
        try {
            $dbConfig = $_SESSION['db_config'] ?? [];
            $adminData = $_SESSION['admin_data'] ?? [];
            $settingsData = $_SESSION['settings_data'] ?? [];
            
            if (empty($dbConfig) || empty($adminData) || empty($settingsData)) {
                throw new Exception('Installation data incomplete. Please start over.');
            }
            
            // Create .env file
            $appUrl = getCurrentUrl();
            $sessionSecret = bin2hex(random_bytes(32));
            
            $envContent = "APP_ENV=production\n";
            $envContent .= "APP_DEBUG=false\n";
            $envContent .= "APP_URL=$appUrl\n";
            $envContent .= "APP_TIMEZONE={$settingsData['timezone']}\n\n";
            
            if ($dbConfig['type'] === 'sqlite') {
                $envContent .= "DB_CONNECTION=sqlite\n";
                $envContent .= "DB_DATABASE=database/{$dbConfig['database']}\n";
            } else {
                $envContent .= "DB_CONNECTION=mysql\n";
                $envContent .= "DB_HOST={$dbConfig['host']}\n";
                $envContent .= "DB_PORT={$dbConfig['port']}\n";
                $envContent .= "DB_DATABASE={$dbConfig['database']}\n";
                $envContent .= "DB_USERNAME={$dbConfig['username']}\n";
                $envContent .= "DB_PASSWORD={$dbConfig['password']}\n";
            }
            
            $envContent .= "\nSESSION_SECRET=$sessionSecret\n";
            
            if (!file_put_contents($envPath, $envContent)) {
                throw new Exception('Could not create .env file. Check file permissions.');
            }
            
            // Set up database
            if ($dbConfig['type'] === 'sqlite') {
                // Use template.sqlite
                $targetDbPath = $rootPath . '/database/' . $dbConfig['database'];
                if (!copy($templateDbPath, $targetDbPath)) {
                    throw new Exception('Could not copy template database.');
                }
                $pdo = new PDO('sqlite:' . $targetDbPath);
            } else {
                // MySQL setup
                $testResult = testDatabaseConnection($dbConfig);
                if (!$testResult['success']) {
                    throw new Exception('MySQL connection failed: ' . $testResult['error']);
                }
                $pdo = $testResult['pdo'];
                
                // Run complete MySQL schema with data
                $schemaFile = $rootPath . '/database/complete_mysql_schema.sql';
                if (file_exists($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    if ($sql) {
                        // Execute each statement separately
                        $statements = array_filter(array_map('trim', explode(';', $sql)));
                        foreach ($statements as $statement) {
                            if (!empty($statement) && !str_starts_with($statement, '--')) {
                                $pdo->exec($statement . ';');
                            }
                        }
                    }
                } else {
                    throw new Exception('MySQL schema file not found.');
                }
            }
            
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create admin user
            $nameParts = explode(' ', $adminData['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
            
            $hashedPassword = password_hash($adminData['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role, is_active, first_name, last_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $adminData['email'],
                $hashedPassword,
                'admin',
                1,
                $firstName,
                $lastName,
                date('Y-m-d H:i:s')
            ]);
            
            // Update settings
            $settingsToUpdate = [
                'site.title' => $settingsData['site_title'],
                'site.description' => $settingsData['site_description'],
                'site.copyright' => $settingsData['site_copyright'],
                'site.email' => $settingsData['site_email'],
            ];
            
            foreach ($settingsToUpdate as $key => $value) {
                if (!empty($value)) {
                    $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ?)');
                    $stmt->execute([$key, $value, date('Y-m-d H:i:s')]);
                }
            }
            
            // Create required directories
            createStorageDirectories($rootPath);
            
            // Create security files
            createSecurityFiles($rootPath);
            
            // Clear session data
            session_destroy();
            
            $success = true;
            $step = 'complete';
            
        } catch (Exception $e) {
            $errors['install'] = $e->getMessage();
        }
    }
}

// Get form data from session
$dbFormData = $_SESSION['db_form_data'] ?? [];
$dbErrors = $_SESSION['db_errors'] ?? [];
$adminFormData = $_SESSION['admin_form_data'] ?? [];
$adminErrors = $_SESSION['admin_errors'] ?? [];
$settingsFormData = $_SESSION['settings_form_data'] ?? [];
$settingsErrors = $_SESSION['settings_errors'] ?? [];

// Clear session errors after displaying
unset($_SESSION['db_errors'], $_SESSION['admin_errors'], $_SESSION['settings_errors']);

// Get requirements check
$requirements = checkRequirements();
$requirementsPassed = !in_array(false, array_values($requirements));
?><!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>photoCMS Installer</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        
        .step-indicator {
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 16px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }
        
        .step {
            position: relative;
            z-index: 2;
            background: white;
        }
        
        .step.active .step-circle {
            background: #000;
            color: white;
        }
        
        .step.completed .step-circle {
            background: #10b981;
            color: white;
        }
        
        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            margin: 0 auto 8px;
        }
        
        .btn-primary {
            background: #000;
            color: white;
            border: 1px solid #000;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: #374151;
            border-color: #374151;
        }
        
        .btn-secondary {
            background: #f8fafc;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #f1f5f9;
            border-color: #9ca3af;
        }
        
        .form-input {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 12px;
            transition: border-color 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #000;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-input.error {
            border-color: #ef4444;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #f1f5f9;
        }
        
        .requirement-check {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .requirement-check:last-child {
            border-bottom: none;
        }
        
        .check-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 12px;
        }
        
        .check-icon.success {
            background: #10b981;
            color: white;
        }
        
        .check-icon.error {
            background: #ef4444;
            color: white;
        }
    </style>
</head>
<body class="h-full bg-gray-50">
    <div class="min-h-full flex flex-col">
        <!-- Header -->
        <div class="bg-white border-b border-gray-200">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="py-6 text-center">
                    <h1 class="text-3xl font-light text-black">
                        <i class="fas fa-camera mr-3"></i>photoCMS
                    </h1>
                    <p class="text-gray-600 mt-2">Installation Setup</p>
                </div>
            </div>
        </div>
        
        <!-- Step indicator -->
        <div class="bg-white border-b border-gray-200">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <div class="step-indicator">
                    <div class="flex justify-between">
                        <div class="step <?= $step === 'requirements' ? 'active' : ($step !== 'requirements' && $step !== 'database' && $step !== 'admin' && $step !== 'settings' && $step !== 'install' ? '' : 'completed') ?>">
                            <div class="step-circle">1</div>
                            <div class="text-xs text-center font-medium">Requirements</div>
                        </div>
                        <div class="step <?= $step === 'database' ? 'active' : (in_array($step, ['admin', 'settings', 'install', 'complete']) ? 'completed' : '') ?>">
                            <div class="step-circle">2</div>
                            <div class="text-xs text-center font-medium">Database</div>
                        </div>
                        <div class="step <?= $step === 'admin' ? 'active' : (in_array($step, ['settings', 'install', 'complete']) ? 'completed' : '') ?>">
                            <div class="step-circle">3</div>
                            <div class="text-xs text-center font-medium">Admin User</div>
                        </div>
                        <div class="step <?= $step === 'settings' ? 'active' : (in_array($step, ['install', 'complete']) ? 'completed' : '') ?>">
                            <div class="step-circle">4</div>
                            <div class="text-xs text-center font-medium">Settings</div>
                        </div>
                        <div class="step <?= $step === 'install' ? 'active' : ($step === 'complete' ? 'completed' : '') ?>">
                            <div class="step-circle">5</div>
                            <div class="text-xs text-center font-medium">Install</div>
                        </div>
                        <div class="step <?= $step === 'complete' ? 'active' : '' ?>">
                            <div class="step-circle">6</div>
                            <div class="text-xs text-center font-medium">Complete</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main content -->
        <div class="flex-1 py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                
                <?php if ($step === 'requirements'): ?>
                    <div class="card p-8">
                        <h2 class="text-2xl font-light text-black mb-6">System Requirements</h2>
                        <p class="text-gray-600 mb-8">Checking if your server meets the requirements for photoCMS.</p>
                        
                        <div class="space-y-1">
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['php_version'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['php_version'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">PHP 8.2+</div>
                                    <div class="text-sm text-gray-500">Current: <?= PHP_VERSION ?></div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['pdo'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['pdo'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">PDO Extension</div>
                                    <div class="text-sm text-gray-500">Database connectivity</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['pdo_sqlite'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['pdo_sqlite'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">SQLite Support</div>
                                    <div class="text-sm text-gray-500">Default database option</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['pdo_mysql'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['pdo_mysql'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">MySQL Support</div>
                                    <div class="text-sm text-gray-500">Alternative database option</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['gd'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['gd'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">GD Extension</div>
                                    <div class="text-sm text-gray-500">Image processing</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['mbstring'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['mbstring'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">Mbstring Extension</div>
                                    <div class="text-sm text-gray-500">String handling</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['openssl'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['openssl'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">OpenSSL Extension</div>
                                    <div class="text-sm text-gray-500">Security features</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['writable_database'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['writable_database'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">Database Directory Writable</div>
                                    <div class="text-sm text-gray-500">/database directory permissions</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['template_db_exists'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['template_db_exists'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">Template Database</div>
                                    <div class="text-sm text-gray-500">Pre-configured database template</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['writable_root'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['writable_root'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">Root Directory Writable</div>
                                    <div class="text-sm text-gray-500">Project root permissions</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['writable_public'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['writable_public'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">Public Directory Writable</div>
                                    <div class="text-sm text-gray-500">/public directory permissions</div>
                                </div>
                            </div>
                            
                            <div class="requirement-check">
                                <div class="check-icon <?= $requirements['can_create_storage'] ? 'success' : 'error' ?>">
                                    <i class="fas fa-<?= $requirements['can_create_storage'] ? 'check' : 'times' ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium">Can Create Storage Directories</div>
                                    <div class="text-sm text-gray-500">Permissions to create required directories</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-8 pt-6 border-t border-gray-100">
                            <div class="text-center">
                                <p class="text-sm text-gray-600 mb-4">
                                    Detected Installation URL: <strong><?= getCurrentUrl() ?></strong>
                                </p>
                                
                                <?php if ($requirementsPassed): ?>
                                    <a href="installer.php?step=database" class="btn-primary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                        <i class="fas fa-arrow-right mr-2"></i>Continue to Database Setup
                                    </a>
                                <?php else: ?>
                                    <div class="text-red-600 mb-4">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        Please fix the failed requirements before continuing.
                                    </div>
                                    <button onclick="window.location.reload()" class="btn-secondary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                        <i class="fas fa-sync mr-2"></i>Recheck Requirements
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($step === 'database'): ?>
                    <div class="card p-8">
                        <h2 class="text-2xl font-light text-black mb-6">Database Configuration</h2>
                        <p class="text-gray-600 mb-8">Configure your database connection. SQLite is recommended for most installations.</p>
                        
                        <?php if (isset($errors['connection'])): ?>
                            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($errors['connection']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="installer.php?step=database">
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-3">Database Type</label>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-gray-50 <?= ($dbFormData['type'] ?? 'sqlite') === 'sqlite' ? 'border-black bg-gray-50' : 'border-gray-200' ?>">
                                        <input type="radio" name="db_type" value="sqlite" class="mr-3" <?= ($dbFormData['type'] ?? 'sqlite') === 'sqlite' ? 'checked' : '' ?>>
                                        <div>
                                            <div class="font-medium">SQLite</div>
                                            <div class="text-sm text-gray-500">Recommended - Simple file-based database</div>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-gray-50 <?= ($dbFormData['type'] ?? 'sqlite') === 'mysql' ? 'border-black bg-gray-50' : 'border-gray-200' ?>">
                                        <input type="radio" name="db_type" value="mysql" class="mr-3" <?= ($dbFormData['type'] ?? 'sqlite') === 'mysql' ? 'checked' : '' ?>>
                                        <div>
                                            <div class="font-medium">MySQL</div>
                                            <div class="text-sm text-gray-500">For larger installations</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- SQLite Config -->
                            <div id="sqlite-config" class="<?= ($dbFormData['type'] ?? 'sqlite') === 'sqlite' ? '' : 'hidden' ?>">
                                <div class="mb-6">
                                    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                                        <div class="flex items-start">
                                            <i class="fas fa-check-circle mr-2 mt-0.5"></i>
                                            <div>
                                                <div class="font-medium">SQLite Configuration</div>
                                                <div class="text-sm">Database will be automatically created as <code class="bg-green-100 px-1 rounded">database.sqlite</code> in the /database directory.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- MySQL Config -->
                            <div id="mysql-config" class="space-y-6 <?= ($dbFormData['type'] ?? 'sqlite') === 'mysql' ? '' : 'hidden' ?>">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Host</label>
                                        <input type="text" name="db_host" class="form-input w-full <?= isset($dbErrors['db_host']) ? 'error' : '' ?>" 
                                               value="<?= htmlspecialchars($dbFormData['host'] ?? 'localhost') ?>" placeholder="localhost">
                                        <?php if (isset($dbErrors['db_host'])): ?>
                                            <div class="text-red-600 text-sm mt-1"><?= $dbErrors['db_host'] ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Port</label>
                                        <input type="number" name="db_port" class="form-input w-full" 
                                               value="<?= htmlspecialchars($dbFormData['port'] ?? '3306') ?>" placeholder="3306">
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Database Name</label>
                                    <input type="text" name="db_database" class="form-input w-full <?= isset($dbErrors['db_database']) ? 'error' : '' ?>" 
                                           value="<?= htmlspecialchars($dbFormData['database'] ?? 'photocms') ?>" placeholder="photocms">
                                    <?php if (isset($dbErrors['db_database'])): ?>
                                        <div class="text-red-600 text-sm mt-1"><?= $dbErrors['db_database'] ?></div>
                                    <?php endif; ?>
                                    <div class="text-sm text-gray-500 mt-1">Database will be created if it doesn't exist</div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                        <input type="text" name="db_username" class="form-input w-full <?= isset($dbErrors['db_username']) ? 'error' : '' ?>" 
                                               value="<?= htmlspecialchars($dbFormData['username'] ?? '') ?>" placeholder="root">
                                        <?php if (isset($dbErrors['db_username'])): ?>
                                            <div class="text-red-600 text-sm mt-1"><?= $dbErrors['db_username'] ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                                        <input type="password" name="db_password" class="form-input w-full" 
                                               value="<?= htmlspecialchars($dbFormData['password'] ?? '') ?>" placeholder="Password (optional)">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-between pt-6">
                                <a href="installer.php?step=requirements" class="btn-secondary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i>Back
                                </a>
                                <button type="submit" class="btn-primary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-database mr-2"></i>Test & Continue
                                </button>
                            </div>
                        </form>
                    </div>
                    
                <?php elseif ($step === 'admin'): ?>
                    <div class="card p-8">
                        <h2 class="text-2xl font-light text-black mb-6">Admin User Account</h2>
                        <p class="text-gray-600 mb-8">Create your first admin user account to manage photoCMS.</p>
                        
                        <form method="post" action="installer.php?step=admin">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                                    <input type="text" name="admin_name" class="form-input w-full <?= isset($adminErrors['admin_name']) ? 'error' : '' ?>" 
                                           value="<?= htmlspecialchars($adminFormData['name'] ?? '') ?>" placeholder="Your full name" required>
                                    <?php if (isset($adminErrors['admin_name'])): ?>
                                        <div class="text-red-600 text-sm mt-1"><?= $adminErrors['admin_name'] ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                                    <input type="email" name="admin_email" class="form-input w-full <?= isset($adminErrors['admin_email']) ? 'error' : '' ?>" 
                                           value="<?= htmlspecialchars($adminFormData['email'] ?? '') ?>" placeholder="admin@example.com" required>
                                    <?php if (isset($adminErrors['admin_email'])): ?>
                                        <div class="text-red-600 text-sm mt-1"><?= $adminErrors['admin_email'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                                    <input type="password" name="admin_password" class="form-input w-full <?= isset($adminErrors['admin_password']) ? 'error' : '' ?>" 
                                           placeholder="At least 8 characters" required>
                                    <?php if (isset($adminErrors['admin_password'])): ?>
                                        <div class="text-red-600 text-sm mt-1"><?= $adminErrors['admin_password'] ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                                    <input type="password" name="admin_password_confirm" class="form-input w-full" 
                                           placeholder="Repeat password" required>
                                </div>
                            </div>
                            
                            <div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg mb-6">
                                <div class="flex items-start">
                                    <i class="fas fa-info-circle mr-2 mt-0.5"></i>
                                    <div>
                                        <div class="font-medium">Important</div>
                                        <div class="text-sm">This account will have full administrative privileges. Use a strong password and keep your credentials secure.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-between pt-6">
                                <a href="installer.php?step=database" class="btn-secondary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i>Back
                                </a>
                                <button type="submit" class="btn-primary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-user-check mr-2"></i>Create Admin User
                                </button>
                            </div>
                        </form>
                    </div>
                    
                <?php elseif ($step === 'settings'): ?>
                    <div class="card p-8">
                        <h2 class="text-2xl font-light text-black mb-6">Site Settings</h2>
                        <p class="text-gray-600 mb-8">Configure your site's basic information and preferences.</p>
                        
                        <form method="post" action="installer.php?step=settings">
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Site Title</label>
                                <input type="text" name="site_title" class="form-input w-full <?= isset($settingsErrors['site_title']) ? 'error' : '' ?>" 
                                       value="<?= htmlspecialchars($settingsFormData['site_title'] ?? 'My Photography') ?>" placeholder="My Photography" required>
                                <?php if (isset($settingsErrors['site_title'])): ?>
                                    <div class="text-red-600 text-sm mt-1"><?= $settingsErrors['site_title'] ?></div>
                                <?php endif; ?>
                                <div class="text-sm text-gray-500 mt-1">This will appear in the browser title and header</div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Site Description</label>
                                <textarea name="site_description" class="form-input w-full" rows="3" 
                                          placeholder="A beautiful photography portfolio"><?= htmlspecialchars($settingsFormData['site_description'] ?? 'A beautiful photography portfolio') ?></textarea>
                                <div class="text-sm text-gray-500 mt-1">A short description for SEO and social media</div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Contact Email</label>
                                    <input type="email" name="site_email" class="form-input w-full" 
                                           value="<?= htmlspecialchars($settingsFormData['site_email'] ?? ($_SESSION['admin_data']['email'] ?? '')) ?>" placeholder="contact@example.com">
                                    <div class="text-sm text-gray-500 mt-1">For contact form submissions</div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Timezone</label>
                                    <select name="timezone" class="form-input w-full">
                                        <option value="Europe/Rome" <?= ($settingsFormData['timezone'] ?? 'Europe/Rome') === 'Europe/Rome' ? 'selected' : '' ?>>Europe/Rome</option>
                                        <option value="UTC" <?= ($settingsFormData['timezone'] ?? '') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                                        <option value="America/New_York" <?= ($settingsFormData['timezone'] ?? '') === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                                        <option value="America/Los_Angeles" <?= ($settingsFormData['timezone'] ?? '') === 'America/Los_Angeles' ? 'selected' : '' ?>>America/Los_Angeles</option>
                                        <option value="Europe/London" <?= ($settingsFormData['timezone'] ?? '') === 'Europe/London' ? 'selected' : '' ?>>Europe/London</option>
                                        <option value="Europe/Paris" <?= ($settingsFormData['timezone'] ?? '') === 'Europe/Paris' ? 'selected' : '' ?>>Europe/Paris</option>
                                        <option value="Europe/Berlin" <?= ($settingsFormData['timezone'] ?? '') === 'Europe/Berlin' ? 'selected' : '' ?>>Europe/Berlin</option>
                                        <option value="Asia/Tokyo" <?= ($settingsFormData['timezone'] ?? '') === 'Asia/Tokyo' ? 'selected' : '' ?>>Asia/Tokyo</option>
                                        <option value="Australia/Sydney" <?= ($settingsFormData['timezone'] ?? '') === 'Australia/Sydney' ? 'selected' : '' ?>>Australia/Sydney</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Copyright Notice</label>
                                <input type="text" name="site_copyright" class="form-input w-full" 
                                       value="<?= htmlspecialchars($settingsFormData['site_copyright'] ?? '© ' . date('Y') . ' My Photography') ?>" placeholder="© <?= date('Y') ?> My Photography">
                                <div class="text-sm text-gray-500 mt-1">Will appear in the site footer</div>
                            </div>
                            
                            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                                <div class="flex items-start">
                                    <i class="fas fa-lightbulb mr-2 mt-0.5"></i>
                                    <div>
                                        <div class="font-medium">Ready to Go</div>
                                        <div class="text-sm">The installer will use the pre-configured template database with default categories, templates, and settings to get you started quickly.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-between pt-6">
                                <a href="installer.php?step=admin" class="btn-secondary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i>Back
                                </a>
                                <button type="submit" class="btn-primary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-cog mr-2"></i>Configure Site
                                </button>
                            </div>
                        </form>
                    </div>
                    
                <?php elseif ($step === 'install'): ?>
                    <div class="card p-8">
                        <h2 class="text-2xl font-light text-black mb-6">Ready to Install</h2>
                        
                        <?php if (!empty($errors['install'])): ?>
                            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($errors['install']) ?>
                            </div>
                            <div class="text-center">
                                <a href="installer.php?step=settings" class="btn-secondary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to Settings
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-600 mb-8">Please review your configuration and click install to complete the setup.</p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                                <div>
                                    <h3 class="font-medium text-gray-900 mb-4"><i class="fas fa-database mr-2"></i>Database</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="text-sm">
                                            <div><strong>Type:</strong> <?= ucfirst($_SESSION['db_config']['type'] ?? 'SQLite') ?></div>
                                            <?php if (($_SESSION['db_config']['type'] ?? 'sqlite') === 'mysql'): ?>
                                                <div><strong>Host:</strong> <?= htmlspecialchars($_SESSION['db_config']['host'] ?? '') ?></div>
                                                <div><strong>Database:</strong> <?= htmlspecialchars($_SESSION['db_config']['database'] ?? '') ?></div>
                                            <?php else: ?>
                                                <div><strong>File:</strong> database.sqlite</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <h3 class="font-medium text-gray-900 mb-4"><i class="fas fa-user mr-2"></i>Admin User</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="text-sm">
                                            <div><strong>Name:</strong> <?= htmlspecialchars($_SESSION['admin_data']['name'] ?? '') ?></div>
                                            <div><strong>Email:</strong> <?= htmlspecialchars($_SESSION['admin_data']['email'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <h3 class="font-medium text-gray-900 mb-4"><i class="fas fa-globe mr-2"></i>Site Configuration</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="text-sm">
                                            <div><strong>Title:</strong> <?= htmlspecialchars($_SESSION['settings_data']['site_title'] ?? '') ?></div>
                                            <div><strong>URL:</strong> <?= getCurrentUrl() ?></div>
                                            <div><strong>Timezone:</strong> <?= htmlspecialchars($_SESSION['settings_data']['timezone'] ?? 'Europe/Rome') ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <h3 class="font-medium text-gray-900 mb-4"><i class="fas fa-magic mr-2"></i>What Will Be Installed</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="text-sm space-y-1">
                                            <div>✓ Database tables and structure</div>
                                            <div>✓ Default categories and templates</div>
                                            <div>✓ Admin user account</div>
                                            <div>✓ Configuration files</div>
                                            <div>✓ Security .htaccess files</div>
                                            <div>✓ Ready-to-use gallery system</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg mb-8">
                                <div class="flex items-start">
                                    <i class="fas fa-exclamation-triangle mr-2 mt-0.5"></i>
                                    <div>
                                        <div class="font-medium">Final Step</div>
                                        <div class="text-sm">This will install photoCMS with your configuration. This process cannot be undone.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <form method="post" action="installer.php?step=install">
                                <div class="flex justify-between">
                                    <a href="installer.php?step=settings" class="btn-secondary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                        <i class="fas fa-arrow-left mr-2"></i>Back
                                    </a>
                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-lg font-medium inline-flex items-center text-lg">
                                        <i class="fas fa-rocket mr-2"></i>Install photoCMS
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    
                <?php elseif ($step === 'complete'): ?>
                    <div class="card p-8 text-center">
                        <div class="mb-6">
                            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-check text-green-600 text-2xl"></i>
                            </div>
                            <h2 class="text-3xl font-light text-black mb-4">Installation Complete!</h2>
                            <p class="text-gray-600 text-lg">photoCMS has been successfully installed and configured.</p>
                        </div>
                        
                        <div class="bg-green-50 border border-green-200 text-green-700 px-6 py-4 rounded-lg mb-8">
                            <div class="font-medium mb-2">🎉 What's Next?</div>
                            <div class="text-sm text-left space-y-1">
                                <div>• Login to the admin panel to start managing your content</div>
                                <div>• Upload your first photos and create albums</div>
                                <div>• Customize your site settings and templates</div>
                                <div>• Explore the gallery features and templates</div>
                            </div>
                        </div>
                        
                        <div class="bg-blue-50 border border-blue-200 text-blue-700 px-6 py-4 rounded-lg mb-8">
                            <div class="font-medium mb-2">🔒 Security Features Activated</div>
                            <div class="text-sm text-left space-y-1">
                                <div>• Protected database directory from web access</div>
                                <div>• Secured .env and configuration files</div>
                                <div>• Protected app/ and vendor/ directories</div>
                                <div>• Created URL rewriting rules</div>
                                <div>• Generated robots.txt to prevent sensitive file indexing</div>
                                <div>• All sensitive files are now protected from direct access</div>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <a href="<?= getCurrentUrl() ?>" class="btn-primary px-8 py-4 rounded-lg font-medium inline-flex items-center text-lg">
                                <i class="fas fa-home mr-2"></i>Visit Your Site
                            </a>
                            <div>
                                <a href="<?= getCurrentUrl() ?>/admin/login" class="btn-secondary px-6 py-3 rounded-lg font-medium inline-flex items-center">
                                    <i class="fas fa-sign-in-alt mr-2"></i>Admin Login
                                </a>
                            </div>
                        </div>
                        
                        <div class="mt-8 pt-6 border-t border-gray-100 text-sm text-gray-500">
                            <p>For security, you may want to remove this installer file after successful installation.</p>
                        </div>
                    </div>
                    
                <?php endif; ?>
                
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-white border-t border-gray-200">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="text-center text-sm text-gray-500">
                    photoCMS Installer • 
                    PHP <?= PHP_VERSION ?> • 
                    <?= date('Y') ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Database type switching
        document.addEventListener('DOMContentLoaded', function() {
            const dbTypeRadios = document.querySelectorAll('input[name="db_type"]');
            const sqliteConfig = document.getElementById('sqlite-config');
            const mysqlConfig = document.getElementById('mysql-config');
            
            dbTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'sqlite') {
                        sqliteConfig.classList.remove('hidden');
                        mysqlConfig.classList.add('hidden');
                    } else {
                        sqliteConfig.classList.add('hidden');
                        mysqlConfig.classList.remove('hidden');
                    }
                });
            });
        });
    </script>
</body>
</html>