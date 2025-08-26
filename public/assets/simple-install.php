<?php
// Simple installer for photoCMS
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
    echo "<h1>PhotoCMS già installato!</h1>";
    echo '<p><a href="index.php">Vai alla home</a></p>';
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Create .env file
        $appUrl = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'], 2);
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
        
        // Copy template database
        $templateDb = $rootPath . '/database/template.sqlite';
        if (file_exists($templateDb)) {
            copy($templateDb, $dbPath);
        } else {
            // Create new database
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Run migrations
            $migrationFiles = glob($rootPath . '/database/migrations/sqlite/*.sql');
            sort($migrationFiles);
            
            foreach ($migrationFiles as $file) {
                $sql = file_get_contents($file);
                $pdo->exec($sql);
            }
            
            // Run seeds
            $seedFiles = glob($rootPath . '/database/seeds/sqlite/*.sql');
            sort($seedFiles);
            
            foreach ($seedFiles as $file) {
                $sql = file_get_contents($file);
                $pdo->exec($sql);
            }
        }
        
        // Create admin user
        $email = $_POST['email'] ?? 'admin@example.com';
        $password = $_POST['password'] ?? 'admin123';
        $firstName = $_POST['first_name'] ?? 'Admin';
        $lastName = $_POST['last_name'] ?? 'User';
        
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role, is_active, first_name, last_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $email, 
            $hashedPassword,
            'admin',
            1,
            $firstName,
            $lastName,
            date('Y-m-d H:i:s')
        ]);
        
        echo "<h1>✅ Installazione completata!</h1>";
        echo "<p>Admin creato: <strong>$email</strong></p>";
        echo '<p><a href="index.php">Vai alla home</a> | <a href="index.php/admin/login">Login Admin</a></p>';
        exit;
        
    } catch (Exception $e) {
        echo "<h1>❌ Errore durante l'installazione</h1>";
        echo "<p>Errore: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo '<p><a href="">Riprova</a></p>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>PhotoCMS - Installazione</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="email"], input[type="password"] { 
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; 
        }
        button { background: #007cba; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #005a87; }
        .info { background: #f0f8ff; padding: 15px; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <h1>📸 PhotoCMS - Installazione</h1>
    
    <div class="info">
        <strong>Info rilevate:</strong><br>
        URL: <?= 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'], 2) ?><br>
        Percorso: <?= $rootPath ?><br>
        Database: <?= $dbPath ?>
    </div>
    
    <form method="POST">
        <div class="form-group">
            <label>Email Admin:</label>
            <input type="email" name="email" value="admin@example.com" required>
        </div>
        
        <div class="form-group">
            <label>Nome:</label>
            <input type="text" name="first_name" value="Admin" required>
        </div>
        
        <div class="form-group">
            <label>Cognome:</label>
            <input type="text" name="last_name" value="User" required>
        </div>
        
        <div class="form-group">
            <label>Password Admin:</label>
            <input type="password" name="password" value="admin123" required>
        </div>
        
        <button type="submit">🚀 Installa PhotoCMS</button>
    </form>
</body>
</html>