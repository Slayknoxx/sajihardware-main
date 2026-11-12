<?php
declare(strict_types=1);

/**
 * Licensing state test (item 2): trial, active, grace, expired, machine-bound,
 * tampered keys and the clock-rollback guard.
 *
 * Runs against an isolated temporary data directory via SAJI_DATA_PATH.
 *
 * Run: php -c .\php-runtime.ini tests\license_states.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_license_states_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';

$failures = 0;
$checks = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "PASS  {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function b64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function issue(array $payload): string
{
    $privateFile = __DIR__ . '/../tools/keys/private.key';
    $secret = base64_decode(trim((string)file_get_contents($privateFile)), true);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return b64url($json) . '.' . b64url(sodium_crypto_sign_detached($json, $secret));
}

function reset_license(string $key = ''): void
{
    settings_set('license_key', $key);
    settings_set('license_revoked', '0');
    settings_set('license_clock_tampered', '0');
    settings_set('license_last_seen_at', gmdate('c'));
}

$cleanup = static function () use ($tmp): void {
    db_close();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-wal');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-shm');
    @rmdir($tmp . DIRECTORY_SEPARATOR . 'logs');
    @rmdir($tmp);
};

try {
    init_db();

    if (!is_file(__DIR__ . '/../tools/keys/private.key')) {
        fwrite(STDERR, "SKIP: tools/keys/private.key not found, cannot issue test licenses\n");
        $cleanup();
        exit(0);
    }

    $base = [
        'v' => 1,
        'customer' => 'State Test Shop',
        'pin' => 'P000000000X',
        'plan' => 'standard',
        'edition' => 'test',
        'seats' => 1,
        'issued_at' => gmdate('c'),
        'expires_at' => null,
        'machine' => '',
        'license_id' => bin2hex(random_bytes(4)),
    ];

    // 1. Fresh trial.
    reset_license('');
    settings_set('trial_started_at', gmdate('c', time() - (2 * 86400)));
    $s = license_status();
    check('trial is valid', $s['valid'] === true && $s['state'] === 'trial', $s['state']);
    check('trial days_left is about 12', $s['days_left'] >= 11 && $s['days_left'] <= 12, (string)$s['days_left']);

    // 2. Expired trial.
    settings_set('trial_started_at', gmdate('c', time() - (20 * 86400)));
    $s = license_status();
    check('expired trial is blocked', $s['valid'] === false && $s['state'] === 'trial_expired', $s['state']);

    // 3. Active license.
    reset_license(issue(array_merge($base, ['expires_at' => gmdate('c', time() + (10 * 86400))])));
    $s = license_status();
    check('active license is valid', $s['valid'] === true && $s['state'] === 'active', $s['state']);
    check('active days_left is about 10', $s['days_left'] >= 9 && $s['days_left'] <= 10, (string)$s['days_left']);

    // 4. Grace period (expired 2 days ago, 7-day grace).
    reset_license(issue(array_merge($base, ['expires_at' => gmdate('c', time() - (2 * 86400))])));
    $s = license_status();
    check('expired license enters grace', $s['valid'] === true && $s['state'] === 'grace', $s['state']);
    check('grace days_left is about 5', $s['days_left'] >= 4 && $s['days_left'] <= 5, (string)$s['days_left']);

    // 5. Expired beyond grace.
    reset_license(issue(array_merge($base, ['expires_at' => gmdate('c', time() - (10 * 86400))])));
    $s = license_status();
    check('license past grace is blocked', $s['valid'] === false && $s['state'] === 'expired', $s['state']);

    // 6. Perpetual license.
    reset_license(issue($base));
    $s = license_status();
    check('perpetual license is active', $s['valid'] === true && $s['state'] === 'active' && $s['expires_at'] === null, $s['state']);

    // 7. Machine-bound license on the wrong machine.
    reset_license(issue(array_merge($base, ['machine' => 'deadbeefdeadbeef'])));
    $s = license_status();
    check('machine mismatch is rejected', $s['valid'] === false && $s['state'] === 'invalid', $s['state']);

    // 8. Tampered key.
    $validKey = issue($base);
    $tampered = substr($validKey, 0, -3) . 'AAA';
    reset_license($tampered);
    $s = license_status();
    check('tampered key is invalid', $s['valid'] === false && $s['state'] === 'invalid', $s['state']);
    $rejected = false;
    try {
        license_activate($tampered);
    } catch (Throwable $e) {
        $rejected = true;
    }
    check('license_activate rejects tampered key', $rejected);

    // 9. Clock-rollback guard.
    reset_license('');
    settings_set('license_clock_tampered', '0');
    settings_set('license_last_seen_at', gmdate('c', time() + (3 * 86400)));
    $s = license_status();
    check('clock rollback is detected', $s['valid'] === false && $s['state'] === 'clock_tampered' && $s['clock_tampered'] === true, $s['state']);
    check('clock flag is persisted', (string)settings_get('license_clock_tampered', '0') === '1');

    // 10. A valid license clears the clock flag.
    $s = license_activate($validKey);
    check('activating a valid license clears the clock flag', $s['valid'] === true && $s['state'] === 'active' && $s['clock_tampered'] === false, $s['state']);
    check('clock flag cleared in settings', (string)settings_get('license_clock_tampered', '0') === '0');

    // 11. A forward clock jump followed by a correction must not look like a rollback.
    reset_license(issue($base));
    $anchor = time();
    settings_set('license_last_seen_at', gmdate('c', $anchor - (10 * 86400)));
    license_status($anchor + (3 * 86400));
    $s = license_status($anchor);
    check('forward jump then correction is not a rollback', $s['state'] !== 'clock_tampered' && (string)settings_get('license_clock_tampered', '0') === '0', $s['state']);
} catch (Throwable $e) {
    check('license states completed without exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
