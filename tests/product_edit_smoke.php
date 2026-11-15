<?php
declare(strict_types=1);

/**
 * Product editing smoke test.
 *
 * Covers price and detail edits, partial updates, stock and sale snapshots
 * staying intact, manager cost restrictions, validation, and API role gates.
 *
 * Run: php -c .\php-runtime.ini tests\product_edit_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_product_edit_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/products.php';

$failures = 0;
$checks = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "PASS  {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

$cleanup = static function () use ($tmp): void {
    db_close();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-wal');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-shm');
    @rmdir($tmp . DIRECTORY_SEPARATOR . 'logs');
    @rmdir($tmp);
};

try {
    init_db();
    $pdo = db();

    $admin = $pdo->query("SELECT id, username, role FROM users WHERE role = 'ADMIN' ORDER BY id LIMIT 1")->fetch();
    check('an admin account exists', is_array($admin));

    $pdo->prepare('INSERT INTO suppliers(name, phone, email, kra_pin) VALUES(?,?,?,?)')
        ->execute(['Price Supplier', '', '', '']);
    $supplierId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO products(sku, barcode, name, category, brand, image_path, barcode_image_path, warehouse, branch, buying_price, selling_price, quantity, reorder_level, supplier_id, supplied_quantity, vat_rate)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['SKU-EDIT-1', '6001001', 'Old Drill', 'Power tools', 'Acme', 'img/old.png', 'img/old-code.png', 'Main', 'Main', 8000, 12000, 12, 4, $supplierId, 12, 0.16]);
    $productId = (int)$pdo->lastInsertId();

    // A completed sale keeps its own price snapshot.
    $pdo->prepare("INSERT INTO sales(receipt_no, payment_method, subtotal, tax, total, created_by, status) VALUES(?,?,?,?,?,?,?)")
        ->execute(['RCPT-EDIT-1', 'Cash', 12000, 1920, 13920, (int)$admin['id'], 'COMPLETED']);
    $saleId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO sale_items(sale_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$saleId, $productId, 'Old Drill', 1, 12000, 12000]);

    $movementsBefore = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();

    // --- Price and detail edit -------------------------------------------
    $result = product_update($admin, [
        'id' => $productId,
        'sku' => '  SKU-EDIT-1  ',
        'barcode' => '6001002',
        'name' => '  Cordless Drill  ',
        'category' => 'Power tools',
        'brand' => 'Acme',
        'warehouse' => 'Main',
        'branch' => 'Kanduyi',
        'supplier_id' => $supplierId,
        'buying_price' => '9500.50',
        'selling_price' => '15500',
        'vat_rate' => '0.16',
        'reorder_level' => '6',
        'note' => 'Supplier price increase',
    ]);
    check('edit reports success', ($result['ok'] ?? false) === true);
    check('edit reports a change', ($result['changed'] ?? false) === true);
    check('selling price is returned in minor units inside the summary', (int)($result['selling_price'] ?? 0) === 1550000, (string)($result['selling_price'] ?? 0));
    check('old selling price is returned', (int)($result['old_selling_price'] ?? 0) === 12000);
    check('edit keeps stock quantity', (int)($result['quantity'] ?? 0) === 12);

    $row = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $row->execute([$productId]);
    $product = $row->fetch();
    check('selling price is updated', (int)$product['selling_price'] === 1550000, (string)$product['selling_price']);
    check('buying price is updated', (int)$product['buying_price'] === 950050, (string)$product['buying_price']);
    check('product name is trimmed', $product['name'] === 'Cordless Drill');
    check('barcode is updated', $product['barcode'] === '6001002');
    check('branch is updated', $product['branch'] === 'Kanduyi');
    check('reorder level is updated', (int)$product['reorder_level'] === 6);
    check('stock quantity is untouched', (int)$product['quantity'] === 12);
    check('supplied quantity is untouched', (int)$product['supplied_quantity'] === 12);
    check('foreign key supplier is untouched', (int)$product['supplier_id'] === $supplierId);

    $movementsAfter = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
    check('editing a price writes no stock movement', $movementsAfter === $movementsBefore);

    $saleItem = $pdo->prepare('SELECT unit_price, product_name FROM sale_items WHERE sale_id = ?');
    $saleItem->execute([$saleId]);
    $snapshot = $saleItem->fetch();
    check('completed sale keeps its item price snapshot', (int)$snapshot['unit_price'] === 12000, (string)$snapshot['unit_price']);
    check('completed sale keeps its product name snapshot', $snapshot['product_name'] === 'Old Drill');

    $audit = $pdo->query('SELECT action, details FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
    check('edit writes an audit entry', ($audit['action'] ?? '') === 'UPDATE_PRODUCT');
    check('audit details include the old and new selling price', str_contains((string)($audit['details'] ?? ''), 'selling price 120.00 to 15500.00'));
    check('audit details include the note', str_contains((string)($audit['details'] ?? ''), 'Supplier price increase'));

    // --- A price-only edit leaves everything else alone -------------------
    product_update($admin, [
        'id' => $productId,
        'sku' => 'SKU-EDIT-1',
        'name' => 'Cordless Drill',
        'category' => 'Power tools',
        'selling_price' => '16000',
    ]);
    $row->execute([$productId]);
    $afterPriceOnly = $row->fetch();
    check('price-only edit updates the selling price', (int)$afterPriceOnly['selling_price'] === 1600000);
    check('price-only edit keeps the buying price', (int)$afterPriceOnly['buying_price'] === 950050);
    check('price-only edit keeps the barcode', $afterPriceOnly['barcode'] === '6001002');
    check('price-only edit keeps the branch', $afterPriceOnly['branch'] === 'Kanduyi');
    check('price-only edit keeps the reorder level', (int)$afterPriceOnly['reorder_level'] === 6);
    check('price-only edit keeps the supplier', (int)$afterPriceOnly['supplier_id'] === $supplierId);
    check('price-only edit keeps the VAT rate', abs((float)$afterPriceOnly['vat_rate'] - 0.16) < 0.0001);

    // --- No-op edit -------------------------------------------------------
    $auditBeforeNoop = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $noop = product_update($admin, [
        'id' => $productId,
        'sku' => 'SKU-EDIT-1',
        'name' => 'Cordless Drill',
        'category' => 'Power tools',
        'selling_price' => '16000',
    ]);
    check('no-op edit reports no change', ($noop['changed'] ?? true) === false);
    check('no-op edit writes no audit entry', (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $auditBeforeNoop);

    // --- Clearing the supplier -------------------------------------------
    $cleared = product_update($admin, [
        'id' => $productId,
        'sku' => 'SKU-EDIT-1',
        'name' => 'Cordless Drill',
        'category' => 'Power tools',
        'selling_price' => '16000',
        'supplier_id' => '',
    ]);
    check('supplier can be cleared', array_key_exists('supplier_id', $cleared) && $cleared['supplier_id'] === null);
    check('clearing the supplier is reported as a change', ($cleared['changed'] ?? false) === true);

    // --- Manager cost restriction ----------------------------------------
    $manager = ['id' => 99, 'username' => 'manager_test', 'role' => 'MANAGER'];
    $managerResult = product_update($manager, [
        'id' => $productId,
        'sku' => 'SKU-EDIT-1',
        'name' => 'Cordless Drill',
        'category' => 'Power tools',
        'buying_price' => '1',
        'selling_price' => '17000',
    ]);
    $row->execute([$productId]);
    $afterManager = $row->fetch();
    check('manager can change the selling price', (int)$afterManager['selling_price'] === 1700000);
    check('manager cannot change the buying price', (int)$afterManager['buying_price'] === 950050);
    check('manager response hides the buying price', !array_key_exists('buying_price', $managerResult));
    $managerAudit = $pdo->query('SELECT details FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('manager audit entry has no buying price line', !str_contains((string)$managerAudit, 'buying price'));

    // --- SKU uniqueness ---------------------------------------------------
    $pdo->prepare('INSERT INTO products(sku, name, category, selling_price, buying_price) VALUES(?,?,?,?,?)')
        ->execute(['SKU-EDIT-2', 'Other Product', 'Hand tools', 500, 300]);
    $duplicateRejected = false;
    try {
        product_update($admin, [
            'id' => $productId,
            'sku' => 'SKU-EDIT-2',
            'name' => 'Cordless Drill',
            'category' => 'Power tools',
            'selling_price' => '17000',
        ]);
    } catch (InvalidArgumentException $e) {
        $duplicateRejected = str_contains($e->getMessage(), 'SKU');
    }
    check('duplicate SKUs are rejected', $duplicateRejected);
    $row->execute([$productId]);
    check('rejected SKU edit leaves the product unchanged', $row->fetch()['sku'] === 'SKU-EDIT-1');

    // --- Validation -------------------------------------------------------
    $base = [
        'id' => $productId,
        'sku' => 'SKU-EDIT-1',
        'name' => 'Cordless Drill',
        'category' => 'Power tools',
        'selling_price' => '17000',
    ];
    $invalidCases = [
        'missing id' => array_diff_key($base, ['id' => true]),
        'zero id' => ['id' => 0] + $base,
        'non-numeric id' => ['id' => 'abc'] + $base,
        'missing product' => ['id' => 999999] + $base,
        'empty sku' => ['sku' => '  '] + $base,
        'overlong sku' => ['sku' => str_repeat('s', 81)] + $base,
        'empty name' => ['name' => '  '] + $base,
        'overlong name' => ['name' => str_repeat('n', 121)] + $base,
        'empty category' => ['category' => ''] + $base,
        'overlong brand' => ['brand' => str_repeat('b', 81)] + $base,
        'overlong barcode' => ['barcode' => str_repeat('9', 81)] + $base,
        'overlong note' => ['note' => str_repeat('x', 201)] + $base,
        'missing selling price' => array_diff_key($base, ['selling_price' => true]),
        'empty selling price' => ['selling_price' => ''] + $base,
        'invalid selling price' => ['selling_price' => '12.3.4'] + $base,
        'negative selling price' => ['selling_price' => '-1'] + $base,
        'negative buying price' => ['buying_price' => '-5'] + $base,
        'non-numeric vat' => ['vat_rate' => 'abc'] + $base,
        'vat below zero' => ['vat_rate' => '-0.1'] + $base,
        'vat above one' => ['vat_rate' => '1.5'] + $base,
        'negative reorder level' => ['reorder_level' => '-1'] + $base,
        'reorder level too large' => ['reorder_level' => '1000001'] + $base,
        'unknown supplier' => ['supplier_id' => '999999'] + $base,
        'invalid supplier' => ['supplier_id' => 'abc'] + $base,
        'overlong warehouse' => ['warehouse' => str_repeat('w', 81)] + $base,
        'overlong branch' => ['branch' => str_repeat('b', 81)] + $base,
    ];
    foreach ($invalidCases as $name => $payload) {
        $rejected = false;
        try {
            product_update($admin, $payload);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        } catch (Throwable $e) {
            $rejected = false;
        }
        check('rejects ' . $name, $rejected);
    }

    $row->execute([$productId]);
    $afterInvalid = $row->fetch();
    check('invalid edits leave the price unchanged', (int)$afterInvalid['selling_price'] === 1700000, (string)$afterInvalid['selling_price']);
    check('invalid edits leave the product name unchanged', $afterInvalid['name'] === 'Cordless Drill');

    // --- Zero price is allowed -------------------------------------------
    $zero = product_update($admin, [
        'id' => $productId,
        'sku' => 'SKU-EDIT-1',
        'name' => 'Cordless Drill',
        'category' => 'Power tools',
        'selling_price' => '0',
    ]);
    check('a zero selling price is accepted', ($zero['ok'] ?? false) === true && (int)$zero['selling_price'] === 0);

    // --- Wiring -----------------------------------------------------------
    check('product editing is limited to admins and managers', preg_match("/update_product'\\s*\\)\\s*\\{\\s*\\\$user\\s*=\\s*require_role\\(\\['ADMIN','MANAGER'\\]\\)/", (string)file_get_contents(__DIR__ . '/../api.php')) === 1);
    check('the product edit helper is loaded by the API', str_contains((string)file_get_contents(__DIR__ . '/../api.php'), "lib/products.php"));
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
