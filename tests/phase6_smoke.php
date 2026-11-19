<?php
declare(strict_types=1);

/**
 * Phase 6 smoke test: analytics follow-ups G1–G8.
 *
 * G1 cost snapshots on sale lines, G2 branch capture, G3 credit sales and
 * customer payments with FIFO allocation and ageing, G4 product-linked
 * returns, G5 purchase orders with receiving, G6 cash shift reconciliation,
 * G7 the extended payment methods, and G8 named-customer checkout capture.
 *
 * Run: php -c .\php-runtime.ini tests\phase6_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_phase6_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';
require_once __DIR__ . '/../lib/analytics.php';
require_once __DIR__ . '/../lib/stock.php';
require_once __DIR__ . '/../lib/credit.php';
require_once __DIR__ . '/../lib/returns.php';
require_once __DIR__ . '/../lib/purchases.php';
require_once __DIR__ . '/../lib/shifts.php';
require_once __DIR__ . '/../lib/reset.php';

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

    // Seed data: seeded products 1 Cement 68000/79000, 2 Wire 210000/245000,
    // 3 PVC 24000/32000, 4 Nails 18000/26000. Admin user id 1 in branch Main.
    $admin = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    check('seed admin exists', (bool)$admin, 'admin user row missing');
    $adminId = (int)$admin['id'];

    $pdo->prepare('INSERT INTO branches(name, location, phone) VALUES(?,?,?)')->execute(['Westside', 'Test Location', '']);
    $westsideId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users(username, full_name, password_hash, role, branch) VALUES(?,?,?,?,?)')
        ->execute(['westmgr', 'Westside Manager', hash_password_local('test1234'), 'MANAGER', 'Westside']);
    $westMgr = $pdo->query("SELECT * FROM users WHERE username = 'westmgr'")->fetch();

    $pdo->prepare('INSERT INTO customers(name, phone, email, balance) VALUES(?,?,?,0)')->execute(['Credit Buyer', '0700000002', 'credit@example.test']);
    $customerId = (int)$pdo->lastInsertId();

    /**
     * Insert a completed sale plus lines. $lines = [[product_id, qty, unit_price], ...].
     */
    $seedSale = static function (
        string $receipt,
        array $lines,
        string $payment = 'Cash',
        ?int $customerId = null,
        string $customerName = 'Walk-in Customer',
        int $amountPaid = -1,
        ?int $branchId = null,
        ?int $createdBy = null,
        array $costOverrides = []
    ): int {
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line[1] * $line[2];
        }
        $tax = (int)round($subtotal * 0.16 / 1.16, 0, PHP_ROUND_HALF_UP);
        $paid = $amountPaid >= 0 ? $amountPaid : $subtotal;
        $stmt = db()->prepare(
            'INSERT INTO sales(receipt_no, customer_id, customer_name, payment_method, subtotal, tax, discount, total,
                amount_paid, change_due, status, etims_status, cashier_name, created_by, created_at, paid_at,
                vat_amount, vat_rate, branch_id)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        // UTC, matching the schema default and the analytics/shift windows.
        $createdAt = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            $receipt, $customerId, $customerName, $payment, $subtotal, $tax, 0, $subtotal,
            $paid, 0, 'COMPLETED', 'PENDING', 'Tester', $createdBy ?? 1, $createdAt,
            $createdAt, $tax, 0.16, $branchId,
        ]);
        $saleId = (int)db()->lastInsertId();
        $item = db()->prepare('INSERT INTO sale_items(sale_id, product_id, product_name, quantity, unit_price, total, cost_price) VALUES(?,?,?,?,?,?,?)');
        foreach ($lines as $line) {
            $name = db()->prepare('SELECT name FROM products WHERE id = ?');
            $name->execute([$line[0]]);
            $cost = array_key_exists($line[0], $costOverrides) ? $costOverrides[$line[0]] : null;
            $item->execute([$saleId, $line[0], (string)$name->fetchColumn(), $line[1], $line[2], $line[1] * $line[2], $cost]);
        }
        return $saleId;
    };

    // ── G1: cost snapshot on sale lines ──────────────────────────────────────
    // Line with a snapshot cost uses it; a null snapshot falls back to the
    // current buying price. Cement snapshot at 70000 (bought price now 68000).
    $g1Sale = $seedSale('P6-001', [[1, 2, 79000]], 'Cash', null, 'Walk-in Customer', -1, null, null, [1 => 70000]);
    $bounds = ['start' => '2000-01-01 00:00:00', 'end' => '2999-01-01 00:00:00'];
    $profit = analytics_profit_totals($bounds);
    // line revenue 158000, cost 2*70000 with the snapshot honoured.
    check('G1 costed revenue uses the line snapshot', (int)$profit['line_revenue'] === 158000, (string)$profit['line_revenue']);
    check('G1 cost uses the snapshot not the current buying price', (int)$profit['cost'] === 140000, (string)$profit['cost']);
    $g1Fallback = $seedSale('P6-002', [[3, 1, 32000]]);
    $profit = analytics_profit_totals($bounds);
    check('G1 falls back to buying_price when no snapshot', (int)$profit['cost'] === 164000, (string)$profit['cost']);

    // ── G2: branch capture ───────────────────────────────────────────────────
    $g2Sale = $seedSale('P6-003', [[4, 1, 26000]], 'Cash', null, 'Walk-in Customer', -1, $westsideId);
    $branches = analytics_branches($bounds);
    $byBranch = [];
    foreach ($branches as $row) {
        $byBranch[$row['branch']] = $row;
    }
    check('G2 branch revenue groups by captured branch', isset($byBranch['Westside']) && (int)$byBranch['Westside']['revenue'] === 26000, json_encode(array_keys($byBranch)));
    check('G2 sales without a branch read as Unassigned', isset($byBranch['Unassigned']), 'missing Unassigned bucket');
    $api = (string)file_get_contents(__DIR__ . '/../api.php');
    check('G2 checkout resolves branch_id from the user branch', str_contains($api, "SELECT id FROM branches WHERE name = ?"), 'branch lookup missing');
    check('G2 conversion inserts branch_id', preg_match("/INSERT INTO sales\(receipt_no, customer_id, customer_name, payment_method[^\)]*branch_id\)/", $api) === 1, 'branch_id not in INSERT');

    // ── G3: credit sales, customer payments, receivables ─────────────────────
    $creditSale1 = $seedSale('P6-004', [[1, 1, 79000]], 'Credit', $customerId, 'Credit Buyer', 20000);
    $creditSale2 = $seedSale('P6-005', [[3, 2, 32000]], 'Credit', $customerId, 'Credit Buyer', 0);
    // Direct seeds bypass the checkout transaction, so apply the same balance
    // arithmetic the checkout performs for the unpaid remainder.
    $pdo->prepare('UPDATE customers SET balance = ? WHERE id = ?')->execute([(79000 - 20000) + 64000, $customerId]);
    $balance = (int)$pdo->query("SELECT balance FROM customers WHERE id = {$customerId}")->fetchColumn();
    check('G3 credit remainder lands on the customer balance', $balance === 123000, (string)$balance);

    // API checkout increments the balance from the sale (source guard).
    check('G3 checkout adds the credit remainder to the balance', str_contains($api, 'UPDATE customers SET balance = balance + ?'), 'balance update missing');
    check('G3 credit checkouts require a saved customer', str_contains($api, "Credit sales require a saved customer"), 'guard missing');

    $thrown = '';
    try {
        customer_payment_record($admin, ['customer_id' => $customerId, 'amount' => 999999999]);
    } catch (InvalidArgumentException $e) {
        $thrown = $e->getMessage();
    }
    check('G3 payment cannot exceed the balance', str_contains($thrown, 'cannot exceed'), $thrown);

    // FIFO: 40000 minor should allocate entirely to the oldest sale's due.
    $payment = customer_payment_record($admin, ['customer_id' => $customerId, 'amount' => '400', 'method' => 'Cash', 'reference' => 'PAY-1']);
    check('G3 payment returns the new balance', (int)$payment['balance'] === $balance - 40000, (string)$payment['balance']);
    check('G3 payment allocates oldest sale first', count($payment['allocations']) === 1 && (int)$payment['allocations'][0]['amount'] === 40000, json_encode($payment['allocations']));

    $paidNow = (int)$pdo->prepare('SELECT amount_paid FROM sales WHERE id = ?')->execute([$creditSale1]) ? null : null;
    $stmt = $pdo->prepare('SELECT amount_paid FROM sales WHERE id = ?');
    $stmt->execute([$creditSale1]);
    check('G3 allocation updates the sale amount_paid', (int)$stmt->fetchColumn() === 60000, 'expected 60000');

    $cashRow = $pdo->query("SELECT COUNT(*) FROM cash_transactions WHERE category = 'Customer payment'")->fetchColumn();
    check('G3 cash payment logs a cash income row', (int)$cashRow === 1, (string)$cashRow);

    // Clear the rest with a second payment: 830 = 19000 remainder on sale 1 + 64000 on sale 2.
    customer_payment_record($admin, ['customer_id' => $customerId, 'amount' => '830', 'method' => 'M-Pesa', 'reference' => 'PAY-2']);
    $stmt = $pdo->prepare('SELECT amount_paid FROM sales WHERE id = ?');
    $stmt->execute([$creditSale2]);
    check('G3 second payment flows to the next oldest sale', (int)$stmt->fetchColumn() === 64000, 'sale 2 not settled');
    $stmt = $pdo->prepare('SELECT amount_paid FROM sales WHERE id = ?');
    $stmt->execute([$creditSale1]);
    check('G3 allocation spills across sales', (int)$stmt->fetchColumn() === 79000, 'sale 1 not settled');
    $balance = (int)$pdo->query("SELECT balance FROM customers WHERE id = {$customerId}")->fetchColumn();
    check('G3 balance reaches zero after full settlement', $balance === 0, (string)$balance);

    $noBalance = '';
    try {
        customer_payment_record($admin, ['customer_id' => $customerId, 'amount' => '10']);
    } catch (InvalidArgumentException $e) {
        $noBalance = $e->getMessage();
    }
    check('G3 payment with no balance is rejected', str_contains($noBalance, 'no outstanding balance'), $noBalance);

    // Ageing: build one stale credit sale with an old created_at.
    $staleSale = $seedSale('P6-006', [[1, 1, 79000]], 'Credit', $customerId, 'Credit Buyer', 0);
    $pdo->prepare("UPDATE sales SET created_at = datetime('now', '-100 days') WHERE id = ?")->execute([$staleSale]);
    $pdo->prepare('UPDATE customers SET balance = ? WHERE id = ?')->execute([79000, $customerId]);
    $receivables = receivables_summary();
    check('G3 receivables report the outstanding balance', (int)$receivables['outstanding'] === 79000, (string)$receivables['outstanding']);
    check('G3 ageing buckets put the old sale over 90 days', (int)$receivables['ageing']['over_90'] === 79000, json_encode($receivables['ageing']));
    check('G3 debtors list carries the customer', (int)$receivables['debtors'] === 1 && (string)$receivables['debtors_list'][0]['name'] === 'Credit Buyer', json_encode($receivables['debtors_list']));

    $rejected = '';
    try {
        customer_payment_record($admin, ['customer_id' => $customerId, 'amount' => '5', 'method' => 'Credit']);
    } catch (InvalidArgumentException $e) {
        $rejected = $e->getMessage();
    }
    check('G3 customer payments cannot be Credit', str_contains($rejected, 'supported payment method'), $rejected);

    // ── G4: returns ──────────────────────────────────────────────────────────
    $stmt = $pdo->prepare('SELECT id, quantity, unit_price FROM sale_items WHERE sale_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$g1Sale]);
    $line = $stmt->fetch();
    $stockBefore = (int)$pdo->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn();
    $ret = return_record($admin, ['sale_id' => $g1Sale, 'sale_item_id' => $line['id'], 'quantity' => 1, 'reason' => 'Broken bag']);
    check('G4 return records the refund amount', (int)$ret['amount'] === (int)$line['unit_price'], (string)$ret['amount']);
    check('G4 refund defaults to the way the sale was paid', ($ret['refund_method'] ?? '') === 'Cash', json_encode($ret['refund_method'] ?? null));
    $stockAfter = (int)$pdo->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn();
    check('G4 return restocks the units', $stockAfter === $stockBefore + 1, "{$stockBefore} -> {$stockAfter}");
    $movement = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id = 1 AND reason = 'RETURN'");
    $movement->execute();
    check('G4 return writes a RETURN movement', (int)$movement->fetchColumn() === 1, 'movement missing');

    $overReturn = '';
    try {
        return_record($admin, ['sale_id' => $g1Sale, 'sale_item_id' => $line['id'], 'quantity' => 5]);
    } catch (InvalidArgumentException $e) {
        $overReturn = $e->getMessage();
    }
    check('G4 cannot return more units than remain', str_contains($overReturn, 'more units than remain'), $overReturn);

    $wrongLine = '';
    try {
        return_record($admin, ['sale_id' => $g2Sale, 'sale_item_id' => $line['id'], 'quantity' => 1]);
    } catch (InvalidArgumentException $e) {
        $wrongLine = $e->getMessage();
    }
    check('G4 rejects lines from another sale', str_contains($wrongLine, 'does not belong'), $wrongLine);

    $returnsTotals = returns_totals($bounds);
    check('G4 returns totals count the refund', (int)$returnsTotals['amount'] === (int)$line['unit_price'] && (int)$returnsTotals['units'] === 1, json_encode($returnsTotals));

    // ── G5: purchase orders ──────────────────────────────────────────────────
    $supplierId = (int)$pdo->query("INSERT INTO suppliers(name, phone, email) VALUES('Test Supplier', '', '')")->fetchColumn() ?: (int)$pdo->lastInsertId();
    $supplierRow = $pdo->query("SELECT id FROM suppliers WHERE name = 'Test Supplier'")->fetch();
    $supplierId = (int)$supplierRow['id'];
    $po = purchase_order_create($admin, [
        'supplier_id' => $supplierId,
        'items' => [
            ['product_id' => 1, 'quantity' => 10, 'unit_cost' => '670'],
            ['product_id' => 2, 'quantity' => 5, 'unit_cost' => '2080'],
        ],
        'expected_at' => date('Y-m-d', strtotime('+7 days')),
        'notes' => 'Monthly restock',
    ]);
    check('G5 purchase order creates with OPEN status', $po['ok'] === true && $po['total'] === 10 * 67000 + 5 * 208000, (string)$po['total']);
    $detail = purchase_order_detail((int)$po['id']);
    check('G5 order detail carries both lines', count($detail['items']) === 2, (string)count($detail['items']));

    $stockBefore = (int)$pdo->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn();
    $receive = purchase_order_receive($admin, ['purchase_order_id' => (int)$po['id'], 'lines' => [
        ['item_id' => (int)$detail['items'][0]['id'], 'quantity' => 6, 'buying_price' => '660'],
    ]]);
    check('G5 partial receiving marks PARTIAL', $receive['status'] === 'PARTIAL', $receive['status']);
    $stockAfter = (int)$pdo->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn();
    check('G5 receiving adds stock', $stockAfter === $stockBefore + 6, "{$stockBefore} -> {$stockAfter}");
    $poMovementStmt = $pdo->prepare("SELECT * FROM stock_movements WHERE product_id = 1 AND reference = ? AND reason = 'RESTOCK' ORDER BY id ASC LIMIT 1");
    $poMovementStmt->execute([(string)$po['po_number']]);
    $poMovement = $poMovementStmt->fetch();
    check('G5 received units write a RESTOCK movement tagged with the PO', $poMovement !== false
        && (int)$poMovement['quantity_delta'] === 6
        && (int)$poMovement['created_by'] === (int)$admin['id'],
        json_encode($poMovement));
    check('G5 receiving can update the buying price', (int)$pdo->query('SELECT buying_price FROM products WHERE id = 1')->fetchColumn() === 66000, 'buying price unchanged');
    check('G5 receiving credits the supplier balance at the effective line cost', $receive['credited'] === 6 * 66000
        && (int)$pdo->query("SELECT balance FROM suppliers WHERE id = {$supplierId}")->fetchColumn() === 6 * 66000,
        json_encode($receive));

    $overReceive = '';
    try {
        purchase_order_receive($admin, ['purchase_order_id' => (int)$po['id'], 'lines' => [
            ['item_id' => (int)$detail['items'][0]['id'], 'quantity' => 10],
        ]]);
    } catch (InvalidArgumentException $e) {
        $overReceive = $e->getMessage();
    }
    check('G5 cannot receive beyond the ordered units', str_contains($overReceive, 'more than the outstanding'), $overReceive);

    $cancelReceived = '';
    try {
        purchase_order_cancel($admin, ['purchase_order_id' => (int)$po['id']]);
    } catch (InvalidArgumentException $e) {
        $cancelReceived = $e->getMessage();
    }
    check('G5 partially received orders cannot be cancelled', str_contains($cancelReceived, 'before any units are received'), $cancelReceived);

    $finish = purchase_order_receive($admin, ['purchase_order_id' => (int)$po['id'], 'lines' => [
        ['item_id' => (int)$detail['items'][0]['id'], 'quantity' => 4],
        ['item_id' => (int)$detail['items'][1]['id'], 'quantity' => 5],
    ]]);
    check('G5 full receiving marks RECEIVED', $finish['status'] === 'RECEIVED', $finish['status']);
    $receivedAt = $pdo->prepare('SELECT received_at FROM purchase_orders WHERE id = ?');
    $receivedAt->execute([(int)$po['id']]);
    check('G5 RECEIVED orders stamp received_at', (string)$receivedAt->fetchColumn() !== '', 'no timestamp');
    check('G5 the remaining receive adds the rest of the value', $finish['credited'] === 4 * 67000 + 5 * 208000, json_encode($finish));
    check('G5 supplier balance totals every received value', (int)$pdo->query("SELECT balance FROM suppliers WHERE id = {$supplierId}")->fetchColumn() === 6 * 66000 + 4 * 67000 + 5 * 208000, 'balance mismatch');

    $upfrontPo = purchase_order_create($admin, ['supplier_id' => $supplierId, 'items' => [['product_id' => 3, 'quantity' => 2, 'unit_cost' => '150']]]);
    $upfrontDetail = purchase_order_detail((int)$upfrontPo['id']);
    $upfront = purchase_order_receive($admin, [
        'purchase_order_id' => (int)$upfrontPo['id'],
        'lines' => [['item_id' => (int)$upfrontDetail['items'][0]['id'], 'quantity' => 2]],
        'paid_upfront' => true,
    ]);
    check('G5 paid-upfront receiving skips the balance', $upfront['credited'] === 0
        && (int)$pdo->query("SELECT balance FROM suppliers WHERE id = {$supplierId}")->fetchColumn() === 6 * 66000 + 4 * 67000 + 5 * 208000,
        json_encode($upfront));

    // ── supplier credit: payments reduce the balance ────────────────────────
    $balanceBeforePayment = 6 * 66000 + 4 * 67000 + 5 * 208000;
    $pay = supplier_payment_record($admin, ['supplier_id' => $supplierId, 'amount' => '4000', 'method' => 'Cash', 'reference' => 'INV-77']);
    check('supplier payment reduces the balance', $pay['ok'] === true && (int)$pay['balance'] === $balanceBeforePayment - 400000, json_encode($pay));
    check('supplier payment writes a supplier_payments row', (int)$pdo->query("SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = {$supplierId} AND amount = 400000 AND reference = 'INV-77'")->fetchColumn() === 1, 'row missing');
    $expenseRow = $pdo->query("SELECT type, category, description FROM cash_transactions WHERE category = 'Supplier payment' ORDER BY id DESC LIMIT 1")->fetch();
    check('supplier cash payment logs a cash expense row', $expenseRow !== false && $expenseRow['type'] === 'EXPENSE' && str_contains((string)$expenseRow['description'], 'Test Supplier'), json_encode($expenseRow));

    $overPay = '';
    try {
        supplier_payment_record($admin, ['supplier_id' => $supplierId, 'amount' => '999999']);
    } catch (InvalidArgumentException $e) {
        $overPay = $e->getMessage();
    }
    check('supplier payment cannot exceed the balance', str_contains($overPay, 'cannot exceed'), $overPay);

    $pdo->query("INSERT INTO suppliers(name, phone, email) VALUES('Settled Supplier', '', '')");
    $settledSupplierId = (int)$pdo->lastInsertId();
    $noBalance = '';
    try {
        supplier_payment_record($admin, ['supplier_id' => $settledSupplierId, 'amount' => '10']);
    } catch (InvalidArgumentException $e) {
        $noBalance = $e->getMessage();
    }
    check('supplier payment with no balance is rejected', str_contains($noBalance, 'no outstanding balance'), $noBalance);

    $badMethod = '';
    try {
        supplier_payment_record($admin, ['supplier_id' => $supplierId, 'amount' => '10', 'method' => 'Credit']);
    } catch (InvalidArgumentException $e) {
        $badMethod = $e->getMessage();
    }
    check('supplier payments cannot be Credit', str_contains($badMethod, 'supported payment method'), $badMethod);

    $cancelPo = purchase_order_create($admin, ['supplier_id' => $supplierId, 'items' => [['product_id' => 3, 'quantity' => 3, 'unit_cost' => '230']]]);
    $cancelled = purchase_order_cancel($admin, ['purchase_order_id' => (int)$cancelPo['id']]);
    check('G5 untouched orders can be cancelled', $cancelled['status'] === 'CANCELLED', json_encode($cancelled));

    // ── G6: cash shifts ─────────────────────────────────────────────────────
    $cashSum = static function (int $userId, string $after): int {
        $stmt = db()->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE created_by = ? AND payment_method = 'Cash' AND status = 'COMPLETED' AND created_at > ?");
        $stmt->execute([$userId, $after]);
        return (int)$stmt->fetchColumn();
    };
    $shift = shift_open($admin, ['opening_float' => '50']);
    check('G6 shift opens with the float', $shift['ok'] === true && (int)$shift['opening_float'] === 5000, json_encode($shift));

    $already = '';
    try {
        shift_open($admin, ['opening_float' => '10']);
    } catch (InvalidArgumentException $e) {
        $already = $e->getMessage();
    }
    check('G6 one open shift per user', str_contains($already, 'already have an open'), $already);

    // Keep the window deterministic: park pre-shift rows outside it and move the
    // shift start a second back so rows inserted below land inside.
    $pdo->exec("UPDATE cash_transactions SET created_at = datetime(created_at, '-1 hour')");
    $pdo->exec("UPDATE returns SET created_at = datetime(created_at, '-1 hour')");
    $pdo->exec("UPDATE cash_shifts SET opened_at = datetime('now','-1 second') WHERE id = " . (int)$shift['shift_id']);

    // The opened_at row plus the shop's sales feed the expected figure.
    $openedAt = (string)$pdo->query('SELECT opened_at FROM cash_shifts WHERE id = ' . (int)$shift['shift_id'])->fetchColumn();
    $baseExpected = 5000 + $cashSum($adminId, $openedAt);
    $current = shift_current($admin);
    check('G6 current shift reports live expected cash', $current !== null && (int)$current['cash_expected'] === $baseExpected, json_encode($current));

    // A cash sale by the admin during the shift feeds the expected figure.
    $cashSaleId = $seedSale('P6-007', [[4, 2, 26000]], 'Cash', null, 'Walk-in Customer', -1, null, $adminId);
    $current = shift_current($admin);
    check('G6 the cashier cash sale lifts expected cash by 52000', (int)$current['cash_expected'] === $baseExpected + 52000, (string)($current['cash_expected'] ?? ''));

    // Cash paid out of the till (manual cash movement, supplier payment, ...)
    // reduces what the shift expects to count.
    $pdo->prepare("INSERT INTO cash_transactions(type, category, description, amount, reference, created_by) VALUES('EXPENSE','Supplies','Paint for the counter',7000,'EXP-1',?)")
        ->execute([$adminId]);
    $current = shift_current($admin);
    check('G6 cash expenses reduce expected cash', (int)$current['cash_expected'] === $baseExpected + 52000 - 7000
        && (int)$current['cash_out'] === 7000, json_encode($current));

    // Cash income (manual cash movement, cash customer payment) adds to the till.
    $pdo->prepare("INSERT INTO cash_transactions(type, category, description, amount, reference, created_by) VALUES('INCOME','Customer payment','Counter payment',2000,'INC-1',?)")
        ->execute([$adminId]);
    $current = shift_current($admin);
    check('G6 cash income lifts expected cash', (int)$current['cash_expected'] === $baseExpected + 52000 - 7000 + 2000
        && (int)$current['cash_in'] === 2000, json_encode($current));

    // Cash refunds paid back on returns come out of the till too.
    $refundItem = $pdo->prepare('SELECT id FROM sale_items WHERE sale_id = ? ORDER BY id LIMIT 1');
    $refundItem->execute([$cashSaleId]);
    $refundItemId = (int)$refundItem->fetchColumn();
    $refund = return_record($admin, [
        'sale_id' => $cashSaleId, 'sale_item_id' => $refundItemId, 'quantity' => 1,
        'reason' => 'Damaged bag', 'refund_method' => 'Cash',
    ]);
    check('G6 returns store the refund method', ($refund['refund_method'] ?? '') === 'Cash', json_encode($refund));
    $current = shift_current($admin);
    check('G6 cash refunds reduce expected cash', (int)$current['cash_expected'] === $baseExpected + 52000 - 7000 + 2000 - (int)$refund['amount']
        && (int)$current['cash_refunds'] === (int)$refund['amount'], json_encode($current));

    $badMethod = '';
    try {
        return_record($admin, ['sale_id' => $cashSaleId, 'sale_item_id' => $refundItemId, 'quantity' => 1, 'refund_method' => 'Bitcoin']);
    } catch (InvalidArgumentException $e) {
        $badMethod = $e->getMessage();
    }
    check('G6 unknown refund methods are rejected', str_contains($badMethod, 'valid refund method'), $badMethod);

    // A non-cash refund leaves the drawer untouched.
    return_record($admin, ['sale_id' => $cashSaleId, 'sale_item_id' => $refundItemId, 'quantity' => 1, 'reason' => 'M-Pesa refund', 'refund_method' => 'M-Pesa']);
    $current = shift_current($admin);
    check('G6 non-cash refunds stay out of expected cash', (int)$current['cash_expected'] === $baseExpected + 52000 - 7000 + 2000 - (int)$refund['amount']
        && (int)$current['cash_refunds'] === (int)$refund['amount'], json_encode($current));

    $closed = shift_close($admin, ['cash_declared' => '550']);
    $finalExpected = (int)$closed['cash_expected'];
    check('G6 close computes the variance', (int)$closed['variance'] === 55000 - $finalExpected, (string)$closed['variance']);
    check('G6 close stores expected and variance', $finalExpected === $baseExpected + 52000 - 7000 + 2000 - (int)$refund['amount'], (string)$finalExpected);
    check('G6 no open shift remains after close', shift_current($admin) === null, 'shift still open');
    $row = $pdo->prepare('SELECT status, closed_by FROM cash_shifts WHERE id = ?');
    $row->execute([(int)$closed['shift_id']]);
    $shiftRow = $row->fetch();
    check('G6 closed shift records who closed it', $shiftRow['status'] === 'CLOSED' && (int)$shiftRow['closed_by'] === $adminId, json_encode($shiftRow));
    $history = shift_history(10);
    check('G6 history lists the closed shift', count($history) === 1 && (string)$history[0]['opened_by_name'] === 'admin', json_encode(array_column($history, 'opened_by_name')));

    // A non-cash sale must not feed expected cash.
    $shift2 = shift_open($westMgr, ['opening_float' => '0']);
    $seedSale('P6-008', [[4, 1, 26000]], 'M-Pesa', null, 'Walk-in Customer', -1, null, (int)$westMgr['id']);
    $current2 = shift_current($westMgr);
    check('G6 non-cash sales stay out of expected cash', (int)$current2['cash_expected'] === 0, (string)($current2['cash_expected'] ?? ''));
    shift_close($westMgr, ['cash_declared' => '0']);

    // ── G7: payment methods ──────────────────────────────────────────────────
    check('G7 method list carries the new channels', PAYMENT_METHODS === ['Cash', 'M-Pesa', 'Card', 'Bank', 'Mobile Money', 'Voucher', 'Credit'], json_encode(PAYMENT_METHODS));
    check('G7 checkout validates against the shared list', str_contains($api, 'in_array($paymentMethod, PAYMENT_METHODS, true)'), 'allow-list missing');
    check('G7 conversion keeps every method except M-Pesa', str_contains($api, '|| $paymentMethod === \'M-Pesa\''), 'M-Pesa guard missing');
    check('G7 non-cash direct methods are fully paid', str_contains($api, 'elseif ($paymentMethod === \'Credit\')'), 'paid-logic missing');

    // ── G8: named customers ──────────────────────────────────────────────────
    check('G8 checkout accepts customer_id', str_contains($api, 'filter_var($d[\'customer_id\'], FILTER_VALIDATE_INT)'), 'customer_id not validated');
    check('G8 idempotency hash includes the customer', str_contains($api, "'customer_id' => \$customerId,"), 'hash missing customer_id');
    check('G8 checkout snapshots the saved customer name', str_contains($api, '$customerName = (string)$customer[\'name\'];'), 'snapshot missing');
    check('G8 customer picker action exists', str_contains($api, 'if ($action === \'customer_options\')'), 'action missing');

    // API gate guards.
    check('payments gated to admins and managers', preg_match("/create_customer_payment'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
    check('returns gated to admins and managers', preg_match("/create_return'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
    check('purchase order creation gated', preg_match("/create_purchase_order'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
    check('shifts open to every signed-in role', preg_match("/shift_open'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN','MANAGER','EMPLOYEE'\\]\\)/", $api) === 1);
    check('shift history gated to admins and managers', preg_match("/shift_history'\\)\\s*\\{\\s*\n\\s*require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
    check('customer payments table is listable', str_contains($api, "'customer_payments'"), 'allow-list missing customer_payments');
    check('supplier payment gated to admins and managers', preg_match("/create_supplier_payment'\\)\\s*\\{\\s*\n\\s*\\\$user = require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1, 'gate missing');
    check('supplier payments table is listable', str_contains($api, "'supplier_payments'"), 'allow-list missing supplier_payments');
    $stockLib = (string)file_get_contents(__DIR__ . '/../lib/stock.php');
    $purchasesLib = (string)file_get_contents(__DIR__ . '/../lib/purchases.php');
    check('restocking and PO receiving share stock_add_units', str_contains($stockLib, 'function stock_add_units(')
        && substr_count($purchasesLib, 'stock_add_units(') >= 1
        && !str_contains($purchasesLib, 'UPDATE products SET quantity = quantity + ?'),
        'receiving still carries its own stock SQL');
    check('purchase order items table is listable', str_contains($api, "'purchase_order_items'"), 'allow-list missing purchase_order_items');

    // Analytics payload wiring.
    $report = analytics_report($admin, ['period' => 'today']);
    check('report carries branch analytics', array_key_exists('branches', $report));
    check('report carries payment mix', array_key_exists('payment_mix', $report));
    check('report carries returns', array_key_exists('returns', $report) && array_key_exists('amount', $report['returns']));
    check('report carries receivables', array_key_exists('receivables', $report) && array_key_exists('ageing', $report['receivables']));
    check('profit line revenue reflects the seeded sales', (int)$report['sales']['transactions'] >= 6, (string)$report['sales']['transactions']);

    // Expense categories: the cash-flow dropdown writes these names, and the
    // analytics table must track every one of them.
    check('expense categories are unique and non-empty',
        count(EXPENSE_CATEGORIES) === count(array_unique(EXPENSE_CATEGORIES))
        && !in_array('', EXPENSE_CATEGORIES, true), (string)count(EXPENSE_CATEGORIES));
    $catInsert = $pdo->prepare('INSERT INTO expenses(title, category, amount, paid_by, created_at) VALUES(?,?,?,?,?)');
    foreach (EXPENSE_CATEGORIES as $category) {
        $catInsert->execute([$category . ' test', $category, 1000, 'admin', gmdate('Y-m-d H:i:s')]);
    }
    $catReport = analytics_expenses(analytics_period('today')['bounds']);
    $missingCats = array_values(array_diff(EXPENSE_CATEGORIES, array_column($catReport['by_category'], 'name')));
    check('every standard expense category is tracked', $missingCats === [], json_encode($missingCats));
    check('used categories leave the unused list', $catReport['unused'] === [], json_encode($catReport['unused']));
    $tracked = array_values(array_filter($catReport['by_category'], static fn (array $row): bool => in_array($row['name'], EXPENSE_CATEGORIES, true)));
    check('each tracked category counts its entries',
        count($tracked) === count(EXPENSE_CATEGORIES)
        && count(array_filter($tracked, static fn (array $row): bool => (int)$row['count'] === 1 && (int)$row['total'] === 1000)) === count(EXPENSE_CATEGORIES),
        json_encode(array_slice($tracked, 0, 2)));

    // Business reset runs last: it wipes the data the checks above rely on.
    // Customer and supplier payments exist by now, so a wrong delete order
    // trips the foreign keys.
    business_data_reset($admin);
    $left = static fn (string $table): int => (int)db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    check('reset clears sales and their lines', $left('sales') === 0 && $left('sale_items') === 0, $left('sales') . '/' . $left('sale_items'));
    check('reset clears payments', $left('customer_payments') === 0 && $left('supplier_payments') === 0
        && $left('cash_transactions') === 0, $left('customer_payments') . '/' . $left('supplier_payments') . '/' . $left('cash_transactions'));
    check('reset clears products, suppliers, and returns', $left('products') === 0 && $left('suppliers') === 0 && $left('returns') === 0,
        $left('products') . '/' . $left('suppliers') . '/' . $left('returns'));
    check('reset re-seeds the branch and walk-in customer', $left('branches') === 1 && $left('customers') === 1
        && (string)db()->query('SELECT name FROM customers')->fetchColumn() === 'Walk-in Customer', 'seed rows missing');
    check('reset keeps user accounts', $left('users') > 0, 'users wiped');
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
