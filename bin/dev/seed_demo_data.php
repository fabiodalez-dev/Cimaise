#!/usr/bin/env php
<?php
/**
 * Cimaise Demo Data Seeder
 *
 * Comprehensive script to populate the application with demo data including:
 * - Categories with images (auto-downloaded from Unsplash)
 * - Tags
 * - Locations
 * - Equipment (cameras, lenses, films, developers, labs)
 * - Multiple albums with various settings (NSFW, password-protected, etc.)
 * - Images with metadata (auto-downloaded from Unsplash)
 *
 * Usage: php bin/dev/seed_demo_data.php [--force]
 *
 * Options:
 *   --force    Skip confirmation prompt
 *
 * Images are automatically downloaded from Unsplash (free stock photos).
 * Original images are stored in /storage/originals/ (like backend upload).
 * Temp files are automatically cleaned up after seeding.
 *
 * This script automatically runs:
 * - php bin/console images:generate (generates variants)
 * - php bin/console nsfw:generate-blur --all (generates blur for protected albums)
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

// Parse arguments
$force = in_array('--force', $argv, true);

if (!$force) {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║           CIMAISE DEMO DATA SEEDER                             ║\n";
    echo "╠════════════════════════════════════════════════════════════════╣\n";
    echo "║  This script will populate your database with demo data.       ║\n";
    echo "║  Existing data with matching slugs will be UPDATED.            ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Continue? [y/N]: ";
    $handle = fopen("php://stdin", "r");
    try {
        $line = fgets($handle);
    } finally {
        fclose($handle);
    }
    if (trim(strtolower($line)) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
}

$container = require __DIR__ . '/../../app/Config/bootstrap.php';
$db = $container['db'];
$pdo = $db->pdo();

$root = dirname(__DIR__, 2);
$mediaPath = $root . '/public/media/seed';
$storageOriginalsPath = $root . '/storage/originals';

// Ensure storage/originals directory exists
if (!is_dir($storageOriginalsPath)) {
    $created = mkdir($storageOriginalsPath, 0755, true);
    if (!$created && !is_dir($storageOriginalsPath)) {
        echo "✗ Error: unable to create directory {$storageOriginalsPath}\n";
        exit(1);
    }
}

// Helper functions
function upsertById(PDO $pdo, string $table, array $data, string $uniqueField = 'slug'): int
{
    $uniqueValue = $data[$uniqueField] ?? null;
    if (!$uniqueValue) {
        throw new InvalidArgumentException("Missing unique field: $uniqueField");
    }

    $stmt = $pdo->prepare("SELECT id FROM $table WHERE $uniqueField = ?");
    $stmt->execute([$uniqueValue]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            if ($key !== 'id' && $key !== $uniqueField) {
                $sets[] = "$key = ?";
                $params[] = $value;
            }
        }
        if (!empty($sets)) {
            $params[] = $existingId;
            $sql = "UPDATE $table SET " . implode(', ', $sets) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($params);
        }
        return (int)$existingId;
    }

    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $pdo->prepare($sql)->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}

function linkManyToMany(PDO $pdo, string $table, string $col1, int $id1, string $col2, int $id2): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sql = "INSERT OR IGNORE INTO $table ($col1, $col2) VALUES (?, ?)";
    } else {
        $sql = "INSERT IGNORE INTO $table ($col1, $col2) VALUES (?, ?)";
    }
    $pdo->prepare($sql)->execute([$id1, $id2]);
}

function getImageInfo(string $path): array
{
    if (!is_file($path)) {
        // Default to 3:2 aspect ratio (1600x1067) - common for photography.
        return ['width' => 1600, 'height' => 1067, 'mime' => 'image/jpeg'];
    }
    $info = @getimagesize($path);
    return [
        'width' => $info[0] ?? 1600,
        'height' => $info[1] ?? 1067,
        'mime' => $info['mime'] ?? 'image/jpeg',
    ];
}

/**
 * Download an image from URL to local path
 */
function downloadImage(string $url, string $path): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (file_exists($path)) {
        return true;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_MAXFILESIZE, 50 * 1024 * 1024);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Cimaise Demo Seeder/1.0');
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0) {
        error_log("Curl error downloading {$url}: [{$errno}] {$error}");
        return false;
    }

    if ($code >= 200 && $code < 300 && $data !== false && strlen($data) > 1000) {
        if (file_put_contents($path, $data) === false) {
            return false;
        }
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            @unlink($path);
            return false;
        }
        return true;
    }
    return false;
}

// Unsplash image URLs for categories (800x600)
$categoryImages = [
    'street.jpg' => 'https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?w=800&h=600&fit=crop',
    'portrait.jpg' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=800&h=600&fit=crop',
    'landscape.jpg' => 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=800&h=600&fit=crop',
    'architecture.jpg' => 'https://images.unsplash.com/photo-1486325212027-8081e485255e?w=800&h=600&fit=crop',
    'film.jpg' => 'https://images.unsplash.com/photo-1495121553079-4c61bcce1894?w=800&h=600&fit=crop',
    'bw.jpg' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=800&h=600&fit=crop',
    'documentary.jpg' => 'https://images.unsplash.com/photo-1529156069898-49953e39b3ac?w=800&h=600&fit=crop',
    'fineart.jpg' => 'https://images.unsplash.com/photo-1513364776144-60967b0f800f?w=800&h=600&fit=crop',
];

// Unsplash image URLs for albums (mixed orientations: horizontal, vertical, square)
$albumImages = [
    'streets-of-milan' => [
        'milan-001.jpg' => ['https://images.unsplash.com/photo-1513581166391-887a96ddeafd?w=1600&h=1067&fit=crop', 1600, 1067],
        'milan-002.jpg' => ['https://images.unsplash.com/photo-1520277739336-7bf67edfa768?w=1067&h=1600&fit=crop', 1067, 1600],
        'milan-003.jpg' => ['https://images.unsplash.com/photo-1534430480872-3498386e7856?w=1600&h=1600&fit=crop', 1600, 1600],
        'milan-004.jpg' => ['https://images.unsplash.com/photo-1516483638261-f4dbaf036963?w=1600&h=1067&fit=crop', 1600, 1067],
        'milan-005.jpg' => ['https://images.unsplash.com/photo-1523730205978-59fd1b2965e3?w=1067&h=1600&fit=crop', 1067, 1600],
        'milan-006.jpg' => ['https://images.unsplash.com/photo-1515542622106-78bda8ba0e5b?w=1600&h=1067&fit=crop', 1600, 1067],
    ],
    'intimate-portraits' => [
        'portrait-001.jpg' => ['https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=1067&h=1600&fit=crop', 1067, 1600],
        'portrait-002.jpg' => ['https://images.unsplash.com/photo-1502823403499-6ccfcf4fb453?w=1600&h=1067&fit=crop', 1600, 1067],
        'portrait-003.jpg' => ['https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=1600&h=1600&fit=crop', 1600, 1600],
        'portrait-004.jpg' => ['https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?w=1067&h=1600&fit=crop', 1067, 1600],
    ],
    'body-studies' => [
        'body-001.jpg' => ['https://images.unsplash.com/photo-1518611012118-696072aa579a?w=1067&h=1600&fit=crop', 1067, 1600],
        'body-002.jpg' => ['https://images.unsplash.com/photo-1571019613454-1cb2f99b2d8b?w=1600&h=1067&fit=crop', 1600, 1067],
        'body-003.jpg' => ['https://images.unsplash.com/photo-1520787497953-1985ca467702?w=1600&h=1600&fit=crop', 1600, 1600],
        'body-004.jpg' => ['https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?w=1067&h=1600&fit=crop', 1067, 1600],
    ],
    'private-collection' => [
        'private-001.jpg' => ['https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=1600&h=1067&fit=crop', 1600, 1067],
        'private-002.jpg' => ['https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=1067&h=1600&fit=crop', 1067, 1600],
        'private-003.jpg' => ['https://images.unsplash.com/photo-1517841905240-472988babdf9?w=1600&h=1600&fit=crop', 1600, 1600],
    ],
    'iceland-fire-ice' => [
        'iceland-001.jpg' => ['https://images.unsplash.com/photo-1476610182048-b716b8518aae?w=1600&h=1067&fit=crop', 1600, 1067],
        'iceland-002.jpg' => ['https://images.unsplash.com/photo-1504893524553-b855bce32c67?w=1067&h=1600&fit=crop', 1067, 1600],
        'iceland-003.jpg' => ['https://images.unsplash.com/photo-1529963183134-61a90db47eaf?w=1600&h=1600&fit=crop', 1600, 1600],
        'iceland-004.jpg' => ['https://images.unsplash.com/photo-1490682143684-14369e18dce8?w=1600&h=1067&fit=crop', 1600, 1067],
        'iceland-005.jpg' => ['https://images.unsplash.com/photo-1551632436-cbf8dd35adfa?w=1067&h=1600&fit=crop', 1067, 1600],
        'iceland-006.jpg' => ['https://images.unsplash.com/photo-1519681393784-d120267933ba?w=1600&h=1067&fit=crop', 1600, 1067],
        'iceland-007.jpg' => ['https://images.unsplash.com/photo-1509316785289-025f5b846b35?w=1067&h=1600&fit=crop', 1067, 1600],
        'iceland-008.jpg' => ['https://images.unsplash.com/photo-1494500764479-0c8f2919a3d8?w=1600&h=1067&fit=crop', 1600, 1067],
    ],
    'tokyo-nights' => [
        'tokyo-001.jpg' => ['https://images.unsplash.com/photo-1540959733332-eab4deabeeaf?w=1600&h=1067&fit=crop', 1600, 1067],
        'tokyo-002.jpg' => ['https://images.unsplash.com/photo-1503899036084-c55cdd92da26?w=1067&h=1600&fit=crop', 1067, 1600],
        'tokyo-003.jpg' => ['https://images.unsplash.com/photo-1536098561742-ca998e48cbcc?w=1600&h=1600&fit=crop', 1600, 1600],
        'tokyo-004.jpg' => ['https://images.unsplash.com/photo-1542051841857-5f90071e7989?w=1600&h=1067&fit=crop', 1600, 1067],
        'tokyo-005.jpg' => ['https://images.unsplash.com/photo-1480796927426-f609979314bd?w=1067&h=1600&fit=crop', 1067, 1600],
        'tokyo-006.jpg' => ['https://images.unsplash.com/photo-1551641506-ee5bf4cb45f1?w=1600&h=1067&fit=crop', 1600, 1067],
    ],
    'brutalist-barcelona' => [
        'barcelona-001.jpg' => ['https://images.unsplash.com/photo-1583422409516-2895a77efded?w=1600&h=1067&fit=crop', 1600, 1067],
        'barcelona-002.jpg' => ['https://images.unsplash.com/photo-1539037116277-4db20889f2d4?w=1067&h=1600&fit=crop', 1067, 1600],
        'barcelona-003.jpg' => ['https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1600&h=1600&fit=crop', 1600, 1600],
        'barcelona-004.jpg' => ['https://images.unsplash.com/photo-1520608421741-68228b76b6df?w=1600&h=1067&fit=crop', 1600, 1067],
    ],
    'venetian-craftsmen' => [
        'venice-001.jpg' => ['https://images.unsplash.com/photo-1523906834658-6e24ef2386f9?w=1600&h=1067&fit=crop', 1600, 1067],
        'venice-002.jpg' => ['https://images.unsplash.com/photo-1514890547357-a9ee288728e0?w=1067&h=1600&fit=crop', 1067, 1600],
        'venice-003.jpg' => ['https://images.unsplash.com/photo-1534113414509-0eec2bfb493f?w=1600&h=1600&fit=crop', 1600, 1600],
        'venice-004.jpg' => ['https://images.unsplash.com/photo-1498307833015-e7b400441eb8?w=1600&h=1067&fit=crop', 1600, 1067],
        'venice-005.jpg' => ['https://images.unsplash.com/photo-1516483638261-f4dbaf036963?w=1067&h=1600&fit=crop', 1067, 1600],
    ],
    // New albums for 15 total
    'paris-passages' => [
        'paris-001.jpg' => ['https://images.unsplash.com/photo-1502602898657-3e91760cbb34?w=1600&h=1067&fit=crop', 1600, 1067],
        'paris-002.jpg' => ['https://images.unsplash.com/photo-1499856871958-5b9627545d1a?w=1067&h=1600&fit=crop', 1067, 1600],
        'paris-003.jpg' => ['https://images.unsplash.com/photo-1431274172761-fca41d930114?w=1600&h=1600&fit=crop', 1600, 1600],
        'paris-004.jpg' => ['https://images.unsplash.com/photo-1478391679764-b2d8b3cd1e94?w=1600&h=1067&fit=crop', 1600, 1067],
        'paris-005.jpg' => ['https://images.unsplash.com/photo-1549144511-f099e773c147?w=1067&h=1600&fit=crop', 1067, 1600],
    ],
    'berlin-shadows' => [
        'berlin-001.jpg' => ['https://images.unsplash.com/photo-1560969184-10fe8719e047?w=1600&h=1067&fit=crop', 1600, 1067],
        'berlin-002.jpg' => ['https://images.unsplash.com/photo-1528728329032-2972f65dfb3f?w=1067&h=1600&fit=crop', 1067, 1600],
        'berlin-003.jpg' => ['https://images.unsplash.com/photo-1546726747-421c6d69c929?w=1600&h=1067&fit=crop', 1600, 1067],
        'berlin-004.jpg' => ['https://images.unsplash.com/photo-1587330979470-3595ac045ab0?w=1600&h=1600&fit=crop', 1600, 1600],
    ],
    'light-and-shadow' => [
        'light-001.jpg' => ['https://images.unsplash.com/photo-1508214751196-bcfd4ca60f91?w=1067&h=1600&fit=crop', 1067, 1600],
        'light-002.jpg' => ['https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=1600&h=1067&fit=crop', 1600, 1067],
        'light-003.jpg' => ['https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=1600&h=1600&fit=crop', 1600, 1600],
        'light-004.jpg' => ['https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=1067&h=1600&fit=crop', 1067, 1600],
    ],
    'exclusive-boudoir' => [
        'boudoir-001.jpg' => ['https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=1067&h=1600&fit=crop', 1067, 1600],
        'boudoir-002.jpg' => ['https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?w=1600&h=1067&fit=crop', 1600, 1067],
        'boudoir-003.jpg' => ['https://images.unsplash.com/photo-1469460340997-2f854421e72f?w=1600&h=1600&fit=crop', 1600, 1600],
    ],
    'patagonia-wild' => [
        'patagonia-001.jpg' => ['https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?w=1600&h=1067&fit=crop', 1600, 1067],
        'patagonia-002.jpg' => ['https://images.unsplash.com/photo-1454496522488-7a8e488e8606?w=1067&h=1600&fit=crop', 1067, 1600],
        'patagonia-003.jpg' => ['https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=1600&h=1600&fit=crop', 1600, 1600],
        'patagonia-004.jpg' => ['https://images.unsplash.com/photo-1483728642387-6c3bdd6c93e5?w=1600&h=1067&fit=crop', 1600, 1067],
        'patagonia-005.jpg' => ['https://images.unsplash.com/photo-1470071459604-3b5ec3a7fe05?w=1067&h=1600&fit=crop', 1067, 1600],
        'patagonia-006.jpg' => ['https://images.unsplash.com/photo-1507400492013-162706c8c05e?w=1600&h=1067&fit=crop', 1600, 1067],
    ],
    'london-underground' => [
        'london-001.jpg' => ['https://images.unsplash.com/photo-1513635269975-59663e0ac1ad?w=1600&h=1067&fit=crop', 1600, 1067],
        'london-002.jpg' => ['https://images.unsplash.com/photo-1529655683826-aba9b3e77383?w=1067&h=1600&fit=crop', 1067, 1600],
        'london-003.jpg' => ['https://images.unsplash.com/photo-1486299267070-83823f5448dd?w=1600&h=1600&fit=crop', 1600, 1600],
        'london-004.jpg' => ['https://images.unsplash.com/photo-1520986606214-8b456906c813?w=1600&h=1067&fit=crop', 1600, 1067],
    ],
    'abstract-silhouettes' => [
        'abstract-001.jpg' => ['https://images.unsplash.com/photo-1483985988355-763728e1935b?w=1067&h=1600&fit=crop', 1067, 1600],
        'abstract-002.jpg' => ['https://images.unsplash.com/photo-1485462537746-965f33f7f6a7?w=1600&h=1067&fit=crop', 1600, 1067],
        'abstract-003.jpg' => ['https://images.unsplash.com/photo-1492446845049-9c50cc313f00?w=1600&h=1600&fit=crop', 1600, 1600],
        'abstract-004.jpg' => ['https://images.unsplash.com/photo-1504703395950-b89145a5425b?w=1067&h=1600&fit=crop', 1067, 1600],
    ],
];

echo "\n🚀 Starting demo data seeding...\n\n";

// ============================================
// CATEGORIES (with images)
// ============================================
echo "📁 Seeding categories...\n";

$categories = [
    [
        'name' => 'Street Photography',
        'slug' => 'street-photography',
        'sort_order' => 1,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/street.jpg',
    ],
    [
        'name' => 'Portrait',
        'slug' => 'portrait',
        'sort_order' => 2,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/portrait.jpg',
    ],
    [
        'name' => 'Landscape',
        'slug' => 'landscape',
        'sort_order' => 3,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/landscape.jpg',
    ],
    [
        'name' => 'Architecture',
        'slug' => 'architecture',
        'sort_order' => 4,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/architecture.jpg',
    ],
    [
        'name' => 'Film Photography',
        'slug' => 'film-photography',
        'sort_order' => 5,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/film.jpg',
    ],
    [
        'name' => 'Black & White',
        'slug' => 'black-white',
        'sort_order' => 6,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/bw.jpg',
    ],
    [
        'name' => 'Documentary',
        'slug' => 'documentary',
        'sort_order' => 7,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/documentary.jpg',
    ],
    [
        'name' => 'Fine Art',
        'slug' => 'fine-art',
        'sort_order' => 8,
        'parent_id' => null,
        'image_path' => '/media/seed/categories/fineart.jpg',
    ],
];

$categoryIds = [];
foreach ($categories as $cat) {
    $categoryIds[$cat['slug']] = upsertById($pdo, 'categories', $cat);

    // Download category image from Unsplash
    $imgFile = basename($cat['image_path']);
    if (isset($categoryImages[$imgFile])) {
        $imgPath = $root . '/public' . $cat['image_path'];
        if (downloadImage($categoryImages[$imgFile], $imgPath)) {
            echo "   ✓ {$cat['name']} (image downloaded)\n";
        } else {
            echo "   ✓ {$cat['name']} (image download failed)\n";
        }
    } else {
        echo "   ✓ {$cat['name']}\n";
    }
}

// Subcategories
$subcategories = [
    ['name' => 'Urban Life', 'slug' => 'urban-life', 'sort_order' => 1, 'parent_id' => $categoryIds['street-photography'], 'image_path' => null],
    ['name' => 'Night Street', 'slug' => 'night-street', 'sort_order' => 2, 'parent_id' => $categoryIds['street-photography'], 'image_path' => null],
    ['name' => 'Studio Portrait', 'slug' => 'studio-portrait', 'sort_order' => 1, 'parent_id' => $categoryIds['portrait'], 'image_path' => null],
    ['name' => 'Environmental Portrait', 'slug' => 'environmental-portrait', 'sort_order' => 2, 'parent_id' => $categoryIds['portrait'], 'image_path' => null],
    ['name' => 'Mountains', 'slug' => 'mountains', 'sort_order' => 1, 'parent_id' => $categoryIds['landscape'], 'image_path' => null],
    ['name' => 'Seascape', 'slug' => 'seascape', 'sort_order' => 2, 'parent_id' => $categoryIds['landscape'], 'image_path' => null],
];

foreach ($subcategories as $sub) {
    $categoryIds[$sub['slug']] = upsertById($pdo, 'categories', $sub);
    echo "   ✓ {$sub['name']} (subcategory)\n";
}

// ============================================
// TAGS
// ============================================
echo "\n🏷️  Seeding tags...\n";

$tags = [
    'street-photography' => 'Street Photography',
    'urban' => 'Urban',
    'film' => 'Film',
    'analog' => 'Analog',
    'digital' => 'Digital',
    'black-and-white' => 'Black and White',
    'color' => 'Color',
    'portrait' => 'Portrait',
    'landscape' => 'Landscape',
    'architecture' => 'Architecture',
    'travel' => 'Travel',
    'italy' => 'Italy',
    'japan' => 'Japan',
    'spain' => 'Spain',
    'france' => 'France',
    'usa' => 'USA',
    'night' => 'Night',
    'golden-hour' => 'Golden Hour',
    'blue-hour' => 'Blue Hour',
    'long-exposure' => 'Long Exposure',
    'documentary' => 'Documentary',
    'editorial' => 'Editorial',
    'conceptual' => 'Conceptual',
    'minimalist' => 'Minimalist',
    'moody' => 'Moody',
    '35mm' => '35mm',
    'medium-format' => 'Medium Format',
    'large-format' => 'Large Format',
];

$tagIds = [];
foreach ($tags as $slug => $name) {
    $tagIds[$slug] = upsertById($pdo, 'tags', ['name' => $name, 'slug' => $slug]);
}
echo "   ✓ " . count($tags) . " tags created\n";

// ============================================
// LOCATIONS
// ============================================
echo "\n📍 Seeding locations...\n";

$locations = [
    ['name' => 'Milan, Italy', 'slug' => 'milan-italy', 'description' => 'Fashion and design capital of Italy'],
    ['name' => 'Rome, Italy', 'slug' => 'rome-italy', 'description' => 'The Eternal City'],
    ['name' => 'Naples, Italy', 'slug' => 'naples-italy', 'description' => 'Historic port city in southern Italy'],
    ['name' => 'Florence, Italy', 'slug' => 'florence-italy', 'description' => 'Birthplace of the Renaissance'],
    ['name' => 'Venice, Italy', 'slug' => 'venice-italy', 'description' => 'City of canals'],
    ['name' => 'Tokyo, Japan', 'slug' => 'tokyo-japan', 'description' => 'Bustling metropolis of contrasts'],
    ['name' => 'Kyoto, Japan', 'slug' => 'kyoto-japan', 'description' => 'Traditional temples and gardens'],
    ['name' => 'Barcelona, Spain', 'slug' => 'barcelona-spain', 'description' => 'Gaudi architecture and Mediterranean vibes'],
    ['name' => 'Paris, France', 'slug' => 'paris-france', 'description' => 'City of Light'],
    ['name' => 'New York, USA', 'slug' => 'new-york-usa', 'description' => 'The city that never sleeps'],
    ['name' => 'San Francisco, USA', 'slug' => 'san-francisco-usa', 'description' => 'Golden Gate and tech hub'],
    ['name' => 'Iceland', 'slug' => 'iceland', 'description' => 'Land of fire and ice'],
    ['name' => 'Scottish Highlands', 'slug' => 'scottish-highlands', 'description' => 'Rugged mountains and lochs'],
    ['name' => 'Dolomites, Italy', 'slug' => 'dolomites-italy', 'description' => 'Dramatic alpine peaks'],
    ['name' => 'Berlin, Germany', 'slug' => 'berlin-germany', 'description' => 'Capital of art and history'],
    ['name' => 'London, UK', 'slug' => 'london-uk', 'description' => 'Historic and modern metropolis'],
    ['name' => 'Patagonia, Argentina', 'slug' => 'patagonia-argentina', 'description' => 'Wild and pristine wilderness'],
];

$locationIds = [];
foreach ($locations as $loc) {
    $locationIds[$loc['slug']] = upsertById($pdo, 'locations', $loc);
}
echo "   ✓ " . count($locations) . " locations created\n";

// ============================================
// CAMERAS
// ============================================
echo "\n📷 Seeding cameras...\n";

$cameras = [
    // Film cameras
    ['make' => 'Leica', 'model' => 'M6', 'type' => 'rangefinder'],
    ['make' => 'Leica', 'model' => 'M3', 'type' => 'rangefinder'],
    ['make' => 'Leica', 'model' => 'MP', 'type' => 'rangefinder'],
    ['make' => 'Canon', 'model' => 'AE-1', 'type' => 'slr'],
    ['make' => 'Canon', 'model' => 'A-1', 'type' => 'slr'],
    ['make' => 'Nikon', 'model' => 'F3', 'type' => 'slr'],
    ['make' => 'Nikon', 'model' => 'FM2', 'type' => 'slr'],
    ['make' => 'Nikon', 'model' => 'F100', 'type' => 'slr'],
    ['make' => 'Pentax', 'model' => 'K1000', 'type' => 'slr'],
    ['make' => 'Olympus', 'model' => 'OM-1', 'type' => 'slr'],
    ['make' => 'Contax', 'model' => 'T2', 'type' => 'compact'],
    ['make' => 'Contax', 'model' => 'G2', 'type' => 'rangefinder'],
    ['make' => 'Hasselblad', 'model' => '500C/M', 'type' => 'medium_format'],
    ['make' => 'Mamiya', 'model' => 'RZ67', 'type' => 'medium_format'],
    ['make' => 'Mamiya', 'model' => '7 II', 'type' => 'rangefinder'],
    ['make' => 'Rolleiflex', 'model' => '2.8F', 'type' => 'tlr'],
    ['make' => 'Yashica', 'model' => 'Mat-124G', 'type' => 'tlr'],
    // Digital cameras
    ['make' => 'Sony', 'model' => 'A7III', 'type' => 'mirrorless'],
    ['make' => 'Sony', 'model' => 'A7RIV', 'type' => 'mirrorless'],
    ['make' => 'Canon', 'model' => 'EOS R5', 'type' => 'mirrorless'],
    ['make' => 'Canon', 'model' => '5D Mark IV', 'type' => 'dslr'],
    ['make' => 'Nikon', 'model' => 'Z6 II', 'type' => 'mirrorless'],
    ['make' => 'Nikon', 'model' => 'D850', 'type' => 'dslr'],
    ['make' => 'Fujifilm', 'model' => 'X-T4', 'type' => 'mirrorless'],
    ['make' => 'Fujifilm', 'model' => 'X-Pro3', 'type' => 'mirrorless'],
    ['make' => 'Fujifilm', 'model' => 'GFX 100S', 'type' => 'medium_format'],
    ['make' => 'Leica', 'model' => 'Q2', 'type' => 'compact'],
    ['make' => 'Leica', 'model' => 'M10-R', 'type' => 'rangefinder'],
    ['make' => 'Ricoh', 'model' => 'GR III', 'type' => 'compact'],
];

$cameraIds = [];
foreach ($cameras as $cam) {
    $key = $cam['make'] . ' ' . $cam['model'];
    $stmt = $pdo->prepare("SELECT id FROM cameras WHERE make = ? AND model = ?");
    $stmt->execute([$cam['make'], $cam['model']]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        $cameraIds[$key] = (int)$existingId;
    } else {
        $pdo->prepare("INSERT INTO cameras (make, model, type) VALUES (?, ?, ?)")
            ->execute([$cam['make'], $cam['model'], $cam['type']]);
        $cameraIds[$key] = (int)$pdo->lastInsertId();
    }
}
echo "   ✓ " . count($cameras) . " cameras created\n";

// ============================================
// LENSES
// ============================================
echo "\n🔭 Seeding lenses...\n";

$lenses = [
    // Leica M
    ['brand' => 'Leica', 'model' => 'Summicron 35mm f/2', 'focal_min' => 35, 'focal_max' => 35, 'aperture_min' => 2.0],
    ['brand' => 'Leica', 'model' => 'Summilux 50mm f/1.4', 'focal_min' => 50, 'focal_max' => 50, 'aperture_min' => 1.4],
    ['brand' => 'Leica', 'model' => 'Elmarit 28mm f/2.8', 'focal_min' => 28, 'focal_max' => 28, 'aperture_min' => 2.8],
    // Voigtlander
    ['brand' => 'Voigtlander', 'model' => 'Nokton 35mm f/1.4', 'focal_min' => 35, 'focal_max' => 35, 'aperture_min' => 1.4],
    ['brand' => 'Voigtlander', 'model' => 'Nokton 40mm f/1.2', 'focal_min' => 40, 'focal_max' => 40, 'aperture_min' => 1.2],
    // Canon FD
    ['brand' => 'Canon', 'model' => 'FD 50mm f/1.4', 'focal_min' => 50, 'focal_max' => 50, 'aperture_min' => 1.4],
    ['brand' => 'Canon', 'model' => 'FD 85mm f/1.8', 'focal_min' => 85, 'focal_max' => 85, 'aperture_min' => 1.8],
    ['brand' => 'Canon', 'model' => 'FD 28mm f/2.8', 'focal_min' => 28, 'focal_max' => 28, 'aperture_min' => 2.8],
    // Nikon F
    ['brand' => 'Nikon', 'model' => 'Nikkor 50mm f/1.4', 'focal_min' => 50, 'focal_max' => 50, 'aperture_min' => 1.4],
    ['brand' => 'Nikon', 'model' => 'Nikkor 35mm f/2', 'focal_min' => 35, 'focal_max' => 35, 'aperture_min' => 2.0],
    ['brand' => 'Nikon', 'model' => 'Nikkor 85mm f/1.8', 'focal_min' => 85, 'focal_max' => 85, 'aperture_min' => 1.8],
    ['brand' => 'Nikon', 'model' => 'Nikkor 24-70mm f/2.8', 'focal_min' => 24, 'focal_max' => 70, 'aperture_min' => 2.8],
    // Zeiss
    ['brand' => 'Zeiss', 'model' => 'Planar 50mm f/1.4', 'focal_min' => 50, 'focal_max' => 50, 'aperture_min' => 1.4],
    ['brand' => 'Zeiss', 'model' => 'Batis 25mm f/2', 'focal_min' => 25, 'focal_max' => 25, 'aperture_min' => 2.0],
    ['brand' => 'Zeiss', 'model' => 'Batis 85mm f/1.8', 'focal_min' => 85, 'focal_max' => 85, 'aperture_min' => 1.8],
    // Sony
    ['brand' => 'Sony', 'model' => 'FE 35mm f/1.4 GM', 'focal_min' => 35, 'focal_max' => 35, 'aperture_min' => 1.4],
    ['brand' => 'Sony', 'model' => 'FE 24-70mm f/2.8 GM', 'focal_min' => 24, 'focal_max' => 70, 'aperture_min' => 2.8],
    ['brand' => 'Sony', 'model' => 'FE 85mm f/1.4 GM', 'focal_min' => 85, 'focal_max' => 85, 'aperture_min' => 1.4],
    // Fujifilm
    ['brand' => 'Fujifilm', 'model' => 'XF 23mm f/1.4 R', 'focal_min' => 23, 'focal_max' => 23, 'aperture_min' => 1.4],
    ['brand' => 'Fujifilm', 'model' => 'XF 56mm f/1.2 R', 'focal_min' => 56, 'focal_max' => 56, 'aperture_min' => 1.2],
    ['brand' => 'Fujifilm', 'model' => 'XF 16-55mm f/2.8', 'focal_min' => 16, 'focal_max' => 55, 'aperture_min' => 2.8],
    // Hasselblad/Mamiya
    ['brand' => 'Hasselblad', 'model' => 'Planar 80mm f/2.8', 'focal_min' => 80, 'focal_max' => 80, 'aperture_min' => 2.8],
    ['brand' => 'Mamiya', 'model' => 'Sekor 110mm f/2.8', 'focal_min' => 110, 'focal_max' => 110, 'aperture_min' => 2.8],
    ['brand' => 'Mamiya', 'model' => 'N 80mm f/4', 'focal_min' => 80, 'focal_max' => 80, 'aperture_min' => 4.0],
];

$lensIds = [];
foreach ($lenses as $lens) {
    $key = $lens['brand'] . ' ' . $lens['model'];
    $stmt = $pdo->prepare("SELECT id FROM lenses WHERE brand = ? AND model = ?");
    $stmt->execute([$lens['brand'], $lens['model']]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        $lensIds[$key] = (int)$existingId;
    } else {
        $pdo->prepare("INSERT INTO lenses (brand, model, focal_min, focal_max, aperture_min) VALUES (?, ?, ?, ?, ?)")
            ->execute([$lens['brand'], $lens['model'], $lens['focal_min'], $lens['focal_max'], $lens['aperture_min']]);
        $lensIds[$key] = (int)$pdo->lastInsertId();
    }
}
echo "   ✓ " . count($lenses) . " lenses created\n";

// ============================================
// FILMS
// ============================================
echo "\n🎞️  Seeding films...\n";

$films = [
    // Color Negative
    ['brand' => 'Kodak', 'name' => 'Portra 400', 'iso' => 400, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Kodak', 'name' => 'Portra 800', 'iso' => 800, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Kodak', 'name' => 'Portra 160', 'iso' => 160, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Kodak', 'name' => 'Gold 200', 'iso' => 200, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Kodak', 'name' => 'Ektar 100', 'iso' => 100, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Kodak', 'name' => 'ColorPlus 200', 'iso' => 200, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Fujifilm', 'name' => 'Pro 400H', 'iso' => 400, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Fujifilm', 'name' => 'Superia 400', 'iso' => 400, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Fujifilm', 'name' => 'C200', 'iso' => 200, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'CineStill', 'name' => '800T', 'iso' => 800, 'format' => '35mm', 'type' => 'color_negative'],
    ['brand' => 'Lomography', 'name' => 'Color 400', 'iso' => 400, 'format' => '35mm', 'type' => 'color_negative'],
    // B&W
    ['brand' => 'Kodak', 'name' => 'Tri-X 400', 'iso' => 400, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Kodak', 'name' => 'T-Max 400', 'iso' => 400, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Kodak', 'name' => 'T-Max 100', 'iso' => 100, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Ilford', 'name' => 'HP5 Plus', 'iso' => 400, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Ilford', 'name' => 'Delta 400', 'iso' => 400, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Ilford', 'name' => 'Delta 3200', 'iso' => 3200, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Ilford', 'name' => 'FP4 Plus', 'iso' => 125, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Ilford', 'name' => 'Pan F Plus', 'iso' => 50, 'format' => '35mm', 'type' => 'bw_negative'],
    ['brand' => 'Fomapan', 'name' => '400 Action', 'iso' => 400, 'format' => '35mm', 'type' => 'bw_negative'],
    // Slide
    ['brand' => 'Kodak', 'name' => 'Ektachrome E100', 'iso' => 100, 'format' => '35mm', 'type' => 'slide'],
    ['brand' => 'Fujifilm', 'name' => 'Velvia 50', 'iso' => 50, 'format' => '35mm', 'type' => 'slide'],
    ['brand' => 'Fujifilm', 'name' => 'Provia 100F', 'iso' => 100, 'format' => '35mm', 'type' => 'slide'],
    // Medium format
    ['brand' => 'Kodak', 'name' => 'Portra 400', 'iso' => 400, 'format' => '120', 'type' => 'color_negative'],
    ['brand' => 'Kodak', 'name' => 'Tri-X 400', 'iso' => 400, 'format' => '120', 'type' => 'bw_negative'],
    ['brand' => 'Ilford', 'name' => 'HP5 Plus', 'iso' => 400, 'format' => '120', 'type' => 'bw_negative'],
    ['brand' => 'Fujifilm', 'name' => 'Pro 400H', 'iso' => 400, 'format' => '120', 'type' => 'color_negative'],
];

$filmIds = [];
foreach ($films as $film) {
    $key = "{$film['brand']} {$film['name']} {$film['iso']} {$film['format']}";
    $stmt = $pdo->prepare("SELECT id FROM films WHERE brand = ? AND name = ? AND iso = ? AND format = ?");
    $stmt->execute([$film['brand'], $film['name'], $film['iso'], $film['format']]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        $filmIds[$key] = (int)$existingId;
    } else {
        $pdo->prepare("INSERT INTO films (brand, name, iso, format, type) VALUES (?, ?, ?, ?, ?)")
            ->execute([$film['brand'], $film['name'], $film['iso'], $film['format'], $film['type']]);
        $filmIds[$key] = (int)$pdo->lastInsertId();
    }
}
echo "   ✓ " . count($films) . " films created\n";

// ============================================
// DEVELOPERS
// ============================================
echo "\n🧪 Seeding developers...\n";

$developers = [
    ['name' => 'Kodak D-76', 'process' => 'BW', 'notes' => 'Classic fine grain developer'],
    ['name' => 'Kodak HC-110', 'process' => 'BW', 'notes' => 'Versatile liquid concentrate'],
    ['name' => 'Kodak XTOL', 'process' => 'BW', 'notes' => 'Modern fine grain developer'],
    ['name' => 'Rodinal', 'process' => 'BW', 'notes' => 'High acutance compensating developer'],
    ['name' => 'Ilford ID-11', 'process' => 'BW', 'notes' => 'Similar to D-76, fine grain'],
    ['name' => 'Ilford Ilfosol 3', 'process' => 'BW', 'notes' => 'General purpose liquid developer'],
    ['name' => 'Ilford DDX', 'process' => 'BW', 'notes' => 'Excellent for push processing'],
    ['name' => 'C-41', 'process' => 'C-41', 'notes' => 'Standard color negative process'],
    ['name' => 'E-6', 'process' => 'E-6', 'notes' => 'Standard slide process'],
    ['name' => 'CineStill CS41', 'process' => 'C-41', 'notes' => 'Simplified color development kit'],
];

$developerIds = [];
foreach ($developers as $dev) {
    $key = $dev['name'];
    $stmt = $pdo->prepare("SELECT id FROM developers WHERE name = ? AND process = ?");
    $stmt->execute([$dev['name'], $dev['process']]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        $developerIds[$key] = (int)$existingId;
    } else {
        $pdo->prepare("INSERT INTO developers (name, process, notes) VALUES (?, ?, ?)")
            ->execute([$dev['name'], $dev['process'], $dev['notes']]);
        $developerIds[$key] = (int)$pdo->lastInsertId();
    }
}
echo "   ✓ " . count($developers) . " developers created\n";

// ============================================
// LABS
// ============================================
echo "\n🏭 Seeding labs...\n";

$labs = [
    ['name' => 'Carmencita Film Lab', 'city' => 'Valencia', 'country' => 'Spain'],
    ['name' => 'Mori Film Lab', 'city' => 'Tokyo', 'country' => 'Japan'],
    ['name' => 'Richard Photo Lab', 'city' => 'Los Angeles', 'country' => 'USA'],
    ['name' => 'The Darkroom', 'city' => 'San Clemente', 'country' => 'USA'],
    ['name' => 'Gelatin Labs', 'city' => 'Toronto', 'country' => 'Canada'],
    ['name' => 'AG Photographic', 'city' => 'Birmingham', 'country' => 'UK'],
    ['name' => 'Nation Photo Lab', 'city' => 'New York', 'country' => 'USA'],
    ['name' => 'Foto Ferrania Lab', 'city' => 'Milan', 'country' => 'Italy'],
    ['name' => 'Foto Professionale', 'city' => 'Rome', 'country' => 'Italy'],
    ['name' => 'Home Development', 'city' => null, 'country' => null],
];

$labIds = [];
foreach ($labs as $lab) {
    $key = $lab['name'];
    $stmt = $pdo->prepare("SELECT id FROM labs WHERE name = ?");
    $stmt->execute([$lab['name']]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        $labIds[$key] = (int)$existingId;
    } else {
        $pdo->prepare("INSERT INTO labs (name, city, country) VALUES (?, ?, ?)")
            ->execute([$lab['name'], $lab['city'], $lab['country']]);
        $labIds[$key] = (int)$pdo->lastInsertId();
    }
}
echo "   ✓ " . count($labs) . " labs created\n";

// ============================================
// ALBUMS
// ============================================
echo "\n📚 Seeding albums...\n";

$albums = [
    // Album 1: Street Photography Portfolio (Standard)
    [
        'title' => 'Streets of Milan',
        'slug' => 'streets-of-milan',
        'category_id' => $categoryIds['street-photography'],
        'location_id' => $locationIds['milan-italy'],
        'template_id' => 1,
        'excerpt' => 'Candid moments and urban life in Milan, capturing the rhythm of the city.',
        'body' => '<p>A visual journey through Milan\'s streets, from the fashion district to the historic Navigli canals. Shot over several months, this series explores the contrast between the city\'s elegant modernity and its working-class neighborhoods.</p><p>All images shot on film, primarily with the Leica M6 and Kodak Portra 400.</p>',
        'shoot_date' => '2024-03-15',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-04-01 10:00:00',
        'sort_order' => 1,
        'is_nsfw' => 0,
        'password_hash' => null,
        'allow_downloads' => 1,
        'allow_template_switch' => 1,
        'categories' => ['street-photography', 'urban-life'],
        'tags' => ['street-photography', 'urban', 'film', 'italy', 'analog'],
        'cameras' => ['Leica M6', 'Contax T2'],
        'lenses' => ['Leica Summicron 35mm f/2', 'Leica Summilux 50mm f/1.4'],
        'films' => ['Kodak Portra 400 35mm', 'Kodak Tri-X 400 35mm'],
        'developers' => ['C-41'],
        'labs' => ['Carmencita Film Lab'],
        'images' => [
            ['file' => 'milan-001.jpg', 'alt' => 'Morning commuter at Central Station', 'caption' => 'Rush hour at Milano Centrale', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'milan-002.jpg', 'alt' => 'Elderly man reading at cafe', 'caption' => 'Sunday morning espresso in Brera', 'camera' => 'Leica M6', 'lens' => 'Leica Summilux 50mm f/1.4', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'milan-003.jpg', 'alt' => 'Street vendor arranging flowers', 'caption' => 'Fresh flowers at the Naviglio Grande market', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'milan-004.jpg', 'alt' => 'Tram passing through Porta Ticinese', 'caption' => 'The iconic orange tram', 'camera' => 'Contax T2', 'lens' => null, 'film' => 'Kodak Tri-X 400 35mm', 'process' => 'analog'],
            ['file' => 'milan-005.jpg', 'alt' => 'Fashion week crowds', 'caption' => 'Street style during Milano Fashion Week', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'milan-006.jpg', 'alt' => 'Night lights on Naviglio', 'caption' => 'Evening atmosphere along the canals', 'camera' => 'Leica M6', 'lens' => 'Leica Summilux 50mm f/1.4', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
        ],
    ],

    // Album 2: Portrait Series (Password Protected)
    [
        'title' => 'Intimate Portraits',
        'slug' => 'intimate-portraits',
        'category_id' => $categoryIds['portrait'],
        'location_id' => $locationIds['florence-italy'],
        'template_id' => 2,
        'excerpt' => 'A private collection of environmental portraits shot on medium format film.',
        'body' => '<p>This intimate portrait series was shot over two years, featuring artists, musicians, and craftspeople in their natural environments. Each session was a collaboration, allowing subjects to express themselves authentically.</p><p>Shot on Hasselblad 500C/M with Kodak Portra 400 in 120 format.</p>',
        'shoot_date' => '2023-06-20',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2023-08-15 12:00:00',
        'sort_order' => 2,
        'is_nsfw' => 0,
        'password_hash' => password_hash('demo123', PASSWORD_DEFAULT), // Password: demo123
        'allow_downloads' => 0,
        'allow_template_switch' => 0,
        'categories' => ['portrait', 'environmental-portrait'],
        'tags' => ['portrait', 'film', 'medium-format', 'italy', 'editorial'],
        'cameras' => ['Hasselblad 500C/M'],
        'lenses' => ['Hasselblad Planar 80mm f/2.8'],
        'films' => ['Kodak Portra 400 120'],
        'developers' => ['C-41'],
        'labs' => ['Richard Photo Lab'],
        'images' => [
            ['file' => 'portrait-001.jpg', 'alt' => 'Glassblower at work', 'caption' => 'Master glassblower in Murano workshop', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Kodak Portra 400 120', 'process' => 'analog'],
            ['file' => 'portrait-002.jpg', 'alt' => 'Jazz musician with saxophone', 'caption' => 'Backstage at the Blue Note Milano', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Kodak Portra 400 120', 'process' => 'analog'],
            ['file' => 'portrait-003.jpg', 'alt' => 'Leather craftsman in workshop', 'caption' => 'Third generation leather artisan in Florence', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Kodak Portra 400 120', 'process' => 'analog'],
            ['file' => 'portrait-004.jpg', 'alt' => 'Painter in studio', 'caption' => 'Contemporary artist in her Brera studio', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Kodak Portra 400 120', 'process' => 'analog'],
        ],
    ],

    // Album 3: NSFW Fine Art
    [
        'title' => 'Body Studies',
        'slug' => 'body-studies',
        'category_id' => $categoryIds['fine-art'],
        'location_id' => null,
        'template_id' => 4,
        'excerpt' => 'Abstract explorations of the human form in black and white.',
        'body' => '<p>A fine art series exploring form, light, and shadow through abstract interpretations of the human body. These images focus on shape and texture, treating the body as landscape.</p><p>⚠️ This gallery contains artistic nudity.</p>',
        'shoot_date' => '2024-01-10',
        'show_date' => 0,
        'is_published' => 1,
        'published_at' => '2024-02-14 00:00:00',
        'sort_order' => 3,
        'is_nsfw' => 1, // NSFW content
        'password_hash' => null,
        'allow_downloads' => 0,
        'allow_template_switch' => 0,
        'categories' => ['fine-art', 'black-white'],
        'tags' => ['black-and-white', 'film', 'conceptual', 'minimalist'],
        'cameras' => ['Mamiya RZ67'],
        'lenses' => ['Mamiya Sekor 110mm f/2.8'],
        'films' => ['Ilford HP5 Plus 120'],
        'developers' => ['Rodinal'],
        'labs' => ['Home Development'],
        'images' => [
            ['file' => 'body-001.jpg', 'alt' => 'Abstract body form 1', 'caption' => 'Study in curves and shadows', 'camera' => 'Mamiya RZ67', 'lens' => 'Mamiya Sekor 110mm f/2.8', 'film' => 'Ilford HP5 Plus 120', 'process' => 'analog'],
            ['file' => 'body-002.jpg', 'alt' => 'Abstract body form 2', 'caption' => 'Light tracing form', 'camera' => 'Mamiya RZ67', 'lens' => 'Mamiya Sekor 110mm f/2.8', 'film' => 'Ilford HP5 Plus 120', 'process' => 'analog'],
            ['file' => 'body-003.jpg', 'alt' => 'Abstract body form 3', 'caption' => 'Negative space study', 'camera' => 'Mamiya RZ67', 'lens' => 'Mamiya Sekor 110mm f/2.8', 'film' => 'Ilford HP5 Plus 120', 'process' => 'analog'],
        ],
    ],

    // Album 4: NSFW + Password Protected
    [
        'title' => 'Private Collection',
        'slug' => 'private-collection',
        'category_id' => $categoryIds['fine-art'],
        'location_id' => null,
        'template_id' => 3,
        'excerpt' => 'An exclusive private collection requiring special access.',
        'body' => '<p>This exclusive collection is available only to verified collectors and requires both age verification and access credentials.</p>',
        'shoot_date' => '2024-05-01',
        'show_date' => 0,
        'is_published' => 1,
        'published_at' => '2024-06-01 00:00:00',
        'sort_order' => 4,
        'is_nsfw' => 1, // NSFW + Password
        'password_hash' => password_hash('private456', PASSWORD_DEFAULT), // Password: private456
        'allow_downloads' => 0,
        'allow_template_switch' => 0,
        'categories' => ['fine-art'],
        'tags' => ['conceptual', 'editorial', 'film'],
        'cameras' => ['Leica M10-R'],
        'lenses' => ['Leica Summilux 50mm f/1.4'],
        'films' => [],
        'developers' => [],
        'labs' => [],
        'images' => [
            ['file' => 'private-001.jpg', 'alt' => 'Private collection 1', 'caption' => 'Exclusive work #1', 'camera' => 'Leica M10-R', 'lens' => 'Leica Summilux 50mm f/1.4', 'film' => null, 'process' => 'digital'],
            ['file' => 'private-002.jpg', 'alt' => 'Private collection 2', 'caption' => 'Exclusive work #2', 'camera' => 'Leica M10-R', 'lens' => 'Leica Summilux 50mm f/1.4', 'film' => null, 'process' => 'digital'],
        ],
    ],

    // Album 5: Landscape (Digital, standard)
    [
        'title' => 'Iceland: Fire and Ice',
        'slug' => 'iceland-fire-ice',
        'category_id' => $categoryIds['landscape'],
        'location_id' => $locationIds['iceland'],
        'template_id' => 6,
        'excerpt' => 'Dramatic landscapes of volcanic Iceland through the seasons.',
        'body' => '<p>A comprehensive photographic exploration of Iceland\'s otherworldly landscapes. From the black sand beaches of Vik to the ice caves of Vatnajökull, this collection captures the raw power and delicate beauty of this volcanic island.</p><p>Shot on Sony A7RIV over multiple trips between 2022-2024.</p>',
        'shoot_date' => '2024-02-20',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-03-10 08:00:00',
        'sort_order' => 5,
        'is_nsfw' => 0,
        'password_hash' => null,
        'allow_downloads' => 1,
        'allow_template_switch' => 1,
        'categories' => ['landscape'],
        'tags' => ['landscape', 'travel', 'digital', 'long-exposure', 'moody'],
        'cameras' => ['Sony A7RIV'],
        'lenses' => ['Sony FE 24-70mm f/2.8 GM', 'Zeiss Batis 25mm f/2'],
        'films' => [],
        'developers' => [],
        'labs' => [],
        'images' => [
            ['file' => 'iceland-001.jpg', 'alt' => 'Reynisfjara black sand beach', 'caption' => 'Basalt columns at Reynisfjara', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'iceland-002.jpg', 'alt' => 'Northern lights over glacier', 'caption' => 'Aurora borealis dancing over Jökulsárlón', 'camera' => 'Sony A7RIV', 'lens' => 'Zeiss Batis 25mm f/2', 'film' => null, 'process' => 'digital'],
            ['file' => 'iceland-003.jpg', 'alt' => 'Ice cave interior', 'caption' => 'Inside the crystal cave at Vatnajökull', 'camera' => 'Sony A7RIV', 'lens' => 'Zeiss Batis 25mm f/2', 'film' => null, 'process' => 'digital'],
            ['file' => 'iceland-004.jpg', 'alt' => 'Waterfall in golden light', 'caption' => 'Skógafoss at golden hour', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'iceland-005.jpg', 'alt' => 'Volcanic highlands', 'caption' => 'Landmannalaugar rhyolite mountains', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'iceland-006.jpg', 'alt' => 'Horses in snow', 'caption' => 'Icelandic horses in winter storm', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'iceland-007.jpg', 'alt' => 'Geothermal area', 'caption' => 'Steam vents at Hverir', 'camera' => 'Sony A7RIV', 'lens' => 'Zeiss Batis 25mm f/2', 'film' => null, 'process' => 'digital'],
            ['file' => 'iceland-008.jpg', 'alt' => 'Diamond Beach ice', 'caption' => 'Ice diamonds at Breiðamerkursandur', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
        ],
    ],

    // Album 6: Tokyo Street (Film + Digital hybrid)
    [
        'title' => 'Tokyo Nights',
        'slug' => 'tokyo-nights',
        'category_id' => $categoryIds['street-photography'],
        'location_id' => $locationIds['tokyo-japan'],
        'template_id' => 5,
        'excerpt' => 'Neon-lit streets and quiet moments in the Japanese metropolis.',
        'body' => '<p>Tokyo at night is a world of contrasts—from the overwhelming sensory experience of Shibuya and Shinjuku to the tranquil side streets of residential neighborhoods. This series captures both extremes.</p><p>Shot on a combination of CineStill 800T film and Fujifilm X-Pro3 digital.</p>',
        'shoot_date' => '2024-04-05',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-05-20 14:00:00',
        'sort_order' => 6,
        'is_nsfw' => 0,
        'password_hash' => null,
        'allow_downloads' => 1,
        'allow_template_switch' => 1,
        'categories' => ['street-photography', 'night-street'],
        'tags' => ['street-photography', 'night', 'japan', 'urban', 'color'],
        'cameras' => ['Leica MP', 'Fujifilm X-Pro3'],
        'lenses' => ['Voigtlander Nokton 35mm f/1.4', 'Fujifilm XF 23mm f/1.4 R'],
        'films' => ['CineStill 800T 35mm'],
        'developers' => ['C-41'],
        'labs' => ['Mori Film Lab'],
        'images' => [
            ['file' => 'tokyo-001.jpg', 'alt' => 'Shibuya crossing at night', 'caption' => 'The famous scramble crossing after rain', 'camera' => 'Leica MP', 'lens' => 'Voigtlander Nokton 35mm f/1.4', 'film' => 'CineStill 800T 35mm', 'process' => 'analog'],
            ['file' => 'tokyo-002.jpg', 'alt' => 'Ramen shop glow', 'caption' => 'Late night ramen in Shinjuku', 'camera' => 'Leica MP', 'lens' => 'Voigtlander Nokton 35mm f/1.4', 'film' => 'CineStill 800T 35mm', 'process' => 'analog'],
            ['file' => 'tokyo-003.jpg', 'alt' => 'Salary worker in train', 'caption' => 'Last train home', 'camera' => 'Fujifilm X-Pro3', 'lens' => 'Fujifilm XF 23mm f/1.4 R', 'film' => null, 'process' => 'digital'],
            ['file' => 'tokyo-004.jpg', 'alt' => 'Kabukicho neon', 'caption' => 'Neon jungle of Kabukicho', 'camera' => 'Leica MP', 'lens' => 'Voigtlander Nokton 35mm f/1.4', 'film' => 'CineStill 800T 35mm', 'process' => 'analog'],
            ['file' => 'tokyo-005.jpg', 'alt' => 'Quiet residential street', 'caption' => 'Residential Shimokitazawa', 'camera' => 'Fujifilm X-Pro3', 'lens' => 'Fujifilm XF 23mm f/1.4 R', 'film' => null, 'process' => 'digital'],
            ['file' => 'tokyo-006.jpg', 'alt' => 'Vending machines at night', 'caption' => 'The ubiquitous vending machine', 'camera' => 'Leica MP', 'lens' => 'Voigtlander Nokton 35mm f/1.4', 'film' => 'CineStill 800T 35mm', 'process' => 'analog'],
        ],
    ],

    // Album 7: Architecture (B&W, draft/unpublished)
    [
        'title' => 'Brutalist Barcelona',
        'slug' => 'brutalist-barcelona',
        'category_id' => $categoryIds['architecture'],
        'location_id' => $locationIds['barcelona-spain'],
        'template_id' => 4,
        'excerpt' => 'Exploring the bold concrete forms of Barcelona\'s brutalist architecture.',
        'body' => '<p>Beyond Gaudi\'s organic curves lies another Barcelona—one of bold concrete forms and uncompromising modernist vision. This series documents the city\'s lesser-known brutalist heritage.</p><p>Work in progress. More images coming soon.</p>',
        'shoot_date' => '2024-07-12',
        'show_date' => 0,
        'is_published' => 0, // Draft
        'published_at' => null,
        'sort_order' => 7,
        'is_nsfw' => 0,
        'password_hash' => null,
        'allow_downloads' => 0,
        'allow_template_switch' => 1,
        'categories' => ['architecture', 'black-white'],
        'tags' => ['architecture', 'black-and-white', 'spain', 'minimalist', 'urban'],
        'cameras' => ['Nikon F3'],
        'lenses' => ['Nikon Nikkor 24-70mm f/2.8'],
        'films' => ['Ilford Delta 400 35mm'],
        'developers' => ['Ilford DDX'],
        'labs' => ['Home Development'],
        'images' => [
            ['file' => 'barcelona-001.jpg', 'alt' => 'Concrete facade', 'caption' => 'Walden 7 apartments', 'camera' => 'Nikon F3', 'lens' => 'Nikon Nikkor 24-70mm f/2.8', 'film' => 'Ilford Delta 400 35mm', 'process' => 'analog'],
            ['file' => 'barcelona-002.jpg', 'alt' => 'Geometric shadows', 'caption' => 'MACBA museum exterior', 'camera' => 'Nikon F3', 'lens' => 'Nikon Nikkor 24-70mm f/2.8', 'film' => 'Ilford Delta 400 35mm', 'process' => 'analog'],
            ['file' => 'barcelona-003.jpg', 'alt' => 'Spiral staircase', 'caption' => 'Interior spiral at Walden 7', 'camera' => 'Nikon F3', 'lens' => 'Nikon Nikkor 24-70mm f/2.8', 'film' => 'Ilford Delta 400 35mm', 'process' => 'analog'],
        ],
    ],

    // Album 8: Documentary
    [
        'title' => 'Venetian Craftsmen',
        'slug' => 'venetian-craftsmen',
        'category_id' => $categoryIds['documentary'],
        'location_id' => $locationIds['venice-italy'],
        'template_id' => 6,
        'excerpt' => 'Documenting the disappearing traditional crafts of Venice.',
        'body' => '<p>Venice is not just a tourist destination—it\'s a living museum of centuries-old craftsmanship. This documentary project follows the last generation of traditional artisans: the gondola builders, the glassblowers of Murano, the lace makers of Burano, and the mask craftsmen of the carnival.</p><p>An ongoing project started in 2022.</p>',
        'shoot_date' => '2024-02-01',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-03-01 09:00:00',
        'sort_order' => 8,
        'is_nsfw' => 0,
        'password_hash' => null,
        'allow_downloads' => 1,
        'allow_template_switch' => 1,
        'categories' => ['documentary'],
        'tags' => ['documentary', 'italy', 'film', 'portrait', 'travel'],
        'cameras' => ['Leica M6', 'Mamiya 7 II'],
        'lenses' => ['Leica Summicron 35mm f/2', 'Mamiya N 80mm f/4'],
        'films' => ['Kodak Portra 400 35mm', 'Kodak Portra 400 120'],
        'developers' => ['C-41'],
        'labs' => ['Foto Professionale'],
        'images' => [
            ['file' => 'venice-001.jpg', 'alt' => 'Gondola workshop', 'caption' => 'Squero di San Trovaso, the last gondola workshop', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'venice-002.jpg', 'alt' => 'Glassblower at furnace', 'caption' => 'Maestro at work in Murano', 'camera' => 'Mamiya 7 II', 'lens' => 'Mamiya N 80mm f/4', 'film' => 'Kodak Portra 400 120', 'process' => 'analog'],
            ['file' => 'venice-003.jpg', 'alt' => 'Lace making detail', 'caption' => 'Intricate merletti di Burano', 'camera' => 'Mamiya 7 II', 'lens' => 'Mamiya N 80mm f/4', 'film' => 'Kodak Portra 400 120', 'process' => 'analog'],
            ['file' => 'venice-004.jpg', 'alt' => 'Carnival mask maker', 'caption' => 'Creating traditional Venetian masks', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'venice-005.jpg', 'alt' => 'Bookbinder hands', 'caption' => 'Centuries-old binding techniques', 'camera' => 'Mamiya 7 II', 'lens' => 'Mamiya N 80mm f/4', 'film' => 'Kodak Portra 400 120', 'process' => 'analog'],
        ],
    ],

    // Album 9: Paris Streets (Standard)
    [
        'title' => 'Paris Passages',
        'slug' => 'paris-passages',
        'category_id' => $categoryIds['street-photography'],
        'location_id' => $locationIds['paris-france'],
        'template_id' => 1,
        'excerpt' => 'Hidden passages and street life in the City of Light.',
        'body' => '<p>The covered passages of Paris are a window into 19th century shopping culture. These hidden gems, combined with the vibrant street life of modern Paris, create a unique photographic tapestry.</p>',
        'shoot_date' => '2024-05-10',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-06-01 10:00:00',
        'sort_order' => 9,
        'is_nsfw' => 0,
        'password_hash' => null,
        'allow_downloads' => 1,
        'allow_template_switch' => 1,
        'categories' => ['street-photography'],
        'tags' => ['street-photography', 'travel', 'france', 'urban'],
        'cameras' => ['Leica M6'],
        'lenses' => ['Leica Summicron 35mm f/2'],
        'films' => ['Kodak Portra 400 35mm'],
        'developers' => ['C-41'],
        'labs' => ['Carmencita Film Lab'],
        'images' => [
            ['file' => 'paris-001.jpg', 'alt' => 'Eiffel Tower at dusk', 'caption' => 'Iconic view from Trocadero', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'paris-002.jpg', 'alt' => 'Covered passage interior', 'caption' => 'Galerie Vivienne details', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'paris-003.jpg', 'alt' => 'Seine river boats', 'caption' => 'Morning light on the Seine', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'paris-004.jpg', 'alt' => 'Cafe terrace', 'caption' => 'Classic Parisian cafe culture', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
            ['file' => 'paris-005.jpg', 'alt' => 'Metro entrance', 'caption' => 'Art Nouveau metro architecture', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Kodak Portra 400 35mm', 'process' => 'analog'],
        ],
    ],

    // Album 10: Berlin Shadows (Password Protected)
    [
        'title' => 'Berlin Shadows',
        'slug' => 'berlin-shadows',
        'category_id' => $categoryIds['street-photography'],
        'location_id' => $locationIds['berlin-germany'],
        'template_id' => 4,
        'excerpt' => 'Dark corners and urban contrasts in the German capital.',
        'body' => '<p>Berlin\'s complex history creates unique urban landscapes. This series explores the interplay of light and shadow in a city constantly reinventing itself.</p><p>🔒 This gallery requires a password for access.</p>',
        'shoot_date' => '2024-04-20',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-05-15 12:00:00',
        'sort_order' => 10,
        'is_nsfw' => 0,
        'password_hash' => password_hash('berlin2024', PASSWORD_DEFAULT), // Password: berlin2024
        'allow_downloads' => 0,
        'allow_template_switch' => 1,
        'categories' => ['street-photography', 'black-white'],
        'tags' => ['street-photography', 'urban', 'black-and-white', 'germany'],
        'cameras' => ['Leica M6'],
        'lenses' => ['Leica Summicron 35mm f/2'],
        'films' => ['Ilford HP5 Plus 35mm'],
        'developers' => ['Rodinal'],
        'labs' => ['Home Development'],
        'images' => [
            ['file' => 'berlin-001.jpg', 'alt' => 'Berlin Wall memorial', 'caption' => 'Shadows of history', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Ilford HP5 Plus 35mm', 'process' => 'analog'],
            ['file' => 'berlin-002.jpg', 'alt' => 'Industrial architecture', 'caption' => 'Post-industrial Berlin', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Ilford HP5 Plus 35mm', 'process' => 'analog'],
            ['file' => 'berlin-003.jpg', 'alt' => 'Street art corner', 'caption' => 'Urban expression', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Ilford HP5 Plus 35mm', 'process' => 'analog'],
            ['file' => 'berlin-004.jpg', 'alt' => 'U-Bahn station', 'caption' => 'Underground geometry', 'camera' => 'Leica M6', 'lens' => 'Leica Summicron 35mm f/2', 'film' => 'Ilford HP5 Plus 35mm', 'process' => 'analog'],
        ],
    ],

    // Album 11: Light and Shadow (NSFW)
    [
        'title' => 'Light and Shadow',
        'slug' => 'light-and-shadow',
        'category_id' => $categoryIds['fine-art'],
        'location_id' => null,
        'template_id' => 4,
        'excerpt' => 'Artistic exploration of light playing across form.',
        'body' => '<p>A fine art series studying the interplay of natural light and the human form. Each image is carefully composed to emphasize shape, texture, and the dynamic between illumination and darkness.</p><p>⚠️ This gallery contains artistic nudity.</p>',
        'shoot_date' => '2024-03-01',
        'show_date' => 0,
        'is_published' => 1,
        'published_at' => '2024-04-01 00:00:00',
        'sort_order' => 11,
        'is_nsfw' => 1, // NSFW
        'password_hash' => null,
        'allow_downloads' => 0,
        'allow_template_switch' => 0,
        'categories' => ['fine-art', 'black-white'],
        'tags' => ['black-and-white', 'conceptual', 'minimalist', 'studio'],
        'cameras' => ['Hasselblad 500C/M'],
        'lenses' => ['Hasselblad Planar 80mm f/2.8'],
        'films' => ['Ilford Delta 400 120'],
        'developers' => ['Ilford DDX'],
        'labs' => ['Home Development'],
        'images' => [
            ['file' => 'light-001.jpg', 'alt' => 'Light study 1', 'caption' => 'Window light study', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Ilford Delta 400 120', 'process' => 'analog'],
            ['file' => 'light-002.jpg', 'alt' => 'Light study 2', 'caption' => 'Chiaroscuro', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Ilford Delta 400 120', 'process' => 'analog'],
            ['file' => 'light-003.jpg', 'alt' => 'Light study 3', 'caption' => 'Form and shadow', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Ilford Delta 400 120', 'process' => 'analog'],
            ['file' => 'light-004.jpg', 'alt' => 'Light study 4', 'caption' => 'Golden hour', 'camera' => 'Hasselblad 500C/M', 'lens' => 'Hasselblad Planar 80mm f/2.8', 'film' => 'Ilford Delta 400 120', 'process' => 'analog'],
        ],
    ],

    // Album 12: Exclusive Boudoir (NSFW + Password)
    [
        'title' => 'Exclusive Boudoir',
        'slug' => 'exclusive-boudoir',
        'category_id' => $categoryIds['fine-art'],
        'location_id' => null,
        'template_id' => 3,
        'excerpt' => 'Premium boudoir collection for verified clients only.',
        'body' => '<p>An exclusive boudoir photography collection requiring both age verification and special access credentials. These intimate portraits celebrate confidence and elegance.</p>',
        'shoot_date' => '2024-06-15',
        'show_date' => 0,
        'is_published' => 1,
        'published_at' => '2024-07-01 00:00:00',
        'sort_order' => 12,
        'is_nsfw' => 1, // NSFW + Password
        'password_hash' => password_hash('exclusive789', PASSWORD_DEFAULT), // Password: exclusive789
        'allow_downloads' => 0,
        'allow_template_switch' => 0,
        'categories' => ['fine-art', 'portrait'],
        'tags' => ['editorial', 'conceptual', 'digital'],
        'cameras' => ['Sony A7RIV'],
        'lenses' => ['Sony FE 85mm f/1.4 GM'],
        'films' => [],
        'developers' => [],
        'labs' => [],
        'images' => [
            ['file' => 'boudoir-001.jpg', 'alt' => 'Boudoir portrait 1', 'caption' => 'Elegance in motion', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 85mm f/1.4 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'boudoir-002.jpg', 'alt' => 'Boudoir portrait 2', 'caption' => 'Natural light beauty', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 85mm f/1.4 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'boudoir-003.jpg', 'alt' => 'Boudoir portrait 3', 'caption' => 'Confidence captured', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 85mm f/1.4 GM', 'film' => null, 'process' => 'digital'],
        ],
    ],

    // Album 13: Patagonia Wild (Standard)
    [
        'title' => 'Patagonia Wild',
        'slug' => 'patagonia-wild',
        'category_id' => $categoryIds['landscape'],
        'location_id' => $locationIds['patagonia-argentina'],
        'template_id' => 6,
        'excerpt' => 'Untamed wilderness at the edge of the world.',
        'body' => '<p>The dramatic landscapes of Patagonia offer some of the most pristine wilderness on Earth. From the Torres del Paine to the Perito Moreno glacier, this collection captures the raw beauty of South America\'s southern frontier.</p>',
        'shoot_date' => '2024-01-20',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-02-28 08:00:00',
        'sort_order' => 13,
        'is_nsfw' => 0,
        'password_hash' => null,
        'allow_downloads' => 1,
        'allow_template_switch' => 1,
        'categories' => ['landscape'],
        'tags' => ['landscape', 'travel', 'digital', 'nature', 'moody'],
        'cameras' => ['Sony A7RIV'],
        'lenses' => ['Sony FE 24-70mm f/2.8 GM', 'Zeiss Batis 25mm f/2'],
        'films' => [],
        'developers' => [],
        'labs' => [],
        'images' => [
            ['file' => 'patagonia-001.jpg', 'alt' => 'Torres del Paine', 'caption' => 'Sunrise at Torres del Paine', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'patagonia-002.jpg', 'alt' => 'Glacier edge', 'caption' => 'Perito Moreno ice face', 'camera' => 'Sony A7RIV', 'lens' => 'Zeiss Batis 25mm f/2', 'film' => null, 'process' => 'digital'],
            ['file' => 'patagonia-003.jpg', 'alt' => 'Mountain reflection', 'caption' => 'Mirror lakes of Patagonia', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'patagonia-004.jpg', 'alt' => 'Guanaco herd', 'caption' => 'Wildlife of the steppe', 'camera' => 'Sony A7RIV', 'lens' => 'Sony FE 24-70mm f/2.8 GM', 'film' => null, 'process' => 'digital'],
            ['file' => 'patagonia-005.jpg', 'alt' => 'Storm clouds', 'caption' => 'Patagonian weather drama', 'camera' => 'Sony A7RIV', 'lens' => 'Zeiss Batis 25mm f/2', 'film' => null, 'process' => 'digital'],
            ['file' => 'patagonia-006.jpg', 'alt' => 'Starry night', 'caption' => 'Milky Way over Fitz Roy', 'camera' => 'Sony A7RIV', 'lens' => 'Zeiss Batis 25mm f/2', 'film' => null, 'process' => 'digital'],
        ],
    ],

    // Album 14: London Underground (Password Protected)
    [
        'title' => 'London Underground',
        'slug' => 'london-underground',
        'category_id' => $categoryIds['street-photography'],
        'location_id' => $locationIds['london-uk'],
        'template_id' => 5,
        'excerpt' => 'The Tube and street scenes from Britain\'s capital.',
        'body' => '<p>London\'s Underground system is a world unto itself—a labyrinth of tunnels, platforms, and fleeting human connections. This series documents life above and below the city\'s surface.</p><p>🔒 Access requires password.</p>',
        'shoot_date' => '2024-03-25',
        'show_date' => 1,
        'is_published' => 1,
        'published_at' => '2024-04-20 14:00:00',
        'sort_order' => 14,
        'is_nsfw' => 0,
        'password_hash' => password_hash('tube2024', PASSWORD_DEFAULT), // Password: tube2024
        'allow_downloads' => 0,
        'allow_template_switch' => 1,
        'categories' => ['street-photography', 'documentary'],
        'tags' => ['street-photography', 'urban', 'uk', 'documentary'],
        'cameras' => ['Leica MP', 'Fujifilm X-Pro3'],
        'lenses' => ['Voigtlander Nokton 35mm f/1.4', 'Fujifilm XF 23mm f/1.4 R'],
        'films' => ['Ilford HP5 Plus 35mm'],
        'developers' => ['Ilford ID-11'],
        'labs' => ['AG Photographic'],
        'images' => [
            ['file' => 'london-001.jpg', 'alt' => 'Big Ben view', 'caption' => 'Westminster at dusk', 'camera' => 'Leica MP', 'lens' => 'Voigtlander Nokton 35mm f/1.4', 'film' => 'Ilford HP5 Plus 35mm', 'process' => 'analog'],
            ['file' => 'london-002.jpg', 'alt' => 'Tube platform', 'caption' => 'Mind the gap', 'camera' => 'Fujifilm X-Pro3', 'lens' => 'Fujifilm XF 23mm f/1.4 R', 'film' => null, 'process' => 'digital'],
            ['file' => 'london-003.jpg', 'alt' => 'Red phone box', 'caption' => 'Classic London icon', 'camera' => 'Leica MP', 'lens' => 'Voigtlander Nokton 35mm f/1.4', 'film' => 'Ilford HP5 Plus 35mm', 'process' => 'analog'],
            ['file' => 'london-004.jpg', 'alt' => 'City workers', 'caption' => 'Rush hour on the escalator', 'camera' => 'Fujifilm X-Pro3', 'lens' => 'Fujifilm XF 23mm f/1.4 R', 'film' => null, 'process' => 'digital'],
        ],
    ],

    // Album 15: Abstract Silhouettes (NSFW)
    [
        'title' => 'Abstract Silhouettes',
        'slug' => 'abstract-silhouettes',
        'category_id' => $categoryIds['fine-art'],
        'location_id' => null,
        'template_id' => 4,
        'excerpt' => 'Minimalist silhouettes exploring form and negative space.',
        'body' => '<p>This series reduces the human form to its essential silhouette, creating powerful graphic images that blur the line between photography and abstract art.</p><p>⚠️ Contains artistic nudity.</p>',
        'shoot_date' => '2024-02-14',
        'show_date' => 0,
        'is_published' => 1,
        'published_at' => '2024-03-14 00:00:00',
        'sort_order' => 15,
        'is_nsfw' => 1, // NSFW
        'password_hash' => null,
        'allow_downloads' => 0,
        'allow_template_switch' => 0,
        'categories' => ['fine-art', 'black-white'],
        'tags' => ['black-and-white', 'minimalist', 'conceptual', 'studio'],
        'cameras' => ['Mamiya RZ67'],
        'lenses' => ['Mamiya Sekor 110mm f/2.8'],
        'films' => ['Ilford Pan F Plus 120'],
        'developers' => ['Rodinal'],
        'labs' => ['Home Development'],
        'images' => [
            ['file' => 'abstract-001.jpg', 'alt' => 'Silhouette study 1', 'caption' => 'Curve and contrast', 'camera' => 'Mamiya RZ67', 'lens' => 'Mamiya Sekor 110mm f/2.8', 'film' => 'Ilford Pan F Plus 120', 'process' => 'analog'],
            ['file' => 'abstract-002.jpg', 'alt' => 'Silhouette study 2', 'caption' => 'Edge and form', 'camera' => 'Mamiya RZ67', 'lens' => 'Mamiya Sekor 110mm f/2.8', 'film' => 'Ilford Pan F Plus 120', 'process' => 'analog'],
            ['file' => 'abstract-003.jpg', 'alt' => 'Silhouette study 3', 'caption' => 'Negative space', 'camera' => 'Mamiya RZ67', 'lens' => 'Mamiya Sekor 110mm f/2.8', 'film' => 'Ilford Pan F Plus 120', 'process' => 'analog'],
            ['file' => 'abstract-004.jpg', 'alt' => 'Silhouette study 4', 'caption' => 'Graphic minimalism', 'camera' => 'Mamiya RZ67', 'lens' => 'Mamiya Sekor 110mm f/2.8', 'film' => 'Ilford Pan F Plus 120', 'process' => 'analog'],
        ],
    ],
];

$albumIds = [];
$imageCount = 0;

foreach ($albums as $albumData) {
    try {
        $pdo->beginTransaction();

        // Extract metadata for junction tables
        $albumCategories = $albumData['categories'] ?? [];
        $albumTags = $albumData['tags'] ?? [];
        $albumCameras = $albumData['cameras'] ?? [];
        $albumLenses = $albumData['lenses'] ?? [];
        $albumFilms = $albumData['films'] ?? [];
        $albumDevelopers = $albumData['developers'] ?? [];
        $albumLabs = $albumData['labs'] ?? [];
        $albumImagesData = $albumData['images'] ?? [];

        // Remove non-column data
        unset(
            $albumData['categories'],
            $albumData['tags'],
            $albumData['cameras'],
            $albumData['lenses'],
            $albumData['films'],
            $albumData['developers'],
            $albumData['labs'],
            $albumData['images']
        );

        // Upsert album
        $albumId = upsertById($pdo, 'albums', $albumData);
        $albumIds[$albumData['slug']] = $albumId;

        $status = [];
        if ($albumData['is_nsfw']) $status[] = 'NSFW';
        if ($albumData['password_hash']) $status[] = 'PASSWORD';
        if (!$albumData['is_published']) $status[] = 'DRAFT';
        $statusStr = !empty($status) ? ' [' . implode('+', $status) . ']' : '';
        echo "   ✓ {$albumData['title']}{$statusStr}\n";

        // Link categories (using junction table)
        $pdo->prepare("DELETE FROM album_category WHERE album_id = ?")->execute([$albumId]);
        foreach ($albumCategories as $catSlug) {
            if (isset($categoryIds[$catSlug])) {
                linkManyToMany($pdo, 'album_category', 'album_id', $albumId, 'category_id', $categoryIds[$catSlug]);
            }
        }

        // Link tags
        $pdo->prepare("DELETE FROM album_tag WHERE album_id = ?")->execute([$albumId]);
        foreach ($albumTags as $tagSlug) {
            if (isset($tagIds[$tagSlug])) {
                linkManyToMany($pdo, 'album_tag', 'album_id', $albumId, 'tag_id', $tagIds[$tagSlug]);
            }
        }

        // Link cameras
        $pdo->prepare("DELETE FROM album_camera WHERE album_id = ?")->execute([$albumId]);
        foreach ($albumCameras as $camName) {
            if (isset($cameraIds[$camName])) {
                linkManyToMany($pdo, 'album_camera', 'album_id', $albumId, 'camera_id', $cameraIds[$camName]);
            }
        }

        // Link lenses
        $pdo->prepare("DELETE FROM album_lens WHERE album_id = ?")->execute([$albumId]);
        foreach ($albumLenses as $lensName) {
            if (isset($lensIds[$lensName])) {
                linkManyToMany($pdo, 'album_lens', 'album_id', $albumId, 'lens_id', $lensIds[$lensName]);
            }
        }

        // Link films
        $pdo->prepare("DELETE FROM album_film WHERE album_id = ?")->execute([$albumId]);
        foreach ($albumFilms as $filmKey) {
            if (isset($filmIds[$filmKey])) {
                linkManyToMany($pdo, 'album_film', 'album_id', $albumId, 'film_id', $filmIds[$filmKey]);
            }
        }

        // Link developers
        $pdo->prepare("DELETE FROM album_developer WHERE album_id = ?")->execute([$albumId]);
        foreach ($albumDevelopers as $devName) {
            if (isset($developerIds[$devName])) {
                linkManyToMany($pdo, 'album_developer', 'album_id', $albumId, 'developer_id', $developerIds[$devName]);
            }
        }

        // Link labs
        $pdo->prepare("DELETE FROM album_lab WHERE album_id = ?")->execute([$albumId]);
        foreach ($albumLabs as $labName) {
            if (isset($labIds[$labName])) {
                linkManyToMany($pdo, 'album_lab', 'album_id', $albumId, 'lab_id', $labIds[$labName]);
            }
        }

        // Link location
        if (!empty($albumData['location_id'])) {
            $pdo->prepare("DELETE FROM album_location WHERE album_id = ?")->execute([$albumId]);
            linkManyToMany($pdo, 'album_location', 'album_id', $albumId, 'location_id', $albumData['location_id']);
        }

        // Insert images
        $coverId = null;
        $sortOrder = 1;
        $albumSlug = $albumData['slug'];
        foreach ($albumImagesData as $imgData) {
            // Download image from Unsplash to temp location first
            $tempPath = $root . '/public/media/seed/albums/' . $albumSlug . '/' . $imgData['file'];
            $downloadedDimensions = null;

            if (isset($albumImages[$albumSlug][$imgData['file']])) {
                $imgUrlData = $albumImages[$albumSlug][$imgData['file']];
                if (downloadImage($imgUrlData[0], $tempPath)) {
                    $downloadedDimensions = ['width' => $imgUrlData[1], 'height' => $imgUrlData[2]];
                }
            }

            // Get image info and hash
            if ($downloadedDimensions) {
                $imgInfo = array_merge($downloadedDimensions, ['mime' => 'image/jpeg']);
            } else {
                $imgInfo = getImageInfo($tempPath);
            }

            if (!is_file($tempPath)) {
                echo "     ⚠ Image file not found: {$tempPath}, skipping\n";
                continue;
            }

            // Generate hash and determine extension (like backend does)
            $hash = sha1_file($tempPath);
            $ext = match ($imgInfo['mime']) {
                'image/jpeg' => '.jpg',
                'image/png' => '.png',
                'image/webp' => '.webp',
                default => '.jpg',
            };

            // Copy to storage/originals/{hash}.{ext} (like backend upload)
            $storageFilePath = $storageOriginalsPath . '/' . $hash . $ext;
            $storageRelativePath = '/storage/originals/' . $hash . $ext;

            if (!is_file($storageFilePath)) {
                if (!copy($tempPath, $storageFilePath)) {
                    if (is_file($storageFilePath)) {
                        @unlink($storageFilePath);
                    }
                    echo "     ⚠ Failed to copy image from {$tempPath} to {$storageFilePath}, skipping\n";
                    continue;
                }
            }

            // Find camera/lens/film IDs
            $imgCameraId = null;
            $imgLensId = null;
            $imgFilmId = null;

            if (!empty($imgData['camera']) && isset($cameraIds[$imgData['camera']])) {
                $imgCameraId = $cameraIds[$imgData['camera']];
            }
            if (!empty($imgData['lens']) && isset($lensIds[$imgData['lens']])) {
                $imgLensId = $lensIds[$imgData['lens']];
            }
            if (!empty($imgData['film'])) {
                $imgFilmId = $filmIds[$imgData['film']] ?? null;
                if ($imgFilmId === null) {
                    echo "     ⚠ Film '{$imgData['film']}' not found for {$imgData['file']}\n";
                }
            }

            // Check if image already exists by hash (more reliable than path)
            $stmt = $pdo->prepare("SELECT id FROM images WHERE album_id = ? AND file_hash = ?");
            $stmt->execute([$albumId, $hash]);
            $existingImgId = $stmt->fetchColumn();

            if ($existingImgId) {
                // Update existing image - also update original_path to new storage location
                $pdo->prepare("UPDATE images SET original_path = ?, alt_text = ?, caption = ?, camera_id = ?, lens_id = ?, film_id = ?, process = ?, width = ?, height = ?, mime = ?, sort_order = ? WHERE id = ?")
                    ->execute([
                        $storageRelativePath,
                        $imgData['alt'],
                        $imgData['caption'],
                        $imgCameraId,
                        $imgLensId,
                        $imgFilmId,
                        $imgData['process'],
                        $imgInfo['width'],
                        $imgInfo['height'],
                        $imgInfo['mime'],
                        $sortOrder,
                        $existingImgId,
                    ]);
                $imgId = (int)$existingImgId;
            } else {
                // Insert new image with storage path (like backend upload)
                $pdo->prepare("INSERT INTO images (album_id, original_path, file_hash, width, height, mime, alt_text, caption, camera_id, lens_id, film_id, process, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $albumId,
                        $storageRelativePath,
                        $hash,
                        $imgInfo['width'],
                        $imgInfo['height'],
                        $imgInfo['mime'],
                        $imgData['alt'],
                        $imgData['caption'],
                        $imgCameraId,
                        $imgLensId,
                        $imgFilmId,
                        $imgData['process'],
                        $sortOrder,
                    ]);
                $imgId = (int)$pdo->lastInsertId();
            }

            if ($coverId === null) {
                $coverId = $imgId;
            }
            $sortOrder++;
            $imageCount++;
        }

        // Set cover image
        if ($coverId) {
            $pdo->prepare("UPDATE albums SET cover_image_id = ? WHERE id = ?")->execute([$coverId, $albumId]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "   ✗ Error while seeding {$albumData['title']}: {$e->getMessage()}\n";
        throw $e;
    }
}

// ============================================
// SUMMARY
// ============================================
echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    SEEDING COMPLETE!                           ║\n";
echo "╠════════════════════════════════════════════════════════════════╣\n";
printf("║  Categories:  %-3d (including %d subcategories)                ║\n", count($categoryIds), count($subcategories));
printf("║  Tags:        %-3d                                            ║\n", count($tagIds));
printf("║  Locations:   %-3d                                            ║\n", count($locationIds));
printf("║  Cameras:     %-3d                                            ║\n", count($cameraIds));
printf("║  Lenses:      %-3d                                            ║\n", count($lensIds));
printf("║  Films:       %-3d                                            ║\n", count($filmIds));
printf("║  Developers:  %-3d                                            ║\n", count($developerIds));
printf("║  Labs:        %-3d                                            ║\n", count($labIds));
printf("║  Albums:      %-3d                                            ║\n", count($albumIds));
printf("║  Images:      %-3d                                            ║\n", $imageCount);
echo "╠════════════════════════════════════════════════════════════════╣\n";
echo "║  Password-protected albums:                                    ║\n";
echo "║    - intimate-portraits (password: demo123)                    ║\n";
echo "║    - berlin-shadows (password: berlin2024)                     ║\n";
echo "║    - london-underground (password: tube2024)                   ║\n";
echo "║    - private-collection (password: private456) [+NSFW]         ║\n";
echo "║    - exclusive-boudoir (password: exclusive789) [+NSFW]        ║\n";
echo "║                                                                ║\n";
echo "║  NSFW albums:                                                  ║\n";
echo "║    - body-studies                                              ║\n";
echo "║    - light-and-shadow                                          ║\n";
echo "║    - abstract-silhouettes                                      ║\n";
echo "║    - private-collection [+PASSWORD]                            ║\n";
echo "║    - exclusive-boudoir [+PASSWORD]                             ║\n";
echo "║                                                                ║\n";
echo "║  Draft albums:                                                 ║\n";
echo "║    - brutalist-barcelona                                       ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📷 Images downloaded from Unsplash (free stock photos)\n";
echo "📁 Originals stored in /storage/originals/ (like backend upload)\n";
echo "\n";

// ============================================
// POST-SEEDING: Generate variants and blur
// ============================================
echo "🔄 Generating image variants and blur previews...\n";
echo "\n";

if (!function_exists('exec')) {
    echo "✗ Error: exec() is disabled in php.ini. Image variants and blur generation require exec() to be enabled.\n";
    exit(1);
}

$consolePath = $root . '/bin/console';
if (!is_file($consolePath)) {
    echo "✗ Error: bin/console not found at {$consolePath}\n";
    exit(1);
}

// Run images:generate first
echo "   Running: php {$consolePath} images:generate\n";
$variantOutput = [];
$variantReturn = 0;
exec('php ' . escapeshellarg($consolePath) . ' images:generate 2>&1', $variantOutput, $variantReturn);
if ($variantReturn === 0) {
    echo "   ✓ Image variants generated successfully\n";
} else {
    echo "   ⚠ Image variant generation returned code {$variantReturn}\n";
    foreach ($variantOutput as $line) {
        echo "     {$line}\n";
    }
}

// Run nsfw:generate-blur for protected albums
echo "   Running: php {$consolePath} nsfw:generate-blur --all\n";
$blurOutput = [];
$blurReturn = 0;
exec('php ' . escapeshellarg($consolePath) . ' nsfw:generate-blur --all 2>&1', $blurOutput, $blurReturn);
if ($blurReturn === 0) {
    echo "   ✓ Blur variants generated for protected albums\n";
} else {
    echo "   ⚠ Blur generation returned code {$blurReturn}\n";
    foreach ($blurOutput as $line) {
        echo "     {$line}\n";
    }
}

// ============================================
// CLEANUP: Remove temp album seed files (keep category images)
// ============================================
$seedAlbumsDir = $root . '/public/media/seed/albums';
if (is_dir($seedAlbumsDir) && str_contains($seedAlbumsDir, '/public/media/seed/albums')) {
    echo "🗑️  Cleaning up temporary album seed files...\n";

    // Recursively delete the albums seed directory only
    // (category images in /public/media/seed/categories/ are kept as they're referenced in DB)
    $deleteSeedDir = function($dir) use (&$deleteSeedDir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $deleteSeedDir($path);
            } else {
                if (!@unlink($path)) {
                    echo "     ⚠ Could not delete file: {$path}\n";
                }
            }
        }
        @rmdir($dir);
    };

    $deleteSeedDir($seedAlbumsDir);
    echo "   ✓ Temporary album files removed (category images preserved)\n";
}

echo "\n";
$postSeedingSuccess = ($variantReturn === 0 && $blurReturn === 0);
if ($postSeedingSuccess) {
    echo "✅ Demo data seeding complete!\n";
    echo "\n";
    exit(0);
} else {
    echo "⚠️  Demo data seeding completed with warnings. Check post-seeding command output above.\n";
    echo "\n";
    // Exit with error code for CI/CD pipelines
    exit(1);
}
