<?php
// Router for PHP built-in server: routes non-existent files to index.php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// SECURITY: Route /media/* through PHP for access control (NSFW/password protection)
if (str_starts_with($uri, '/media/')) {
    require __DIR__ . '/index.php';
    return;
}

// Dynamic typography CSS (must go through PHP)
if ($uri === '/fonts/typography.css') {
    require __DIR__ . '/index.php';
    return;
}

// SECURITY: confine static serving to the public/ document root. urldecode()
// above means a request like /..%2f.env decodes to a traversal sequence; without
// this realpath check the built-in dev server would happily serve files (e.g.
// .env) outside public/. realpath() resolves ../ and symlinks before comparison.
$docRoot = realpath(__DIR__);
$real = realpath($file);
if ($uri !== '/' && $real !== false && $docRoot !== false
    && str_starts_with($real, $docRoot . DIRECTORY_SEPARATOR)
    && is_file($real)) {
    return false; // serve the requested resource as-is
}
require __DIR__ . '/index.php';

