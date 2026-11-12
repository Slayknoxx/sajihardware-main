<?php
declare(strict_types=1);

/**
 * Phase 2 smoke test: branding profile, CSV parsing/preview/import and the
 * password-reset helpers. Runs against an isolated temporary data directory.
 *
 * Run: php -c .\php-runtime.ini tests\phase2_smoke.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_phase2_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';
require_once __DIR__ . '/../lib/import.php';

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

    // Branding profile defaults and overrides.
    $company = company_profile();
    check('company profile has a name', $company['name'] !== '');
    check('company profile has a brand colour', (bool)preg_match('/^#[0-9a-f]{6}$/', $company['color']), $company['color']);
    settings_set('company_name', 'White Label Shop');
    settings_set('company_phone', '0700000000');
    settings_set('brand_color', '#123456');
    settings_set('receipt_footer', 'Karibu tena.');
    $company = company_profile();
    check('branding override is applied', $company['name'] === 'White Label Shop' && $company['phone'] === '0700000000' && $company['color'] === '#123456' && $company['footer'] === 'Karibu tena.');

    // CSV template.
    $template = csv_import_template('products');
    check('product template has a header', str_contains($template['csv'], 'sku') && str_contains($template['csv'], 'selling_price'));
    check('template file name is set', $template['filename'] === 'products-template.csv');

    // Supplier import used by product supplier lookup.
    $supplierCsv = "name,phone,email,kra_pin\nAcme Supplies,0711111111,acme@example.com,P051234567X\n";
    $supplierResult = csv_import_commit('suppliers', $supplierCsv, 'skip', 'test');
    check('supplier import inserts', $supplierResult['insert'] === 1, (string)$supplierResult['insert']);
    check('supplier row exists', (int)db()->query("SELECT COUNT(*) FROM suppliers WHERE name='Acme Supplies'")->fetchColumn() === 1);

    // Product preview and commit, including a quoted comma in the name.
    $productCsv = "sku,barcode,name,category,brand,buying_price,selling_price,quantity,reorder_level,warehouse,branch,supplier,vat_rate\n"
        . "IMP-1,111,\"Cement, 50kg\",Building,Bamburi,600,750,10,2,Main,Main,Acme Supplies,0.05\n"
        . "IMP-2,222,PVC Pipe,Plumbing,Generic,100,150,5,1,Main,Main,,0.16\n";
    $preview = csv_import_validate('products', $productCsv, 'skip');
    check('product preview counts rows', $preview['total'] === 2 && $preview['insert'] === 2 && $preview['errors'] === [], json_encode($preview['errors']));
    check('quoted comma is parsed', $preview['rows'][0]['summary'] === 'IMP-1 Cement, 50kg', $preview['rows'][0]['summary']);
    $inserted = csv_import_commit('products', $productCsv, 'skip', 'test');
    check('product import inserts', $inserted['insert'] === 2, (string)$inserted['insert']);
    check('product supplier was linked', (int)db()->query('SELECT supplier_id FROM products WHERE sku="IMP-1"')->fetchColumn() > 0);
    check('CSV imports product VAT rate', (float)db()->query('SELECT vat_rate FROM products WHERE sku="IMP-1"')->fetchColumn() === 0.05);

    // Duplicate handling: skip by default, update when requested.
    $skip = csv_import_commit('products', $productCsv, 'skip', 'test');
    check('duplicate products are skipped', $skip['insert'] === 0 && $skip['skip'] === 2, json_encode($skip));
    $updateCsv = "sku,name,category,selling_price,quantity\nIMP-1,\"Cement, 50kg\",Building,800,99\n";
    $updated = csv_import_commit('products', $updateCsv, 'update', 'test');
    check('existing product is updated', $updated['update'] === 1, json_encode($updated));
    $row = db()->query('SELECT selling_price, quantity FROM products WHERE sku="IMP-1"')->fetch();
    check('update changes price but preserves stock', (int)$row['selling_price'] === 80000 && (int)$row['quantity'] === 10, json_encode($row));

    // SKU matching is case-insensitive but the stored SKU casing stays intact.
    $caseCsv = "sku,name,category,selling_price\nimp-1,\"Cement, 50kg\",Building,810\n";
    $caseUpdate = csv_import_commit('products', $caseCsv, 'update', 'test');
    $row = db()->query('SELECT sku, selling_price FROM products WHERE sku="IMP-1"')->fetch();
    check('lowercase sku updates the existing product', $caseUpdate['update'] === 1 && (int)$row['selling_price'] === 81000 && $row['sku'] === 'IMP-1', json_encode([$caseUpdate, $row]));

    // Invalid row blocks the whole file.
    $productsBefore = (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $badCsv = "sku,name,category,selling_price\nIMP-3,Good,Category,10\nIMP-4,Bad,Category,not-a-number\n";
    $badPreview = csv_import_validate('products', $badCsv, 'skip');
    check('invalid price is reported', count($badPreview['errors']) === 1, json_encode($badPreview['errors']));
    $threw = false;
    try {
        csv_import_commit('products', $badCsv, 'skip', 'test');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check('invalid file is not imported', $threw && (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn() === $productsBefore);

    // Customer import.
    $customerCsv = "name,phone,email,kra_pin\n\"Shop, Ltd\",0722222222,shop@example.com,P051234567X\n";
    $customers = csv_import_commit('customers', $customerCsv, 'skip', 'test');
    check('customer import inserts', $customers['insert'] === 1, json_encode($customers));
    $customer = db()->query('SELECT name, email FROM customers WHERE phone="0722222222"')->fetch();
    check('quoted customer name is preserved', $customer['name'] === 'Shop, Ltd' && $customer['email'] === 'shop@example.com');

    // Monetary precision, stock ledger, logging and repeat initialization.
    check('KES monetary values are stored as cents', money_to_minor('12.34') === 1234 && money_minor_to_major(1234) === 12.34);
    check('API monetary values are formatted back to KES', money_json_format(['total' => 1234])['total'] === 12.34);
    check('default role permissions protect cost prices', !role_has_permission('EMPLOYEE', 'cost.view') && role_has_permission('ADMIN', 'cost.view'));
    check('daily stock ledger reconciliation passes', run_integrity_checks()['status'] === 'OK');
    app_log('phase2 smoke log', false, 'info');
    $log = file_get_contents($tmp . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log');
    $logRecord = json_decode(trim((string)$log), true);
    check('application log uses structured JSON lines', is_array($logRecord) && $logRecord['level'] === 'info' && $logRecord['message'] === 'phase2 smoke log');
    db()->prepare('DELETE FROM schema_migrations WHERE version=2')->execute();
    $adminHash = hash_password_local('Changed-admin-password');
    db()->prepare('UPDATE users SET password_hash=?, must_change_password=1 WHERE username=?')->execute([$adminHash, 'admin']);
    init_db();
    $admin = db()->query("SELECT password_hash, must_change_password FROM users WHERE username='admin'")->fetch();
    check('authentication migration restores the default admin login without forcing a change', verify_password_local('admin123', (string)$admin['password_hash']) && (int)$admin['must_change_password'] === 0);
    db()->prepare('UPDATE users SET password_hash=? WHERE username=?')->execute([$adminHash, 'admin']);
    init_db();
    $savedAdminHash = db()->query("SELECT password_hash FROM users WHERE username='admin'")->fetchColumn();
    check('default admin reset runs only once', verify_password_local('Changed-admin-password', (string)$savedAdminHash));

    // Reset helpers.
    check('temporary password is long enough', strlen(generate_temp_password()) >= 10);
    check('session_version column exists', in_array('session_version', array_column(db()->query('PRAGMA table_info(users)')->fetchAll(), 'name'), true));
} catch (Throwable $e) {
    check('phase 2 checks completed without exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
