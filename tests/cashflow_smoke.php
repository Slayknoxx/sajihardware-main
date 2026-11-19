<?php
declare(strict_types=1);

/**
 * Cash-flow ledger smoke test.
 *
 * Covers the combined expense/cash-movement listing and admin-only editing.
 *
 * Run: php -c .\php-runtime.ini tests\cashflow_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_cashflow_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/cashflow.php';

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

    $anchor = '2026-11-14';
    $pdo->prepare('INSERT INTO expenses(title, category, amount, paid_by, created_at) VALUES(?,?,?,?,?)')
        ->execute(['Diesel', 'Transport & Fuel', 150000, 'John', $anchor . ' 09:00:00']);
    $expenseId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO cash_transactions(type, category, description, amount, reference, created_by, created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(['INCOME', 'Scrap sale', 'Sold empty cartons', 50000, 'REF-1', (int)$admin['id'], $anchor . ' 10:00:00']);
    $incomeId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO cash_transactions(type, category, description, amount, reference, created_by, created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(['EXPENSE', 'Utilities', 'Water bill', 25000, 'REF-2', (int)$admin['id'], $anchor . ' 11:00:00']);
    $outflowId = (int)$pdo->lastInsertId();

    // --- Combined ledger -------------------------------------------------
    $entries = cash_flow_entries('date(?)', 'date(?)', $anchor);
    check('ledger returns every entry for the period', count($entries) === 3, (string)count($entries));
    $sources = array_column($entries, 'source');
    check('ledger includes the expense', in_array('expense', $sources, true));
    check('ledger includes cash movements', count(array_filter($sources, fn($source) => $source === 'cash_transaction')) === 2);
    check('ledger is newest first', ($entries[0]['description'] ?? '') === 'Water bill', (string)($entries[0]['description'] ?? ''));
    check('expense title is exposed as the description', ($entries[2]['description'] ?? '') === 'Diesel', (string)($entries[2]['description'] ?? ''));
    check('expense paid-by is exposed as the reference', ($entries[2]['reference'] ?? '') === 'John');
    check('manual expense keeps its chart-of-accounts category', ($entries[0]['category'] ?? '') === 'Utilities'
        && in_array($entries[0]['category'], EXPENSE_CATEGORIES, true), (string)($entries[0]['category'] ?? ''));
    $report = cash_flow_report(['period' => 'day', 'date' => $anchor]);
    check('cash-flow report uses the requested period', ($report['period'] ?? '') === 'day' && ($report['date'] ?? '') === $anchor);
    check('cash-flow report totals match the current ledger data', (int)$report['summary']['expenses'] === 175000 && (int)$report['summary']['net_cash'] === -125000);
    check('cash-flow report includes its ledger entries', count($report['entries'] ?? []) === 3);

    // --- Expense update ---------------------------------------------------
    $result = cash_entry_update($admin, [
        'source' => 'expense',
        'id' => $expenseId,
        'category' => '  Fuel  ',
        'description' => '  Diesel refill  ',
        'amount' => '1500.50',
        'reference' => '  Petrol station  ',
    ]);
    check('expense update reports success', ($result['ok'] ?? false) === true);
    check('expense category is trimmed', ($result['category'] ?? '') === 'Fuel', (string)($result['category'] ?? ''));
    check('expense title is trimmed', ($result['description'] ?? '') === 'Diesel refill');
    check('expense amount is stored in minor units', (int)($result['amount'] ?? 0) === 150050, (string)($result['amount'] ?? 0));

    $expense = $pdo->prepare('SELECT title, category, amount, paid_by FROM expenses WHERE id = ?');
    $expense->execute([$expenseId]);
    $expenseRow = $expense->fetch();
    check('expense category is updated', ($expenseRow['category'] ?? '') === 'Fuel');
    check('expense title is updated', ($expenseRow['title'] ?? '') === 'Diesel refill');
    check('expense amount is updated', (int)($expenseRow['amount'] ?? 0) === 150050);
    check('expense paid-by is updated', ($expenseRow['paid_by'] ?? '') === 'Petrol station');

    $audit = $pdo->query('SELECT action, details FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
    check('expense edit writes an audit entry', ($audit['action'] ?? '') === 'UPDATE_EXPENSE');
    check('expense audit details include the category', str_contains((string)($audit['details'] ?? ''), 'Fuel'));

    // --- Cash movement update --------------------------------------------
    $incomeResult = cash_entry_update($admin, [
        'source' => 'cash_transaction',
        'id' => $incomeId,
        'type' => 'income',
        'category' => 'Recycling',
        'description' => 'Cartons sold',
        'amount' => '600.00',
        'reference' => 'REF-10',
    ]);
    check('cash movement update reports success', ($incomeResult['ok'] ?? false) === true);
    check('cash movement type is normalised', (string)($incomeResult['source'] ?? '') === 'cash_transaction');

    $income = $pdo->prepare('SELECT type, category, description, amount, reference FROM cash_transactions WHERE id = ?');
    $income->execute([$incomeId]);
    $incomeRow = $income->fetch();
    check('cash movement type is updated', ($incomeRow['type'] ?? '') === 'INCOME');
    check('cash movement category is updated', ($incomeRow['category'] ?? '') === 'Recycling');
    check('cash movement amount is updated', (int)($incomeRow['amount'] ?? 0) === 60000);
    check('cash movement reference is updated', ($incomeRow['reference'] ?? '') === 'REF-10');

    $outflowResult = cash_entry_update($admin, [
        'source' => 'cash_transaction',
        'id' => $outflowId,
        'type' => 'INCOME',
        'category' => 'Refund',
        'description' => '',
        'amount' => '250.00',
        'reference' => '',
    ]);
    check('cash movement can change direction', ($outflowResult['ok'] ?? false) === true);
    $outflow = $pdo->prepare('SELECT type, description FROM cash_transactions WHERE id = ?');
    $outflow->execute([$outflowId]);
    $outflowRow = $outflow->fetch();
    check('cash movement direction is stored', ($outflowRow['type'] ?? '') === 'INCOME');
    check('cash movement description may be blank', ($outflowRow['description'] ?? 'x') === '');

    $cashAudit = $pdo->query('SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('cash movement edit writes an audit entry', $cashAudit === 'UPDATE_CASH_TRANSACTION');

    // --- Validation ------------------------------------------------------
    $invalidCases = [
        'unknown source' => ['source' => 'sales', 'id' => 1, 'category' => 'X', 'amount' => '1'],
        'missing source' => ['id' => 1, 'category' => 'X', 'amount' => '1'],
        'zero id' => ['source' => 'expense', 'id' => 0, 'category' => 'X', 'amount' => '1'],
        'non-numeric id' => ['source' => 'expense', 'id' => 'abc', 'category' => 'X', 'amount' => '1'],
        'missing expense' => ['source' => 'expense', 'id' => 999999, 'category' => 'X', 'amount' => '1'],
        'missing cash movement' => ['source' => 'cash_transaction', 'id' => 999999, 'category' => 'X', 'amount' => '1'],
        'blank category' => ['source' => 'expense', 'id' => $expenseId, 'category' => '   ', 'amount' => '1', 'description' => 'X'],
        'overlong category' => ['source' => 'expense', 'id' => $expenseId, 'category' => str_repeat('x', 81), 'amount' => '1', 'description' => 'X'],
        'blank expense title' => ['source' => 'expense', 'id' => $expenseId, 'category' => 'X', 'description' => '  ', 'amount' => '1'],
        'overlong description' => ['source' => 'cash_transaction', 'id' => $incomeId, 'category' => 'X', 'description' => str_repeat('x', 256), 'amount' => '1'],
        'overlong reference' => ['source' => 'expense', 'id' => $expenseId, 'category' => 'X', 'description' => 'X', 'reference' => str_repeat('x', 121), 'amount' => '1'],
        'negative amount' => ['source' => 'expense', 'id' => $expenseId, 'category' => 'X', 'description' => 'X', 'amount' => '-1'],
        'invalid amount' => ['source' => 'expense', 'id' => $expenseId, 'category' => 'X', 'description' => 'X', 'amount' => 'abc'],
        'invalid cash type' => ['source' => 'cash_transaction', 'id' => $incomeId, 'category' => 'X', 'type' => 'TRANSFER', 'amount' => '1'],
    ];
    foreach ($invalidCases as $name => $payload) {
        $rejected = false;
        try {
            cash_entry_update($admin, $payload);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        } catch (Throwable $e) {
            $rejected = false;
        }
        check('rejects ' . $name, $rejected);
    }

    $afterInvalid = $pdo->prepare('SELECT title, category, amount FROM expenses WHERE id = ?');
    $afterInvalid->execute([$expenseId]);
    $afterRow = $afterInvalid->fetch();
    check('invalid edits leave the expense unchanged', ($afterRow['title'] ?? '') === 'Diesel refill' && (int)($afterRow['amount'] ?? 0) === 150050);
    $cashAfterInvalid = $pdo->prepare('SELECT category, amount FROM cash_transactions WHERE id = ?');
    $cashAfterInvalid->execute([$incomeId]);
    $cashAfterRow = $cashAfterInvalid->fetch();
    check('invalid edits leave the cash movement unchanged', ($cashAfterRow['category'] ?? '') === 'Recycling' && (int)($cashAfterRow['amount'] ?? 0) === 60000);

    // --- API wiring ------------------------------------------------------
    $api = (string)file_get_contents(__DIR__ . '/../api.php');
    $cashflow = (string)file_get_contents(__DIR__ . '/../lib/cashflow.php');
    check('cash-flow editing is limited to admins', preg_match("/update_cash_entry'\\s*\\)\\s*\\{\\s*\\\$user\\s*=\\s*require_role\\(\\['ADMIN'\\]\\)/", $api) === 1);
    check('cash-flow response includes the ledger', str_contains($cashflow, "'entries' => cash_flow_entries(") === true);
    check('cash-flow viewing stays available to managers', preg_match("/if \\(\\\$action === 'cash_flow'\\) \\{\\s+require_role\\(\\['ADMIN','MANAGER'\\]\\);/", $api) === 1);
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
