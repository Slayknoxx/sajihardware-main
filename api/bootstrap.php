<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';

security_session_cookie_params();
security_send_headers();

function payment_db(): PDO|SqliteDb
{
    static $db = null;
    if ($db instanceof PDO || $db instanceof SqliteDb) {
        return $db;
    }
    $db = db();
    return $db;
}

function payment_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function payment_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function require_payment_user(): array
{
    return require_login();
}

function require_callback_token(): void
{
    $token = (string)($_GET['token'] ?? '');
    if ($token === '') {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '';
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($segment) => $segment !== ''));
        if ($segments !== []) {
            $token = (string)end($segments);
        }
    }

    $expected = APP_SECRET !== '' ? APP_SECRET : (CALLBACK_SECRET !== '' ? CALLBACK_SECRET : KCB_CALLBACK_TOKEN);
    if ($expected === '' || str_starts_with($expected, 'CHANGE_') || $token === '' || !hash_equals($expected, $token)) {
        payment_json(['error' => 'Invalid callback token.'], 401);
    }
}

function kcb_configured(): bool
{
    foreach ([KCB_CONSUMER_KEY, KCB_CONSUMER_SECRET, KCB_ORG_SHORT_CODE, KCB_TOKEN_URL, KCB_STK_URL, CALLBACK_URL, APP_SECRET] as $value) {
        if ($value === '' || str_starts_with($value, 'PASTE_') || str_starts_with($value, 'https://YOUR_')) {
            return false;
        }
    }
    return KCB_BUNI_ENABLED === true;
}

function kcb_http(string $url, array $headers, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Enable PHP cURL in XAMPP before using KCB Buni.');
    }

    $caBundle = env_value('CURL_CAINFO', env_value('OPENSSL_CAFILE', 'C:/xamppnew3/php/extras/ssl/cacert.pem'));
    if (!is_file($caBundle)) {
        $caBundle = 'C:/xamppnew3/apache/bin/curl-ca-bundle.crt';
    }
    if (!is_file($caBundle)) {
        $caBundle = 'C:/xamppnew3/php/extras/ssl/cacert.pem';
    }
    if (is_file($caBundle)) {
        ini_set('curl.cainfo', $caBundle);
        ini_set('openssl.cafile', $caBundle);
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => $payload !== null,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => KCB_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => $caBundle,
    ]);
    if ($payload !== null) {
        $isForm = in_array('Content-Type: application/x-www-form-urlencoded', $headers, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $isForm ? http_build_query($payload) : json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $info = curl_getinfo($curl);
    curl_close($curl);

    if (defined('KCB_DEBUG') && KCB_DEBUG) {
        $debugMessage = "[KCB_DEBUG] URL=" . $url . "\n";
        $debugMessage .= "[KCB_DEBUG] STATUS=" . $status . "\n";
        $debugMessage .= "[KCB_DEBUG] ERROR=" . ($error !== '' ? $error : 'none') . "\n";
        $debugMessage .= "[KCB_DEBUG] HEADERS=" . json_encode($headers) . "\n";
        $debugMessage .= "[KCB_DEBUG] PAYLOAD=" . json_encode($payload) . "\n";
        $debugMessage .= "[KCB_DEBUG] BODY=" . substr((string)$body, 0, 1500) . "\n";
        error_log($debugMessage);
    }

    if ($body === false || $error !== '') {
        throw new RuntimeException('KCB request failed: ' . $error);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('KCB returned a non-JSON response. Raw body: ' . substr((string)$body, 0, 1000));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('KCB rejected the request with HTTP ' . $status . '. Raw body: ' . substr((string)$body, 0, 1000));
    }
    return $decoded;
}

function kcb_access_token(): string
{
    $basic = base64_encode(KCB_CONSUMER_KEY . ':' . KCB_CONSUMER_SECRET);
    $token = kcb_http(KCB_TOKEN_URL, [
        'Accept: application/json',
        'Authorization: Basic ' . $basic,
        'Content-Type: application/x-www-form-urlencoded',
    ], ['grant_type' => 'client_credentials']);
    $accessToken = (string)($token['access_token'] ?? $token['accessToken'] ?? $token['token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('KCB token response did not include an access token.');
    }
    return $accessToken;
}

function parse_kcb_callback(array $payload): array
{
    $stk = $payload['Body']['stkCallback'] ?? $payload['stkCallback'] ?? $payload;
    $items = $stk['CallbackMetadata']['Item'] ?? [];
    $meta = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if (isset($item['Name'])) {
                $meta[(string)$item['Name']] = $item['Value'] ?? '';
            }
        }
    }
    $resultCode = (string)($stk['ResultCode'] ?? $stk['resultCode'] ?? $payload['resultCode'] ?? $payload['responseCode'] ?? $payload['statusCode'] ?? '');
    $status = strtoupper((string)($payload['status'] ?? $payload['result'] ?? $stk['ResultDesc'] ?? $payload['responseDescription'] ?? ''));
    return [
        'reference' => (string)($payload['accountReference'] ?? $payload['reference'] ?? $payload['merchantReference'] ?? $payload['invoiceNumber'] ?? $payload['BillRefNumber'] ?? $meta['AccountReference'] ?? ''),
        'checkout_request_id' => (string)($stk['CheckoutRequestID'] ?? $stk['checkoutRequestId'] ?? $payload['checkoutRequestId'] ?? $payload['conversationID'] ?? $payload['conversationId'] ?? $payload['requestId'] ?? ''),
        'merchant_request_id' => (string)($stk['MerchantRequestID'] ?? $stk['merchantRequestId'] ?? $payload['merchantRequestId'] ?? $payload['originatorConversationID'] ?? $payload['originatorConversationId'] ?? ''),
        'mpesa_receipt' => (string)($meta['MpesaReceiptNumber'] ?? $payload['mpesaReceiptNumber'] ?? $payload['receiptNumber'] ?? $payload['transactionId'] ?? ''),
        'amount' => (float)($meta['Amount'] ?? $payload['amount'] ?? $payload['paidAmount'] ?? 0),
        'phone' => (string)($meta['PhoneNumber'] ?? $payload['phoneNumber'] ?? $payload['msisdn'] ?? ''),
        'result_code' => $resultCode,
        'result_description' => (string)($stk['ResultDesc'] ?? $payload['resultDescription'] ?? $payload['message'] ?? ''),
        'paid' => $resultCode === '0' && $status !== 'FAILED',
    ];
}

function restore_stock_for_failed_sale(int $saleId): void
{
    $items = db()->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id = ?');
    $items->execute([$saleId]);
    foreach ($items->fetchAll() as $item) {
        db()->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([(int)$item['quantity'], (int)$item['product_id']]);
    }
}
