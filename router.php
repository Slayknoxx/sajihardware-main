<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Decode once and normalise separators, then reject traversal before any
// filesystem lookup. The built-in server is the supported run mode and does
// NOT apply the .htaccess deny rules, so this check is the real boundary.
$path = str_replace('\\', '/', rawurldecode($path));
if ($path === '' || $path[0] !== '/') {
    $path = '/' . $path;
}
if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

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
    // Only the browser-facing payment endpoints are web-reachable. Internal
    // files (config.php, bootstrap.php, schema.sql, ...) stay private.
    $allowedApiFiles = ['callback.php', 'payment_status.php', 'stk_push.php'];
    if (!in_array($relative, $allowedApiFiles, true)) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
    $target = __DIR__ . '/api/' . $relative;
    if (!is_file($target)) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
    require $target;
    return true;
}

// Static files may only come from public/, even if a path escapes it.
$publicRoot = realpath(__DIR__ . '/public');
$target = realpath(__DIR__ . '/public' . $path);
if ($publicRoot === false || $target === false || !is_file($target)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
$normalizedRoot = rtrim(strtolower($publicRoot), '\\/') . DIRECTORY_SEPARATOR;
if (!str_starts_with(strtolower($target), $normalizedRoot)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

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
