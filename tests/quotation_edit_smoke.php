<?php
declare(strict_types=1);

/**
 * Quotation editing smoke test.
 *
 * Covers line add/remove, quantity changes, preserved line snapshots, customer
 * name/KRA PIN updates, totals, converted-quote locking, and validation.
 *
 * Run: php -c .\php-runtime.ini tests\quotation_edit_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_quote_edit_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/quotations.php';

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

function insert_product(PDO $pdo, string $sku, string $name, int $price, float $vat): int
{
    $pdo->prepare('INSERT INTO products(sku, barcode, name, category, buying_price, selling_price, quantity, reorder_level, vat_rate) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$sku, '', $name, 'Test', 1000, $price, 50, 1, $vat]);
    return (int)$pdo->lastInsertId();
}

function insert_quote(PDO $pdo, int $userId, string $status, int $discount): int
{
    $pdo->prepare('INSERT INTO quotations(quote_no, customer_name, customer_kra_pin, subtotal, tax, discount, total, status, created_by) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute(['QT-TEST-' . bin2hex(random_bytes(3)), 'Old Customer', 'OLD-PIN', 25000, 0, $discount, 25000 - $discount, $status, $userId]);
    return (int)$pdo->lastInsertId();
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

    $productA = insert_product($pdo, 'QTA', 'Snapshot Product A', 10000, 0.16);
    $productB = insert_product($pdo, 'QTB', 'Snapshot Product B', 5000, 0.0);
    $productC = insert_product($pdo, 'QTC', 'Current Product C', 20000, 0.16);

    $quoteId = insert_quote($pdo, (int)$admin['id'], 'DRAFT', 1000);
    $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$quoteId, $productA, 'Snapshot Product A', 2, 10000, 20000]);
    $lineA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$quoteId, $productB, 'Snapshot Product B', 1, 5000, 5000]);
    $lineB = (int)$pdo->lastInsertId();

    // Existing lines keep their snapshots even after the product record changes.
    $pdo->prepare('UPDATE products SET name = ?, selling_price = ? WHERE id = ?')
        ->execute(['Renamed Product A', 12000, $productA]);

    $result = quotation_update($admin, [
        'id' => $quoteId,
        'customer_name' => '  Acme Traders  ',
        'customer_kra_pin' => 'P051234567X',
        'items' => [
            ['id' => $lineA, 'quantity' => 3],
            ['product_id' => $productC, 'quantity' => 1],
        ],
    ]);

    check('quotation update reports success', ($result['ok'] ?? false) === true);
    check('quotation update returns the quote number', str_starts_with((string)($result['quote_no'] ?? ''), 'QT-TEST-'));
    check('customer name is trimmed', ($result['customer_name'] ?? '') === 'Acme Traders', (string)($result['customer_name'] ?? ''));
    check('KRA PIN is stored', ($result['customer_kra_pin'] ?? '') === 'P051234567X');
    check('subtotal is recalculated', (int)($result['subtotal'] ?? 0) === 50000, (string)($result['subtotal'] ?? 0));
    check('total is recalculated', (int)($result['total'] ?? 0) === 49000, (string)($result['total'] ?? 0));
    check('VAT is recalculated', (int)($result['tax'] ?? 0) === 6758, (string)($result['tax'] ?? 0));
    check('item count reflects the saved lines', (int)($result['item_count'] ?? 0) === 2);

    $quote = $pdo->prepare('SELECT customer_name, customer_kra_pin, subtotal, tax, discount, total FROM quotations WHERE id = ?');
    $quote->execute([$quoteId]);
    $quoteRow = $quote->fetch();
    check('quotation row has the new customer name', ($quoteRow['customer_name'] ?? '') === 'Acme Traders');
    check('quotation row has the new KRA PIN', ($quoteRow['customer_kra_pin'] ?? '') === 'P051234567X');
    check('quotation row has the new subtotal', (int)($quoteRow['subtotal'] ?? 0) === 50000);
    check('quotation row has the new total', (int)($quoteRow['total'] ?? 0) === 49000);
    check('existing discount is preserved', (int)($quoteRow['discount'] ?? 0) === 1000);

    $items = $pdo->prepare('SELECT id, product_id, product_name, quantity, unit_price, total FROM quotation_items WHERE quotation_id = ? ORDER BY id');
    $items->execute([$quoteId]);
    $rows = $items->fetchAll();
    check('removed line is deleted', count($rows) === 2, (string)count($rows));
    check('remaining existing line keeps its snapshot name', ($rows[0]['product_name'] ?? '') === 'Snapshot Product A', (string)($rows[0]['product_name'] ?? ''));
    check('remaining existing line keeps its snapshot price', (int)($rows[0]['unit_price'] ?? 0) === 10000);
    check('remaining existing line has the new quantity', (int)($rows[0]['quantity'] ?? 0) === 3);
    check('remaining existing line total is recalculated', (int)($rows[0]['total'] ?? 0) === 30000);
    check('new line uses the current product name', ($rows[1]['product_name'] ?? '') === 'Current Product C', (string)($rows[1]['product_name'] ?? ''));
    check('new line uses the current selling price', (int)($rows[1]['unit_price'] ?? 0) === 20000);

    $audit = $pdo->query('SELECT action, details FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
    check('quotation edit writes an audit entry', ($audit['action'] ?? '') === 'UPDATE_QUOTATION');
    check('quotation audit details include the quote number', str_contains((string)($audit['details'] ?? ''), 'QT-TEST-'));

    // --- Converted quotations are locked ---------------------------------
    $convertedId = insert_quote($pdo, (int)$admin['id'], 'CONVERTED', 0);
    $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$convertedId, $productA, 'Snapshot Product A', 1, 10000, 10000]);
    $convertedRejected = false;
    try {
        quotation_update($admin, [
            'id' => $convertedId,
            'customer_name' => 'Blocked',
            'items' => [['product_id' => $productC, 'quantity' => 1]],
        ]);
    } catch (InvalidArgumentException $e) {
        $convertedRejected = str_contains($e->getMessage(), 'converted');
    }
    check('converted quotations cannot be edited', $convertedRejected);
    $stillConverted = $pdo->prepare('SELECT customer_name, status FROM quotations WHERE id = ?');
    $stillConverted->execute([$convertedId]);
    $stillConvertedRow = $stillConverted->fetch();
    check('rejected converted edit leaves the record unchanged', ($stillConvertedRow['customer_name'] ?? '') === 'Old Customer' && ($stillConvertedRow['status'] ?? '') === 'CONVERTED');

    // --- Validation -------------------------------------------------------
    $invalidCases = [
        'missing id' => ['items' => [['id' => $lineA, 'quantity' => 1]]],
        'zero id' => ['id' => 0, 'items' => [['id' => $lineA, 'quantity' => 1]]],
        'missing quotation' => ['id' => 999999, 'items' => [['product_id' => $productC, 'quantity' => 1]]],
        'empty items' => ['id' => $quoteId, 'items' => []],
        'missing items' => ['id' => $quoteId],
        'zero quantity' => ['id' => $quoteId, 'items' => [['id' => $lineA, 'quantity' => 0]]],
        'overlong quantity' => ['id' => $quoteId, 'items' => [['id' => $lineA, 'quantity' => 10001]]],
        'unknown line' => ['id' => $quoteId, 'items' => [['id' => 999999, 'quantity' => 1]]],
        'new line without product' => ['id' => $quoteId, 'items' => [['quantity' => 1]]],
        'unknown product' => ['id' => $quoteId, 'items' => [['product_id' => 999999, 'quantity' => 1]]],
        'overlong customer name' => ['id' => $quoteId, 'customer_name' => str_repeat('x', 121), 'items' => [['id' => $lineA, 'quantity' => 1]]],
        'overlong KRA PIN' => ['id' => $quoteId, 'customer_kra_pin' => str_repeat('1', 41), 'items' => [['id' => $lineA, 'quantity' => 1]]],
    ];
    foreach ($invalidCases as $name => $payload) {
        $rejected = false;
        try {
            quotation_update($admin, $payload);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        } catch (Throwable $e) {
            $rejected = false;
        }
        check('rejects ' . $name, $rejected);
    }

    // A duplicate existing line would be ambiguous, so it is refused.
    $duplicateRejected = false;
    try {
        quotation_update($admin, [
            'id' => $quoteId,
            'items' => [
                ['id' => $lineA, 'quantity' => 1],
                ['id' => $lineA, 'quantity' => 2],
            ],
        ]);
    } catch (InvalidArgumentException $e) {
        $duplicateRejected = true;
    }
    check('rejects duplicate existing lines', $duplicateRejected);

    $afterInvalid = $pdo->prepare('SELECT customer_name, subtotal FROM quotations WHERE id = ?');
    $afterInvalid->execute([$quoteId]);
    $afterInvalidRow = $afterInvalid->fetch();
    check('invalid edits leave the quotation unchanged', ($afterInvalidRow['customer_name'] ?? '') === 'Acme Traders' && (int)($afterInvalidRow['subtotal'] ?? 0) === 50000);

    // An empty customer name falls back to the default rather than a blank.
    $blankName = quotation_update($admin, [
        'id' => $quoteId,
        'customer_name' => '   ',
        'items' => [['id' => $lineA, 'quantity' => 1]],
    ]);
    check('blank customer name falls back to Walk-in Customer', ($blankName['customer_name'] ?? '') === 'Walk-in Customer');

    // Discount cannot exceed the new subtotal.
    $discountQuote = insert_quote($pdo, (int)$admin['id'], 'DRAFT', 999999);
    $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$discountQuote, $productC, 'Current Product C', 1, 20000, 20000]);
    $discountLine = (int)$pdo->lastInsertId();
    $discountResult = quotation_update($admin, [
        'id' => $discountQuote,
        'items' => [['id' => $discountLine, 'quantity' => 1]],
    ]);
    check('discount is capped at the subtotal', (int)($discountResult['discount'] ?? -1) === 20000 && (int)($discountResult['total'] ?? -1) === 0);

    // Both unprocessed and converted quotations can be removed; converted sales remain intact.
    $draftToDelete = insert_quote($pdo, (int)$admin['id'], 'DRAFT', 0);
    $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$draftToDelete, $productA, 'Snapshot Product A', 1, 10000, 10000]);
    $draftDeleteResult = quotation_delete($admin, $draftToDelete);
    $quoteCount = $pdo->prepare('SELECT COUNT(*) FROM quotations WHERE id = ?');
    $quoteCount->execute([$draftToDelete]);
    check('unprocessed quotation is deleted', ($draftDeleteResult['ok'] ?? false) === true && (int)$quoteCount->fetchColumn() === 0);
    $itemCount = $pdo->prepare('SELECT COUNT(*) FROM quotation_items WHERE quotation_id = ?');
    $itemCount->execute([$draftToDelete]);
    check('unprocessed quotation lines are deleted', (int)$itemCount->fetchColumn() === 0);

    $convertedToDelete = insert_quote($pdo, (int)$admin['id'], 'CONVERTED', 0);
    $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$convertedToDelete, $productA, 'Snapshot Product A', 1, 10000, 10000]);
    $pdo->prepare('INSERT INTO sales(receipt_no, customer_name, payment_method, subtotal, tax, total, created_by, quotation_id) VALUES(?,?,?,?,?,?,?,?)')
        ->execute(['RCP-QUOTE-DELETE', 'Old Customer', 'Cash', 10000, 0, 10000, (int)$admin['id'], $convertedToDelete]);
    $saleId = (int)$pdo->lastInsertId();
    $convertedDeleteResult = quotation_delete($admin, $convertedToDelete);
    $saleStmt = $pdo->prepare('SELECT id, quotation_id FROM sales WHERE id = ?');
    $saleStmt->execute([$saleId]);
    $saleAfterDelete = $saleStmt->fetch();
    $quoteCount->execute([$convertedToDelete]);
    check('converted quotation is deleted', ($convertedDeleteResult['ok'] ?? false) === true && (int)$quoteCount->fetchColumn() === 0);
    check('converted quotation deletion preserves its sale and clears the stale link', is_array($saleAfterDelete) && (int)$saleAfterDelete['id'] === $saleId && $saleAfterDelete['quotation_id'] === null);

    // The API must keep editing to admins and managers.
    $api = (string)file_get_contents(__DIR__ . '/../api.php');
    check('quotation editing is limited to admins and managers', preg_match("/update_quotation'\\s*\\)\\s*\\{\\s*\\\$user\\s*=\\s*require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
    check('quotation deletion requires the records.delete permission', preg_match("/delete_quotation'\\s*\\)\\s*\\{\\s*\\\$user\\s*=\\s*require_permission\\('records\\.delete'\\)/", $api) === 1);
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
