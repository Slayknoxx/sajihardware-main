<?php
declare(strict_types=1);

/**
 * Admin record editing smoke test: the full-field product and supplier forms
 * on the Admin page.
 *
 * Covers the ADMIN-only API gates, detail loading, full-column product edits
 * (including quantity adjustments that write an ADJUSTMENT stock movement),
 * supplier edits including the balance, validation failures, duplicate SKU
 * handling, and audit trail entries.
 *
 * Run: php -c .\php-runtime.ini tests\admin_edit_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_admin_edit_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';
require_once __DIR__ . '/../lib/admin.php';

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

    $admin = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    check('seed admin exists', (bool)$admin, 'admin row missing');

    // Gates: every action must be ADMIN only in api.php.
    $api = (string)file_get_contents(__DIR__ . '/../api.php');
    check('product detail is admin-only', preg_match("/admin_product_detail'\\)\\s*\\{\\s*\n\\s*require_role\\(\\['ADMIN'\\]\\)/", $api) === 1, 'gate missing');
    check('product update is admin-only', preg_match("/admin_update_product'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN'\\]\\)/", $api) === 1, 'gate missing');
    check('supplier detail is admin-only', preg_match("/admin_supplier_detail'\\)\\s*\\{\\s*\n\\s*require_role\\(\\['ADMIN'\\]\\)/", $api) === 1, 'gate missing');
    check('supplier update is admin-only', preg_match("/admin_update_supplier'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN'\\]\\)/", $api) === 1, 'gate missing');
    check('customer detail is admin-only', preg_match("/admin_customer_detail'\\)\\s*\\{\\s*\n\\s*require_role\\(\\['ADMIN'\\]\\)/", $api) === 1, 'gate missing');
    check('customer update is admin-only', preg_match("/admin_update_customer'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN'\\]\\)/", $api) === 1, 'gate missing');
    check('admin editor lib is wired into the API', str_contains($api, "lib/admin.php"), 'require_once missing');

    // Seed a supplier and a product with a known stock level.
    $pdo->prepare('INSERT INTO suppliers(name, phone, email, kra_pin, balance) VALUES(?,?,?,?,?)')
        ->execute(['Admin Test Supplier', '0733000001', 'sup@example.test', 'A123456789B', 0]);
    $supplierId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products(sku, barcode, name, category, brand, image_path, barcode_image_path, warehouse, branch, buying_price, selling_price, quantity, reorder_level, supplier_id, supplied_quantity, vat_rate) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['AE-001', '700001', 'Admin Test Cement', 'Building', 'TestBrand', '', '', 'Main', 'Main', 68000, 79000, 10, 5, $supplierId, 10, 0.16]);
    $productId = (int)$pdo->lastInsertId();

    // ── detail loading ───────────────────────────────────────────────────────
    $detail = admin_product_detail($productId);
    check('product detail returns every column', $detail !== null
        && (int)$detail['quantity'] === 10
        && (int)$detail['supplied_quantity'] === 10
        && (int)$detail['buying_price'] === 68000
        && (int)$detail['supplier_id'] === $supplierId, json_encode($detail));
    check('supplier detail returns the balance', (fn (): bool => ((int)(admin_supplier_detail($supplierId)['balance'] ?? -1)) === 0)(), 'balance missing');

    $missing = admin_product_detail(99999);
    check('missing product detail reads as null', $missing === null, 'not null');

    // ── product update ───────────────────────────────────────────────────────
    $result = admin_product_update($admin, [
        'id' => $productId,
        'sku' => 'AE-001',
        'barcode' => '700001',
        'name' => 'Admin Test Cement Pro',
        'category' => 'Building',
        'brand' => 'TestBrand',
        'image_path' => 'images/cement.png',
        'barcode_image_path' => '',
        'warehouse' => 'Main',
        'branch' => 'Main',
        'supplier_id' => (string)$supplierId,
        'buying_price' => '700',
        'selling_price' => '800',
        'vat_rate' => '0.16',
        'quantity' => '14',
        'supplied_quantity' => '12',
        'reorder_level' => '6',
        'note' => 'stock count correction',
    ]);
    check('admin product update reports the changes', ($result['ok'] ?? false) === true && count($result['changes']) >= 5, json_encode($result['changes'] ?? []));
    $current = $pdo->query('SELECT * FROM products WHERE id = ' . $productId)->fetch();
    check('product name was updated', $current['name'] === 'Admin Test Cement Pro', (string)$current['name']);
    check('product buying price updated to minor units', (int)$current['buying_price'] === 70000, (string)$current['buying_price']);
    check('product selling price updated', (int)$current['selling_price'] === 80000, (string)$current['selling_price']);
    check('product stock quantity updated', (int)$current['quantity'] === 14, (string)$current['quantity']);
    check('product supplied quantity updated', (int)$current['supplied_quantity'] === 12, (string)$current['supplied_quantity']);
    check('product reorder level updated', (int)$current['reorder_level'] === 6, (string)$current['reorder_level']);
    check('product image path updated', $current['image_path'] === 'images/cement.png', (string)$current['image_path']);

    $movement = $pdo->prepare("SELECT quantity_delta, reason, reference FROM stock_movements WHERE product_id = ? AND reason = 'ADJUSTMENT' ORDER BY id DESC LIMIT 1");
    $movement->execute([$productId]);
    $row = $movement->fetch();
    check('quantity change writes an ADJUSTMENT movement', $row !== false && (int)$row['quantity_delta'] === 4 && $row['reference'] === 'stock count correction', json_encode($row));

    $audit = $pdo->query("SELECT details FROM audit_logs WHERE action = 'UPDATE_PRODUCT' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('product edit writes an audit entry', is_string($audit) && str_contains($audit, 'AE-001') && str_contains($audit, 'stock quantity 10 to 14'), (string)$audit);

    // No-change save writes no movement and no audit row.
    $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'UPDATE_PRODUCT'")->fetchColumn();
    $movementCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason = 'ADJUSTMENT'")->fetchColumn();
    admin_product_update($admin, [
        'id' => $productId,
        'sku' => 'AE-001',
        'barcode' => '700001',
        'name' => 'Admin Test Cement Pro',
        'category' => 'Building',
        'brand' => 'TestBrand',
        'image_path' => 'images/cement.png',
        'barcode_image_path' => '',
        'warehouse' => 'Main',
        'branch' => 'Main',
        'supplier_id' => (string)$supplierId,
        'buying_price' => '700',
        'selling_price' => '800',
        'vat_rate' => '0.16',
        'quantity' => '14',
        'supplied_quantity' => '12',
        'reorder_level' => '6',
        'note' => '',
    ]);
    check('no-change save writes no extra audit entry', (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'UPDATE_PRODUCT'")->fetchColumn() === $auditCount, 'audit changed');
    check('no-change save writes no extra movement', (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason = 'ADJUSTMENT'")->fetchColumn() === $movementCount, 'movement changed');

    // Validation failures.
    $duplicate = '';
    try {
        admin_product_update($admin, [
            'id' => $productId, 'sku' => 'SH-001', 'name' => 'Dup', 'category' => 'X',
            'buying_price' => '100', 'selling_price' => '200', 'vat_rate' => '0.16',
            'quantity' => '1', 'supplied_quantity' => '1', 'reorder_level' => '0',
        ]);
    } catch (InvalidArgumentException $e) {
        $duplicate = $e->getMessage();
    }
    check('duplicate SKU is rejected', str_contains($duplicate, 'SKU'), $duplicate);

    $negative = '';
    try {
        admin_product_update($admin, [
            'id' => $productId, 'sku' => 'AE-001', 'name' => 'Neg', 'category' => 'X',
            'buying_price' => '-5', 'selling_price' => '200', 'vat_rate' => '0.16',
            'quantity' => '1', 'supplied_quantity' => '1', 'reorder_level' => '0',
        ]);
    } catch (InvalidArgumentException $e) {
        $negative = $e->getMessage();
    }
    check('negative price is rejected', str_contains($negative, 'cannot be negative'), $negative);

    $badVat = '';
    try {
        admin_product_update($admin, [
            'id' => $productId, 'sku' => 'AE-001', 'name' => 'Vat', 'category' => 'X',
            'buying_price' => '100', 'selling_price' => '200', 'vat_rate' => '0.5x',
            'quantity' => '1', 'supplied_quantity' => '1', 'reorder_level' => '0',
        ]);
    } catch (InvalidArgumentException $e) {
        $badVat = $e->getMessage();
    }
    check('non-numeric VAT rate is rejected', str_contains($badVat, 'VAT rate'), $badVat);

    $badSupplier = '';
    try {
        admin_product_update($admin, [
            'id' => $productId, 'sku' => 'AE-001', 'name' => 'Sup', 'category' => 'X',
            'supplier_id' => '9999',
            'buying_price' => '100', 'selling_price' => '200', 'vat_rate' => '0.16',
            'quantity' => '1', 'supplied_quantity' => '1', 'reorder_level' => '0',
        ]);
    } catch (InvalidArgumentException $e) {
        $badSupplier = $e->getMessage();
    }
    check('unknown supplier is rejected', str_contains($badSupplier, 'Supplier not found'), $badSupplier);

    $unassign = admin_product_update($admin, [
        'id' => $productId, 'sku' => 'AE-001', 'name' => 'Admin Test Cement Pro', 'category' => 'Building',
        'supplier_id' => '',
        'buying_price' => '700', 'selling_price' => '800', 'vat_rate' => '0.16',
        'quantity' => '14', 'supplied_quantity' => '12', 'reorder_level' => '6',
    ]);
    check('supplier can be unassigned with an empty value', ($unassign['ok'] ?? false) === true
        && (int)$pdo->query('SELECT supplier_id FROM products WHERE id = ' . $productId)->fetchColumn() === 0
        ? true : (int)$pdo->query('SELECT supplier_id FROM products WHERE id = ' . $productId)->fetchColumn() === null, 'supplier_id not cleared');

    // ── supplier update ──────────────────────────────────────────────────────
    $supplierResult = admin_supplier_update($admin, [
        'id' => $supplierId,
        'name' => 'Admin Test Supplier Ltd',
        'phone' => '0733000002',
        'email' => 'new@example.test',
        'kra_pin' => 'A987654321C',
        'balance' => '250.50',
        'note' => 'year-end correction',
    ]);
    check('supplier update reports the changes', ($supplierResult['ok'] ?? false) === true && count($supplierResult['changes']) === 5, json_encode($supplierResult['changes'] ?? []));
    $currentSupplier = $pdo->query('SELECT * FROM suppliers WHERE id = ' . $supplierId)->fetch();
    check('supplier fields updated', $currentSupplier['name'] === 'Admin Test Supplier Ltd' && $currentSupplier['phone'] === '0733000002' && $currentSupplier['email'] === 'new@example.test', (string)$currentSupplier['name']);
    check('supplier balance stored in minor units', (int)$currentSupplier['balance'] === 25050, (string)$currentSupplier['balance']);
    check('supplier kra pin updated', $currentSupplier['kra_pin'] === 'A987654321C', (string)$currentSupplier['kra_pin']);

    $supplierAudit = $pdo->query("SELECT details FROM audit_logs WHERE action = 'UPDATE_SUPPLIER' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('supplier edit writes an audit entry', is_string($supplierAudit) && str_contains($supplierAudit, 'balance 0.00 to 250.50'), (string)$supplierAudit);

    $negativeBalance = '';
    try {
        admin_supplier_update($admin, [
            'id' => $supplierId, 'name' => 'Admin Test Supplier Ltd', 'phone' => '', 'email' => '', 'kra_pin' => '', 'balance' => '-1',
        ]);
    } catch (InvalidArgumentException $e) {
        $negativeBalance = $e->getMessage();
    }
    check('negative supplier balance is rejected', str_contains($negativeBalance, 'cannot be negative'), $negativeBalance);

    $missingSupplier = '';
    try {
        admin_supplier_update($admin, ['id' => 99999, 'name' => 'Ghost']);
    } catch (InvalidArgumentException $e) {
        $missingSupplier = $e->getMessage();
    }
    check('unknown supplier edit is rejected', str_contains($missingSupplier, 'not found'), $missingSupplier);

    $emptyName = '';
    try {
        admin_supplier_update($admin, ['id' => $supplierId, 'name' => '   ']);
    } catch (InvalidArgumentException $e) {
        $emptyName = $e->getMessage();
    }
    check('blank supplier name is rejected', str_contains($emptyName, 'required'), $emptyName);

    // ── customer update ──────────────────────────────────────────────────────
    $pdo->prepare('INSERT INTO customers(name, phone, email, kra_pin, balance) VALUES(?,?,?,?,?)')
        ->execute(['Admin Test Customer', '0744000001', 'cust@example.test', 'C123456789D', 0]);
    $customerId = (int)$pdo->lastInsertId();

    $detail = admin_customer_detail($customerId);
    check('customer detail returns every column', $detail !== null
        && $detail['name'] === 'Admin Test Customer'
        && (int)$detail['balance'] === 0, json_encode($detail));

    $customerResult = admin_customer_update($admin, [
        'id' => $customerId,
        'name' => 'Admin Test Customer Ltd',
        'phone' => '0744000002',
        'email' => 'new-cust@example.test',
        'kra_pin' => 'C987654321E',
        'balance' => '300',
        'note' => 'debt recorded at the counter',
    ]);
    check('customer update reports the changes', ($customerResult['ok'] ?? false) === true && count($customerResult['changes']) === 5, json_encode($customerResult['changes'] ?? []));
    $currentCustomer = $pdo->query('SELECT * FROM customers WHERE id = ' . $customerId)->fetch();
    check('customer fields updated', $currentCustomer['name'] === 'Admin Test Customer Ltd' && $currentCustomer['phone'] === '0744000002' && $currentCustomer['email'] === 'new-cust@example.test', (string)$currentCustomer['name']);
    check('customer balance stores credit in minor units', (int)$currentCustomer['balance'] === 30000, (string)$currentCustomer['balance']);
    check('customer kra pin updated', $currentCustomer['kra_pin'] === 'C987654321E', (string)$currentCustomer['kra_pin']);

    $customerAudit = $pdo->query("SELECT details FROM audit_logs WHERE action = 'UPDATE_CUSTOMER' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('customer edit writes an audit entry', is_string($customerAudit) && str_contains($customerAudit, 'balance 0.00 to 300.00') && str_contains($customerAudit, 'debt recorded at the counter'), (string)$customerAudit);

    // A blank balance keeps the current value; a reduce-to-zero correction works.
    $keep = admin_customer_update($admin, ['id' => $customerId, 'name' => 'Admin Test Customer Ltd']);
    check('blank balance keeps the current value', ($keep['ok'] ?? false) === true && (int)$keep['balance'] === 30000, json_encode($keep));
    $zero = admin_customer_update($admin, ['id' => $customerId, 'name' => 'Admin Test Customer Ltd', 'balance' => '0', 'note' => 'paid in full']);
    check('balance can be corrected to zero', ($zero['ok'] ?? false) === true && (int)$zero['balance'] === 0, json_encode($zero));

    $negativeCustomer = '';
    try {
        admin_customer_update($admin, ['id' => $customerId, 'name' => 'Admin Test Customer Ltd', 'balance' => '-2']);
    } catch (InvalidArgumentException $e) {
        $negativeCustomer = $e->getMessage();
    }
    check('negative customer balance is rejected', str_contains($negativeCustomer, 'cannot be negative'), $negativeCustomer);

    $missingCustomer = '';
    try {
        admin_customer_update($admin, ['id' => 99999, 'name' => 'Ghost']);
    } catch (InvalidArgumentException $e) {
        $missingCustomer = $e->getMessage();
    }
    check('unknown customer edit is rejected', str_contains($missingCustomer, 'not found'), $missingCustomer);

    $blankCustomerName = '';
    try {
        admin_customer_update($admin, ['id' => $customerId, 'name' => '']);
    } catch (InvalidArgumentException $e) {
        $blankCustomerName = $e->getMessage();
    }
    check('blank customer name is rejected', str_contains($blankCustomerName, 'required'), $blankCustomerName);
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
