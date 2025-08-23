#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$outDir = $root . '/public/media/test';
@mkdir($outDir, 0775, true);

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD not available. Cannot generate placeholders.\n");
    exit(1);
}

function draw_image(string $path, int $w, int $h, string $label): void {
    $im = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($im, 245, 245, 245);
    $fg = imagecolorallocate($im, 17, 17, 17);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    // border
    imagerectangle($im, 0, 0, $w-1, $h-1, $fg);
    // label centered
    $font = 5; // built-in font
    $tw = imagefontwidth($font) * strlen($label);
    $th = imagefontheight($font);
    imagestring($im, $font, (int)(($w - $tw)/2), (int)(($h - $th)/2), $label, $fg);
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}

$defs = [
    ['street-001.jpg', 1600, 1067, 'Street 001'],
    ['street-002.jpg', 1600, 1067, 'Street 002'],
    ['street-003.jpg', 1600, 1067, 'Street 003'],
    ['street-004.jpg', 1600, 1067, 'Street 004'],
    ['street-005.jpg', 1600, 1067, 'Street 005'],
    ['street-006.jpg', 1600, 1067, 'Street 006'],
];

foreach ($defs as [$name, $w, $h, $label]) {
    $dest = $outDir . '/' . $name;
    draw_image($dest, $w, $h, $label);
    echo "Generated: /public/media/test/$name\n";
}

echo "Done.\n";

