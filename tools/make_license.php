<?php
declare(strict_types=1);

/**
 * Offline license issuer for SAJI Hardware POS.
 *
 * Keep tools/keys/private.key secret. Only the public key is embedded in the app.
 *
 * Usage (from the project root):
 *   php -c .\php-runtime.ini tools\make_license.php keygen
 *   php -c .\php-runtime.ini tools\make_license.php issue --customer="Shop Name" --pin=P051234567X --plan=standard --days=365
 *   php -c .\php-runtime.ini tools\make_license.php issue --customer="Shop Name" --plan=perpetual --machine=<machine-id>
 *
 * Options for issue:
 *   --customer=   Customer / business name (required)
 *   --pin=        KRA PIN (optional)
 *   --plan=       trial|standard|pro|perpetual (default standard)
 *   --edition=    Free text edition label (optional)
 *   --seats=      Number of tills/users (default 1)
 *   --days=       Days from today (omit for perpetual)
 *   --machine=    Bind to a specific machine ID (optional)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "The sodium extension is required. Enable extension=sodium in php-runtime.ini.\n");
    exit(2);
}

$root = dirname(__DIR__);
$keyDir = __DIR__ . DIRECTORY_SEPARATOR . 'keys';
$privateKeyFile = $keyDir . DIRECTORY_SEPARATOR . 'private.key';
$publicKeyFile = $keyDir . DIRECTORY_SEPARATOR . 'public.key';

function license_cli_parse(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = explode('=', substr($arg, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '1';
    }
    return $options;
}

function license_cli_b64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

$command = $argv[1] ?? 'help';

if ($command === 'keygen') {
    if (is_file($privateKeyFile)) {
        fwrite(STDERR, "A key pair already exists at tools/keys. Delete it first if you really want to rotate keys.\n");
        exit(3);
    }
    if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
        fwrite(STDERR, "Could not create tools/keys.\n");
        exit(4);
    }
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    file_put_contents($privateKeyFile, base64_encode($secret));
    file_put_contents($publicKeyFile, base64_encode($public));
    @chmod($privateKeyFile, 0600);
    echo "Key pair created.\n\n";
    echo "Add this to .env (and never commit the private key):\n\n";
    echo "LICENSE_PUBLIC_KEY=" . base64_encode($public) . "\n\n";
    echo "Private key: " . $privateKeyFile . "\n";
    echo "Public key:  " . $publicKeyFile . "\n";
    exit(0);
}

if ($command === 'issue') {
    if (!is_file($privateKeyFile)) {
        fwrite(STDERR, "No private key found. Run: php tools/make_license.php keygen\n");
        exit(3);
    }
    $options = license_cli_parse($argv);
    $customer = trim((string)($options['customer'] ?? ''));
    if ($customer === '') {
        fwrite(STDERR, "Missing --customer=\"Business name\".\n");
        exit(4);
    }

    $now = time();
    $payload = [
        'v' => 1,
        'customer' => $customer,
        'pin' => trim((string)($options['pin'] ?? '')),
        'plan' => trim((string)($options['plan'] ?? 'standard')),
        'edition' => trim((string)($options['edition'] ?? '')),
        'seats' => (int)($options['seats'] ?? 1),
        'issued_at' => gmdate('c', $now),
        'expires_at' => null,
        'machine' => trim((string)($options['machine'] ?? '')),
        'license_id' => bin2hex(random_bytes(8)),
    ];
    if (isset($options['days']) && $options['days'] !== '') {
        $payload['expires_at'] = gmdate('c', $now + ((int)$options['days'] * 86400));
    }

    $secret = base64_decode(trim((string)file_get_contents($privateKeyFile)), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "The private key file is invalid.\n");
        exit(5);
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = sodium_crypto_sign_detached($json, $secret);
    $key = license_cli_b64url($json) . '.' . license_cli_b64url($signature);

    echo "License issued for: " . $customer . "\n";
    echo "Plan: " . $payload['plan'] . "\n";
    echo "Expires: " . ($payload['expires_at'] ?? 'perpetual') . "\n";
    echo "\n--- LICENSE KEY (send to customer) ---\n";
    echo $key . "\n";
    echo "--- END LICENSE KEY ---\n";
    exit(0);
}

echo "SAJI Hardware POS license tool\n\n";
echo "Commands:\n";
echo "  php tools/make_license.php keygen\n";
echo "  php tools/make_license.php issue --customer=\"Shop\" [--pin=PIN] [--plan=standard] [--days=365] [--machine=ID]\n";
exit(0);
