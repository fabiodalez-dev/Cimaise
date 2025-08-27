#!/usr/bin/env php
<?php
declare(strict_types=1);

// Usage: php bin/normalize_templates.php <path-to-sqlite> [<path-to-sqlite-2> ...]

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/normalize_templates.php <db1.sqlite> [db2.sqlite ...]\n");
    exit(1);
}

function normalizeColumns(array $settings): array {
    $defaults = ['desktop' => 3, 'tablet' => 2, 'mobile' => 1];
    if (!isset($settings['columns']) || !is_array($settings['columns'])) {
        $settings['columns'] = $defaults;
        return $settings;
    }
    foreach (['desktop','tablet','mobile'] as $key) {
        $value = $settings['columns'][$key] ?? $defaults[$key];
        // Flatten nested same-key objects: {"desktop":{"desktop":3}}
        $guard = 0;
        while (is_array($value) && array_key_exists($key, $value) && $guard < 10) {
            $value = $value[$key];
            $guard++;
        }
        if (!is_numeric($value)) {
            $value = $defaults[$key];
        }
        $settings['columns'][$key] = (int)$value;
    }
    return $settings;
}

foreach (array_slice($argv, 1) as $dbPath) {
    if (!is_file($dbPath)) {
        fwrite(STDERR, "Skip: not found $dbPath\n");
        continue;
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    echo "Normalizing templates in $dbPath ...\n";
    $rows = $pdo->query('SELECT id, settings, libs FROM templates')->fetchAll();
    $updates = 0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $settings = json_decode($row['settings'] ?? '{}', true);
        if (!is_array($settings)) { $settings = []; }
        $before = $settings;
        $settings = normalizeColumns($settings);

        // Ensure masonry library flag coherence
        $libs = json_decode($row['libs'] ?? '[]', true);
        if (!is_array($libs)) { $libs = []; }
        $libs = array_values(array_unique(array_map('strval', $libs)));
        $hasMasonry = !empty($settings['masonry']);
        if ($hasMasonry && !in_array('masonry', $libs, true)) {
            $libs[] = 'masonry';
        }
        if (!$hasMasonry && in_array('masonry', $libs, true)) {
            // keep or remove? We remove to reflect settings
            $libs = array_values(array_filter($libs, fn($x) => $x !== 'masonry'));
        }

        $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES);
        $libsJson = json_encode($libs, JSON_UNESCAPED_SLASHES);

        if ($settingsJson !== ($row['settings'] ?? '') || $libsJson !== ($row['libs'] ?? '')) {
            $stmt = $pdo->prepare('UPDATE templates SET settings = :s, libs = :l WHERE id = :id');
            $stmt->execute([':s' => $settingsJson, ':l' => $libsJson, ':id' => $id]);
            $updates++;
        }
    }
    echo "Updated $updates row(s) in $dbPath\n";
}

echo "Done.\n";

