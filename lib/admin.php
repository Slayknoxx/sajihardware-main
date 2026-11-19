<?php
declare(strict_types=1);

/**
 * Admin-only full-field editing for products and suppliers, exposed on the
 * Admin page. These helpers assume the API layer already gated the action to
 * ADMIN; unlike the manager-facing editors they allow every column, including
 * stock quantity and the supplier balance.
 */

function admin_product_detail(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function admin_supplier_detail(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function admin_customer_detail(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function admin_product_update(array $user, array $input): array
{
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid product to edit.');
    }

    $clean = static function (string $key, string $label, int $max, bool $required = false) use ($input): string {
        $value = trim((string)($input[$key] ?? ''));
        if ($required && $value === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
        }
        return $value;
    };

    $sku = $clean('sku', 'SKU', 80, true);
    $name = $clean('name', 'Product name', 120, true);
    $category = $clean('category', 'Category', 80, true);
    $barcode = $clean('barcode', 'Barcode', 80);
    $brand = $clean('brand', 'Brand', 80);
    $imagePath = $clean('image_path', 'Product image path', 255);
    $barcodeImagePath = $clean('barcode_image_path', 'Barcode image path', 255);
    $warehouse = $clean('warehouse', 'Warehouse', 80);
    $branch = $clean('branch', 'Branch', 80);
    if ($warehouse === '') {
        $warehouse = 'Main';
    }
    if ($branch === '') {
        $branch = 'Main';
    }
    $note = $clean('note', 'Note', 200);

    $buyingPrice = money_to_minor($input['buying_price'] ?? null);
    $sellingPrice = money_to_minor($input['selling_price'] ?? null);
    if ($buyingPrice < 0 || $sellingPrice < 0) {
        throw new InvalidArgumentException('Product prices cannot be negative.');
    }

    $vatRate = (float)($input['vat_rate'] ?? VAT_RATE);
    if (!is_numeric($input['vat_rate'] ?? null) || $vatRate < 0 || $vatRate > 1) {
        throw new InvalidArgumentException('VAT rate must be between 0 and 1 (0.16 = 16%).');
    }

    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity < 0) {
        throw new InvalidArgumentException('Stock quantity must be a whole number of 0 or more.');
    }
    $suppliedQuantity = filter_var($input['supplied_quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($suppliedQuantity === false || $suppliedQuantity < 0) {
        throw new InvalidArgumentException('Supplied quantity must be a whole number of 0 or more.');
    }
    $reorderLevel = filter_var($input['reorder_level'] ?? null, FILTER_VALIDATE_INT);
    if ($reorderLevel === false || $reorderLevel < 0) {
        throw new InvalidArgumentException('Reorder level must be a whole number of 0 or more.');
    }

    $supplierId = null;
    if (($input['supplier_id'] ?? '') !== '' && $input['supplier_id'] !== null) {
        $supplierId = filter_var($input['supplier_id'], FILTER_VALIDATE_INT);
        if ($supplierId === false || $supplierId < 1) {
            throw new InvalidArgumentException('Choose a valid supplier.');
        }
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $lookup = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $lookup->execute([$id]);
        $current = $lookup->fetch();
        if (!$current) {
            throw new InvalidArgumentException('Product not found.');
        }

        $duplicate = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND id <> ?');
        $duplicate->execute([$sku, $id]);
        if ($duplicate->fetchColumn() !== false) {
            throw new InvalidArgumentException('Another product already uses that SKU.');
        }

        if ($supplierId !== null) {
            $supplier = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
            $supplier->execute([$supplierId]);
            if ($supplier->fetchColumn() === false) {
                throw new InvalidArgumentException('Supplier not found.');
            }
        }

        $changes = [];
        $moneyChange = static fn (string $label, int $old, int $new): string
            => $label . ' ' . number_format(money_minor_to_major($old), 2, '.', '')
                . ' to ' . number_format(money_minor_to_major($new), 2, '.', '');
        $textChange = static fn (string $label, string $old, string $new): string
            => $label . ' "' . $old . '" to "' . $new . '"';

        if ($sku !== (string)$current['sku']) {
            $changes[] = $textChange('sku', (string)$current['sku'], $sku);
        }
        if ($name !== (string)$current['name']) {
            $changes[] = $textChange('name', (string)$current['name'], $name);
        }
        if ($category !== (string)$current['category']) {
            $changes[] = $textChange('category', (string)$current['category'], $category);
        }
        if ($barcode !== (string)$current['barcode']) {
            $changes[] = $textChange('barcode', (string)$current['barcode'], $barcode);
        }
        if ($brand !== (string)$current['brand']) {
            $changes[] = $textChange('brand', (string)$current['brand'], $brand);
        }
        if ($imagePath !== (string)$current['image_path']) {
            $changes[] = 'image path updated';
        }
        if ($barcodeImagePath !== (string)$current['barcode_image_path']) {
            $changes[] = 'barcode image path updated';
        }
        if ($warehouse !== (string)$current['warehouse']) {
            $changes[] = $textChange('warehouse', (string)$current['warehouse'], $warehouse);
        }
        if ($branch !== (string)$current['branch']) {
            $changes[] = $textChange('branch', (string)$current['branch'], $branch);
        }
        if ($buyingPrice !== (int)$current['buying_price']) {
            $changes[] = $moneyChange('buying price', (int)$current['buying_price'], $buyingPrice);
        }
        if ($sellingPrice !== (int)$current['selling_price']) {
            $changes[] = $moneyChange('selling price', (int)$current['selling_price'], $sellingPrice);
        }
        if (abs($vatRate - (float)$current['vat_rate']) > 0.000001) {
            $changes[] = 'vat rate ' . $current['vat_rate'] . ' to ' . $vatRate;
        }
        if ($reorderLevel !== (int)$current['reorder_level']) {
            $changes[] = 'reorder level ' . $current['reorder_level'] . ' to ' . $reorderLevel;
        }
        if ($suppliedQuantity !== (int)$current['supplied_quantity']) {
            $changes[] = 'supplied quantity ' . $current['supplied_quantity'] . ' to ' . $suppliedQuantity;
        }

        $currentSupplier = $current['supplier_id'] === null ? null : (int)$current['supplier_id'];
        if ($supplierId !== $currentSupplier) {
            $changes[] = 'supplier ' . ($currentSupplier ?? 'none') . ' to ' . ($supplierId ?? 'none');
        }

        // Quantity edits become a manual stock adjustment so the movement
        // ledger stays truthful; completed sales keep their snapshots.
        $oldQuantity = (int)$current['quantity'];
        $delta = $quantity - $oldQuantity;
        if ($delta !== 0) {
            $changes[] = 'stock quantity ' . $oldQuantity . ' to ' . $quantity;
            $reference = $note !== '' ? $note : 'admin-edit';
            $pdo->prepare('INSERT INTO stock_movements(product_id, quantity_delta, reason, reference, created_by) VALUES(?,?,?,?,?)')
                ->execute([$id, $delta, 'ADJUSTMENT', $reference, (int)($user['id'] ?? 0)]);
        }

        $pdo->prepare(
            'UPDATE products SET sku = ?, barcode = ?, name = ?, category = ?, brand = ?,
                    image_path = ?, barcode_image_path = ?, warehouse = ?, branch = ?,
                    buying_price = ?, selling_price = ?, quantity = ?, reorder_level = ?,
                    supplier_id = ?, supplied_quantity = ?, vat_rate = ?
             WHERE id = ?'
        )->execute([
            $sku,
            $barcode,
            $name,
            $category,
            $brand,
            $imagePath,
            $barcodeImagePath,
            $warehouse,
            $branch,
            $buyingPrice,
            $sellingPrice,
            $quantity,
            $reorderLevel,
            $supplierId,
            $suppliedQuantity,
            $vatRate,
            $id,
        ]);

        if ($changes !== []) {
            $details = $sku . ': ' . implode('; ', $changes);
            if ($note !== '') {
                $details .= ' | ' . $note;
            }
            audit((string)($user['username'] ?? 'system'), 'UPDATE_PRODUCT', $details);
        }

        db_commit();

        return [
            'ok' => true,
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'quantity' => $quantity,
            'changed' => $changes !== [],
            'changes' => $changes,
        ];
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
}

function admin_supplier_update(array $user, array $input): array
{
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid supplier to edit.');
    }

    $clean = static function (string $key, string $label, int $max, bool $required = false) use ($input): string {
        $value = trim((string)($input[$key] ?? ''));
        if ($required && $value === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
        }
        return $value;
    };

    $name = $clean('name', 'Supplier name', 120, true);
    $phone = $clean('phone', 'Phone', 40);
    $email = $clean('email', 'Email', 120);
    $kraPin = $clean('kra_pin', 'KRA PIN', 40);
    $note = $clean('note', 'Note', 200);

    // The balance is what the business owes the supplier; admins may correct
    // it directly and the change is audited. A blank balance keeps the current
    // value, matching the partial-update behaviour of the other editors.
    $balance = null;
    $balanceRaw = $input['balance'] ?? null;
    if ($balanceRaw !== null && trim((string)$balanceRaw) !== '') {
        $balance = money_to_minor($balanceRaw);
        if ($balance < 0) {
            throw new InvalidArgumentException('Supplier balance cannot be negative.');
        }
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $lookup = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $lookup->execute([$id]);
        $current = $lookup->fetch();
        if (!$current) {
            throw new InvalidArgumentException('Supplier not found.');
        }

        $changes = [];
        $textChange = static fn (string $label, string $old, string $new): string
            => $label . ' "' . $old . '" to "' . $new . '"';

        if ($name !== (string)$current['name']) {
            $changes[] = $textChange('name', (string)$current['name'], $name);
        }
        if ($phone !== (string)$current['phone']) {
            $changes[] = $textChange('phone', (string)$current['phone'], $phone);
        }
        if ($email !== (string)$current['email']) {
            $changes[] = $textChange('email', (string)$current['email'], $email);
        }
        if ($kraPin !== (string)$current['kra_pin']) {
            $changes[] = $textChange('KRA PIN', (string)$current['kra_pin'], $kraPin);
        }
        $effectiveBalance = $balance ?? (int)$current['balance'];
        if ($balance !== null && $balance !== (int)$current['balance']) {
            $changes[] = 'balance ' . number_format(money_minor_to_major((int)$current['balance']), 2, '.', '')
                . ' to ' . number_format(money_minor_to_major($balance), 2, '.', '');
        }

        $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, email = ?, kra_pin = ?, balance = ? WHERE id = ?')
            ->execute([$name, $phone, $email, $kraPin, $effectiveBalance, $id]);

        if ($changes !== []) {
            $details = $name . ': ' . implode('; ', $changes);
            if ($note !== '') {
                $details .= ' | ' . $note;
            }
            audit((string)($user['username'] ?? 'system'), 'UPDATE_SUPPLIER', $details);
        }

        db_commit();

        return [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'balance' => $effectiveBalance,
            'changed' => $changes !== [],
            'changes' => $changes,
        ];
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
}

function admin_customer_update(array $user, array $input): array
{
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid customer to edit.');
    }

    $clean = static function (string $key, string $label, int $max, bool $required = false) use ($input): string {
        $value = trim((string)($input[$key] ?? ''));
        if ($required && $value === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
        }
        return $value;
    };

    $name = $clean('name', 'Customer name', 120, true);
    $phone = $clean('phone', 'Phone', 40);
    $email = $clean('email', 'Email', 120);
    $kraPin = $clean('kra_pin', 'KRA PIN', 40);
    $note = $clean('note', 'Note', 200);

    // The balance is what the customer owes the business on credit. Admins may
    // add credit (a debt) or correct it directly; the change is audited. A
    // blank balance keeps the current value.
    $balance = null;
    $balanceRaw = $input['balance'] ?? null;
    if ($balanceRaw !== null && trim((string)$balanceRaw) !== '') {
        $balance = money_to_minor($balanceRaw);
        if ($balance < 0) {
            throw new InvalidArgumentException('Customer balance cannot be negative.');
        }
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $lookup = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $lookup->execute([$id]);
        $current = $lookup->fetch();
        if (!$current) {
            throw new InvalidArgumentException('Customer not found.');
        }

        $changes = [];
        $textChange = static fn (string $label, string $old, string $new): string
            => $label . ' "' . $old . '" to "' . $new . '"';

        if ($name !== (string)$current['name']) {
            $changes[] = $textChange('name', (string)$current['name'], $name);
        }
        if ($phone !== (string)$current['phone']) {
            $changes[] = $textChange('phone', (string)$current['phone'], $phone);
        }
        if ($email !== (string)$current['email']) {
            $changes[] = $textChange('email', (string)$current['email'], $email);
        }
        if ($kraPin !== (string)$current['kra_pin']) {
            $changes[] = $textChange('KRA PIN', (string)$current['kra_pin'], $kraPin);
        }
        $effectiveBalance = $balance ?? (int)$current['balance'];
        if ($balance !== null && $balance !== (int)$current['balance']) {
            $changes[] = 'balance ' . number_format(money_minor_to_major((int)$current['balance']), 2, '.', '')
                . ' to ' . number_format(money_minor_to_major($balance), 2, '.', '');
        }

        $pdo->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, kra_pin = ?, balance = ? WHERE id = ?')
            ->execute([$name, $phone, $email, $kraPin, $effectiveBalance, $id]);

        if ($changes !== []) {
            $details = $name . ': ' . implode('; ', $changes);
            if ($note !== '') {
                $details .= ' | ' . $note;
            }
            audit((string)($user['username'] ?? 'system'), 'UPDATE_CUSTOMER', $details);
        }

        db_commit();

        return [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'balance' => $effectiveBalance,
            'changed' => $changes !== [],
            'changes' => $changes,
        ];
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
}
