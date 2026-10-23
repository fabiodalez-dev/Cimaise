<?php
// Disposable test containers only. Simulate compiled templates from an older release.
declare(strict_types=1);
$root = '/var/www/html';
$files = ['version.json', 'app/Views/admin/_layout.twig', 'app/Views/admin/albums/edit.twig'];
foreach ($files as $index => $file) {
    $path = $root . '/' . $file;
    $backup = $root . '/storage/tmp/template-backup-' . $index;
    if (($argv[1] ?? '') === 'restore') {
        copy($backup, $path);
        unlink($backup);
        continue;
    }
    copy($path, $backup);
    $source = file_get_contents($path);
    $source = match ($index) {
        0 => preg_replace('/"version":\s*"[^"]+"/', '"version": "legacy-cache-test"', $source),
        1 => str_replace("{{ trans('admin.sidebar.dashboard_sub') }}", 'OLD-ITALIAN-SIDEBAR', $source),
        2 => str_replace('id="show_equipment"', 'id="old-equipment-field"', $source),
    };
    file_put_contents($path, $source);
}
