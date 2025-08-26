<?php
// Repair installation script
error_reporting(E_ALL);
ini_set('display_errors', 1);

$rootPath = dirname(__DIR__);
$templatePath = $rootPath . '/database/template.sqlite';
$targetPath = $rootPath . '/database/database.sqlite';
$envPath = $rootPath . '/.env';

echo "<h1>Installation Repair</h1>";

// Step 1: Copy template database
echo "<h2>Step 1: Copying Template Database</h2>";

if (!file_exists($templatePath)) {
    echo "<p style='color: red;'><strong>ERROR:</strong> Template database not found at: $templatePath</p>";
    exit(1);
}

echo "<p><strong>Template database found:</strong> " . filesize($templatePath) . " bytes</p>";

// Remove existing empty database if it exists
if (file_exists($targetPath)) {
    echo "<p>Removing existing database file...</p>";
    unlink($targetPath);
}

echo "<p>Copying template database...</p>";
if (copy($templatePath, $targetPath)) {
    echo "<p style='color: green;'><strong>SUCCESS:</strong> Template database copied (" . filesize($targetPath) . " bytes)</p>";
} else {
    echo "<p style='color: red;'><strong>ERROR:</strong> Failed to copy template database</p>";
    exit(1);
}

// Step 2: Test database connection
echo "<h2>Step 2: Testing Database Connection</h2>";

try {
    $pdo = new PDO('sqlite:' . $targetPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color: green;'><strong>SUCCESS:</strong> Database connection established</p>";
    
    // Check tables
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Tables found:</strong> " . implode(', ', $tables) . "</p>";
    
    // Check users
    if (in_array('users', $tables)) {
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
        $userCount = $stmt->fetch()['count'];
        echo "<p><strong>Users in database:</strong> $userCount</p>";
        
        if ($userCount > 0) {
            $stmt = $pdo->query('SELECT email, first_name, last_name, role FROM users WHERE role = "admin"');
            $admins = $stmt->fetchAll();
            echo "<p><strong>Admin users:</strong></p>";
            echo "<ul>";
            foreach ($admins as $admin) {
                echo "<li>{$admin['first_name']} {$admin['last_name']} ({$admin['email']}) - {$admin['role']}</li>";
            }
            echo "</ul>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>ERROR:</strong> Database connection failed - " . $e->getMessage() . "</p>";
    exit(1);
}

// Step 3: Create .env file
echo "<h2>Step 3: Creating .env File</h2>";

// Get base URL for the current installation
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$basePath = $scriptDir === '/' ? '' : $scriptDir;

// Remove /public from the path if present
if (str_ends_with($basePath, '/public')) {
    $basePath = substr($basePath, 0, -7);
}

$appUrl = $protocol . '://' . $host . $basePath;

$envContent = "APP_ENV=production\n";
$envContent .= "APP_DEBUG=false\n";
$envContent .= "APP_URL=$appUrl\n";
$envContent .= "APP_TIMEZONE=Europe/Rome\n\n";
$envContent .= "DB_CONNECTION=sqlite\n";
$envContent .= "DB_DATABASE=database/database.sqlite\n\n";
$envContent .= "SESSION_SECRET=" . bin2hex(random_bytes(32)) . "\n";

echo "<p>App URL detected: <strong>$appUrl</strong></p>";
echo "<p>Writing .env file...</p>";

if (file_put_contents($envPath, $envContent)) {
    echo "<p style='color: green;'><strong>SUCCESS:</strong> .env file created</p>";
    echo "<h3>.env file content:</h3>";
    echo "<pre>" . htmlspecialchars($envContent) . "</pre>";
} else {
    echo "<p style='color: red;'><strong>ERROR:</strong> Failed to create .env file</p>";
    exit(1);
}

// Step 4: Final verification
echo "<h2>Step 4: Final Verification</h2>";

// Test if the app thinks it's installed now
if (file_exists($rootPath . '/app/Installer/Installer.php')) {
    require_once $rootPath . '/app/Installer/Installer.php';
    try {
        $installer = new \App\Installer\Installer($rootPath);
        $isInstalled = $installer->isInstalled();
        
        if ($isInstalled) {
            echo "<p style='color: green;'><strong>SUCCESS:</strong> Installation is now complete!</p>";
            echo "<p><strong>Next steps:</strong></p>";
            echo "<ul>";
            echo "<li><a href='$basePath/admin/login'>Go to Admin Login</a></li>";
            echo "<li><a href='$basePath/'>View Frontend</a></li>";
            echo "</ul>";
            
            // Clean up this repair script
            echo "<p style='color: orange;'><strong>Security:</strong> This repair script will be deleted for security.</p>";
            unlink(__FILE__);
            
        } else {
            echo "<p style='color: orange;'><strong>WARNING:</strong> App still doesn't think it's installed</p>";
        }
    } catch (\Throwable $e) {
        echo "<p style='color: red;'><strong>ERROR:</strong> Installation check failed: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'><strong>ERROR:</strong> Installer class not found</p>";
}

echo "<h2>Installation Repair Complete</h2>";
echo "<p>If you're still having issues, please check the server error logs for more details.</p>";
?>