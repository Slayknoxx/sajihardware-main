<?php
declare(strict_types=1);

if (!function_exists('env_value')) {
    function env_value(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value !== false && $value !== null && trim((string)$value) !== '') {
            return $value;
        }

        $envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $envKey = trim($parts[0]);
                if ($envKey !== $key) {
                    continue;
                }
                $envValue = trim($parts[1]);
                if (($envValue[0] ?? '') === '"' && str_ends_with($envValue, '"')) {
                    $envValue = substr($envValue, 1, -1);
                }
                if (($envValue[0] ?? '') === "'" && str_ends_with($envValue, "'")) {
                    $envValue = substr($envValue, 1, -1);
                }
                // A blank entry (for example "LICENSE_PUBLIC_KEY=") must not
                // override the built-in default; treat it as unset.
                if (trim($envValue) === '') {
                    continue;
                }
                return $envValue;
            }
        }

        return $default;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env_value($key, $default ? 'true' : 'false');
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default = 0): int
    {
        $value = env_value($key, $default);
        return is_numeric((string)$value) ? (int)$value : $default;
    }
}

define('APP_NAME', 'SAJI HARDWARE POS');
define('APP_VERSION', '1.1.0');
define('COMPANY_NAME', 'SAJI HARDWARE LTD');
define('COMPANY_EMAIL', 'sajihardwareltd@gmail.com');
define('COMPANY_LOCATION', 'Kanduyi - Webuye Road');
define('VAT_RATE', 0.16);

define('BASE_PATH', __DIR__);
// These overrides exist so tests can run against an isolated copy of the data.
define('DATA_PATH', (string)env_value('SAJI_DATA_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'data'));
define('DB_PATH', DATA_PATH . DIRECTORY_SEPARATOR . (string)env_value('SAJI_DB_FILE', 'saji_pos.sqlite3'));
define('BACKUP_PATH', (string)env_value('SAJI_BACKUP_PATH', DATA_PATH . DIRECTORY_SEPARATOR . 'backups'));
define('LOGO_WEB_PATH', 'assets/saji-logo.png');

define('ETIMS_ENABLED', env_bool('ETIMS_ENABLED', false));
define('KRA_PIN', env_value('KRA_PIN', 'YOUR_KRA_PIN_HERE'));
define('VSCU_SERIAL', env_value('VSCU_SERIAL', 'VSCU_SERIAL_NUMBER'));
define('VSCU_VERIFICATION_KEY', env_value('VSCU_VERIFICATION_KEY', 'your-long-random-verification-key'));
define('VSCU_DEVICE_ID', env_value('VSCU_DEVICE_ID', 'device-unique-id'));
define('ETIMS_SANDBOX', env_bool('ETIMS_SANDBOX', false));

define('ENABLE_RECEIPT_ENCRYPTION', true);
define('ENABLE_RECEIPT_SIGNING', true);
define('RECEIPT_SIGNATURE_ALGORITHM', 'sha256');

// Licensing. Leave LICENSE_PUBLIC_KEY empty to run in unlicensed/trial mode only.
// Populate it from tools/make_license.php keygen (the base64 public key, never the private one).
define('LICENSE_PUBLIC_KEY', env_value('LICENSE_PUBLIC_KEY', 'I34vXob5ZwBGtG2DNea9dZAZRraAenO8FusuVJvZrM0='));
define('LICENSE_TRIAL_DAYS', env_int('LICENSE_TRIAL_DAYS', 14));
define('LICENSE_GRACE_DAYS', env_int('LICENSE_GRACE_DAYS', 7));
// Optional hybrid check-in server. When empty the app works fully offline.
define('LICENSE_SERVER_URL', env_value('LICENSE_SERVER_URL', ''));
define('LICENSE_CHECKIN_HOURS', env_int('LICENSE_CHECKIN_HOURS', 24));

define('ETIMS_CONFIG', [
    'enabled' => ETIMS_ENABLED,
    'base_url' => env_value('ETIMS_BASE_URL', 'ADD_ETIMS_BASE_URL'),
    'pin' => KRA_PIN,
    'branch_id' => env_value('ETIMS_BRANCH_ID', '01'),
    'device_serial' => VSCU_SERIAL,
]);

function config_is_placeholder_value(mixed $value): bool
{
    if (!is_scalar($value) && $value !== null) {
        return false;
    }
    $text = trim((string)$value);
    if ($text === '') {
        return false;
    }

    $upper = strtoupper($text);
    foreach (['YOUR_', 'PASTE_', 'ADD_', 'CHANGE_', 'HTTPS://YOUR-', 'YOUR-NGROK-URL.EXAMPLE'] as $needle) {
        if (str_contains($upper, $needle)) {
            return true;
        }
    }

    return false;
}

function validate_runtime_configuration(): void
{
    if (ETIMS_ENABLED) {
        foreach (['KRA_PIN', 'VSCU_SERIAL', 'VSCU_VERIFICATION_KEY', 'VSCU_DEVICE_ID'] as $setting) {
            if (config_is_placeholder_value(constant($setting))) {
                throw new RuntimeException("ETIMS is enabled but {$setting} is still set to a placeholder value. Update the settings in .env or config.php.");
            }
        }

        if (config_is_placeholder_value(ETIMS_CONFIG['base_url'])) {
            throw new RuntimeException('ETIMS is enabled but ETIMS_BASE_URL is unset or still points to a placeholder value.');
        }
    }
}

validate_runtime_configuration();
