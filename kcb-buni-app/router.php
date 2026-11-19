<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = str_replace('\\', '/', rawurldecode($path));
if ($path === '' || $path[0] !== '/') {
    $path = '/' . $path;
}
if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if (preg_match('#^/api/callback\.php(/.*)?$#', $path, $match)) {
    $_SERVER['PATH_INFO'] = $match[1] ?? '';
    require __DIR__ . '/api/callback.php';
    return true;
}

if (in_array($path, ['/api/stk_push.php', '/api/status.php'], true)) {
    require __DIR__ . substr($path, 1);
    return true;
}

if ($path === '/' || $path === '/index.html') {
    return false;
}

if (str_starts_with($path, '/assets/')) {
    $assetsRoot = realpath(__DIR__ . '/assets');
    $target = realpath(__DIR__ . $path);
    if ($assetsRoot !== false && $target !== false && is_file($target)) {
        $normalizedRoot = rtrim(strtolower($assetsRoot), '\\/') . DIRECTORY_SEPARATOR;
        if (str_starts_with(strtolower($target), $normalizedRoot)) {
            return false;
        }
    }
}

http_response_code(404);
echo 'Not found';
return true;
