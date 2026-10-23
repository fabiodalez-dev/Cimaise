<?php
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
function envv(string $key, mixed $default = null): mixed { return $default; }
$db = new App\Support\Database(database: '/var/www/html/database/test.sqlite', isSqlite: true);
$db->pdo()->exec(file_get_contents('/var/www/html/database/schema.sqlite.sql'));
$db->execute("INSERT INTO users(email,password_hash,role) VALUES(?,?,'admin')", ['test@example.test', password_hash('Test-pass-12345', PASSWORD_DEFAULT)]);
$db->execute("INSERT INTO albums(id,title,slug,category_id) VALUES(1,'Test','test',1)");
$db->execute("INSERT INTO images(id,album_id,original_path,file_hash,width,height,mime) VALUES(999,1,'/storage/originals/missing.jpg','missing',180,120,'image/jpeg')");
$settings = new App\Services\SettingsService($db);
$settings->set('image.formats', ['jpg' => true, 'webp' => true, 'avif' => true]);
$settings->set('image.breakpoints', ['sm' => 48, 'md' => 96, 'lg' => 144]);
$settings->set('image.variants_async', true);
file_put_contents('/var/www/html/storage/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=database/test.sqlite\nAPP_ENV=testing\nAPP_DEBUG=false\n");
file_put_contents('/var/www/html/storage/tmp/.installed', 'integration test');
$image = imagecreatetruecolor(180, 120);
imagejpeg($image, '/var/www/html/storage/tmp/upload.jpg');
