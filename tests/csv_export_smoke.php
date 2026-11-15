<?php
declare(strict_types=1);

/**
 * CSV export smoke test.
 *
 * Covers live data, full record sets, report periods, and cost visibility.
 *
 * Run: php -c .\php-runtime.ini tests\csv_export_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_csv_export_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/analytics.php';
require_once __DIR__ . '/../lib/cashflow.php';
require_once __DIR__ . '/../lib/exports.php';

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
    echo "FAIL  {$name}" . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
}

function parse_csv_export(string $csv): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
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

    $date = date('Y-m-d');
    $pdo->prepare('INSERT INTO suppliers(name, phone, email, kra_pin, balance) VALUES(?,?,?,?,?)')
        ->execute(['CSV Supplier', '254700000001', 'supplier@example.test', 'SUP123', 12345]);
    $supplierId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO customers(name, phone, email, kra_pin, balance) VALUES(?,?,?,?,?)')
        ->execute(['=1+1', '254700000002', 'customer@example.test', 'CUS123', 23456]);
    $customerId = (int)$pdo->lastInsertId();
    for ($i = 0; $i < 300; $i++) {
        $pdo->prepare('INSERT INTO customers(name) VALUES(?)')->execute(['CSV customer ' . $i]);
    }
    $pdo->prepare('INSERT INTO products(sku, barcode, name, category, brand, buying_price, selling_price, quantity, reorder_level, supplier_id, supplied_quantity) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['CSV-1', '1001', 'CSV Product', 'Test', 'Brand', 10000, 15000, 7, 2, $supplierId, 20]);
    $productId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO quotations(quote_no, customer_name, customer_kra_pin, subtotal, tax, discount, total, status, valid_until, created_by) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute(['QT-CSV-1', 'CSV Customer', 'KRA-1', 15000, 2000, 1000, 14000, 'DRAFT', $date, (int)$admin['id']]);
    $quoteId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
        ->execute([$quoteId, $productId, 'CSV Product', 1, 15000, 15000]);

    $pdo->prepare('INSERT INTO expenses(title, category, amount, paid_by, created_at) VALUES(?,?,?,?,?)')
        ->execute(['CSV expense', 'Test', 12500, 'Cashier', $date . ' 09:00:00']);
    $pdo->prepare('INSERT INTO cash_transactions(type, category, description, amount, reference, created_by, created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(['INCOME', 'Test', 'CSV movement', 5000, 'CSV-REF', (int)$admin['id'], $date . ' 10:00:00']);
    $pdo->prepare('INSERT INTO sales(receipt_no, customer_name, payment_method, subtotal, tax, discount, total, created_by, created_at, status, vat_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['RCP-CSV-1', 'CSV Customer', 'Cash', 30000, 4000, 0, 30000, (int)$admin['id'], $date . ' 11:00:00', 'COMPLETED', 4000]);

    $customerExport = parse_csv_export(csv_export_file($admin, 'customers')['csv']);
    check('customer export includes every current record beyond the 300-row UI limit', count($customerExport) === 303, (string)count($customerExport));
    $customerNames = array_column(array_slice($customerExport, 1), 1);
    check('customer export escapes spreadsheet formulas', in_array("'=1+1", $customerNames, true));

    $supplierExport = parse_csv_export(csv_export_file($admin, 'suppliers')['csv']);
    check('supplier export includes current records', count($supplierExport) === 2);
    check('supplier export uses major currency units', (float)($supplierExport[1][5] ?? 0) === 123.45);

    $adminProducts = parse_csv_export(csv_export_file($admin, 'products')['csv']);
    $skuColumn = array_search('sku', $adminProducts[0], true);
    $buyingColumn = array_search('buying_price', $adminProducts[0], true);
    $productRow = null;
    foreach (array_slice($adminProducts, 1) as $record) {
        if (($record[$skuColumn] ?? '') === 'CSV-1') {
            $productRow = $record;
            break;
        }
    }
    check('product export includes current stock and buying price for cost viewers', $buyingColumn !== false && (float)($productRow[$buyingColumn] ?? 0) === 100.0);
    $manager = ['username' => 'manager', 'role' => 'MANAGER'];
    $managerProducts = parse_csv_export(csv_export_file($manager, 'products')['csv']);
    check('product export does not expose buying price without cost permission', !in_array('buying_price', $managerProducts[0], true));

    $quoteExport = parse_csv_export(csv_export_file($admin, 'quotations')['csv']);
    check('quotation export includes current quote and line details', count($quoteExport) === 2 && ($quoteExport[1][1] ?? '') === 'QT-CSV-1' && ($quoteExport[1][13] ?? '') === 'CSV Product');
    check('quotation export formats monetary amounts in major units', (float)($quoteExport[1][10] ?? 0) === 140.0 && (float)($quoteExport[1][15] ?? 0) === 150.0);

    $cashExport = parse_csv_export(csv_export_file($admin, 'cash_flow', ['period' => 'day', 'date' => $date])['csv']);
    check('cash-flow export reflects the selected period and current entries', count($cashExport) >= 5 && in_array('CSV expense', array_column($cashExport, 5), true) && in_array('CSV movement', array_column($cashExport, 5), true));

    $analyticsExport = parse_csv_export(csv_export_file($admin, 'analytics', ['period' => 'today'])['csv']);
    $analyticsValues = array_column(array_slice($analyticsExport, 1), 1, 0);
    check('analytics export reflects a fresh report for its selected period', ($analyticsValues['period.key'] ?? '') === 'today' && (float)($analyticsValues['sales.revenue'] ?? 0) === 300.0);
    $managerAnalytics = parse_csv_export(csv_export_file($manager, 'analytics', ['period' => 'today'])['csv']);
    $managerAnalyticsValues = array_column(array_slice($managerAnalytics, 1), 1, 0);
    check('analytics export respects cost visibility', array_key_exists('sales.gross_profit', $managerAnalyticsValues) && ($managerAnalyticsValues['sales.gross_profit'] ?? '') === '');

    $unsupportedRejected = false;
    try {
        csv_export_file($admin, 'users');
    } catch (InvalidArgumentException $e) {
        $unsupportedRejected = str_contains($e->getMessage(), 'supported');
    }
    check('unsupported exports are rejected', $unsupportedRejected);

    $api = (string)file_get_contents(__DIR__ . '/../api.php');
    $index = (string)file_get_contents(__DIR__ . '/../public/index.php');
    check('CSV export endpoint is limited to admins and managers', preg_match("/if \\(\\\$action === 'export_csv'\\) \\{\\s+\\\$user = require_role\\(\\['ADMIN','MANAGER'\\]\\);/", $api) === 1);
    check('admin page provides all six requested CSV exports', count(array_filter(
        ['cash_flow', 'analytics', 'quotations', 'customers', 'suppliers', 'products'],
        static fn(string $type): bool => str_contains($index, 'data-export-csv="' . $type . '"')
    )) === 6);
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
