<?php
declare(strict_types=1);

/**
 * Purchase orders with line items and receiving, plus supplier payments.
 *
 * Creating a purchase order reserves nothing; receiving adds the accepted
 * units to live stock through the shared restock primitive
 * (stock_add_units(), the same code path the Products page uses), optionally
 * refreshes the buying price, and marks the order partial or received. Unless the goods
 * were paid upfront, receiving also adds the received value to the supplier's
 * balance (what the business owes them). Supplier payments reduce that
 * balance and log a cash expense.
 */

function purchase_order_no(): string
{
    return 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Create a purchase order for one supplier with one or more product lines.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input supplier_id, items[] {product_id, quantity, unit_cost}, notes, expected_at.
 * @return array Summary of the created order.
 */
function purchase_order_create(array $user, array $input): array
{
    $supplierId = filter_var($input['supplier_id'] ?? null, FILTER_VALIDATE_INT);
    if ($supplierId === false || $supplierId < 1) {
        throw new InvalidArgumentException('Choose a supplier for the order.');
    }

    $itemsInput = $input['items'] ?? null;
    if (!is_array($itemsInput) || $itemsInput === []) {
        throw new InvalidArgumentException('Add at least one product line to the order.');
    }

    $notes = trim((string)($input['notes'] ?? ''));
    if (mb_strlen($notes) > 200) {
        throw new InvalidArgumentException('Notes must be 200 characters or fewer.');
    }

    $expectedAt = null;
    $expectedRaw = trim((string)($input['expected_at'] ?? ''));
    if ($expectedRaw !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedRaw)) {
            throw new InvalidArgumentException('Expected date must be YYYY-MM-DD.');
        }
        $expectedAt = $expectedRaw;
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $supplier = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ?');
        $supplier->execute([$supplierId]);
        $supplierRow = $supplier->fetch();
        if (!$supplierRow) {
            throw new InvalidArgumentException('Supplier not found.');
        }

        $lines = [];
        $total = 0;
        foreach ($itemsInput as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Order lines are invalid.');
            }
            $productId = filter_var($entry['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1) {
                throw new InvalidArgumentException('Choose a valid product for every line.');
            }
            if ($quantity === false || $quantity < 1 || $quantity > 100000) {
                throw new InvalidArgumentException('Order quantities must be between 1 and 100000.');
            }
            $unitCost = money_to_minor($entry['unit_cost'] ?? 0);
            if ($unitCost < 0) {
                throw new InvalidArgumentException('Unit cost cannot be negative.');
            }

            $product = $pdo->prepare('SELECT id, sku, name FROM products WHERE id = ?');
            $product->execute([$productId]);
            $productRow = $product->fetch();
            if (!$productRow) {
                throw new InvalidArgumentException('A product on the order no longer exists.');
            }
            $lines[] = [
                'product_id' => $productId,
                'product_name' => (string)$productRow['name'],
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ];
            $total += $unitCost * $quantity;
        }

        $poNumber = purchase_order_no();
        $pdo->prepare('INSERT INTO purchase_orders(supplier_id, po_number, status, total, expected_at, notes, created_by) VALUES(?,?,?,?,?,?,?)')
            ->execute([$supplierId, $poNumber, 'OPEN', $total, $expectedAt, $notes, (int)($user['id'] ?? 0)]);
        $poId = (int)$pdo->lastInsertId();

        $insertItem = $pdo->prepare('INSERT INTO purchase_order_items(purchase_order_id, product_id, product_name, quantity, unit_cost) VALUES(?,?,?,?,?)');
        foreach ($lines as $line) {
            $insertItem->execute([$poId, $line['product_id'], $line['product_name'], $line['quantity'], $line['unit_cost']]);
        }

        audit((string)($user['username'] ?? 'system'), 'CREATE_PURCHASE_ORDER', $poNumber . ' ' . $supplierRow['name']);

        db_commit();

        return [
            'ok' => true,
            'id' => $poId,
            'po_number' => $poNumber,
            'supplier' => (string)$supplierRow['name'],
            'supplier_id' => $supplierId,
            'total' => $total,
            'items' => $lines,
            'expected_at' => $expectedAt,
            'notes' => $notes,
        ];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}

/**
 * Receive units against a purchase order.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input purchase_order_id, lines[] {item_id, quantity, buying_price?}, paid_upfront?.
 * @return array Summary of the receiving run.
 */
function purchase_order_receive(array $user, array $input): array
{
    $poId = filter_var($input['purchase_order_id'] ?? null, FILTER_VALIDATE_INT);
    if ($poId === false || $poId < 1) {
        throw new InvalidArgumentException('Choose a purchase order to receive.');
    }

    $linesInput = $input['lines'] ?? null;
    if (!is_array($linesInput) || $linesInput === []) {
        throw new InvalidArgumentException('Enter the received quantities for at least one line.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $poStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $poStmt->execute([$poId]);
        $order = $poStmt->fetch();
        if (!$order) {
            throw new InvalidArgumentException('Purchase order not found.');
        }
        if (in_array($order['status'], ['RECEIVED', 'CANCELLED', 'DRAFT'], true)) {
            throw new InvalidArgumentException('This order can no longer receive units.');
        }

        $received = 0;
        $receivedValue = 0;
        $details = [];
        foreach ($linesInput as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Receiving lines are invalid.');
            }
            $itemId = filter_var($entry['item_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId < 1) {
                throw new InvalidArgumentException('Choose a valid order line.');
            }
            if ($quantity === false || $quantity < 1 || $quantity > 100000) {
                throw new InvalidArgumentException('Received quantities must be between 1 and 100000.');
            }

            $itemStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?');
            $itemStmt->execute([$itemId, $poId]);
            $item = $itemStmt->fetch();
            if (!$item) {
                throw new InvalidArgumentException('That line does not belong to the order.');
            }

            $outstanding = (int)$item['quantity'] - (int)$item['received_quantity'];
            if ($quantity > $outstanding) {
                throw new InvalidArgumentException('Cannot receive more than the outstanding units for ' . $item['product_name'] . '.');
            }

            $hasCost = array_key_exists('buying_price', $entry)
                && $entry['buying_price'] !== ''
                && $entry['buying_price'] !== null;
            $buyingPrice = null;
            if ($hasCost) {
                $buyingPrice = money_to_minor($entry['buying_price']);
                if ($buyingPrice < 0) {
                    throw new InvalidArgumentException('Buying price cannot be negative.');
                }
            }

            $pdo->prepare('UPDATE purchase_order_items SET received_quantity = received_quantity + ? WHERE id = ?')
                ->execute([$quantity, $itemId]);

            // Shares the manual-restock primitive, so both entry points move
            // stock, supplied quantity, price, and movement history alike.
            stock_add_units(
                (int)$item['product_id'],
                $quantity,
                (string)$order['po_number'],
                $buyingPrice,
                (int)($user['id'] ?? 0) ?: null
            );

            $received += $quantity;
            // Credit terms: the received value (units x unit cost) becomes a
            // payable. A buying price entered now is the effective line cost.
            $lineCost = $buyingPrice !== null ? $buyingPrice : (int)$item['unit_cost'];
            $receivedValue += $quantity * $lineCost;
            $details[] = (string)$item['product_name'] . ' +' . $quantity;
        }

        // Unless the goods were paid upfront, receiving adds the received
        // value to the supplier's balance (what the business owes them).
        $paidUpfront = !empty($input['paid_upfront']);
        $supplierBalance = null;
        if (!$paidUpfront && $receivedValue > 0) {
            $pdo->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')
                ->execute([$receivedValue, (int)$order['supplier_id']]);
            $balanceStmt = $pdo->prepare('SELECT balance FROM suppliers WHERE id = ?');
            $balanceStmt->execute([(int)$order['supplier_id']]);
            $supplierBalance = (int)$balanceStmt->fetchColumn();
        }

        $progress = $pdo->prepare('SELECT COALESCE(SUM(received_quantity),0) AS received_total, COALESCE(SUM(quantity),0) AS ordered_total FROM purchase_order_items WHERE purchase_order_id = ?');
        $progress->execute([$poId]);
        $progressRow = $progress->fetch();
        $receivedTotal = (int)$progressRow['received_total'];
        $orderedTotal = (int)$progressRow['ordered_total'];
        $status = (int)$receivedTotal >= (int)$orderedTotal ? 'RECEIVED' : 'PARTIAL';
        $pdo->prepare($status === 'RECEIVED' ? 'UPDATE purchase_orders SET status = ?, received_at = ? WHERE id = ?' : 'UPDATE purchase_orders SET status = ? WHERE id = ?')
            ->execute($status === 'RECEIVED' ? [$status, date('Y-m-d H:i:s'), $poId] : [$status, $poId]);

        audit((string)($user['username'] ?? 'system'), 'RECEIVE_PURCHASE_ORDER', $order['po_number'] . ' ' . implode(', ', $details));

        db_commit();

        return [
            'ok' => true,
            'purchase_order_id' => $poId,
            'po_number' => (string)$order['po_number'],
            'received' => $received,
            'received_total' => (int)$receivedTotal,
            'ordered_total' => (int)$orderedTotal,
            'status' => $status,
            'paid_upfront' => $paidUpfront,
            'credited' => $paidUpfront ? 0 : $receivedValue,
            'supplier_balance' => $supplierBalance,
        ];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}

/**
 * Cancel an order that has not received anything yet.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input purchase_order_id.
 * @return array Summary of the cancellation.
 */
function purchase_order_cancel(array $user, array $input): array
{
    $poId = filter_var($input['purchase_order_id'] ?? null, FILTER_VALIDATE_INT);
    if ($poId === false || $poId < 1) {
        throw new InvalidArgumentException('Choose a purchase order to cancel.');
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $stmt->execute([$poId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new InvalidArgumentException('Purchase order not found.');
    }
    if ($order['status'] === 'RECEIVED') {
        throw new InvalidArgumentException('Received orders cannot be cancelled.');
    }
    if ($order['status'] === 'CANCELLED') {
        throw new InvalidArgumentException('This order is already cancelled.');
    }

    $received = $pdo->prepare('SELECT COALESCE(SUM(received_quantity),0) FROM purchase_order_items WHERE purchase_order_id = ?');
    $received->execute([$poId]);
    if ((int)$received->fetchColumn() > 0) {
        throw new InvalidArgumentException('Cancel is only available before any units are received.');
    }

    $pdo->prepare("UPDATE purchase_orders SET status = 'CANCELLED' WHERE id = ?")->execute([$poId]);
    audit((string)($user['username'] ?? 'system'), 'CANCEL_PURCHASE_ORDER', (string)$order['po_number']);

    return ['ok' => true, 'purchase_order_id' => $poId, 'po_number' => (string)$order['po_number'], 'status' => 'CANCELLED'];
}

/**
 * One purchase order with its lines, for the receiving dialog.
 *
 * @param int $id Purchase order id.
 * @return array|null Order header plus line items, or null when missing.
 */
function purchase_order_detail(int $id): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT po.*, s.name AS supplier
         FROM purchase_orders po
         LEFT JOIN suppliers s ON s.id = po.supplier_id
         WHERE po.id = ?'
    );
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $items = $pdo->prepare('SELECT id, product_id, product_name, quantity, received_quantity, unit_cost FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id');
    $items->execute([$id]);
    $order['items'] = $items->fetchAll();

    return $order;
}

/**
 * Record a payment to a supplier against their balance (what the business
 * owes them). The balance is reduced straight away — there is no allocation
 * across orders, and the change is not audited. Cash payments log a cash
 * expense so the Cash Flow ledger reflects the money leaving the till.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input supplier_id, amount, method, reference.
 * @return array Summary of the payment.
 */
function supplier_payment_record(array $user, array $input): array
{
    $supplierId = filter_var($input['supplier_id'] ?? null, FILTER_VALIDATE_INT);
    if ($supplierId === false || $supplierId < 1) {
        throw new InvalidArgumentException('Choose a valid supplier.');
    }

    $amount = money_to_minor($input['amount'] ?? null);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Enter a payment amount greater than zero.');
    }

    $method = trim((string)($input['method'] ?? 'Cash')) ?: 'Cash';
    if (!in_array($method, ['Cash', 'M-Pesa', 'Card', 'Bank', 'Mobile Money', 'Voucher'], true)) {
        throw new InvalidArgumentException('Choose a supported payment method.');
    }

    $reference = trim((string)($input['reference'] ?? ''));
    if (mb_strlen($reference) > 120) {
        throw new InvalidArgumentException('Reference must be 120 characters or fewer.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $lookup = $pdo->prepare('SELECT id, name, balance FROM suppliers WHERE id = ?');
        $lookup->execute([$supplierId]);
        $supplier = $lookup->fetch();
        if (!$supplier) {
            throw new InvalidArgumentException('Supplier not found.');
        }

        $balance = (int)$supplier['balance'];
        if ($balance <= 0) {
            throw new InvalidArgumentException('This supplier has no outstanding balance.');
        }
        if ($amount > $balance) {
            throw new InvalidArgumentException('Payment cannot exceed the outstanding balance.');
        }

        $newBalance = $balance - $amount;
        $pdo->prepare('UPDATE suppliers SET balance = ? WHERE id = ?')
            ->execute([$newBalance, $supplierId]);

        $pdo->prepare('INSERT INTO supplier_payments(supplier_id, amount, method, reference, created_by) VALUES(?,?,?,?,?)')
            ->execute([$supplierId, $amount, $method, $reference, (int)($user['id'] ?? 0)]);

        if ($method === 'Cash') {
            $pdo->prepare('INSERT INTO cash_transactions(type, category, description, amount, reference, created_by) VALUES(?,?,?,?,?,?)')
                ->execute([
                    'EXPENSE',
                    'Supplier payment',
                    'Payment to ' . $supplier['name'],
                    $amount,
                    $reference !== '' ? $reference : (string)$supplierId,
                    (int)($user['id'] ?? 0),
                ]);
        }

        db_commit();

        return [
            'ok' => true,
            'supplier_id' => $supplierId,
            'supplier' => (string)$supplier['name'],
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'previous_balance' => $balance,
            'balance' => $newBalance,
        ];
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
}
