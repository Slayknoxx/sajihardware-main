<?php
declare(strict_types=1);

/**
 * Phase 5 smoke test: role-based page access.
 *
 * The server publishes the allowed page ids at login, and the browser hides
 * anything outside that list. Employees get a reduced workspace without the
 * Dashboard or Customers & Suppliers.
 *
 * Run: php -c .\php-runtime.ini tests\phase5_smoke.php
 */

require_once __DIR__ . '/../lib.php';

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

$employee = role_pages('EMPLOYEE');
$manager = role_pages('MANAGER');
$admin = role_pages('ADMIN');

check('employees cannot open the dashboard', !in_array('dashboard', $employee, true));
check('employees cannot open customers and suppliers', !in_array('people', $employee, true));
check('employees can open sales', in_array('sales', $employee, true));
check('employees can open the product catalogue', in_array('products', $employee, true));
check('employees can open quotations', in_array('quotations', $employee, true));
check('employees can open settings', in_array('settings', $employee, true));
check('employees have a reduced workspace', count($employee) < count($admin), (string)count($employee) . ' of ' . count($admin));

check('managers keep the dashboard', in_array('dashboard', $manager, true));
check('managers keep customers and suppliers', in_array('people', $manager, true));
check('managers cannot open admin', !in_array('admin', $manager, true));

check('admins open the dashboard', in_array('dashboard', $admin, true));
check('admins open admin', in_array('admin', $admin, true));
check('admins open every configured page', $admin === array_keys(role_pages_config()) || count($admin) === count(role_pages_config()['ADMIN']));

check('unknown roles get no pages', role_pages('UNKNOWN') === []);
check('page ids are unique within a role', count($admin) === count(array_unique($admin)) && count($manager) === count(array_unique($manager)) && count($employee) === count(array_unique($employee)));
check('employee pages are a subset of admin pages', array_diff($employee, $admin) === []);

// The catalogue-only promise also relies on the API refusing product writes and
// customer/supplier reads for employees. Guard the source so those regressions
// are caught even without a running browser.
$api = (string)file_get_contents(__DIR__ . '/../api.php');
check('product creation is limited to admins and managers', preg_match("/save_product'\\s*\\)\\s*\\{\\s*require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
check('supplier creation is limited to admins and managers', preg_match("/create_supplier'\\s*\\)\\s*\\{\\s*require_role\\(\\['ADMIN','MANAGER'\\]\\)/", $api) === 1);
check('customer and supplier lists are limited to admins and managers', str_contains($api, "in_array(\$table, ['customers', 'suppliers'], true)"));

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
