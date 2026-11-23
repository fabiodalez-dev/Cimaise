<?php

declare(strict_types=1);

/*
 * E2E fixture: drive AlbumLayoutService for album-layout.spec.js until the
 * admin save endpoints exist. Runs against the same database as the dev
 * server (bootstrap reads .env).
 *
 * Usage: php tests/e2e/fixtures/album-layout.php <album_id> <draft|publish|default|clear>
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

$albumId = (int) ($argv[1] ?? 0);
$action = (string) ($argv[2] ?? '');
if ($albumId < 1 || !in_array($action, ['draft', 'publish', 'default', 'clear'], true)) {
    fwrite(STDERR, "usage: album-layout.php <album_id> <draft|publish|default|clear>\n");
    exit(2);
}

$ids = array_map('intval', $db->query(
    'SELECT id FROM images WHERE album_id = ? ORDER BY sort_order ASC, id ASC',
    [$albumId]
)->fetchAll(\PDO::FETCH_COLUMN));
if (count($ids) < 4) {
    fwrite(STDERR, "album {$albumId} needs at least 4 images\n");
    exit(3);
}

$service = new AlbumLayoutService($db);
$revision = $service->state($albumId)['revision'];

if ($action === 'clear') {
    if ($revision > 0) {
        $service->save($albumId, $revision, null, 'default');
    }
    $db->execute('DELETE FROM album_layouts WHERE album_id = ?', [$albumId]);
    echo json_encode(['revision' => 0, 'order' => $ids]), "\n";
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
