<?php
declare(strict_types=1);

/**
 * Quotation editing.
 *
 * Admins and managers can adjust a draft or accepted quotation: add lines,
 * remove lines, change quantities, and update the customer name and KRA PIN.
 * Converted quotations are locked because they already produced a sale.
 *
 * Existing lines keep their name and unit-price snapshots. Newly added lines
 * use the product's current name and selling price. Totals and VAT are
 * recalculated from the resulting lines with the quotation's existing discount.
 */

/**
 * Recalculate and save a quotation.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input id, customer_name, customer_kra_pin, items[]
 *                     Each item is either {id, quantity} for an existing line
 *                     or {product_id, quantity} for a newly added line.
 * @return array Summary of the updated quotation.
 */
function quotation_update(array $user, array $input): array
{
    $quotationId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($quotationId === false || $quotationId < 1) {
        throw new InvalidArgumentException('Choose a valid quotation to edit.');
    }

    $customerName = trim((string)($input['customer_name'] ?? ''));
    if ($customerName === '') {
        $customerName = 'Walk-in Customer';
    }
    if (mb_strlen($customerName) > 120) {
        throw new InvalidArgumentException('Customer name must be 120 characters or fewer.');
    }

    $kraPin = trim((string)($input['customer_kra_pin'] ?? ''));
    if (mb_strlen($kraPin) > 40) {
        throw new InvalidArgumentException('KRA PIN must be 40 characters or fewer.');
    }

    $requested = $input['items'] ?? null;
    if (!is_array($requested) || $requested === []) {
        throw new InvalidArgumentException('A quotation must contain at least one product.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $stmt = $pdo->prepare('SELECT * FROM quotations WHERE id = ?');
        $stmt->execute([$quotationId]);
        $quote = $stmt->fetch();
        if (!$quote) {
            throw new InvalidArgumentException('Quotation not found.');
        }
        if (($quote['status'] ?? '') === 'CONVERTED') {
            throw new InvalidArgumentException('A converted quotation can no longer be edited.');
        }

        $itemsStmt = $pdo->prepare('SELECT id, product_id, product_name, unit_price FROM quotation_items WHERE quotation_id = ?');
        $itemsStmt->execute([$quotationId]);
        $existing = [];
        foreach ($itemsStmt->fetchAll() as $row) {
            $existing[(int)$row['id']] = $row;
        }

        $keptIds = [];
        $lines = [];
        foreach ($requested as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Quotation lines are invalid.');
            }
            $quantity = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 10000) {
                throw new InvalidArgumentException('Quotation quantities must be positive whole numbers.');
            }

            $lineId = filter_var($entry['id'] ?? null, FILTER_VALIDATE_INT);
            if ($lineId !== false && $lineId > 0) {
                if (!isset($existing[(int)$lineId])) {
                    throw new InvalidArgumentException('Quotation line not found.');
                }
                if (isset($keptIds[(int)$lineId])) {
                    throw new InvalidArgumentException('A quotation line was sent more than once.');
                }
                $keptIds[(int)$lineId] = true;
                $row = $existing[(int)$lineId];
                $productId = (int)$row['product_id'];
                $productName = (string)$row['product_name'];
                $unitPrice = (int)$row['unit_price'];
            } else {
                $productId = filter_var($entry['product_id'] ?? null, FILTER_VALIDATE_INT);
                if ($productId === false || $productId < 1) {
                    throw new InvalidArgumentException('Choose a product for each new line.');
                }
                $productStmt = $pdo->prepare('SELECT id, name, selling_price FROM products WHERE id = ?');
                $productStmt->execute([$productId]);
                $product = $productStmt->fetch();
                if (!$product) {
                    throw new InvalidArgumentException('Product not found.');
                }
                $productName = (string)$product['name'];
                $unitPrice = (int)$product['selling_price'];
            }

            $vatStmt = $pdo->prepare('SELECT vat_rate FROM products WHERE id = ?');
            $vatStmt->execute([$productId]);
            $vatRate = max(0.0, min(1.0, (float)($vatStmt->fetchColumn() ?: 0)));

            $lines[] = [
                'id' => $lineId !== false && $lineId > 0 ? (int)$lineId : null,
                'product_id' => $productId,
                'product_name' => $productName,
                'quantity' => (int)$quantity,
                'unit_price' => $unitPrice,
                'total' => $unitPrice * (int)$quantity,
                'vat_rate' => $vatRate,
            ];
        }

        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line['total'];
        }

        $discount = (int)($quote['discount'] ?? 0);
        if ($discount < 0) {
            $discount = 0;
        }
        if ($discount > $subtotal) {
            $discount = $subtotal;
        }

        $tax = 0;
        $discountAllocated = 0;
        $lineCount = count($lines);
        foreach ($lines as $index => $line) {
            $lineDiscount = $index === $lineCount - 1
                ? $discount - $discountAllocated
                : intdiv($discount * $line['total'], max(1, $subtotal));
            $discountAllocated += $lineDiscount;
            $rate = (float)$line['vat_rate'];
            $tax += (int)round(($line['total'] - $lineDiscount) * $rate / (1 + $rate), 0, PHP_ROUND_HALF_UP);
        }
        $total = $subtotal - $discount;

        $deletedIds = array_diff(array_keys($existing), array_keys($keptIds));
        if ($deletedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($deletedIds), '?'));
            $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id = ? AND id IN ({$placeholders})")
                ->execute(array_merge([$quotationId], array_values($deletedIds)));
        }

        foreach ($lines as $line) {
            if ($line['id'] !== null) {
                $pdo->prepare('UPDATE quotation_items SET quantity = ?, total = ? WHERE id = ? AND quotation_id = ?')
                    ->execute([$line['quantity'], $line['total'], $line['id'], $quotationId]);
                continue;
            }
            $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
                ->execute([$quotationId, $line['product_id'], $line['product_name'], $line['quantity'], $line['unit_price'], $line['total']]);
        }

        $pdo->prepare('UPDATE quotations SET customer_name = ?, customer_kra_pin = ?, subtotal = ?, tax = ?, discount = ?, total = ? WHERE id = ?')
            ->execute([$customerName, $kraPin, $subtotal, $tax, $discount, $total, $quotationId]);

        audit(
            (string)($user['username'] ?? 'system'),
            'UPDATE_QUOTATION',
            (string)$quote['quote_no']
        );

        db_commit();

        return [
            'ok' => true,
            'id' => $quotationId,
            'quote_no' => (string)$quote['quote_no'],
            'customer_name' => $customerName,
            'customer_kra_pin' => $kraPin,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'total' => $total,
            'item_count' => count($lines),
        ];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}

function quotation_delete(array $user, int $quotationId): ?array
{
    if ($quotationId < 1) {
        throw new InvalidArgumentException('Choose a valid quotation to delete.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $stmt = $pdo->prepare('SELECT quote_no FROM quotations WHERE id = ?');
        $stmt->execute([$quotationId]);
        $quote = $stmt->fetch();
        if (!$quote) {
            db_rollback();
            return null;
        }

        $pdo->prepare('UPDATE sales SET quotation_id = NULL WHERE quotation_id = ?')
            ->execute([$quotationId]);
        $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id = ?')
            ->execute([$quotationId]);
        $pdo->prepare('DELETE FROM quotations WHERE id = ?')
            ->execute([$quotationId]);
        audit(
            (string)($user['username'] ?? 'system'),
            'DELETE_QUOTATION',
            (string)$quote['quote_no']
        );
        db_commit();

        return ['ok' => true];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}
