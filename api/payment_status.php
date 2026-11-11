<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    require_payment_user();
    init_db();
    $saleId = (int)($_GET['sale_id'] ?? 0);
    $reference = trim((string)($_GET['reference'] ?? ''));
    if ($saleId < 1 && $reference === '') {
        payment_json(['error' => 'sale_id or reference is required.'], 400);
    }

    if ($reference !== '') {
        $stmt = payment_db()->prepare('SELECT * FROM payments WHERE payment_reference=?');
        $stmt->execute([$reference]);
    } else {
        $stmt = payment_db()->prepare('SELECT * FROM payments WHERE local_sale_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$saleId]);
    }
    $payment = $stmt->fetch();

    $saleStmt = db()->prepare('SELECT id, receipt_no, status, mpesa_status, payment_reference, mpesa_request_id, mpesa_receipt_no, amount_paid, total FROM sales WHERE id=? OR payment_reference=? LIMIT 1');
    $saleStmt->execute([$saleId, $reference]);
    $sale = $saleStmt->fetch();
    if (!$sale) {
        payment_json(['error' => 'Sale not found.'], 404);
    }
    $paid = $payment && $payment['status'] === 'PAID'
        && $sale['status'] === 'COMPLETED'
        && $sale['mpesa_status'] === 'PAID'
        && (float)$sale['amount_paid'] === (float)$sale['total'];
    payment_json(['payment' => $payment ? ['status' => $payment['status'], 'reference' => $payment['payment_reference'], 'receipt' => $payment['mpesa_receipt']] : null, 'sale' => $sale, 'paid' => $paid]);
} catch (Throwable $e) {
    payment_json(['error' => $e->getMessage()], 500);
}
