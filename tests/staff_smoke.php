<?php
declare(strict_types=1);

/**
 * Employee and branch management smoke test.
 *
 * Covers editing by the staff helper and admin-only deletion.
 *
 * Run: php -c .\php-runtime.ini tests\staff_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_staff_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/staff.php';

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

    $pdo->prepare('INSERT INTO employees(name, phone, role, salary, active) VALUES(?,?,?,?,?)')
        ->execute(['Old Employee', '', 'Cashier', 0, 1]);
    $employeeId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO branches(name, location, phone, active) VALUES(?,?,?,?)')
        ->execute(['Old Branch', '', '', 1]);
    $branchId = (int)$pdo->lastInsertId();

    // --- Employee update --------------------------------------------------
    $employeeResult = staff_update($admin, [
        'table' => 'employees',
        'id' => $employeeId,
        'name' => '  Jane Wanjiku  ',
        'phone' => '0712 345 678',
        'role' => '  Supervisor  ',
        'salary' => '25000.50',
        'active' => '0',
    ]);
    check('employee update reports success', ($employeeResult['ok'] ?? false) === true);
    check('employee name is trimmed', ($employeeResult['name'] ?? '') === 'Jane Wanjiku', (string)($employeeResult['name'] ?? ''));
    check('employee role is trimmed', ($employeeResult['role'] ?? '') === 'Supervisor');
    check('employee salary is stored in minor units', (int)($employeeResult['salary'] ?? 0) === 2500050, (string)($employeeResult['salary'] ?? 0));

    $employee = $pdo->prepare('SELECT name, phone, role, salary, active FROM employees WHERE id = ?');
    $employee->execute([$employeeId]);
    $employeeRow = $employee->fetch();
    check('employee name is stored', ($employeeRow['name'] ?? '') === 'Jane Wanjiku');
    check('employee phone is stored', ($employeeRow['phone'] ?? '') === '0712 345 678');
    check('employee role is stored', ($employeeRow['role'] ?? '') === 'Supervisor');
    check('employee salary is stored', (int)($employeeRow['salary'] ?? 0) === 2500050);
    check('employee can be marked inactive', (int)($employeeRow['active'] ?? 1) === 0);

    $audit = $pdo->query('SELECT action, details FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
    check('employee edit writes an audit entry', ($audit['action'] ?? '') === 'UPDATE_EMPLOYEE');
    check('employee audit details include the name', str_contains((string)($audit['details'] ?? ''), 'Jane Wanjiku'));

    // --- Branch update ----------------------------------------------------
    $branchResult = staff_update($admin, [
        'table' => 'branches',
        'id' => $branchId,
        'name' => '  Mombasa Road  ',
        'location' => '  Nairobi  ',
        'phone' => '020 222 333',
        'active' => '1',
    ]);
    check('branch update reports success', ($branchResult['ok'] ?? false) === true);
    check('branch name is trimmed', ($branchResult['name'] ?? '') === 'Mombasa Road');
    check('branch location is trimmed', ($branchResult['location'] ?? '') === 'Nairobi');

    $branch = $pdo->prepare('SELECT name, location, phone, active FROM branches WHERE id = ?');
    $branch->execute([$branchId]);
    $branchRow = $branch->fetch();
    check('branch name is stored', ($branchRow['name'] ?? '') === 'Mombasa Road');
    check('branch location is stored', ($branchRow['location'] ?? '') === 'Nairobi');
    check('branch phone is stored', ($branchRow['phone'] ?? '') === '020 222 333');
    check('branch stays active', (int)($branchRow['active'] ?? 0) === 1);

    $branchAudit = $pdo->query('SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('branch edit writes an audit entry', $branchAudit === 'UPDATE_BRANCH');

    // Branch names stay unique.
    $pdo->prepare('INSERT INTO branches(name, location, phone, active) VALUES(?,?,?,?)')
        ->execute(['Second Branch', '', '', 1]);
    $otherBranchId = (int)$pdo->lastInsertId();
    $duplicateRejected = false;
    try {
        staff_update($admin, [
            'table' => 'branches',
            'id' => $otherBranchId,
            'name' => 'Mombasa Road',
        ]);
    } catch (InvalidArgumentException $e) {
        $duplicateRejected = str_contains($e->getMessage(), 'already exists');
    }
    check('duplicate branch names are rejected', $duplicateRejected);
    $otherBranch = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
    $otherBranch->execute([$otherBranchId]);
    check('rejected branch edit leaves the name unchanged', ($otherBranch->fetchColumn() ?: '') === 'Second Branch');

    // --- Validation -------------------------------------------------------
    $invalidCases = [
        'unknown table' => ['table' => 'users', 'id' => 1, 'name' => 'X'],
        'missing table' => ['id' => 1, 'name' => 'X'],
        'zero id' => ['table' => 'employees', 'id' => 0, 'name' => 'X'],
        'non-numeric id' => ['table' => 'employees', 'id' => 'abc', 'name' => 'X'],
        'missing employee' => ['table' => 'employees', 'id' => 999999, 'name' => 'X', 'role' => 'Cashier'],
        'missing branch' => ['table' => 'branches', 'id' => 999999, 'name' => 'X'],
        'empty name' => ['table' => 'employees', 'id' => $employeeId, 'name' => '   ', 'role' => 'Cashier'],
        'overlong name' => ['table' => 'employees', 'id' => $employeeId, 'name' => str_repeat('x', 121), 'role' => 'Cashier'],
        'overlong phone' => ['table' => 'employees', 'id' => $employeeId, 'name' => 'X', 'phone' => str_repeat('1', 41), 'role' => 'Cashier'],
        'missing employee role' => ['table' => 'employees', 'id' => $employeeId, 'name' => 'X', 'role' => '  '],
        'overlong employee role' => ['table' => 'employees', 'id' => $employeeId, 'name' => 'X', 'role' => str_repeat('x', 81)],
        'negative salary' => ['table' => 'employees', 'id' => $employeeId, 'name' => 'X', 'role' => 'Cashier', 'salary' => '-1'],
        'invalid salary' => ['table' => 'employees', 'id' => $employeeId, 'name' => 'X', 'role' => 'Cashier', 'salary' => 'abc'],
        'overlong location' => ['table' => 'branches', 'id' => $branchId, 'name' => 'X', 'location' => str_repeat('x', 121)],
    ];
    foreach ($invalidCases as $name => $payload) {
        $rejected = false;
        try {
            staff_update($admin, $payload);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        } catch (Throwable $e) {
            $rejected = false;
        }
        check('rejects ' . $name, $rejected);
    }

    $afterInvalid = $pdo->prepare('SELECT name, role, salary FROM employees WHERE id = ?');
    $afterInvalid->execute([$employeeId]);
    $afterRow = $afterInvalid->fetch();
    check('invalid edits leave the employee unchanged', ($afterRow['name'] ?? '') === 'Jane Wanjiku' && (int)($afterRow['salary'] ?? 0) === 2500050);

    // --- Deletion ---------------------------------------------------------
    $deleteEmployee = staff_delete($admin, ['table' => 'employees', 'id' => $employeeId]);
    check('employee deletion reports success', ($deleteEmployee['ok'] ?? false) === true);
    check('employee deletion returns the name', ($deleteEmployee['name'] ?? '') === 'Jane Wanjiku');
    $goneEmployee = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE id = ?');
    $goneEmployee->execute([$employeeId]);
    check('employee row is deleted', (int)$goneEmployee->fetchColumn() === 0);
    $deleteAudit = $pdo->query('SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('employee deletion writes an audit entry', $deleteAudit === 'DELETE_EMPLOYEE');

    $deleteBranch = staff_delete($admin, ['table' => 'branches', 'id' => $branchId]);
    check('branch deletion reports success', ($deleteBranch['ok'] ?? false) === true);
    $goneBranch = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE id = ?');
    $goneBranch->execute([$branchId]);
    check('branch row is deleted', (int)$goneBranch->fetchColumn() === 0);
    $branchDeleteAudit = $pdo->query('SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('branch deletion writes an audit entry', $branchDeleteAudit === 'DELETE_BRANCH');

    foreach ([
        'delete unknown table' => ['table' => 'users', 'id' => 1],
        'delete zero id' => ['table' => 'employees', 'id' => 0],
        'delete missing employee' => ['table' => 'employees', 'id' => 999999],
    ] as $name => $payload) {
        $rejected = false;
        try {
            staff_delete($admin, $payload);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        check('rejects ' . $name, $rejected);
    }

    // --- Role and API wiring ---------------------------------------------
    check('managers can open the admin staff page', in_array('admin', role_pages('MANAGER'), true));
    check('employees cannot open the admin staff page', !in_array('admin', role_pages('EMPLOYEE'), true));

    $api = (string)file_get_contents(__DIR__ . '/../api.php');
    check('staff editing is limited to admins and managers', preg_match("/update_staff'\\s*\\)\\s*\\{\\s*\\\$user\\s*=\\s*require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
    check('staff deletion is limited to admins', preg_match("/delete_staff'\\s*\\)\\s*\\{\\s*\\\$user\\s*=\\s*require_role\\(\\['ADMIN'\\]\\)/", $api) === 1);
    check('managers can create employee records too', str_contains($api, "'employees' => ['ADMIN','MANAGER'"));
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
