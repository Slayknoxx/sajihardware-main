<?php
/**
 * api/stk_push.php
 * Called by the frontend (script.js) with { phone, amount } via POST JSON.
 * Validates input, asks KCB to send an STK push, and stores a PENDING
 * transaction row keyed by CheckoutRequestID so the frontend can poll it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../kcb_client.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ---- very simple rate limiting per IP (5 requests / minute) ----
session_start();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();
$_SESSION['rl'] = $_SESSION['rl'] ?? [];
$_SESSION['rl'] = array_filter($_SESSION['rl'], fn($t) => $t > $now - 60);
if (count($_SESSION['rl']) >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait a moment and try again.']);
    exit;
}
$_SESSION['rl'][] = $now;

// ---- read + validate input ----
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$phoneRaw = trim((string) ($body['phone'] ?? ''));
$amountRaw = trim((string) ($body['amount'] ?? ''));

// Normalise phone to 2547XXXXXXXX / 2541XXXXXXXX format
$phone = preg_replace('/\D/', '', $phoneRaw);
if (str_starts_with($phone, '0') && strlen($phone) === 10) {
    $phone = '254' . substr($phone, 1);
} elseif (str_starts_with($phone, '7') && strlen($phone) === 9) {
    $phone = '254' . $phone;
} elseif (str_starts_with($phone, '1') && strlen($phone) === 9) {
    $phone = '254' . $phone;
}

$validPhone = (bool) preg_match('/^254(7|1)\d{8}$/', $phone);
if (!$validPhone) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid Safaricom/KCB phone number, e.g. 0712345678']);
    exit;
}

if (!is_numeric($amountRaw)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Amount must be a number']);
    exit;
}

$amount = (float) $amountRaw;
if ($amount < 1 || $amount > 150000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Amount must be between 1 and 150,000']);
    exit;
}
$amountStr = (string) intval($amount); // KCB expects whole-shilling amounts as a string

// Unique invoice number for our own tracking
$invoice = 'INV' . date('YmdHis') . random_int(100, 999);

try {
    $result = kcb_stk_push($phone, $amountStr, $invoice, 'Payment via app');

    // Normalize the nested, top-level, and JSON-string response shapes used by
    // different KCB environments before reading the result fields.
    $flattenResponse = function (mixed $value) use (&$flattenResponse): array {
        if (is_string($value)) {
            $decodedValue = json_decode($value, true);
            return is_array($decodedValue) ? $flattenResponse($decodedValue) : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $flat = $value;
        foreach ($value as $nestedValue) {
            if (is_array($nestedValue) || is_string($nestedValue)) {
                $flat = array_replace($flat, $flattenResponse($nestedValue));
            }
        }
        return $flat;
    };
    $responseBlock = $flattenResponse($result);
    $responseCode = (string) ($responseBlock['ResponseCode']
        ?? $responseBlock['responseCode']
        ?? $responseBlock['response_code']
        ?? $responseBlock['statusCode']
        ?? $responseBlock['status']
        ?? '1');
    $checkoutRequestId = $responseBlock['CheckoutRequestID']
        ?? $responseBlock['checkoutRequestId']
        ?? $responseBlock['checkoutRequestID']
        ?? null;
    $merchantRequestId = $responseBlock['MerchantRequestID']
        ?? $responseBlock['merchantRequestId']
        ?? $responseBlock['merchantRequestID']
        ?? null;

    if ($responseCode !== '0' || !$checkoutRequestId) {
        $desc = $responseBlock['ResponseDescription']
            ?? $responseBlock['responseDescription']
            ?? $responseBlock['statusDescription']
            ?? $responseBlock['responseMessage']
            ?? $responseBlock['responseMsg']
            ?? $responseBlock['response_message']
            ?? $responseBlock['message']
            ?? $responseBlock['error']
            ?? $responseBlock['description']
            ?? $responseBlock['resultDesc']
            ?? (is_string($result['response'] ?? null) ? $result['response'] : null)
            ?? (is_string($result['header'] ?? null) ? $result['header'] : null)
            ?? $result['message']
            ?? ('KCB returned an unrecognized STK response. Fields: '
                . implode(', ', array_keys($result)));
        error_log('KCB STK response fields: ' . implode(', ', array_keys($result)));
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "KCB rejected the STK push (code $responseCode): $desc",
        ]);
        exit;
    }

    create_transaction($merchantRequestId ?? '', $checkoutRequestId, $phone, $amountStr, $invoice);

    echo json_encode([
        'success' => true,
        'message' => $responseBlock['CustomerMessage'] ?? 'STK push sent. Check your phone.',
        'checkoutRequestId' => $checkoutRequestId,
    ]);
} catch (KcbApiException $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Unexpected STK server error: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unexpected server error']);
}
