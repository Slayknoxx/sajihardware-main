<?php
/**
 * router.php
 * Only needed when running with PHP's built-in server:
 *   php -S localhost:8000 router.php
 *
 * It lets a URL like /api/callback.php/SECRET correctly map to
 * api/callback.php with PATH_INFO = /SECRET, and serves everything
 * else (html/css/js) as normal static files.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Handle /api/callback.php/<secret>
if (preg_match('#^/api/callback\.php(/.*)?$#', $uri, $m)) {
    $_SERVER['PATH_INFO'] = $m[1] ?? '';
    require __DIR__ . '/api/callback.php';
    return true;
}

// Serve existing static files directly
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false; // let the built-in server handle it
}

// Fallback to index.html for the root
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.html';
    return true;
}

return false;
