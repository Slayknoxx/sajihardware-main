<?php
declare(strict_types=1);

/**
 * Exercise license activation against the live settings table, then restore
 * the previous license value so the test is non-destructive.
 */

$file = $argv[1] ?? '';
if (!is_file($file)) {
    fwrite(STDERR, "Pass the issued-license output file\n");
    exit(2);
}
$contents = (string)file_get_contents($file);
$key = '';
foreach (preg_split('/\R/', $contents) as $line) {
    $line = trim($line);
    if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $line)) {
        $key = $line;
        break;
    }
}
if ($key === '') {
    fwrite(STDERR, "No license key found\n");
    exit(3);
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';
init_db();

$previous = (string)(settings_get('license_key', '') ?? '');
try {
    $status = license_activate($key);
    $ok = $status['valid'] === true && $status['state'] === 'active' && $status['customer'] === 'Test Hardware';
    echo $ok ? "ACTIVATE_OK\n" : "ACTIVATE_FAIL " . json_encode($status) . "\n";
    echo 'state=' . $status['state'] . ' plan=' . $status['plan'] . ' customer=' . $status['customer'] . "\n";

    // A tampered key must be rejected.
    $tampered = substr($key, 0, -4) . 'AAAA';
    $rejected = false;
    try {
        license_activate($tampered);
    } catch (Throwable $e) {
        $rejected = true;
    }
    echo $rejected ? "TAMPER_REJECTED\n" : "TAMPER_ACCEPTED\n";
} finally {
    // Restore the original state.
    if ($previous === '') {
        settings_set('license_key', '');
    } else {
        settings_set('license_key', $previous);
    }
    settings_set('license_revoked', '0');
}

echo "RESTORED\n";
exit(0);
