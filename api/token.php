<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    require_payment_user();
    if (!kcb_configured()) {
        payment_json(['error' => 'KCB Buni is not configured in api/config.php.'], 503);
    }
    payment_json(['access_token' => kcb_access_token()]);
} catch (Throwable $e) {
    payment_json(['error' => $e->getMessage()], 500);
}
