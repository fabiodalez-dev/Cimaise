#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$container = require __DIR__ . '/../../app/Config/bootstrap.php';
$db = $container['db'];
$pdo = $db->pdo();

// Ensure required lookup/category base data exists (minimal safety)
$pdo->exec("INSERT OR IGNORE INTO categories(id,name,slug,sort_order) VALUES (2,'Street Photography','street',2)");

// Create or update album
$album = [
    'title' => 'Street Photography Portfolio',
    'slug' => 'street-photography-portfolio',
    'category_id' => 2,
    'excerpt' => 'A street photography collection across Italian cities (Spring 2024).',
    'body' => '<p>Candid moments, light and shadow, and urban life in Italy.</p>',
    'shoot_date' => '2024-05-15',
    'show_date' => 1,
    'is_published' => 1,
    'published_at' => '2024-06-01 10:00:00',
    'sort_order' => 1,
    'template_id' => 1,
];

// Upsert-like for SQLite
$stmt = $pdo->prepare("SELECT id FROM albums WHERE slug = :slug");
$stmt->execute([':slug' => $album['slug']]);
$existingId = $stmt->fetchColumn();
if ($existingId) {
    $albumId = (int)$existingId;
    $sql = "UPDATE albums SET title=:title, category_id=:category_id, excerpt=:excerpt, body=:body, shoot_date=:shoot_date, show_date=:show_date, is_published=:is_published, published_at=:published_at, sort_order=:sort_order, template_id=:template_id WHERE id=:id";
    $pdo->prepare($sql)->execute([
        ':title'=>$album['title'], ':category_id'=>$album['category_id'], ':excerpt'=>$album['excerpt'], ':body'=>$album['body'], ':shoot_date'=>$album['shoot_date'], ':show_date'=>$album['show_date'], ':is_published'=>$album['is_published'], ':published_at'=>$album['published_at'], ':sort_order'=>$album['sort_order'], ':template_id'=>$album['template_id'], ':id'=>$albumId
    ]);
} else {
    $sql = "INSERT INTO albums (title,slug,category_id,excerpt,body,shoot_date,show_date,is_published,published_at,sort_order,template_id) VALUES (:title,:slug,:category_id,:excerpt,:body,:shoot_date,:show_date,:is_published,:published_at,:sort_order,:template_id)";
    $pdo->prepare($sql)->execute([
        ':title'=>$album['title'], ':slug'=>$album['slug'], ':category_id'=>$album['category_id'], ':excerpt'=>$album['excerpt'], ':body'=>$album['body'], ':shoot_date'=>$album['shoot_date'], ':show_date'=>$album['show_date'], ':is_published'=>$album['is_published'], ':published_at'=>$album['published_at'], ':sort_order'=>$album['sort_order'], ':template_id'=>$album['template_id']
    ]);
    $albumId = (int)$pdo->lastInsertId();
}

// Tags
$tags = ['street-photography'=>'Street Photography','urban-life'=>'Urban Life','film-photography'=>'Film Photography','italy'=>'Italy','documentary'=>'Documentary','black-white'=>'Black and White'];
foreach ($tags as $slug => $name) {
    $pdo->prepare("INSERT OR IGNORE INTO tags (name, slug) VALUES (:name, :slug)")->execute([':name'=>$name, ':slug'=>$slug]);
    $tagId = (int)$pdo->query("SELECT id FROM tags WHERE slug = '".str_replace("'","''",$slug)."'")->fetchColumn();
    $pdo->prepare("INSERT OR IGNORE INTO album_tag (album_id, tag_id) VALUES (?,?)")->execute([$albumId, $tagId]);
}

// Ensure album has multiple categories in pivot (demo: category 1 and 2 if exist)
try {
    $pdo->prepare('INSERT OR IGNORE INTO album_category (album_id, category_id) VALUES (?,?)')->execute([$albumId, 1]);
    $pdo->prepare('INSERT OR IGNORE INTO album_category (album_id, category_id) VALUES (?,?)')->execute([$albumId, 2]);
} catch (Throwable $e) { /* ignore */ }

// Link equipment to album (from lookups if present)
// Cameras
$cameraNames = ['Canon AE-1', 'Leica M6', 'Nikon F3'];
foreach ($cameraNames as $n) {
    $c = $pdo->prepare("SELECT id FROM cameras WHERE (make || ' ' || model) = :full OR model = :full");
    $c->execute([':full' => $n]);
    if ($cid = $c->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO album_camera (album_id, camera_id) VALUES (?,?)')->execute([$albumId, (int)$cid]);
    }
}
// Lenses
$lensNames = ['Nikon 50mm f/1.4', 'Leica 35mm f/2', 'Canon 85mm f/1.8'];
foreach ($lensNames as $n) {
    $l = $pdo->prepare("SELECT id FROM lenses WHERE (brand || ' ' || model) = :full OR model = :full");
    $l->execute([':full' => $n]);
    if ($lid = $l->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO album_lens (album_id, lens_id) VALUES (?,?)')->execute([$albumId, (int)$lid]);
    }
}
// Films
$filmNames = ['Kodak Portra 400', 'Ilford HP5+', 'Kodak Tri-X 400'];
foreach ($filmNames as $n) {
    $f = $pdo->prepare("SELECT id FROM films WHERE (brand || ' ' || name) = :full OR name = :full");
    $f->execute([':full' => $n]);
    if ($fid = $f->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO album_film (album_id, film_id) VALUES (?,?)')->execute([$albumId, (int)$fid]);
    }
}
// Developers
$devNames = ['Kodak D-76', 'Rodinal'];
foreach ($devNames as $n) {
    $d = $pdo->prepare('SELECT id FROM developers WHERE name = :n');
    $d->execute([':n' => $n]);
    if ($did = $d->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO album_developer (album_id, developer_id) VALUES (?,?)')->execute([$albumId, (int)$did]);
    }
}
// Labs
$labNames = ['Carmencita Film Lab', 'Mori Film Lab'];
foreach ($labNames as $n) {
    $l = $pdo->prepare('SELECT id FROM labs WHERE name = :n');
    $l->execute([':n' => $n]);
    if ($lid = $l->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO album_lab (album_id, lab_id) VALUES (?,?)')->execute([$albumId, (int)$lid]);
    }
}

// Prepare test image paths
$root = dirname(__DIR__, 2);
$files = [
    ['street-001.jpg','Leica M6','Leica 35mm f/2','Morning commuter rushing through Milan Central Station','Silhouette of person walking through sunlit train station','analog'],
    ['street-002.jpg','Canon AE-1','Canon 85mm f/1.8','Elderly man reading newspaper at outdoor café in Rome','Man reading newspaper at outdoor café','analog'],
    ['street-003.jpg','Nikon F3','Nikon 50mm f/1.4','Children playing football in narrow Naples alley','Kids playing soccer between buildings','analog'],
    ['street-004.jpg','Leica M6','Leica 35mm f/2','Vendor arranging fresh vegetables at market stall','Hands arranging colorful produce','analog'],
    ['street-005.jpg','Canon AE-1','Canon 85mm f/1.8','Dramatic shadows on ancient Roman architecture','Stone columns and light patterns','bw'],
    ['street-006.jpg','Nikon F3','Nikon 50mm f/1.4','Street musician performing for evening crowd','Guitarist with case open','bw'],
];

$coverId = null;
$sort = 1;
foreach ($files as [$name, $camera, $lens, $caption, $alt, $process]) {
    $rel = '/media/test/'.$name;
    $full = $root . '/public' . $rel;
    if (!is_file($full)) { echo "Missing file: $rel\n"; continue; }
    $info = @getimagesize($full);
    $mime = $info['mime'] ?? 'image/jpeg';
    $w = $info[0] ?? 1600; $h = $info[1] ?? 1067;
    $hash = sha1_file($full);
    $stmt = $pdo->prepare("INSERT INTO images (album_id, original_path, file_hash, width, height, mime, alt_text, caption, custom_camera, custom_lens, process, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$albumId, $rel, $hash, $w, $h, $mime, $alt, $caption, $camera, $lens, $process, $sort]);
    $imgId = (int)$pdo->lastInsertId();
    if ($coverId === null) { $coverId = $imgId; }
    $sort++;
}

if ($coverId) {
    $pdo->prepare("UPDATE albums SET cover_image_id = :cid WHERE id = :id")->execute([':cid'=>$coverId, ':id'=>$albumId]);
}

echo "Seeded album #$albumId with images.\n";
