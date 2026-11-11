<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

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

$raw = file_get_contents('php://input') ?: '';
$logLine = '[' . date('c') . '] ' . $raw . PHP_EOL;
@file_put_contents(__DIR__ . '/../data/callbacks.log', $logLine, FILE_APPEND);

$data = json_decode($raw, true);
$stk = $data['Body']['stkCallback'] ?? $data['stkCallback'] ?? null;

if (!$stk) {
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Received']);
    exit;
}

$checkoutRequestId = (string)($stk['CheckoutRequestID'] ?? $stk['checkoutRequestId'] ?? '');
$resultCode = (int)($stk['ResultCode'] ?? 1);
$resultDesc = (string)($stk['ResultDesc'] ?? 'Unknown result');

$mpesaReceipt = null;
if (!empty($stk['CallbackMetadata']['Item']) && is_array($stk['CallbackMetadata']['Item'])) {
    foreach ($stk['CallbackMetadata']['Item'] as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
            $mpesaReceipt = $item['Value'] ?? null;
        }
    }
}

try {
    $payments = payment_db();
    $payments->beginTransaction();
    $stmt = $payments->prepare('SELECT * FROM payments WHERE checkout_request_id=? OR payment_reference=? LIMIT 1');
    $stmt->execute([$checkoutRequestId, $checkoutRequestId]);
    $payment = $stmt->fetch();

    if ($payment) {
        $amountMatches = (float)$payment['amount'] > 0;
        $paid = $resultCode === 0 && $amountMatches && $checkoutRequestId !== '';
        $status = $paid ? 'PAID' : 'FAILED';

        $update = $payments->prepare('UPDATE payments SET status=?, result_code=?, result_description=?, mpesa_receipt=?, callback_json=?, paid_at=CASE WHEN ? = 1 THEN COALESCE(paid_at, CURRENT_TIMESTAMP) ELSE paid_at END, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN (\'PENDING\', \'STK_SENT\')');
        $update->execute([$status, (string)$resultCode, $resultDesc, (string)($mpesaReceipt ?? ''), $raw, $paid ? 1 : 0, (int)$payment['id']]);

        $saleId = (int)$payment['local_sale_id'];
        if ($paid && $update->rowCount() > 0) {
            db()->prepare('UPDATE sales SET status=?, mpesa_status=?, amount_paid=total, mpesa_request_id=COALESCE(NULLIF(?, ""), mpesa_request_id), mpesa_receipt_no=?, paid_at=CURRENT_TIMESTAMP WHERE id=? AND status=? AND payment_reference=?')
                ->execute(['COMPLETED', 'PAID', $checkoutRequestId, (string)($mpesaReceipt ?? ''), $saleId, 'PENDING_PAYMENT', $payment['payment_reference']]);
        } elseif (!$paid && $update->rowCount() > 0) {
            db()->prepare('UPDATE sales SET status=?, mpesa_status=?, mpesa_request_id=COALESCE(NULLIF(?, ""), mpesa_request_id) WHERE id=? AND status=? AND payment_reference=?')
                ->execute(['PAYMENT_FAILED', 'FAILED_' . $resultCode, $checkoutRequestId, $saleId, 'PENDING_PAYMENT', $payment['payment_reference']]);
            restore_stock_for_failed_sale($saleId);
        }
    }

    $payments->commit();
} catch (Throwable $e) {
    if (isset($payments) && $payments->inTransaction()) {
        $payments->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => $e->getMessage()]);
    exit;
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Received']);
