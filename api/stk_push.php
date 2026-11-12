<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../kcb_client.php';

header('Content-Type: application/json');

try {
    require_payment_user();
    security_require_csrf();
    if (!kcb_configured()) {
        payment_json(['error' => 'KCB Buni is not configured in api/config.php.'], 503);
    }

    init_db();
    $data = payment_input();
    $saleId = (int)($data['sale_id'] ?? 0);
    $reference = trim((string)($data['reference'] ?? ''));
    $phoneRaw = trim((string)($data['phone'] ?? ''));
    $phone = normalize_phone($phoneRaw);
    $amount = round((float)($data['amount'] ?? 0), 2);

    if ($saleId < 1 || $reference === '' || $phone === '' || $amount <= 0) {
        payment_json(['error' => 'sale_id, reference, phone, and amount are required.'], 400);
    }

    if (!preg_match('/^254\d{9,10}$/', $phone)) {
        payment_json(['error' => 'Enter a valid MPesa phone number in 254 format.'], 422);
    }

    $saleStmt = db()->prepare('SELECT id, receipt_no, total, status, payment_method, payment_reference FROM sales WHERE id = ?');
    $saleStmt->execute([$saleId]);
    $sale = $saleStmt->fetch();
    if (!$sale || $sale['payment_method'] !== 'M-Pesa' || $sale['status'] !== 'PENDING_PAYMENT') {
        payment_json(['error' => 'Sale is not waiting for M-Pesa payment.'], 409);
    }
    if (!hash_equals((string)$sale['payment_reference'], $reference) || abs((float)$sale['total'] - $amount) > 0.01) {
        payment_json(['error' => 'Payment reference or amount does not match the sale.'], 409);
    }

    $payments = payment_db();
    $payments->beginTransaction();
    $stmt = $payments->prepare('INSERT INTO payments(local_sale_id, receipt_no, payment_reference, phone, amount, status) VALUES(?,?,?,?,?,?) ON CONFLICT(payment_reference) DO UPDATE SET phone=excluded.phone, amount=excluded.amount, updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$saleId, $sale['receipt_no'], $reference, $phone, $amount, 'PENDING']);
    $payments->commit();

    $amountStr = (string)intval($amount);
    $result = kcb_stk_push($phone, $amountStr, $reference, 'SAJI Hardware payment ' . $sale['receipt_no']);

    $flattenResponse = function (mixed $value) use (&$flattenResponse): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $flattenResponse($decoded) : [];
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
    $responseCode = (string)($responseBlock['ResponseCode'] ?? $responseBlock['responseCode'] ?? $responseBlock['response_code'] ?? $responseBlock['statusCode'] ?? $responseBlock['status'] ?? '1');
    $checkoutRequestId = $responseBlock['CheckoutRequestID'] ?? $responseBlock['checkoutRequestId'] ?? $responseBlock['checkoutRequestID'] ?? null;
    $merchantRequestId = $responseBlock['MerchantRequestID'] ?? $responseBlock['merchantRequestId'] ?? $responseBlock['merchantRequestID'] ?? null;

    if ($responseCode !== '0' || !$checkoutRequestId) {
        $desc = $responseBlock['ResponseDescription'] ?? $responseBlock['responseDescription'] ?? $responseBlock['statusDescription'] ?? $responseBlock['responseMessage'] ?? $responseBlock['responseMsg'] ?? $responseBlock['response_message'] ?? $responseBlock['message'] ?? $responseBlock['error'] ?? $responseBlock['description'] ?? $responseBlock['resultDesc'] ?? 'KCB returned an unrecognized STK response.';
        throw new RuntimeException("KCB rejected the STK push (code $responseCode): $desc");
    }

    $payments->beginTransaction();
    $update = $payments->prepare('UPDATE payments SET status=?, checkout_request_id=?, merchant_request_id=?, stk_response_json=?, updated_at=CURRENT_TIMESTAMP WHERE payment_reference=?');
    $update->execute(['STK_SENT', $checkoutRequestId, $merchantRequestId ?? '', json_encode($result, JSON_THROW_ON_ERROR), $reference]);
    $payments->commit();

    db()->prepare('UPDATE sales SET mpesa_status=?, mpesa_request_id=?, mpesa_phone=? WHERE id=?')->execute(['STK_SENT', $checkoutRequestId, $phone, $saleId]);

    payment_json([
        'ok' => true,
        'payment_status' => 'STK_SENT',
        'sale_id' => $saleId,
        'reference' => $reference,
        'checkout_request_id' => $checkoutRequestId,
        'poll_seconds' => PAYMENT_POLL_TIMEOUT_SECONDS,
    ]);
} catch (KcbApiException $e) {
    if (isset($payments) && $payments->inTransaction()) {
        $payments->rollBack();
    }
    if (isset($saleId) && $saleId > 0) {
        db()->prepare('UPDATE sales SET mpesa_status=? WHERE id=? AND status=?')->execute(['STK_FAILED', $saleId, 'PENDING_PAYMENT']);
    }
    payment_json(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    if (isset($payments) && $payments->inTransaction()) {
        $payments->rollBack();
    }
    if (isset($saleId) && $saleId > 0) {
        db()->prepare('UPDATE sales SET mpesa_status=? WHERE id=? AND status=?')->execute(['STK_FAILED', $saleId, 'PENDING_PAYMENT']);
    }
    payment_json(['error' => $e->getMessage()], 500);
}
