<?php
/**
 * Simple photoCMS Installer - Works in any server environment
 * 
 * This standalone installer automatically detects the server configuration
 * and installs photoCMS correctly, whether in root or subdirectory.
 */

// Prevent multiple installations
session_start();
$rootPath = dirname(__DIR__);
$dbPath = $rootPath . '/database/app.db';
$envPath = $rootPath . '/.env';

// Check if already installed
$installed = false;
if (file_exists($envPath) && file_exists($dbPath) && filesize($dbPath) > 0) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
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
    $basePath = dirname($scriptPath); // Remove /simple-installer.php
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }
    header('Location: ' . $basePath . '/index.php');
    exit;
}

$errors = [];
$success = false;

// Handle installation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';
    $siteTitle = trim($_POST['site_title'] ?? 'My Photography');
    $siteDescription = trim($_POST['site_description'] ?? 'A beautiful photography portfolio');
    
    // Validation
    if (empty($adminName)) $errors[] = 'Admin name is required';
    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid admin email is required';
    if (strlen($adminPassword) < 8) $errors[] = 'Password must be at least 8 characters';
    if ($adminPassword !== $adminPasswordConfirm) $errors[] = 'Passwords do not match';
    
    if (empty($errors)) {
        try {
            // Detect base URL correctly
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = dirname($scriptPath); // Remove /simple-installer.php  
            if ($basePath === '/' || $basePath === '\\') {
                $basePath = '';
            }
            $appUrl = $protocol . '://' . $host . $basePath;
            
            // Create .env file
            $sessionSecret = bin2hex(random_bytes(32));
            $envContent = "APP_ENV=production
APP_DEBUG=false
APP_URL=$appUrl
APP_TIMEZONE=Europe/Rome

DB_CONNECTION=sqlite
DB_DATABASE=database/app.db

SESSION_SECRET=$sessionSecret
";
            
            if (!file_put_contents($envPath, $envContent)) {
                throw new Exception('Could not create .env file. Check file permissions.');
            }
            
            // Create SQLite database
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Run migrations
            $migrationFiles = glob($rootPath . '/database/migrations/sqlite/*.sql');
            if ($migrationFiles) {
                sort($migrationFiles);
                foreach ($migrationFiles as $file) {
                    $sql = file_get_contents($file);
                    if ($sql) {
                        $pdo->exec($sql);
                    }
                }
            }
            
            // Run seeds
            $seedFiles = glob($rootPath . '/database/seeds/sqlite/*.sql');
            if ($seedFiles) {
                sort($seedFiles);
                foreach ($seedFiles as $file) {
                    $sql = file_get_contents($file);
                    if ($sql) {
                        $pdo->exec($sql);
                    }
                }
            }
            
            // Create admin user
            $nameParts = explode(' ', $adminName, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
            
            $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role, is_active, first_name, last_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $adminEmail,
                $hashedPassword,
                'admin',
                1,
                $firstName,
                $lastName,
                date('Y-m-d H:i:s')
            ]);
            
            // Update settings
            $settingsToUpdate = [
                'site.title' => $siteTitle,
                'site.description' => $siteDescription,
                'site.copyright' => '© ' . date('Y') . ' ' . $siteTitle,
                'site.email' => $adminEmail,
            ];
            
            foreach ($settingsToUpdate as $key => $value) {
                $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ?)');
                $stmt->execute([$key, $value, date('Y-m-d H:i:s')]);
            }
            
            $success = true;
            
        } catch (Exception $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

// Get current URL info for display
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = dirname($scriptPath);
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}
$currentUrl = $protocol . '://' . $host . $basePath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>photoCMS Simple Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 600px; margin-top: 3rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .btn-primary { background: linear-gradient(45deg, #667eea, #764ba2); border: none; border-radius: 25px; }
        .btn-primary:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center text-white mb-4">
            <h1 class="display-4">📸 photoCMS</h1>
            <p class="lead">Simple Installation</p>
        </div>
        
        <div class="card">
            <div class="card-body p-5">
                <?php if ($success): ?>
                    <div class="text-center">
                        <div class="text-success mb-4" style="font-size: 4rem;">✅</div>
                        <h3 class="text-success mb-4">Installation Complete!</h3>
                        <p class="mb-4">photoCMS has been successfully installed at:</p>
                        <p class="alert alert-info"><strong><?= htmlspecialchars($currentUrl) ?></strong></p>
                        
                        <div class="d-grid gap-2">
                            <a href="index.php" class="btn btn-primary btn-lg">
                                🏠 Visit Your Site
                            </a>
                            <a href="index.php/admin/login" class="btn btn-outline-secondary">
                                🔐 Admin Login
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <h3 class="mb-4">🚀 Install photoCMS</h3>
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <strong>Please fix the following errors:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-info mb-4">
                        <strong>📍 Detected Installation Path:</strong><br>
                        <code><?= htmlspecialchars($currentUrl) ?></code>
                    </div>
                    
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">👤 Admin Full Name</label>
                            <input type="text" class="form-control" name="admin_name" 
                                   value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" 
                                   placeholder="Your full name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">📧 Admin Email</label>
                            <input type="email" class="form-control" name="admin_email" 
                                   value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" 
                                   placeholder="admin@example.com" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">🔒 Password</label>
                                    <input type="password" class="form-control" name="admin_password" 
                                           placeholder="At least 8 characters" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">🔒 Confirm Password</label>
                                    <input type="password" class="form-control" name="admin_password_confirm" 
                                           placeholder="Repeat password" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">🌐 Site Title</label>
                            <input type="text" class="form-control" name="site_title" 
                                   value="<?= htmlspecialchars($_POST['site_title'] ?? 'My Photography') ?>" 
                                   required>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">📝 Site Description</label>
                            <textarea class="form-control" name="site_description" rows="2" 
                                      placeholder="A brief description of your photography website"><?= htmlspecialchars($_POST['site_description'] ?? 'A beautiful photography portfolio') ?></textarea>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                🚀 Install photoCMS
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-4 text-muted small">
                        <strong>System Info:</strong><br>
                        PHP <?= PHP_VERSION ?> | SQLite Ready | 
                        Path: <?= htmlspecialchars($rootPath) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>