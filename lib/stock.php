<?php
declare(strict_types=1);

/**
 * Stock control helpers.
 *
 * Restocking is the only supported way to increase the quantity of an existing
 * product. It updates live stock, the supplied-quantity counter used by Stock
 * Analytics, and writes a movement row so every change is traceable.
 */

function stock_can_restock(string $role): bool
{
    return in_array($role, ['ADMIN', 'MANAGER'], true);
}

/**
 * Add received units to an existing product.
 *
 * @param array $user  Authenticated user.
 * @param array $input product_id, quantity, reference, optional buying_price.
 * @return array Summary of the movement.
 */
function stock_restock(array $user, array $input): array
{
    $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    $reference = trim((string)($input['reference'] ?? ''));
    $hasBuyingPrice = array_key_exists('buying_price', $input)
        && $input['buying_price'] !== ''
        && $input['buying_price'] !== null;

    if ($productId === false || $productId < 1) {
        throw new InvalidArgumentException('Choose a valid product to restock.');
    }
    if ($quantity === false || $quantity < 1 || $quantity > 100000) {
        throw new InvalidArgumentException('Units received must be a whole number between 1 and 100000.');
    }
    if (mb_strlen($reference) > 120) {
        throw new InvalidArgumentException('Reference must be 120 characters or fewer.');
    }

    $buyingPrice = null;
    if ($hasBuyingPrice) {
        $buyingPrice = money_to_minor($input['buying_price']);
        if ($buyingPrice < 0) {
            throw new InvalidArgumentException('Buying price cannot be negative.');
        }
    }

    db_begin_immediate();
    try {
        $stmt = db()->prepare('SELECT id, sku, name, quantity FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new InvalidArgumentException('Product not found.');
        }

        $previousQuantity = (int)$product['quantity'];
        if ($buyingPrice !== null) {
            db()->prepare('UPDATE products SET quantity = quantity + ?, supplied_quantity = supplied_quantity + ?, buying_price = ? WHERE id = ?')
                ->execute([$quantity, $quantity, $buyingPrice, $productId]);
        } else {
            db()->prepare('UPDATE products SET quantity = quantity + ?, supplied_quantity = supplied_quantity + ? WHERE id = ?')
                ->execute([$quantity, $quantity, $productId]);
        }

        db()->prepare('INSERT INTO stock_movements(product_id, quantity_delta, reason, reference, created_by) VALUES(?,?,?,?,?)')
            ->execute([$productId, $quantity, 'RESTOCK', $reference, (int)($user['id'] ?? 0) ?: null]);

        audit(
            (string)($user['username'] ?? 'system'),
            'RESTOCK_PRODUCT',
            $product['sku'] . ' +' . $quantity . ($reference !== '' ? ' ref ' . $reference : '')
        );

        db_commit();

        return [
            'ok' => true,
            'product_id' => (int)$product['id'],
            'sku' => (string)$product['sku'],
            'name' => (string)$product['name'],
            'previous_quantity' => $previousQuantity,
            'quantity_added' => $quantity,
            'new_quantity' => $previousQuantity + $quantity,
            'reference' => $reference,
        ];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}

/**
 * Most recent stock movements, newest first.
 *
 * @return array<int, array<string, mixed>>
 */
function stock_recent_movements(int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->query(
        "SELECT m.id, m.quantity_delta, m.reason, m.reference, m.created_at,
                p.sku, p.name AS product_name,
                COALESCE(u.username, 'System') AS username
         FROM stock_movements m
         JOIN products p ON p.id = m.product_id
         LEFT JOIN users u ON u.id = m.created_by
         ORDER BY m.id DESC
         LIMIT {$limit}"
    );
    return $stmt->fetchAll();
}
