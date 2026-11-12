<?php
declare(strict_types=1);

/**
 * Security helpers shared by the JSON API and payment endpoints.
 */

function security_session_cookie_params(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 1800,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function security_send_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://api.qrserver.com; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}

function security_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function security_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

/**
 * Reject mutating requests that do not carry the session's CSRF token.
 */
function security_require_csrf(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return;
    }
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    $supplied = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expected === '' || $supplied === '' || !hash_equals($expected, $supplied)) {
        app_log('CSRF validation failed for ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . ' from ' . security_client_ip());
        json_response([
            'error' => 'Your session security token expired. Reload the page and try again.',
            'code' => 'CSRF',
        ], 403);
    }
}

/**
 * Lightweight origin check for the login request (no CSRF token exists yet).
 */
function security_check_origin(): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $originHost = (string)(parse_url($origin, PHP_URL_HOST) ?? '');
    $requestHost = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($originHost === '' || !hash_equals($requestHost, $originHost)) {
        app_log('Cross-origin login rejected from ' . $origin);
        json_response(['error' => 'Cross-origin login is not allowed', 'code' => 'CSRF'], 403);
    }
}

/**
 * Seconds the caller must wait before trying to log in again (0 = allowed).
 */
function security_login_lock_seconds(string $username, string $ip): int
{
    $stmt = db()->prepare("SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_attempt
        FROM login_attempts
        WHERE username = ? AND ip = ? AND success = 0
          AND attempted_at >= datetime('now', '-15 minutes')");
    $stmt->execute([$username, $ip]);
    $row = $stmt->fetch();
    $failures = (int)($row['failures'] ?? 0);
    if ($failures < 5) {
        return 0;
    }
    $last = (string)($row['last_attempt'] ?? '');
    $lastTs = $last !== '' ? (int)strtotime($last . ' UTC') : 0;
    if ($lastTs <= 0) {
        return 0;
    }
    return max(0, 300 - (time() - $lastTs));
}

function security_record_login(string $username, string $ip, bool $success): void
{
    db()->prepare('INSERT INTO login_attempts(username, ip, success) VALUES(?,?,?)')
        ->execute([$username, $ip, $success ? 1 : 0]);

    if ($success) {
        db()->prepare('DELETE FROM login_attempts WHERE username = ? AND ip = ? AND success = 0')
            ->execute([$username, $ip]);
    }

    // Occasional housekeeping; cheap and bounded.
    if (random_int(1, 100) === 1) {
        db()->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-30 days')");
    }
}
