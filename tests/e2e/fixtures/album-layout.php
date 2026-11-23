<?php

declare(strict_types=1);

/*
 * E2E fixture: drive AlbumLayoutService for album-layout.spec.js until the
 * admin save endpoints exist. Runs against the same database as the dev
 * server (bootstrap reads .env).
 *
 * Usage: php tests/e2e/fixtures/album-layout.php <album_id> <draft|publish|default|clear|empty|text-only>
 *          (empty: publish a layout that renders nothing; text-only: publish
 *          a layout with a single text block and no photos)
 *        php tests/e2e/fixtures/album-layout.php setting <key> <json value>
 *          (prints {"previous": <decoded value or null>}; used to pin a
 *          setting the spec depends on and restore it afterwards)
 *
 * Run it with `php -d apc.enabled=0` (the spec does): QueryCache then uses the
 * same file backend as the web server, so settings changes invalidate the
 * cache the server actually reads.
 *
 * The layout deliberately differs from the default gallery order: the LAST
 * photo opens the page, then a text block, then a 2-column grid with the
 * first two photos, then the remaining photos in a 2-column grid.
 * Prints JSON: {"revision": int, "order": [image ids in display order]}.
 */

use App\Services\AlbumLayoutService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$container = require $root . '/app/Config/bootstrap.php';
/** @var \App\Support\Database $db */
$db = $container['db'];

if (($argv[1] ?? '') === 'setting') {
    $key = (string) ($argv[2] ?? '');
    if ($key === '' || !isset($argv[3])) {
        fwrite(STDERR, "usage: album-layout.php setting <key> <json value>\n");
        exit(2);
    }
    $settings = new \App\Services\SettingsService($db);
    $previous = $settings->get($key);
    $settings->set($key, json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR));
    echo json_encode(['previous' => $previous]), "\n";
    exit(0);
}

$albumId = (int) ($argv[1] ?? 0);
$action = (string) ($argv[2] ?? '');
if ($albumId < 1 || !in_array($action, ['draft', 'publish', 'default', 'clear', 'empty', 'text-only'], true)) {
    fwrite(STDERR, "usage: album-layout.php <album_id> <draft|publish|default|clear|empty|text-only>\n");
    exit(2);
}

$ids = array_map('intval', $db->query(
    'SELECT id FROM images WHERE album_id = ? ORDER BY sort_order ASC, id ASC',
    [$albumId]
)->fetchAll(\PDO::FETCH_COLUMN));
$service = new AlbumLayoutService($db);
$revision = $service->state($albumId)['revision'];

// Teardown must work whatever the setup managed to upload.
if ($action === 'clear') {
    if ($revision > 0) {
        $service->save($albumId, $revision, null, 'default');
    }
    $db->execute('DELETE FROM album_layouts WHERE album_id = ?', [$albumId]);
    echo json_encode(['revision' => 0, 'order' => $ids]), "\n";
    exit(0);
}

if (count($ids) < 4) {
    fwrite(STDERR, "album {$albumId} needs at least 4 images\n");
    exit(3);
}

if ($action === 'empty' || $action === 'text-only') {
    $blocks = $action === 'text-only'
        ? [['id' => 'only', 'type' => 'text', 'text' => 'LAYOUT-E2E-TEXT-ONLY']]
        : [];
    $newRevision = $service->save($albumId, $revision, ['include_remaining' => false, 'blocks' => $blocks], 'publish');
    echo json_encode(['revision' => $newRevision, 'order' => []]), "\n";
    exit(0);
}

$last = $ids[count($ids) - 1];
$layout = [
    'include_remaining' => true,
    'remaining_columns' => 2,
    'remaining_gap' => 12,
    'blocks' => [
        ['id' => 'lead', 'type' => 'photo', 'images' => [$last]],
        ['id' => 'copy', 'type' => 'text', 'text' => 'LAYOUT-E2E-TEXT <b>not html</b>', 'max_width' => 640],
        ['id' => 'pair', 'type' => 'grid', 'images' => [$ids[0], $ids[1]], 'columns' => 2, 'gap' => 16],
    ],
];
$order = array_merge([$last, $ids[0], $ids[1]], array_slice($ids, 2, count($ids) - 3));

$newRevision = $service->save($albumId, $revision, $layout, $action);
echo json_encode(['revision' => $newRevision, 'order' => $order]), "\n";
