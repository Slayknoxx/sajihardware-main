<?php
declare(strict_types=1);

/**
 * Business analytics smoke test: period maths, confirmed-money filtering,
 * local-day boundaries, cost gating and the shape of the report payload.
 *
 * Run: php -c .\php-runtime.ini tests\analytics_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_analytics_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';
require_once __DIR__ . '/../lib/analytics.php';

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

$timezone = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
$utc = new DateTimeZone('UTC');
$toUtc = static function (string $localDateTime) use ($timezone, $utc): string {
    return (new DateTimeImmutable($localDateTime, $timezone))->setTimezone($utc)->format('Y-m-d H:i:s');
};

$today = new DateTimeImmutable('today', $timezone);
$yesterday = $today->modify('-1 day');
$lastMonth = $today->modify('first day of last month');
$lastYear = $today->modify('-1 year');

/**
 * Insert a sale plus its items. Returns the sale id.
 *
 * @param array<int, array{0:int,1:int,2:int}> $lines [product_id, qty, unit_price_minor]
 */
function seed_sale(
    string $receipt,
    string $createdAtUtc,
    string $status,
    array $lines,
    string $payment = 'Cash',
    string $cashier = 'Test Cashier',
    int $discount = 0,
    ?int $customerId = null,
    string $customerName = 'Walk-in Customer',
    string $etimsStatus = 'PENDING',
    float $vatRate = 0.16
): int {
    $pdo = db();
    $subtotal = 0;
    foreach ($lines as $line) {
        $subtotal += $line[1] * $line[2];
    }
    $tax = 0;
    $allocated = 0;
    $lastIndex = count($lines) - 1;
    foreach ($lines as $index => $line) {
        $lineTotal = $line[1] * $line[2];
        $lineDiscount = $index === $lastIndex
            ? $discount - $allocated
            : intdiv($discount * $lineTotal, max(1, $subtotal));
        $allocated += $lineDiscount;
        $tax += (int)round(($lineTotal - $lineDiscount) * $vatRate / (1 + $vatRate), 0, PHP_ROUND_HALF_UP);
    }
    $total = $subtotal - $discount;

    $stmt = $pdo->prepare(
        'INSERT INTO sales(receipt_no, customer_id, customer_name, payment_method, subtotal, tax, discount, total,
            amount_paid, change_due, status, mpesa_status, etims_status, cashier_name, created_by, created_at,
            paid_at, vat_amount, vat_rate)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $receipt, $customerId, $customerName, $payment, $subtotal, $tax, $discount, $total,
        $payment === 'Cash' ? $total : 0, 0, $status,
        $payment === 'M-Pesa' ? 'PENDING' : 'NOT_SENT',
        $etimsStatus, $cashier, 1, $createdAtUtc,
        $status === 'COMPLETED' ? $createdAtUtc : null, $tax, $vatRate,
    ]);
    $saleId = (int)$pdo->lastInsertId();
    $item = $pdo->prepare('INSERT INTO sale_items(sale_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)');
    foreach ($lines as $line) {
        $product = $pdo->prepare('SELECT name FROM products WHERE id = ?');
        $product->execute([$line[0]]);
        $item->execute([$saleId, $line[0], (string)$product->fetchColumn(), $line[1], $line[2], $line[1] * $line[2]]);
    }
    return $saleId;
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

    // Products seeded by init_db: 1 Cement 68000/79000, 2 Wire 210000/245000,
    // 3 PVC 24000/32000, 4 Nails 18000/26000.
    $pdo->prepare('INSERT INTO customers(name, phone, email) VALUES(?,?,?)')->execute(['Acme Ltd', '0700000001', 'acme@example.test']);
    $customerId = (int)$pdo->lastInsertId();

    // Today, local 10:00 — the main completed sale.
    seed_sale('AN-001', $toUtc($today->format('Y-m-d') . ' 10:00:00'), 'COMPLETED', [[1, 2, 79000]]);
    // Today, local 00:30 (yesterday in UTC) — proves shop-local day boundaries.
    seed_sale('AN-002', $toUtc($today->format('Y-m-d') . ' 00:30:00'), 'COMPLETED', [[4, 1, 26000]]);
    // Today, discounted sale.
    seed_sale('AN-003', $toUtc($today->format('Y-m-d') . ' 12:00:00'), 'COMPLETED', [[1, 2, 79000]], 'Cash', 'Test Cashier', 10000);
    // Today, named customer.
    seed_sale('AN-004', $toUtc($today->format('Y-m-d') . ' 13:00:00'), 'COMPLETED', [[3, 1, 32000]], 'Cash', 'Test Cashier', 0, $customerId, 'Acme Ltd');
    // Today, unpaid M-Pesa — must never count as revenue.
    seed_sale('AN-005', $toUtc($today->format('Y-m-d') . ' 14:00:00'), 'PENDING_PAYMENT', [[2, 1, 245000]], 'M-Pesa');
    // Today, voided sale.
    seed_sale('AN-006', $toUtc($today->format('Y-m-d') . ' 15:00:00'), 'VOID', [[4, 1, 26000]]);
    // Yesterday, local 23:30 — must not leak into today.
    seed_sale('AN-007', $toUtc($yesterday->format('Y-m-d') . ' 23:30:00'), 'COMPLETED', [[1, 1, 79000]]);
    // Yesterday, morning — previous-period comparison base.
    seed_sale('AN-008', $toUtc($yesterday->format('Y-m-d') . ' 10:00:00'), 'COMPLETED', [[3, 2, 32000]]);
    // Same day last month.
    seed_sale('AN-009', $toUtc($lastMonth->format('Y-m-d') . ' 11:00:00'), 'COMPLETED', [[1, 1, 79000]]);
    // Same day last year (MTD last-year comparison).
    seed_sale('AN-010', $toUtc($lastYear->format('Y-m-d') . ' 11:00:00'), 'COMPLETED', [[1, 1, 79000]]);

    $expense = $pdo->prepare('INSERT INTO expenses(title, category, amount, paid_by, created_at) VALUES(?,?,?,?,?)');
    $expense->execute(['Shop rent', 'Rent', 200000, 'admin', $toUtc($today->format('Y-m-d') . ' 09:00:00')]);
    $expense->execute(['Water', 'Utilities', 50000, 'admin', $toUtc($yesterday->format('Y-m-d') . ' 09:00:00')]);

    // --- Period maths -----------------------------------------------------
    $period = analytics_period('today');
    check('today starts and ends on the shop-local date', $period['start'] === $today->format('Y-m-d') && $period['end'] === $today->format('Y-m-d'));

    $week = analytics_period('7d');
    check('7d covers seven days', $week['days'] === 7, (string)$week['days']);

    $mtd = analytics_period('mtd');
    check('mtd starts on the first of the month', $mtd['start'] === $today->format('Y-m-01'), $mtd['start']);
    check('mtd comparison ends on the same day of the previous month', substr($mtd['comparison']['end'], 0, 7) === $today->modify('first day of last month')->format('Y-m'));

    $previousMonth = analytics_period('last_month');
    check('last_month ends on the last day of the previous month', $previousMonth['end'] === $today->modify('first day of this month')->modify('-1 day')->format('Y-m-d'), $previousMonth['end']);

    $ytd = analytics_period('ytd');
    check('ytd starts on January 1', $ytd['start'] === $today->format('Y') . '-01-01', $ytd['start']);

    $custom = analytics_period('custom', '2026-01-10', '2026-01-01');
    check('custom range swaps reversed dates', $custom['start'] === '2026-01-01' && $custom['end'] === '2026-01-10');

    $invalid = analytics_period('nonsense');
    check('unknown period falls back to mtd', $invalid['key'] === 'mtd');

    // --- Confirmed-money filtering ---------------------------------------
    $admin = ['role' => 'ADMIN'];
    $report = analytics_report($admin, ['period' => 'today']);
    check('today revenue counts completed sales only', (int)$report['sales']['revenue'] === 364000, (string)$report['sales']['revenue']);
    check('today transaction count is completed only', (int)$report['sales']['transactions'] === 4, (string)$report['sales']['transactions']);
    check('local 00:30 sale is included in today', (int)$report['sales']['revenue'] === 364000);
    check('yesterday 23:30 sale is excluded from today', (int)$report['sales']['revenue'] !== 443000);
    check('cash revenue matches completed cash sales', (int)$report['money']['cash']['revenue'] === 364000, (string)$report['money']['cash']['revenue']);
    check('unpaid M-Pesa is not revenue', (int)$report['money']['mpesa']['revenue'] === 0, (string)$report['money']['mpesa']['revenue']);
    check('pending M-Pesa is visible as exposure', (int)$report['money']['pending']['count'] === 1 && (int)$report['money']['pending']['amount'] === 245000);
    check('voids are reported separately', (int)$report['money']['voids']['count'] === 1 && (int)$report['money']['voids']['void_value'] === 26000);
    check('discounts are totalled', (int)$report['money']['discounts'] === 10000, (string)$report['money']['discounts']);
    check('average basket is revenue per completed sale', abs((float)$report['sales']['avg_basket'] - 91000.0) < 0.01, (string)$report['sales']['avg_basket']);

    // --- Comparisons ------------------------------------------------------
    check('previous period is yesterday', (int)$report['sales']['previous']['revenue'] === 143000, (string)$report['sales']['previous']['revenue']);
    check('revenue delta compares against the previous period', $report['sales']['change']['revenue_pct'] === 154.5, (string)$report['sales']['change']['revenue_pct']);
    check('zero base yields no comparison', analytics_change(500, 0) === null);

    // --- Profit gating ----------------------------------------------------
    check('admin sees gross profit', $report['sales']['gross_profit'] !== null);
    check('gross profit excludes VAT', abs((float)$report['sales']['gross_profit'] - (-207)) < 1, (string)$report['sales']['gross_profit']);
    check('admin sees inventory at cost', $report['inventory']['at_cost'] !== null && (int)$report['inventory']['at_cost'] === 14170000, (string)$report['inventory']['at_cost']);

    $employeeReport = analytics_report(['role' => 'EMPLOYEE'], ['period' => 'today']);
    check('employee report is marked restricted', $employeeReport['restricted'] === true);
    check('employee cannot see gross profit', $employeeReport['sales']['gross_profit'] === null);
    check('employee cannot see gross margin', $employeeReport['sales']['gross_margin_pct'] === null);
    check('employee cannot see inventory cost', $employeeReport['inventory']['at_cost'] === null);
    check('employee cannot see potential margin', $employeeReport['inventory']['potential_margin'] === null);

    // --- Breakdowns -------------------------------------------------------
    check('top product by units is cement', ($report['top_products']['by_units'][0]['name'] ?? '') === 'Cement 50kg');
    check('top product units are aggregated', (int)($report['top_products']['by_units'][0]['units'] ?? 0) === 4, (string)($report['top_products']['by_units'][0]['units'] ?? ''));
    check('top cashier is reported', count($report['top_cashiers']) > 0);
    check('trend has a point per day for today view', count($report['trend']['points']) === 7, (string)count($report['trend']['points']));
    check('hourly series has 24 buckets', is_array($report['hourly']) && count($report['hourly']) === 24);
    check('category breakdown includes cement', in_array('Building Materials', array_column($report['categories'], 'name'), true));
    check('new customers are counted', (int)$report['customers']['new'] === 1, (string)$report['customers']['new']);
    check('named customer appears in the top list', ($report['customers']['top'][0]['name'] ?? '') === 'Acme Ltd');
    check('walk-in sales are counted separately', (int)$report['customers']['walk_in_sales'] === 3, (string)$report['customers']['walk_in_sales']);
    check('expenses are grouped by category', ($report['expenses']['by_category'][0]['name'] ?? '') === 'Rent' && (int)$report['expenses']['total'] === 200000);
    check('VAT breakdown is present', count($report['money']['vat_breakdown']) === 1 && (int)$report['money']['vat_breakdown'][0]['vat'] === 50207);
    check('inventory low/out counts are present', $report['inventory']['low_stock'] === 0 && $report['inventory']['out_of_stock'] === 0);

    // --- Compliance -------------------------------------------------------
    check('compliance counts completed sales only', (int)$report['compliance']['pending'] === 4, (string)$report['compliance']['pending']);
    check('compliance backlog spans all pending sales', (int)$report['compliance']['backlog']['count'] === 8, (string)$report['compliance']['backlog']['count']);
    check('compliance backlog has an age', $report['compliance']['backlog']['oldest_days'] !== null);

    // --- MTD comparison to last year -------------------------------------
    $mtdReport = analytics_report($admin, ['period' => 'mtd']);
    check('mtd last-year revenue is populated', (int)$mtdReport['sales']['last_year']['revenue'] >= 79000, (string)$mtdReport['sales']['last_year']['revenue']);

    // --- Empty period -----------------------------------------------------
    $empty = analytics_report($admin, ['period' => 'custom', 'start' => '2000-01-01', 'end' => '2000-01-31']);
    check('empty period reports zero revenue', (int)$empty['sales']['revenue'] === 0);
    check('empty period has no comparison', $empty['sales']['change']['revenue_pct'] === null);
    check('empty period has an empty trend', count($empty['trend']['points']) === 31);
    check('empty period has no top products', $empty['top_products']['by_revenue'] === []);
    check('empty period has no expenses', (int)$empty['expenses']['count'] === 0);
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
