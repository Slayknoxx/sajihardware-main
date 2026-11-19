<?php
declare(strict_types=1);

/**
 * Product-linked sale returns.
 *
 * A return is recorded against one sale line, restocks the units, and keeps a
 * product link so analytics can compute return rates and net revenue.
 */

/**
 * Record a returned quantity for one sale line.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input sale_id, sale_item_id, quantity, reason.
 * @return array Summary of the return.
 */
function return_record(array $user, array $input): array
{
    $saleId = filter_var($input['sale_id'] ?? null, FILTER_VALIDATE_INT);
    if ($saleId === false || $saleId < 1) {
        throw new InvalidArgumentException('Choose a valid sale to return against.');
    }

    $saleItemId = filter_var($input['sale_item_id'] ?? null, FILTER_VALIDATE_INT);
    if ($saleItemId === false || $saleItemId < 1) {
        throw new InvalidArgumentException('Choose the returned product line.');
    }

    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity < 1 || $quantity > 10000) {
        throw new InvalidArgumentException('Return quantity must be a whole number between 1 and 10000.');
    }

    $reason = trim((string)($input['reason'] ?? ''));
    if (mb_strlen($reason) > 200) {
        throw new InvalidArgumentException('Reason must be 200 characters or fewer.');
    }

    $refundMethod = trim((string)($input['refund_method'] ?? ''));
    if ($refundMethod !== '' && !in_array($refundMethod, REFUND_METHODS, true)) {
        throw new InvalidArgumentException('Choose a valid refund method.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $saleStmt = $pdo->prepare("SELECT id, receipt_no, status, payment_method FROM sales WHERE id = ?");
        $saleStmt->execute([$saleId]);
        $sale = $saleStmt->fetch();
        if (!$sale) {
            throw new InvalidArgumentException('Sale not found.');
        }
        if ($sale['status'] !== 'COMPLETED') {
            throw new InvalidArgumentException('Only completed sales can be returned.');
        }

        // Default the refund to the way the customer paid. Credit sales never
        // took money in, so they default to no refund.
        if ($refundMethod === '') {
            $saleMethod = (string)($sale['payment_method'] ?? '');
            $refundMethod = in_array($saleMethod, REFUND_METHODS, true) ? $saleMethod : 'None';
        }

        $itemStmt = $pdo->prepare('SELECT id, sale_id, product_id, product_name, quantity, unit_price FROM sale_items WHERE id = ?');
        $itemStmt->execute([$saleItemId]);
        $item = $itemStmt->fetch();
        if (!$item || (int)$item['sale_id'] !== $saleId) {
            throw new InvalidArgumentException('That product line does not belong to the sale.');
        }

        $returned = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM returns WHERE sale_item_id = ?');
        $returned->execute([$saleItemId]);
        $alreadyReturned = (int)$returned->fetchColumn();
        if ($quantity > (int)$item['quantity'] - $alreadyReturned) {
            throw new InvalidArgumentException('Cannot return more units than remain on that line.');
        }

        $amount = (int)$item['unit_price'] * $quantity;
        $pdo->prepare(
            'INSERT INTO returns(receipt_no, sale_id, sale_item_id, product_id, quantity, unit_price, amount, reason, status, refund_method, created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $sale['receipt_no'], $saleId, $saleItemId, (int)$item['product_id'], $quantity,
            (int)$item['unit_price'], $amount, $reason, 'COMPLETED', $refundMethod, (int)($user['id'] ?? 0),
        ]);

        $stock = $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
        $stock->execute([$quantity, (int)$item['product_id']]);
        $pdo->prepare('INSERT INTO stock_movements(product_id, quantity_delta, reason, reference, created_by) VALUES(?,?,?,?,?)')
            ->execute([(int)$item['product_id'], $quantity, 'RETURN', (string)$sale['receipt_no'], (int)($user['id'] ?? 0)]);

        $skuStmt = $pdo->prepare('SELECT sku FROM products WHERE id = ?');
        $skuStmt->execute([(int)$item['product_id']]);
        $sku = (string)$skuStmt->fetchColumn();

        audit(
            (string)($user['username'] ?? 'system'),
            'RETURN_RECORDED',
            (string)$sale['receipt_no'] . ' ' . $sku . ' -' . $quantity
                . ' (' . $refundMethod . ')'
                . ($reason !== '' ? ' | ' . $reason : '')
        );

        db_commit();

        return [
            'ok' => true,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'product_id' => (int)$item['product_id'],
            'product_name' => (string)$item['product_name'],
            'receipt_no' => (string)$sale['receipt_no'],
            'quantity' => $quantity,
            'unit_price' => (int)$item['unit_price'],
            'amount' => $amount,
            'reason' => $reason,
            'refund_method' => $refundMethod,
        ];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}

/**
 * Return totals for the analytics period: how much was refunded and how it
 * compares with revenue in the same window.
 *
 * @param array $bounds UTC start/end bounds from analytics_period().
 * @return array Count, refunded amount, and returned units.
 */
function returns_totals(array $bounds): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS amount,
                COALESCE(SUM(quantity),0) AS units
         FROM returns
         WHERE status = 'COMPLETED' AND created_at BETWEEN ? AND ?"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    $row = $stmt->fetch() ?: ['count' => 0, 'amount' => 0, 'units' => 0];
    return [
        'count' => (int)$row['count'],
        'amount' => (int)$row['amount'],
        'units' => (int)$row['units'],
    ];
}
