<?php
declare(strict_types=1);

/**
 * Existing product editing.
 *
 * Prices and catalogue details can be changed without touching stock. Units
 * still move through restocking, and completed sales keep their own price
 * snapshots, so historical receipts and stock movements are never rewritten.
 */

/**
 * Update a product's details and prices.
 *
 * Fields the caller leaves out keep their stored value, so a price-only edit
 * cannot blank the barcode, media paths, or supplier by accident. Buying price
 * is only changed for roles with the cost.view permission.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input id, sku, name, category, plus optional brand, barcode,
 *                     image_path, barcode_image_path, warehouse, branch,
 *                     buying_price, selling_price, vat_rate, reorder_level,
 *                     supplier_id, note.
 * @return array Summary of the update.
 */
function product_update(array $user, array $input): array
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
    $optional = static fn (string $key, string $label, int $max): ?string
        => array_key_exists($key, $input) ? $clean($key, $label, $max) : null;

    $sku = $clean('sku', 'SKU', 80, true);
    $name = $clean('name', 'Product name', 120, true);
    $category = $clean('category', 'Category', 80, true);
    $barcode = $optional('barcode', 'Barcode', 80);
    $brand = $optional('brand', 'Brand', 80);
    $imagePath = $optional('image_path', 'Product image path', 255);
    $barcodeImagePath = $optional('barcode_image_path', 'Barcode image path', 255);
    $warehouse = $optional('warehouse', 'Warehouse', 80);
    $branch = $optional('branch', 'Branch', 80);
    if ($warehouse === '') {
        $warehouse = 'Main';
    }
    if ($branch === '') {
        $branch = 'Main';
    }
    $note = $clean('note', 'Note', 200);

    $sellingRaw = $input['selling_price'] ?? null;
    if ($sellingRaw === null || trim((string)$sellingRaw) === '') {
        throw new InvalidArgumentException('Selling price is required.');
    }
    $sellingPrice = money_to_minor($sellingRaw);
    if ($sellingPrice < 0) {
        throw new InvalidArgumentException('Selling price cannot be negative.');
    }

    $canViewCost = role_has_permission((string)($user['role'] ?? ''), 'cost.view');
    $buyingPrice = null;
    $buyingRaw = $input['buying_price'] ?? null;
    if ($canViewCost && $buyingRaw !== null && trim((string)$buyingRaw) !== '') {
        $buyingPrice = money_to_minor($buyingRaw);
        if ($buyingPrice < 0) {
            throw new InvalidArgumentException('Buying price cannot be negative.');
        }
    }

    $vatRate = null;
    $vatRaw = $input['vat_rate'] ?? null;
    if ($vatRaw !== null && trim((string)$vatRaw) !== '') {
        if (!is_numeric($vatRaw)) {
            throw new InvalidArgumentException('VAT rate must be a number between 0 and 1.');
        }
        $vatRate = (float)$vatRaw;
        if ($vatRate < 0 || $vatRate > 1) {
            throw new InvalidArgumentException('VAT rate must be between 0 and 1 (0.16 = 16%).');
        }
    }

    $reorderLevel = null;
    if (($input['reorder_level'] ?? '') !== '' && $input['reorder_level'] !== null) {
        $reorderLevel = filter_var($input['reorder_level'], FILTER_VALIDATE_INT);
        if ($reorderLevel === false || $reorderLevel < 0 || $reorderLevel > 1000000) {
            throw new InvalidArgumentException('Reorder level must be a whole number between 0 and 1000000.');
        }
    }

    $supplierId = null;
    $supplierProvided = array_key_exists('supplier_id', $input);
    if ($supplierProvided && $input['supplier_id'] !== '' && $input['supplier_id'] !== null) {
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
        if ($barcode !== null && $barcode !== (string)$current['barcode']) {
            $changes[] = $textChange('barcode', (string)$current['barcode'], $barcode);
        }
        if ($brand !== null && $brand !== (string)$current['brand']) {
            $changes[] = $textChange('brand', (string)$current['brand'], $brand);
        }
        if ($imagePath !== null && $imagePath !== (string)$current['image_path']) {
            $changes[] = 'image path updated';
        }
        if ($barcodeImagePath !== null && $barcodeImagePath !== (string)$current['barcode_image_path']) {
            $changes[] = 'barcode image path updated';
        }
        if ($warehouse !== null && $warehouse !== (string)$current['warehouse']) {
            $changes[] = $textChange('warehouse', (string)$current['warehouse'], $warehouse);
        }
        if ($branch !== null && $branch !== (string)$current['branch']) {
            $changes[] = $textChange('branch', (string)$current['branch'], $branch);
        }
        if ($sellingPrice !== (int)$current['selling_price']) {
            $changes[] = $moneyChange('selling price', (int)$current['selling_price'], $sellingPrice);
        }
        $effectiveBuyingPrice = (int)$current['buying_price'];
        if ($buyingPrice !== null) {
            $effectiveBuyingPrice = $buyingPrice;
            if ($buyingPrice !== (int)$current['buying_price']) {
                $changes[] = $moneyChange('buying price', (int)$current['buying_price'], $buyingPrice);
            }
        }
        $effectiveVatRate = $vatRate ?? (float)$current['vat_rate'];
        if ($vatRate !== null && abs($vatRate - (float)$current['vat_rate']) > 0.000001) {
            $changes[] = 'vat rate ' . $current['vat_rate'] . ' to ' . $vatRate;
        }
        $effectiveReorderLevel = $reorderLevel ?? (int)$current['reorder_level'];
        if ($reorderLevel !== null && $reorderLevel !== (int)$current['reorder_level']) {
            $changes[] = 'reorder level ' . $current['reorder_level'] . ' to ' . $reorderLevel;
        }

        $currentSupplier = $current['supplier_id'] === null ? null : (int)$current['supplier_id'];
        $effectiveSupplierId = $supplierProvided ? $supplierId : $currentSupplier;
        if ($supplierProvided && $effectiveSupplierId !== $currentSupplier) {
            $changes[] = 'supplier ' . ($currentSupplier ?? 'none') . ' to ' . ($effectiveSupplierId ?? 'none');
        }

        $pdo->prepare(
            'UPDATE products SET sku = ?, barcode = ?, name = ?, category = ?, brand = ?,
                    image_path = ?, barcode_image_path = ?, warehouse = ?, branch = ?,
                    buying_price = ?, selling_price = ?, reorder_level = ?, supplier_id = ?, vat_rate = ?
             WHERE id = ?'
        )->execute([
            $sku,
            $barcode ?? (string)$current['barcode'],
            $name,
            $category,
            $brand ?? (string)$current['brand'],
            $imagePath ?? (string)$current['image_path'],
            $barcodeImagePath ?? (string)$current['barcode_image_path'],
            $warehouse ?? (string)$current['warehouse'],
            $branch ?? (string)$current['branch'],
            $effectiveBuyingPrice,
            $sellingPrice,
            $effectiveReorderLevel,
            $effectiveSupplierId,
            $effectiveVatRate,
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

        $result = [
            'ok' => true,
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'category' => $category,
            'brand' => $brand ?? (string)$current['brand'],
            'barcode' => $barcode ?? (string)$current['barcode'],
            'image_path' => $imagePath ?? (string)$current['image_path'],
            'barcode_image_path' => $barcodeImagePath ?? (string)$current['barcode_image_path'],
            'warehouse' => $warehouse ?? (string)$current['warehouse'],
            'branch' => $branch ?? (string)$current['branch'],
            'selling_price' => $sellingPrice,
            'vat_rate' => $effectiveVatRate,
            'reorder_level' => $effectiveReorderLevel,
            'supplier_id' => $effectiveSupplierId,
            'quantity' => (int)$current['quantity'],
            'changed' => $changes !== [],
            'changes' => $changes,
        ];
        if ($canViewCost) {
            $result['buying_price'] = $effectiveBuyingPrice;
            $result['old_buying_price'] = (int)$current['buying_price'];
        }
        $result['old_selling_price'] = (int)$current['selling_price'];

        return $result;
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}
