<?php
declare(strict_types=1);

/**
 * Verify that a license key issued by tools/make_license.php can be decoded.
 * Usage:
 *   set LICENSE_PUBLIC_KEY=<base64 public key>
 *   php -c .\php-runtime.ini tests\verify_license.php <file-with-issued-output>
 */

$file = $argv[1] ?? '';
if (!is_file($file)) {
    fwrite(STDERR, "Pass a file containing the output of make_license.php issue\n");
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
    fwrite(STDERR, "No license key found in {$file}\n");
    exit(3);
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/license.php';

$payload = license_decode($key);
if ($payload === null) {
    fwrite(STDERR, "DECODE_FAIL\n");
    exit(1);
}
echo "DECODE_OK\n";
echo 'customer=' . ($payload['customer'] ?? '') . "\n";
echo 'plan=' . ($payload['plan'] ?? '') . "\n";
echo 'expires=' . ($payload['expires_at'] ?? 'perpetual') . "\n";
echo 'machine=' . (($payload['machine'] ?? '') !== '' ? $payload['machine'] : '(unbound)') . "\n";
exit(0);
