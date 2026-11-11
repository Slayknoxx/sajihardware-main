<?php
/**
 * config.php
 * Loads environment variables from .env (never hard-code secrets here).
 */

declare(strict_types=1);

function load_env(string $path): void
{
    if (!file_exists($path)) {
        http_response_code(500);
        die("Missing .env file. Copy .env.example to .env and fill in your KCB credentials.");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // strip surrounding quotes if present
        $value = trim($value, "\"'");
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

load_env(__DIR__ . '/.env');

function env(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return $val;
}

// ---- KCB Buni settings ----
define('KCB_CONSUMER_KEY', env('KCB_CONSUMER_KEY', ''));
define('KCB_CONSUMER_SECRET', env('KCB_CONSUMER_SECRET', ''));
define('KCB_BASE_URL', rtrim(env('KCB_BASE_URL', 'https://uat.buni.kcbgroup.com'), '/'));
define('KCB_ORG_SHORT_CODE', env('KCB_ORG_SHORT_CODE', ''));
define('KCB_ORG_PASSKEY', env('KCB_ORG_PASSKEY', ''));
define('KCB_SHARED_SHORTCODE', strtolower((string) env('KCB_SHARED_SHORTCODE', 'true')) === 'true');

// ---- Security settings ----
define('CALLBACK_SECRET', env('CALLBACK_SECRET', ''));
define('CALLBACK_URL', env('CALLBACK_URL', ''));
define('APP_SECRET', env('APP_SECRET', ''));
define('ALLOWED_ORIGIN', env('ALLOWED_ORIGIN', ''));

if (KCB_CONSUMER_KEY === '' || KCB_CONSUMER_SECRET === '') {
    // Do not crash the whole app for the static HTML, but API endpoints will
    // check this again and refuse to run without real credentials.
}

// ---- Basic security headers for every request ----
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer-when-downgrade');

// CORS: only allow same-origin by default, or a configured origin
if (ALLOWED_ORIGIN !== '') {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-App-Token');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
