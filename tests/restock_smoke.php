<?php
declare(strict_types=1);

/**
 * Phase 4 smoke test: restocking updates stock and supplied quantity, writes a
 * movement and audit entry, validates input, and feeds Stock Analytics.
 *
 * Run: php -c .\php-runtime.ini tests\restock_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_restock_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';
require_once __DIR__ . '/../lib/analytics.php';
require_once __DIR__ . '/../lib/stock.php';

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
    check('an admin account exists for restocking', is_array($admin));

    $product = $pdo->query('SELECT id, sku, name, quantity, supplied_quantity, buying_price FROM products ORDER BY id LIMIT 1')->fetch();
    check('a seed product exists', is_array($product));

    $productId = (int)$product['id'];
    $previousQuantity = (int)$product['quantity'];
    $previousSupplied = (int)$product['supplied_quantity'];
    $movementsBefore = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
    $auditBefore = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

    // --- Happy path -------------------------------------------------------
    $result = stock_restock($admin, [
        'product_id' => $productId,
        'quantity' => 25,
        'reference' => 'GRN-100',
    ]);

    check('restock reports success', ($result['ok'] ?? false) === true);
    check('restock returns the previous quantity', (int)$result['previous_quantity'] === $previousQuantity, (string)$result['previous_quantity']);
    check('restock returns the new quantity', (int)$result['new_quantity'] === $previousQuantity + 25, (string)$result['new_quantity']);
    check('restock returns the added quantity', (int)$result['quantity_added'] === 25);

    $after = $pdo->prepare('SELECT quantity, supplied_quantity FROM products WHERE id = ?');
    $after->execute([$productId]);
    $afterRow = $after->fetch();
    check('live stock increased', (int)$afterRow['quantity'] === $previousQuantity + 25, (string)$afterRow['quantity']);
    check('supplied quantity increased', (int)$afterRow['supplied_quantity'] === $previousSupplied + 25, (string)$afterRow['supplied_quantity']);

    $movement = $pdo->query('SELECT * FROM stock_movements ORDER BY id DESC LIMIT 1')->fetch();
    check('a movement row was written', (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === $movementsBefore + 1);
    check('movement reason is RESTOCK', ($movement['reason'] ?? '') === 'RESTOCK');
    check('movement delta is the added quantity', (int)($movement['quantity_delta'] ?? 0) === 25);
    check('movement stores the reference', ($movement['reference'] ?? '') === 'GRN-100');
    check('movement records the user', (int)($movement['created_by'] ?? 0) === (int)$admin['id']);

    $auditRow = $pdo->query("SELECT action, details FROM audit_logs ORDER BY id DESC LIMIT 1")->fetch();
    check('an audit entry was written', (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $auditBefore + 1);
    check('audit action is RESTOCK_PRODUCT', ($auditRow['action'] ?? '') === 'RESTOCK_PRODUCT');
    check('audit details include the SKU and quantity', str_contains((string)($auditRow['details'] ?? ''), (string)$product['sku']) && str_contains((string)($auditRow['details'] ?? ''), '+25'));

    // --- Optional buying price -------------------------------------------
    $priceResult = stock_restock($admin, [
        'product_id' => $productId,
        'quantity' => 5,
        'reference' => 'GRN-101',
        'buying_price' => '123.45',
    ]);
    check('restock with a price succeeds', ($priceResult['ok'] ?? false) === true);
    $priced = $pdo->prepare('SELECT quantity, buying_price FROM products WHERE id = ?');
    $priced->execute([$productId]);
    $pricedRow = $priced->fetch();
    check('buying price is stored in minor units', (int)$pricedRow['buying_price'] === 12345, (string)$pricedRow['buying_price']);
    check('quantity also increased with the priced restock', (int)$pricedRow['quantity'] === $previousQuantity + 30, (string)$pricedRow['quantity']);

    // --- Validation -------------------------------------------------------
    $invalidCases = [
        'zero quantity' => ['product_id' => $productId, 'quantity' => 0],
        'negative quantity' => ['product_id' => $productId, 'quantity' => -3],
        'non-integer quantity' => ['product_id' => $productId, 'quantity' => 'abc'],
        'fractional quantity' => ['product_id' => $productId, 'quantity' => '2.5'],
        'missing product' => ['product_id' => 999999, 'quantity' => 1],
        'missing product id' => ['quantity' => 1],
        'overlong reference' => ['product_id' => $productId, 'quantity' => 1, 'reference' => str_repeat('x', 121)],
        'negative buying price' => ['product_id' => $productId, 'quantity' => 1, 'buying_price' => '-1'],
    ];
    foreach ($invalidCases as $name => $payload) {
        $rejected = false;
        try {
            stock_restock($admin, $payload);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        } catch (Throwable $e) {
            $rejected = false;
        }
        check('rejects ' . $name, $rejected);
    }

    $afterInvalid = $pdo->prepare('SELECT quantity FROM products WHERE id = ?');
    $afterInvalid->execute([$productId]);
    check('invalid requests do not change stock', (int)$afterInvalid->fetchColumn() === $previousQuantity + 30);
    check('invalid requests do not write movements', (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === $movementsBefore + 2);

    // --- Movement history -------------------------------------------------
    $movements = stock_recent_movements(25);
    check('recent movements are returned', count($movements) > 0);
    check('the newest movement is first', (int)($movements[0]['id'] ?? 0) > (int)($movements[1]['id'] ?? 0));
    check('movements include the product name and SKU', ($movements[0]['product_name'] ?? '') !== '' && ($movements[0]['sku'] ?? '') !== '');
    check('movements include the acting user', ($movements[0]['username'] ?? '') === (string)$admin['username']);
    check('the movement limit is clamped', count(stock_recent_movements(0)) === 1);

    // --- Role helper ------------------------------------------------------
    check('employees cannot restock', stock_can_restock('EMPLOYEE') === false);
    check('managers can restock', stock_can_restock('MANAGER') === true);
    check('admins can restock', stock_can_restock('ADMIN') === true);

    // --- Analytics --------------------------------------------------------
    $expectedCost = (int)$pdo->query('SELECT COALESCE(SUM(quantity * buying_price), 0) FROM products')->fetchColumn();
    $report = analytics_report($admin, ['period' => 'today']);
    check('analytics inventory at cost follows the restock', (int)$report['inventory']['at_cost'] === $expectedCost, (string)$report['inventory']['at_cost'] . ' vs ' . $expectedCost);
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
