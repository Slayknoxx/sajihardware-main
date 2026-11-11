<?php
declare(strict_types=1);

if (!function_exists('env_value')) {
    function env_value(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value !== false && $value !== null && trim((string)$value) !== '') {
            return $value;
        }

        $envPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.env';
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

define('PAYMENT_DB_HOST', env_value('PAYMENT_DB_HOST', '127.0.0.1'));
define('PAYMENT_DB_NAME', env_value('PAYMENT_DB_NAME', 'saji_payments'));
define('PAYMENT_DB_USER', env_value('PAYMENT_DB_USER', 'root'));
define('PAYMENT_DB_PASS', env_value('PAYMENT_DB_PASS', ''));
define('PAYMENT_DB_CHARSET', env_value('PAYMENT_DB_CHARSET', 'utf8mb4'));

define('KCB_BUNI_ENABLED', env_bool('KCB_BUNI_ENABLED', false));
define('KCB_DEBUG', env_bool('KCB_DEBUG', false));
define('KCB_CONSUMER_KEY', env_value('KCB_CONSUMER_KEY', 'PASTE_YOUR_KCB_CONSUMER_KEY'));
define('KCB_CONSUMER_SECRET', env_value('KCB_CONSUMER_SECRET', 'PASTE_YOUR_KCB_CONSUMER_SECRET'));
define('KCB_BASE_URL', rtrim((string)env_value('KCB_BASE_URL', 'https://uat.buni.kcbgroup.com'), '/'));
define('KCB_TOKEN_URL', env_value('KCB_TOKEN_URL', KCB_BASE_URL . '/token?grant_type=client_credentials'));
define('KCB_STK_URL', env_value('KCB_STK_URL', KCB_BASE_URL . '/mm/api/request/1.0.0/stkpush'));
define('KCB_ORG_SHORT_CODE', env_value('KCB_ORG_SHORT_CODE', 'PASTE_KCB_ORG_SHORT_CODE'));
define('KCB_ORG_PASSKEY', env_value('KCB_ORG_PASSKEY', ''));
define('KCB_SHARED_SHORTCODE', env_bool('KCB_SHARED_SHORTCODE', env_bool('KCB_SHARED_SHORT_CODE', true)));
define('KCB_SHARED_SHORT_CODE', KCB_SHARED_SHORTCODE ? 'true' : 'false');
define('CALLBACK_SECRET', env_value('CALLBACK_SECRET', env_value('KCB_CALLBACK_SECRET', '')));
define('CALLBACK_URL', env_value('CALLBACK_URL', env_value('KCB_CALLBACK_URL', 'https://your-ngrok-url.example/api/callback.php')));
define('APP_SECRET', env_value('APP_SECRET', env_value('KCB_CALLBACK_TOKEN', CALLBACK_SECRET)));
define('KCB_CALLBACK_URL', CALLBACK_URL);
define('KCB_CALLBACK_SECRET', CALLBACK_SECRET);
define('KCB_CALLBACK_TOKEN', APP_SECRET);
define('PAYMENT_POLL_TIMEOUT_SECONDS', env_int('PAYMENT_POLL_TIMEOUT_SECONDS', 90));
define('KCB_TIMEOUT_SECONDS', env_int('KCB_TIMEOUT_SECONDS', 20));
