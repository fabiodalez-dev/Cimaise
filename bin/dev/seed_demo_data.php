#!/usr/bin/env php
<?php
/**
 * Cimaise Demo Data Seeder
 *
 * Comprehensive script to populate the application with demo data including:
 * - Categories with images
 * - Tags
 * - Locations
 * - Equipment (cameras, lenses, films, developers, labs)
 * - Multiple albums with various settings (NSFW, password-protected, etc.)
 * - Images with metadata
 *
 * Usage: php bin/dev/seed_demo_data.php [--force]
 *
 * Options:
 *   --force    Skip confirmation prompt
 *
 * IMPORTANT: Place test images in public/media/seed/ before running.
 * Required image structure:
 *   public/media/seed/categories/   - Category cover images
 *   public/media/seed/albums/       - Album images (organized by album slug)
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
    $line = fgets($handle);
    if (trim(strtolower($line)) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
    fclose($handle);
}

$container = require __DIR__ . '/../../app/Config/bootstrap.php';
$db = $container['db'];
$pdo = $db->pdo();

$root = dirname(__DIR__, 2);
$mediaPath = $root . '/public/media/seed';

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
    $pdo->prepare("INSERT OR IGNORE INTO $table ($col1, $col2) VALUES (?, ?)")->execute([$id1, $id2]);
}

function getImageInfo(string $path): array
{
    if (!is_file($path)) {
        return ['width' => 1600, 'height' => 1067, 'mime' => 'image/jpeg'];
    }
    $info = @getimagesize($path);
    return [
        'width' => $info[0] ?? 1600,
        'height' => $info[1] ?? 1067,
        'mime' => $info['mime'] ?? 'image/jpeg',
    ];
}

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
    echo "   ✓ {$cat['name']}\n";
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
        'template_id' => 7,
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
];

$albumIds = [];
$imageCount = 0;

foreach ($albums as $albumData) {
    // Extract metadata for junction tables
    $albumCategories = $albumData['categories'] ?? [];
    $albumTags = $albumData['tags'] ?? [];
    $albumCameras = $albumData['cameras'] ?? [];
    $albumLenses = $albumData['lenses'] ?? [];
    $albumFilms = $albumData['films'] ?? [];
    $albumDevelopers = $albumData['developers'] ?? [];
    $albumLabs = $albumData['labs'] ?? [];
    $albumImages = $albumData['images'] ?? [];

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
    foreach ($albumImages as $imgData) {
        $filePath = '/media/seed/albums/' . $albumData['slug'] . '/' . $imgData['file'];
        $fullPath = $root . '/public' . $filePath;
        $imgInfo = getImageInfo($fullPath);
        $hash = is_file($fullPath) ? sha1_file($fullPath) : sha1($filePath . time());

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
            // Film key format: "Brand Name ISO Format"
            foreach ($filmIds as $key => $id) {
                if (strpos($key, $imgData['film']) !== false || $imgData['film'] === $key) {
                    $imgFilmId = $id;
                    break;
                }
            }
        }

        // Check if image already exists
        $stmt = $pdo->prepare("SELECT id FROM images WHERE album_id = ? AND original_path = ?");
        $stmt->execute([$albumId, $filePath]);
        $existingImgId = $stmt->fetchColumn();

        if ($existingImgId) {
            // Update existing image
            $pdo->prepare("UPDATE images SET alt_text = ?, caption = ?, camera_id = ?, lens_id = ?, film_id = ?, process = ?, width = ?, height = ?, mime = ?, sort_order = ? WHERE id = ?")
                ->execute([
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
            // Insert new image
            $pdo->prepare("INSERT INTO images (album_id, original_path, file_hash, width, height, mime, alt_text, caption, camera_id, lens_id, film_id, process, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $albumId,
                    $filePath,
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
echo "║    - private-collection (password: private456)                 ║\n";
echo "║                                                                ║\n";
echo "║  NSFW albums:                                                  ║\n";
echo "║    - body-studies                                              ║\n";
echo "║    - private-collection (also password-protected)              ║\n";
echo "║                                                                ║\n";
echo "║  Draft albums:                                                 ║\n";
echo "║    - brutalist-barcelona                                       ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📁 Place your test images in:\n";
echo "   public/media/seed/categories/   - Category cover images\n";
echo "   public/media/seed/albums/{slug}/ - Album images\n";
echo "\n";
echo "🚀 Run variant generation after adding images:\n";
echo "   php bin/console images:generate\n";
echo "\n";
