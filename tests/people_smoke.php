<?php
declare(strict_types=1);

/**
 * People smoke test: customer and supplier editing.
 *
 * Run: php -c .\php-runtime.ini tests\people_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_people_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/people.php';

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

    $customer = $pdo->query('SELECT id, name, phone, email, kra_pin FROM customers ORDER BY id LIMIT 1')->fetch();
    check('a seed customer exists', is_array($customer));
    $customerId = (int)$customer['id'];

    // --- Customer update --------------------------------------------------
    $result = person_update($admin, [
        'table' => 'customers',
        'id' => $customerId,
        'name' => '  Acme Traders  ',
        'phone' => '0712 345 678',
        'email' => 'accounts@acme.test',
        'kra_pin' => 'P051234567X',
    ]);
    check('customer update reports success', ($result['ok'] ?? false) === true);
    check('customer update returns the table', ($result['table'] ?? '') === 'customers');
    check('customer update trims the name', ($result['name'] ?? '') === 'Acme Traders', (string)($result['name'] ?? ''));

    $updated = $pdo->prepare('SELECT name, phone, email, kra_pin FROM customers WHERE id = ?');
    $updated->execute([$customerId]);
    $row = $updated->fetch();
    check('customer name is stored', ($row['name'] ?? '') === 'Acme Traders', (string)($row['name'] ?? ''));
    check('customer phone is stored', ($row['phone'] ?? '') === '0712 345 678');
    check('customer email is stored', ($row['email'] ?? '') === 'accounts@acme.test');
    check('customer KRA PIN is stored', ($row['kra_pin'] ?? '') === 'P051234567X');

    $audit = $pdo->query('SELECT action, details FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
    check('customer edit writes an audit entry', ($audit['action'] ?? '') === 'UPDATE_CUSTOMER');
    check('customer audit details include the name', str_contains((string)($audit['details'] ?? ''), 'Acme Traders'));

    // --- Supplier update --------------------------------------------------
    $pdo->prepare('INSERT INTO suppliers(name, phone, email, kra_pin) VALUES(?,?,?,?)')
        ->execute(['Old Supplier', '', '', '']);
    $supplierId = (int)$pdo->lastInsertId();

    $supplierResult = person_update($admin, [
        'table' => 'suppliers',
        'id' => $supplierId,
        'name' => 'Bamburi Depot',
        'phone' => '020 222 333',
        'email' => 'orders@bamburi.test',
        'kra_pin' => 'P059876543Y',
    ]);
    check('supplier update reports success', ($supplierResult['ok'] ?? false) === true);
    $supplier = $pdo->prepare('SELECT name, phone, email, kra_pin FROM suppliers WHERE id = ?');
    $supplier->execute([$supplierId]);
    $supplierRow = $supplier->fetch();
    check('supplier name is stored', ($supplierRow['name'] ?? '') === 'Bamburi Depot');
    check('supplier email is stored', ($supplierRow['email'] ?? '') === 'orders@bamburi.test');
    $supplierAudit = $pdo->query('SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('supplier edit writes an audit entry', $supplierAudit === 'UPDATE_SUPPLIER');

    // --- Validation -------------------------------------------------------
    $invalidCases = [
        'unknown table' => ['table' => 'employees', 'id' => 1, 'name' => 'X'],
        'missing table' => ['id' => 1, 'name' => 'X'],
        'zero id' => ['table' => 'customers', 'id' => 0, 'name' => 'X'],
        'non-numeric id' => ['table' => 'customers', 'id' => 'abc', 'name' => 'X'],
        'missing record' => ['table' => 'customers', 'id' => 999999, 'name' => 'X'],
        'empty name' => ['table' => 'customers', 'id' => $customerId, 'name' => '   '],
        'overlong name' => ['table' => 'customers', 'id' => $customerId, 'name' => str_repeat('x', 121)],
        'invalid email' => ['table' => 'customers', 'id' => $customerId, 'name' => 'Acme', 'email' => 'not-an-email'],
        'overlong phone' => ['table' => 'customers', 'id' => $customerId, 'name' => 'Acme', 'phone' => str_repeat('1', 41)],
        'overlong KRA PIN' => ['table' => 'customers', 'id' => $customerId, 'name' => 'Acme', 'kra_pin' => str_repeat('1', 41)],
    ];
    foreach ($invalidCases as $name => $payload) {
        $rejected = false;
        try {
            person_update($admin, $payload);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        } catch (Throwable $e) {
            $rejected = false;
        }
        check('rejects ' . $name, $rejected);
    }

    $afterInvalid = $pdo->prepare('SELECT name, email FROM customers WHERE id = ?');
    $afterInvalid->execute([$customerId]);
    $afterRow = $afterInvalid->fetch();
    check('invalid edits leave the record unchanged', ($afterRow['name'] ?? '') === 'Acme Traders' && ($afterRow['email'] ?? '') === 'accounts@acme.test');

    // An empty email is allowed (many local suppliers have none).
    $blankEmail = person_update($admin, [
        'table' => 'suppliers',
        'id' => $supplierId,
        'name' => 'Bamburi Depot',
        'email' => '',
    ]);
    check('empty email is accepted', ($blankEmail['ok'] ?? false) === true);
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
