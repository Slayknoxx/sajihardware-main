<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

if (preg_match('#^/api/callback\.php(/.*)?$#', $path, $m)) {
    $_SERVER['PATH_INFO'] = $m[1] ?? '';
    require __DIR__ . '/api/callback.php';
    return true;
}

if ($path === '/') {
    require __DIR__ . '/public/index.php';
    return true;
}
if ($path === '/api.php') {
    require __DIR__ . '/public/api.php';
    return true;
}
if (str_starts_with($path, '/api/')) {
    $relative = substr($path, strlen('/api/'));
    if ($relative === '' || basename($relative) !== $relative || !str_ends_with($relative, '.php')) {
        http_response_code(404);
        return true;
    }
    $target = __DIR__ . '/api/' . $relative;
    if (!is_file($target)) {
        http_response_code(404);
        return true;
    }
    require $target;
    return true;
}

$target = __DIR__ . '/public' . $path;
if (is_file($target)) {
    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $contentType = $contentTypes[$extension] ?? 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (string)filesize($target));
    readfile($target);
    return true;
}
http_response_code(404);
echo 'Not found';
return true;
