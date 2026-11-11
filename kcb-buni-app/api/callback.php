<?php
/**
 * api/callback.php
 * KCB Buni posts the transaction result here after the customer enters
 * their PIN (or cancels / times out). This URL has NO KCB-side signature,
 * so we protect it with a long random secret in the URL path
 * (see CALLBACK_URL / CALLBACK_SECRET in .env) and by validating the
 * CheckoutRequestID actually exists in our own database.
 *
 * Expected URL shape:
 *   https://your-domain/api/callback.php/<CALLBACK_SECRET>
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// ---- verify the secret in the URL path ----
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$suppliedSecret = trim($pathInfo, '/');

if (CALLBACK_SECRET === '' || !hash_equals(CALLBACK_SECRET, $suppliedSecret)) {
    http_response_code(403);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');

// Always log raw callbacks for auditing/debugging
$logLine = '[' . date('c') . '] ' . $raw . PHP_EOL;
@file_put_contents(__DIR__ . '/../data/callbacks.log', $logLine, FILE_APPEND);

$data = json_decode($raw, true);
$stk = $data['Body']['stkCallback'] ?? null;

if (!$stk) {
    // Acknowledge anyway so KCB doesn't keep retrying a malformed payload we can't use
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Received']);
    exit;
}

$checkoutRequestId = $stk['CheckoutRequestID'] ?? '';
$resultCode = (int) ($stk['ResultCode'] ?? 1);
$resultDesc = (string) ($stk['ResultDesc'] ?? 'Unknown result');

$mpesaReceipt = null;
if (!empty($stk['CallbackMetadata']['Item']) && is_array($stk['CallbackMetadata']['Item'])) {
    foreach ($stk['CallbackMetadata']['Item'] as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
            $mpesaReceipt = $item['Value'] ?? null;
        }
    }
}

if ($checkoutRequestId !== '') {
    // Only update if this CheckoutRequestID exists in our own DB (extra safety
    // against processing callbacks we never actually requested)
    $existing = get_transaction($checkoutRequestId);
    if ($existing) {
        update_transaction_from_callback($checkoutRequestId, $resultCode, $resultDesc, $mpesaReceipt, $raw);
    }
}

// KCB / M-Pesa expects a 200 OK acknowledgement
http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Received']);
