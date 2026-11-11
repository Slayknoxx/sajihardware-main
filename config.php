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
define('COMPANY_NAME', 'SAJI HARDWARE LTD');
define('COMPANY_EMAIL', 'sajihardwareltd@gmail.com');
define('COMPANY_LOCATION', 'Kanduyi - Webuye Road');
define('VAT_RATE', 0.16);

define('BASE_PATH', __DIR__);
define('DATA_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'data');
define('DB_PATH', DATA_PATH . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3');
define('BACKUP_PATH', DATA_PATH . DIRECTORY_SEPARATOR . 'backups');
define('LOGO_WEB_PATH', 'assets/saji-logo.png');

define('ETIMS_ENABLED', env_bool('ETIMS_ENABLED', true));
define('KRA_PIN', env_value('KRA_PIN', 'YOUR_KRA_PIN_HERE'));
define('VSCU_SERIAL', env_value('VSCU_SERIAL', 'VSCU_SERIAL_NUMBER'));
define('VSCU_VERIFICATION_KEY', env_value('VSCU_VERIFICATION_KEY', 'your-long-random-verification-key'));
define('VSCU_DEVICE_ID', env_value('VSCU_DEVICE_ID', 'device-unique-id'));
define('ETIMS_SANDBOX', env_bool('ETIMS_SANDBOX', false));

define('ENABLE_RECEIPT_ENCRYPTION', true);
define('ENABLE_RECEIPT_SIGNING', true);
define('RECEIPT_SIGNATURE_ALGORITHM', 'sha256');

define('ETIMS_CONFIG', [
    'enabled' => ETIMS_ENABLED,
    'base_url' => env_value('ETIMS_BASE_URL', 'ADD_ETIMS_BASE_URL'),
    'pin' => KRA_PIN,
    'branch_id' => env_value('ETIMS_BRANCH_ID', '01'),
    'device_serial' => VSCU_SERIAL,
]);
