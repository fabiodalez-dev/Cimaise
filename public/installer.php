<?php
// Beautiful photoCMS installer with all original fields
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
    header('Location: index.php');
    exit;
}

// Get current step
$step = $_GET['step'] ?? 'welcome';
$errors = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'admin') {
        // Validate admin form
        $adminData = [
            'name' => trim($_POST['admin_name'] ?? ''),
            'email' => trim($_POST['admin_email'] ?? ''),
            'password' => $_POST['admin_password'] ?? '',
            'password_confirm' => $_POST['admin_password_confirm'] ?? '',
        ];
        
        if (empty($adminData['name'])) $errors['admin_name'] = 'Full name is required';
        if (empty($adminData['email'])) $errors['admin_email'] = 'Email is required';
        if (!filter_var($adminData['email'], FILTER_VALIDATE_EMAIL)) $errors['admin_email'] = 'Valid email is required';
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
        // Validate settings form
        $settingsData = [
            'site_title' => trim($_POST['site_title'] ?? 'My Photography'),
            'site_description' => trim($_POST['site_description'] ?? 'A beautiful photography portfolio'),
            'site_copyright' => trim($_POST['site_copyright'] ?? '© 2024 My Photography'),
            'site_email' => trim($_POST['site_email'] ?? ''),
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
        // Perform installation
        try {
            $adminData = $_SESSION['admin_data'] ?? [];
            $settingsData = $_SESSION['settings_data'] ?? [];
            
            // Create .env file
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            // Detect base path from current script location
            $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = dirname(dirname($scriptPath)); // Remove /public/installer.php to get base
            if ($basePath === '/' || $basePath === '\\') {
                $basePath = '';
            }
            $appUrl = $protocol . '://' . $host . $basePath;
            
            $sessionSecret = bin2hex(random_bytes(32));
            
            $envContent = "APP_ENV=production
APP_DEBUG=false
APP_URL=$appUrl
APP_TIMEZONE=Europe/Rome

DB_CONNECTION=sqlite
DB_DATABASE=database/app.db

SESSION_SECRET=$sessionSecret
";
            
            file_put_contents($envPath, $envContent);
            
            // Copy template database or create new one
            $templateDb = $rootPath . '/database/template.sqlite';
            if (file_exists($templateDb)) {
                copy($templateDb, $dbPath);
            } else {
                // Create database and run migrations
                $pdo = new PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $migrationFiles = glob($rootPath . '/database/migrations/sqlite/*.sql');
                if ($migrationFiles) {
                    sort($migrationFiles);
                    foreach ($migrationFiles as $file) {
                        $sql = file_get_contents($file);
                        $pdo->exec($sql);
                    }
                }
                
                $seedFiles = glob($rootPath . '/database/seeds/sqlite/*.sql');
                if ($seedFiles) {
                    sort($seedFiles);
                    foreach ($seedFiles as $file) {
                        $sql = file_get_contents($file);
                        $pdo->exec($sql);
                    }
                }
            }
            
            // Create admin user
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Parse name into first and last name
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
            
            // Clear session data
            session_destroy();
            
            $step = 'complete';
            
        } catch (Exception $e) {
            $errors['install'] = $e->getMessage();
        }
    }
}

// Get form data from session
$adminFormData = $_SESSION['admin_form_data'] ?? [];
$adminErrors = $_SESSION['admin_errors'] ?? [];
$settingsFormData = $_SESSION['settings_form_data'] ?? [];
$settingsErrors = $_SESSION['settings_errors'] ?? [];

// Clear session errors after displaying
unset($_SESSION['admin_errors'], $_SESSION['settings_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>photoCMS Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .installer-container { max-width: 800px; margin: 2rem auto; }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 2rem; }
        .step { text-align: center; flex: 1; position: relative; }
        .step:not(:last-child):after {
            content: ''; position: absolute; top: 14px; right: 0; width: 100%; height: 2px;
            background: #dee2e6; z-index: 1;
        }
        .step.active .step-number { background: #0d6efd; color: white; }
        .step.completed .step-number { background: #198754; color: white; }
        .step-number {
            width: 30px; height: 30px; border-radius: 50%; background: #e9ecef; color: #6c757d;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 0.5rem;
            position: relative; z-index: 2;
        }
        .step-label { font-size: 0.875rem; color: #6c757d; }
        .step.active .step-label { color: #0d6efd; font-weight: 500; }
        .step.completed .step-label { color: #198754; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .btn-primary { background-color: #212529; border-color: #212529; }
        .btn-primary:hover { background-color: #000; border-color: #000; }
    </style>
</head>
<body>
    <div class="installer-container">
        <div class="text-center mb-4">
            <h1 class="display-6"><i class="fas fa-camera me-2"></i>photoCMS Installer</h1>
            <p class="text-muted">Complete the installation process to get started</p>
        </div>
        
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step <?= $step === 'welcome' ? 'active' : ($step !== 'welcome' ? 'completed' : '') ?>">
                <div class="step-number">1</div>
                <div class="step-label">Welcome</div>
            </div>
            <div class="step <?= $step === 'admin' ? 'active' : (in_array($step, ['settings', 'install', 'complete']) ? 'completed' : '') ?>">
                <div class="step-number">2</div>
                <div class="step-label">Admin User</div>
            </div>
            <div class="step <?= $step === 'settings' ? 'active' : (in_array($step, ['install', 'complete']) ? 'completed' : '') ?>">
                <div class="step-number">3</div>
                <div class="step-label">Settings</div>
            </div>
            <div class="step <?= $step === 'install' ? 'active' : ($step === 'complete' ? 'completed' : '') ?>">
                <div class="step-number">4</div>
                <div class="step-label">Install</div>
            </div>
            <div class="step <?= $step === 'complete' ? 'active' : '' ?>">
                <div class="step-number">5</div>
                <div class="step-label">Complete</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <?php if ($step === 'welcome'): ?>
                    <h3 class="mb-4"><i class="fas fa-rocket me-2"></i>Welcome to photoCMS</h3>
                    <p class="lead">This installer will guide you through the process of setting up photoCMS on your server.</p>
                    
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle me-2"></i>System Requirements</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><i class="fas fa-check text-success me-2"></i>PHP <?= PHP_VERSION ?></p>
                                <p class="mb-1"><i class="fas fa-check text-success me-2"></i>SQLite Support</p>
                                <p class="mb-1"><i class="fas fa-check text-success me-2"></i>File Permissions</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><i class="fas fa-info-circle me-2"></i>Detected URL: <strong><?php
                                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
                                    $basePath = dirname(dirname($scriptPath));
                                    if ($basePath === '/' || $basePath === '\\') {
                                        $basePath = '';
                                    }
                                    echo $protocol . '://' . $host . $basePath;
                                ?></strong></p>
                                <p class="mb-1"><i class="fas fa-info-circle me-2"></i>Database: SQLite</p>
                                <p class="mb-1"><i class="fas fa-info-circle me-2"></i>Path: <?= $rootPath ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5><i class="fas fa-info-circle me-2"></i>What This Installer Will Do</h5>
                        <ul>
                            <li>Create database tables and seed default data</li>
                            <li>Create your first admin user account</li>
                            <li>Set up initial site configuration</li>
                            <li>Create default templates and categories</li>
                            <li>Generate configuration files</li>
                        </ul>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="installer.php?step=admin" class="btn btn-primary btn-lg">
                            <i class="fas fa-arrow-right me-2"></i>Continue
                        </a>
                    </div>
                    
                <?php elseif ($step === 'admin'): ?>
                    <h3 class="mb-4"><i class="fas fa-user-shield me-2"></i>Admin User Account</h3>
                    <p class="text-muted">Create your first admin user account</p>
                    
                    <form method="post">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control <?= isset($adminErrors['admin_name']) ? 'is-invalid' : '' ?>" 
                                           name="admin_name" value="<?= htmlspecialchars($adminFormData['name'] ?? '') ?>" required>
                                    <?php if (isset($adminErrors['admin_name'])): ?>
                                        <div class="invalid-feedback"><?= $adminErrors['admin_name'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control <?= isset($adminErrors['admin_email']) ? 'is-invalid' : '' ?>" 
                                           name="admin_email" value="<?= htmlspecialchars($adminFormData['email'] ?? '') ?>" required>
                                    <?php if (isset($adminErrors['admin_email'])): ?>
                                        <div class="invalid-feedback"><?= $adminErrors['admin_email'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Password</label>
                                    <input type="password" class="form-control <?= isset($adminErrors['admin_password']) ? 'is-invalid' : '' ?>" 
                                           name="admin_password" required>
                                    <div class="form-text">At least 8 characters</div>
                                    <?php if (isset($adminErrors['admin_password'])): ?>
                                        <div class="invalid-feedback"><?= $adminErrors['admin_password'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Confirm Password</label>
                                    <input type="password" class="form-control" name="admin_password_confirm" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle me-2"></i>Important</h5>
                            <p class="mb-0">This account will have full administrative privileges. Make sure to use a strong password and keep your credentials secure.</p>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-between">
                            <a href="installer.php?step=welcome" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check me-2"></i>Continue
                            </button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'settings'): ?>
                    <h3 class="mb-4"><i class="fas fa-cog me-2"></i>Site Settings</h3>
                    <p class="text-muted">Configure your site's basic information</p>
                    
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Site Title</label>
                            <input type="text" class="form-control <?= isset($settingsErrors['site_title']) ? 'is-invalid' : '' ?>" 
                                   name="site_title" value="<?= htmlspecialchars($settingsFormData['site_title'] ?? 'My Photography') ?>" required>
                            <div class="form-text">The name of your photography website</div>
                            <?php if (isset($settingsErrors['site_title'])): ?>
                                <div class="invalid-feedback"><?= $settingsErrors['site_title'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Site Description</label>
                            <textarea class="form-control" name="site_description" rows="3"><?= htmlspecialchars($settingsFormData['site_description'] ?? 'A beautiful photography portfolio') ?></textarea>
                            <div class="form-text">A short description of your photography portfolio</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Copyright Notice</label>
                                    <input type="text" class="form-control" name="site_copyright" 
                                           value="<?= htmlspecialchars($settingsFormData['site_copyright'] ?? '© ' . date('Y') . ' My Photography') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Contact Email</label>
                                    <input type="email" class="form-control" name="site_email" 
                                           value="<?= htmlspecialchars($settingsFormData['site_email'] ?? ($_SESSION['admin_data']['email'] ?? '')) ?>">
                                    <div class="form-text">For contact form submissions</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <h5><i class="fas fa-lightbulb me-2"></i>Default Content</h5>
                            <p>The installer will automatically create:</p>
                            <ul class="mb-0">
                                <li>A default "Foto" category</li>
                                <li>Six professional gallery templates</li>
                                <li>Basic image format settings</li>
                            </ul>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-between">
                            <a href="installer.php?step=admin" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check me-2"></i>Continue
                            </button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'install'): ?>
                    <h3 class="mb-4"><i class="fas fa-cogs me-2"></i>Ready to Install</h3>
                    
                    <?php if (!empty($errors['install'])): ?>
                        <div class="alert alert-danger">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Installation Error</h5>
                            <p class="mb-0"><?= htmlspecialchars($errors['install']) ?></p>
                        </div>
                        <div class="d-grid gap-2">
                            <a href="installer.php?step=settings" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </a>
                        </div>
                    <?php else: ?>
                        <p>Please review your configuration and click install to complete the setup.</p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5><i class="fas fa-user me-2"></i>Admin Account</h5>
                                <p><strong>Name:</strong> <?= htmlspecialchars($_SESSION['admin_data']['name'] ?? '') ?></p>
                                <p><strong>Email:</strong> <?= htmlspecialchars($_SESSION['admin_data']['email'] ?? '') ?></p>
                            </div>
                            <div class="col-md-6">
                                <h5><i class="fas fa-globe me-2"></i>Site Settings</h5>
                                <p><strong>Title:</strong> <?= htmlspecialchars($_SESSION['settings_data']['site_title'] ?? '') ?></p>
                                <p><strong>URL:</strong> <?php
                                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
                                    $basePath = dirname(dirname($scriptPath));
                                    if ($basePath === '/' || $basePath === '\\') {
                                        $basePath = '';
                                    }
                                    echo $protocol . '://' . $host . $basePath;
                                ?></p>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Final Step</h5>
                            <p class="mb-0">This will create the database, install tables, and configure your site. This process cannot be undone.</p>
                        </div>
                        
                        <form method="post">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-between">
                                <a href="installer.php?step=settings" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back
                                </a>
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-rocket me-2"></i>Install photoCMS
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                    
                <?php elseif ($step === 'complete'): ?>
                    <div class="text-center">
                        <div class="mb-4">
                            <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                        </div>
                        <h3 class="mb-4">🎉 Installation Complete!</h3>
                        <p class="lead">photoCMS has been successfully installed and configured.</p>
                        
                        <div class="alert alert-success">
                            <h5><i class="fas fa-info-circle me-2"></i>What's Next?</h5>
                            <ul class="text-start">
                                <li>Login to the admin panel to start managing your content</li>
                                <li>Create your first photo albums and categories</li>
                                <li>Upload your photography portfolio</li>
                                <li>Customize templates and settings</li>
                            </ul>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <a href="index.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-home me-2"></i>Visit Your Site
                            </a>
                            <a href="index.php/admin/login" class="btn btn-outline-secondary">
                                <i class="fas fa-sign-in-alt me-2"></i>Admin Login
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>