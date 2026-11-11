<?php
/**
 * api/status.php?checkoutRequestId=...
 * Polled by the frontend every couple seconds after the STK push is sent,
 * so the UI can move to the success or failure screen once KCB's callback
 * has updated our database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$checkoutRequestId = trim((string) ($_GET['checkoutRequestId'] ?? ''));

if ($checkoutRequestId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing checkoutRequestId']);
    exit;
}

$tx = get_transaction($checkoutRequestId);

if (!$tx) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'status' => $tx['status'], // PENDING | SUCCESS | FAILED
    'amount' => $tx['amount'],
    'phone' => $tx['phone_number'],
    'resultDesc' => $tx['result_desc'],
    'mpesaReceipt' => $tx['mpesa_receipt'],
    'createdAt' => $tx['created_at'],
    'updatedAt' => $tx['updated_at'],
]);
