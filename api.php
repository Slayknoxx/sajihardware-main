<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/license.php';
require_once __DIR__ . '/lib/import.php';

$action = $_GET['action'] ?? '';
$timeoutMinutes = 30;
if ($action !== 'health') {
    init_db();
    $timeoutMinutes = max(5, min(1440, (int)settings_get('session_timeout_minutes', '30')));
}
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_set_cookie_params([
    'lifetime' => $timeoutMinutes * 60,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
security_send_headers();

// Every mutating request needs the session CSRF token. Login is exempt because
// no authenticated token exists until the login response creates one.
if ($action !== 'login') {
    security_require_csrf();
}

if (isset($_SESSION['user'])) {
    $lastSeen = (int)($_SESSION['last_seen'] ?? 0);
    if ($lastSeen > 0 && (time() - $lastSeen) > $timeoutMinutes * 60) {
        $_SESSION = [];
        session_unset();
        session_destroy();
    } else {
        $_SESSION['last_seen'] = time();
    }
}

try {
    if ($action === 'login') {
        security_check_origin();
        $data = input_json();
        $username = trim((string)($data['username'] ?? ''));
        $ip = security_client_ip();
        $lockedSeconds = security_login_lock_seconds($username, $ip);
        if ($lockedSeconds > 0) {
            json_response([
                'error' => 'Too many failed attempts. Try again in ' . (int)ceil($lockedSeconds / 60) . ' minute(s).',
                'code' => 'LOCKED',
            ], 429);
        }
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $password = (string)($data['password'] ?? '');
        if (!$user || !verify_password_local($password, $user['password_hash'])) {
            security_record_login($username, $ip, false);
            json_response(['error' => 'Invalid username or password'], 401);
        }
        security_record_login($username, $ip, true);

        // Prevent session fixation: issue a new ID once credentials are verified.
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'branch' => $user['branch'], 'session_version' => (int)($user['session_version'] ?? 0)];
        $_SESSION['last_seen'] = time();
        audit($user['username'], 'LOGIN');
        json_response([
            'user' => $_SESSION['user'],
            'csrf' => security_csrf_token(),
            'license' => license_status(),
            'company' => company_profile(),
            'setup_completed' => (string)(settings_get('setup_completed', '0') ?? '0') === '1',
            'permissions' => role_permissions_config()[$user['role']] ?? [],
            'version' => APP_VERSION,
        ]);
    }
    if ($action === 'logout') {
        session_destroy();
        json_response(['ok' => true]);
    }
    if ($action === 'me') {
        $me = session_current_user();
        if ($me) {
            try {
                license_checkin();
            } catch (Throwable $e) {
                app_log('Session check error: ' . $e->getMessage());
            }
        }
        json_response([
            'user' => $me,
            'company' => company_profile(),
            'csrf' => security_csrf_token(),
            'license' => license_status(),
            'version' => APP_VERSION,
            'setup_completed' => (string)(settings_get('setup_completed', '0') ?? '0') === '1',
            'permissions' => $me ? (role_permissions_config()[$me['role']] ?? []) : [],
        ]);
    }

    if ($action === 'health') {
        json_response(['ok' => true, 'mode' => 'local', 'time' => date(DATE_ATOM)]);
    }

    $user = require_login();
    if ($action === 'system_health') {
        require_role(['ADMIN']);
        $latestRun = db()->query('SELECT status, findings_json, started_at, completed_at FROM integrity_runs ORDER BY id DESC LIMIT 1')->fetch();
        $logPath = DATA_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
        $lastBackup = null;
        $backups = backup_list();
        if ($backups !== []) {
            $lastBackup = $backups[0]['created_at'];
        }
        json_response([
            'database' => strtolower((string)db()->query('PRAGMA integrity_check')->fetchColumn()) === 'ok' ? 'OK' : 'FAILED',
            'foreign_key_violations' => count(db()->query('PRAGMA foreign_key_check')->fetchAll()),
            'last_backup' => $lastBackup,
            'log_bytes' => is_file($logPath) ? (int)filesize($logPath) : 0,
            'latest_integrity_run' => $latestRun ? [
                'status' => $latestRun['status'],
                'findings' => json_decode((string)$latestRun['findings_json'], true) ?: [],
                'started_at' => $latestRun['started_at'],
                'completed_at' => $latestRun['completed_at'],
            ] : null,
        ]);
    }

    if ($action === 'save_role_settings') {
        require_role(['ADMIN']);
        $d = input_json();
        $timeout = filter_var($d['session_timeout_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($timeout === false || $timeout < 5 || $timeout > 1440) {
            json_response(['error' => 'Session timeout must be between 5 and 1440 minutes'], 400);
        }
        $current = role_permissions_config();
        $submitted = $d['permissions'] ?? null;
        if (!is_array($submitted)) {
            json_response(['error' => 'Role permissions are invalid'], 400);
        }
        foreach (['MANAGER', 'EMPLOYEE'] as $role) {
            if (!isset($submitted[$role]) || !is_array($submitted[$role])) {
                json_response(['error' => 'Role permissions are incomplete'], 400);
            }
            $limit = filter_var($submitted[$role]['discount_max_percent'] ?? null, FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 0 || $limit > 100) {
                json_response(['error' => 'Discount limits must be between 0 and 100 percent'], 400);
            }
            foreach (['sale.void', 'cost.view', 'records.delete'] as $permission) {
                if (!array_key_exists($permission, $submitted[$role]) || !is_bool($submitted[$role][$permission])) {
                    json_response(['error' => 'Role permission values must be true or false'], 400);
                }
                $current[$role][$permission] = $submitted[$role][$permission];
            }
            $current[$role]['discount_max_percent'] = $limit;
        }
        $current['ADMIN'] = ['sale.void' => true, 'cost.view' => true, 'records.delete' => true, 'discount_max_percent' => 100];
        settings_set('session_timeout_minutes', (string)$timeout);
        settings_set('role_permissions', json_encode($current, JSON_THROW_ON_ERROR));
        audit($user['username'], 'SAVE_ROLE_SETTINGS');
        json_response(['ok' => true, 'session_timeout_minutes' => $timeout, 'permissions' => $current]);
    }

    if ($action === 'admin_settings') {
        require_role(['ADMIN']);
        json_response([
            'session_timeout_minutes' => (int)(settings_get('session_timeout_minutes', '30') ?? 30),
            'permissions' => role_permissions_config(),
        ]);
    }

    if ($action === 'audit_list') {
        require_role(['ADMIN']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
        $search = trim((string)($_GET['search'] ?? ''));
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE actor LIKE ? OR action LIKE ? OR details LIKE ?';
            $term = '%' . $search . '%';
            $params = [$term, $term, $term];
        }
        $count = db()->prepare('SELECT COUNT(*) FROM audit_logs' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $query = db()->prepare('SELECT id, actor, action, details, created_at FROM audit_logs' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
        $query->execute(array_merge($params, [$limit, ($page - 1) * $limit]));
        json_response(['rows' => $query->fetchAll(), 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / $limit)]);
    }

    integrity_check_if_due();

    // Safety net: keep taking automatic backups even if the license has lapsed.
    backup_auto_maybe();

    // Actions that must remain reachable while unlicensed so an admin can activate.
    $licenseExempt = ['license_status', 'license_activate', 'license_deactivate', 'save_setup', 'reset_user_password', 'admin_settings', 'save_role_settings', 'save_settings', 'backup', 'backup_list', 'backup_download', 'backup_delete', 'backup_restore'];
    if (!in_array($action, $licenseExempt, true)) {
        license_require();
    }

    if ($action === 'license_status') {
        json_response(['license' => license_status(), 'version' => APP_VERSION]);
    }

    if ($action === 'license_activate') {
        require_role(['ADMIN']);
        $d = input_json();
        try {
            $status = license_activate((string)($d['key'] ?? ''));
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        }
        json_response(['ok' => true, 'license' => $status]);
    }

    if ($action === 'license_deactivate') {
        require_role(['ADMIN']);
        license_deactivate();
        json_response(['ok' => true, 'license' => license_status()]);
    }

    if ($action === 'dashboard') {
        $pdo = db();
        $today = date('Y-m-d');
        $sales = $pdo->query("SELECT COALESCE(SUM(total),0) total, COUNT(*) count FROM sales WHERE status='COMPLETED' AND date(created_at)=date('now')")->fetch();
        $month = $pdo->query("SELECT COALESCE(SUM(total),0) total FROM sales WHERE status='COMPLETED' AND strftime('%Y-%m', created_at)=strftime('%Y-%m','now')")->fetch();
        $low = $pdo->query('SELECT COUNT(*) FROM products WHERE quantity <= reorder_level')->fetchColumn();
        $stock = $pdo->query('SELECT COALESCE(SUM(quantity * buying_price),0) value FROM products')->fetchColumn();
        $recent = $pdo->query("SELECT receipt_no, customer_name, payment_method, total, created_at FROM sales WHERE status='COMPLETED' AND date(created_at)=date('now') ORDER BY id DESC LIMIT 8")->fetchAll();
        json_response([
            'today' => $sales,
            'month_total' => $month['total'],
            'low_stock' => $low,
            'stock_value' => role_has_permission((string)$user['role'], 'cost.view') ? $stock : null,
            'recent_sales' => $recent,
        ]);
    }

    if ($action === 'list') {
        $table = $_GET['table'] ?? '';
        $allowed = ['products','customers','suppliers','employees','branches','held_sales','returns','expenses','cash_transactions','quotations','purchase_orders','sync_queue','audit_logs','users','sales','sale_items'];
        if (!in_array($table, $allowed, true)) {
            json_response(['error' => 'Unsupported table'], 400);
        }
        if ($table === 'users') {
            require_role(['ADMIN']);
            $rows = db()->query('SELECT id, username, full_name, role, branch, active, created_at FROM users ORDER BY id DESC')->fetchAll();
        } elseif ($table === 'products') {
            $rows = db()->query('SELECT p.*, COALESCE(s.name, "Unassigned") supplier_name FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 300')->fetchAll();
            if (!role_has_permission((string)$user['role'], 'cost.view')) {
                foreach ($rows as &$row) {
                    unset($row['buying_price']);
                }
                unset($row);
            }
        } elseif ($table === 'quotations') {
            $rows = db()->query('SELECT q.id, q.quote_no, q.customer_name, q.subtotal, q.tax, q.discount, q.total, q.status, q.valid_until, q.converted_sale_id, q.created_at, s.receipt_no FROM quotations q LEFT JOIN sales s ON s.id=q.converted_sale_id ORDER BY q.id DESC LIMIT 300')->fetchAll();
        } else {
            if ($table === 'audit_logs') {
                require_role(['ADMIN']);
            }
            $query = $table === 'sales'
                ? "SELECT * FROM sales WHERE status='COMPLETED' ORDER BY id DESC LIMIT 300"
                : "SELECT * FROM {$table} ORDER BY id DESC LIMIT 300";
            $rows = db()->query($query)->fetchAll();
        }
        json_response(['rows' => $rows]);
    }

    if ($action === 'delete_person') {
        require_permission('records.delete');
        $d = input_json();
        $table = ($d['table'] ?? '') === 'suppliers' ? 'suppliers' : (($d['table'] ?? '') === 'customers' ? 'customers' : '');
        $id = (int)($d['id'] ?? 0);
        if (!$table || $id < 1) json_response(['error' => 'A valid customer or supplier is required'], 400);
        $stmt = db()->prepare("SELECT name FROM {$table} WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_response(['error' => 'Record not found'], 404);
        if ($table === 'customers') {
            $stmt = db()->prepare('SELECT COUNT(*) FROM sales WHERE customer_id=?');
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) json_response(['error' => 'Customer has sales and cannot be deleted'], 409);
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id=?');
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) json_response(['error' => 'Supplier has purchase orders and cannot be deleted'], 409);
        }
        db()->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        audit($user['username'], 'DELETE_' . strtoupper($table), $row['name']);
        json_response(['ok' => true]);
    }

    if ($action === 'cash_flow') {
        require_role(['ADMIN','MANAGER']);
        $d = input_json();
        $period = in_array($d['period'] ?? 'month', ['day','month','year'], true) ? $d['period'] : 'month';
        $anchor = trim((string)($d['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) $anchor = date('Y-m-d');
        $formats = ['day' => ["date(?)", "date(?)", "strftime('%H:00', created_at)"], 'month' => ["strftime('%Y-%m', ?)", "strftime('%Y-%m', ?)", "strftime('%d', created_at)"], 'year' => ["strftime('%Y', ?)", "strftime('%Y', ?)", "strftime('%m', created_at)"]];
        [$startExpr, $endExpr, $bucketFormat] = $formats[$period];
        $start = $period === 'day' ? $anchor : ($period === 'month' ? substr($anchor, 0, 7) : substr($anchor, 0, 4));
        $salesWhere = "status='COMPLETED' AND {$startExpr} = {$endExpr}";
        $salesStmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN payment_method='Cash' THEN total ELSE 0 END),0) cash_sales, COALESCE(SUM(total),0) total_sales FROM sales WHERE {$salesWhere}");
        $salesStmt->execute([$anchor, $anchor]);
        $summary = $salesStmt->fetch();
        $expenseStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) total_expenses FROM expenses WHERE {$startExpr} = {$endExpr}");
        $expenseStmt->execute([$anchor, $anchor]);
        $expenses = $expenseStmt->fetch();
        $movementStmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='INCOME' THEN amount ELSE 0 END),0) other_income, COALESCE(SUM(CASE WHEN type='EXPENSE' THEN amount ELSE 0 END),0) other_expenses FROM cash_transactions WHERE {$startExpr} = {$endExpr}");
        $movementStmt->execute([$anchor, $anchor]);
        $movements = $movementStmt->fetch();
        $seriesStmt = db()->prepare("SELECT bucket, COALESCE(SUM(income),0) income, COALESCE(SUM(expenses),0) expenses FROM (SELECT {$bucketFormat} bucket, CASE WHEN payment_method='Cash' THEN total ELSE 0 END income, 0 expenses FROM sales WHERE {$salesWhere} UNION ALL SELECT {$bucketFormat} bucket, 0 income, amount expenses FROM expenses WHERE {$startExpr} = {$endExpr} UNION ALL SELECT {$bucketFormat} bucket, CASE WHEN type='INCOME' THEN amount ELSE 0 END income, CASE WHEN type='EXPENSE' THEN amount ELSE 0 END expenses FROM cash_transactions WHERE {$startExpr} = {$endExpr}) GROUP BY bucket ORDER BY bucket");
        $seriesParams = array_merge([$anchor, $anchor], [$anchor, $anchor], [$anchor, $anchor]);
        $seriesStmt->execute($seriesParams);
        $series = $seriesStmt->fetchAll();
        $totalExpenses = (float)$expenses['total_expenses'] + (float)$movements['other_expenses'];
        $totalIncome = (float)$summary['cash_sales'] + (float)$movements['other_income'];
        json_response(['period' => $period, 'date' => $anchor, 'summary' => ['cash_sales' => $summary['cash_sales'], 'total_sales' => $summary['total_sales'], 'expenses' => $totalExpenses, 'other_income' => $movements['other_income'], 'other_expenses' => $movements['other_expenses'], 'net_cash' => $totalIncome - $totalExpenses], 'series' => $series]);
    }

    if ($action === 'reset_business_data') {
        require_role(['ADMIN']);
        $d = input_json();
        if (($d['confirmation'] ?? '') !== 'RESET BUSINESS DATA') {
            json_response(['error' => 'Type RESET BUSINESS DATA to confirm'], 400);
        }
        $pdo = db();
        $pdo->beginTransaction();
        foreach (['payment_events','payments','checkout_requests','sync_queue','quotation_items','quotations','sale_items','sales','stock_movements','held_sales','returns','cash_transactions','expenses','purchase_orders','products','customers','suppliers','employees','branches','cash_shifts'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
        $pdo->prepare('INSERT INTO branches(name, location, phone) VALUES(?,?,?)')->execute(['Main', COMPANY_LOCATION, '']);
        $pdo->prepare('INSERT INTO customers(name, phone, email) VALUES(?,?,?)')->execute(['Walk-in Customer', '', COMPANY_EMAIL]);
        audit($user['username'], 'RESET_BUSINESS_DATA', 'Business data reset; user accounts preserved');
        $pdo->commit();
        json_response(['ok' => true]);
    }

    if ($action === 'etims_summary') {
        require_role(['ADMIN','MANAGER']);
        $rows = db()->query("SELECT etims_status, COUNT(*) count, COALESCE(SUM(total),0) total FROM sales WHERE status='COMPLETED' GROUP BY etims_status")->fetchAll();
        $rates = array_map('floatval', array_column(db()->query('SELECT DISTINCT vat_rate FROM products ORDER BY vat_rate')->fetchAll(), 'vat_rate'));
        json_response(['vat_rate' => count($rates) === 1 ? $rates[0] : null, 'vat_rates' => $rates, 'rows' => $rows]);
    }

    if ($action === 'stock_analytics') {
        require_role(['ADMIN','MANAGER']);
        $rows = db()->query('SELECT COALESCE(s.name, "Unassigned") supplier_name, p.name product, p.supplied_quantity total_units_supplied, p.quantity units_available_in_stock, COALESCE(SUM(CASE WHEN sa.status="COMPLETED" THEN si.quantity ELSE 0 END),0) units_sold, p.supplied_quantity - COALESCE(SUM(CASE WHEN sa.status="COMPLETED" THEN si.quantity ELSE 0 END),0) remaining_units FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN sale_items si ON si.product_id=p.id LEFT JOIN sales sa ON sa.id=si.sale_id GROUP BY p.id, supplier_name, p.name, p.supplied_quantity, p.quantity ORDER BY supplier_name, p.name')->fetchAll();
        json_response(['rows' => $rows]);
    }

    if ($action === 'create_quotation') {
        require_role(['ADMIN','MANAGER','EMPLOYEE']);
        $d = input_json();
        $cart = is_array($d['cart'] ?? null) ? $d['cart'] : [];
        if (!$cart) json_response(['error' => 'Quotation must contain at least one product'], 400);
        $pdo = db();
        $subtotal = 0;
        $items = [];
        foreach ($cart as $item) {
            $stmt = $pdo->prepare('SELECT id, name, selling_price, vat_rate FROM products WHERE id=?');
            $stmt->execute([(int)($item['id'] ?? 0)]);
            $product = $stmt->fetch();
            $quantity = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 10000) {
                json_response(['error' => 'Quotation quantities must be positive whole numbers'], 400);
            }
            if (!$product) json_response(['error' => 'Product not found'], 404);
            $lineTotal = (int)$product['selling_price'] * $quantity;
            $subtotal += $lineTotal;
            $items[] = [$product, $quantity, $lineTotal];
        }
        $discount = money_to_minor($d['discount'] ?? 0);
        $permissions = role_permissions_config();
        $maxDiscount = (int)($permissions[$user['role']]['discount_max_percent'] ?? 0);
        if ($discount < 0 || $discount > $subtotal || $discount * 100 > $subtotal * $maxDiscount) {
            json_response(['error' => 'Quotation discount exceeds the amount allowed for your role'], 400);
        }
        $tax = 0;
        $discountAllocated = 0;
        foreach ($items as $index => [$product, $quantity, $lineTotal]) {
            $lineDiscount = $index === count($items) - 1
                ? $discount - $discountAllocated
                : intdiv($discount * $lineTotal, max(1, $subtotal));
            $discountAllocated += $lineDiscount;
            $rate = max(0.0, min(1.0, (float)$product['vat_rate']));
            $tax += (int)round(($lineTotal - $lineDiscount) * $rate / (1 + $rate), 0, PHP_ROUND_HALF_UP);
        }
        $total = $subtotal - $discount;
        $quoteNo = 'QT-' . date('Ymd-His') . '-' . random_int(100, 999);
        db_begin_immediate();
        $pdo->prepare('INSERT INTO quotations(quote_no, customer_id, customer_name, subtotal, tax, discount, total, valid_until, created_by) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$quoteNo, !empty($d['customer_id']) ? (int)$d['customer_id'] : null, $d['customer_name'] ?? 'Walk-in Customer', $subtotal, $tax, $discount, $total, $d['valid_until'] ?? null, $user['id']]);
        $quoteId = (int)$pdo->lastInsertId();
        foreach ($items as [$product, $quantity, $lineTotal]) {
            $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
                ->execute([$quoteId, $product['id'], $product['name'], $quantity, $product['selling_price'], $lineTotal]);
        }
        db_commit();
        audit($user['username'], 'CREATE_QUOTATION', $quoteNo);
        json_response(['ok' => true, 'id' => $quoteId, 'quote_no' => $quoteNo]);
    }

    if ($action === 'quotation_detail') {
        require_login();
        $id = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM quotations WHERE id=?');
        $stmt->execute([$id]);
        $quote = $stmt->fetch();
        if (!$quote) json_response(['error' => 'Quotation not found'], 404);
        $stmt = db()->prepare('SELECT * FROM quotation_items WHERE quotation_id=?');
        $stmt->execute([$id]);
        json_response(['quotation' => $quote, 'items' => $stmt->fetchAll(), 'company' => company_profile()]);
    }

    if ($action === 'delete_quotation') {
        require_permission('records.delete');
        $id = (int)(input_json()['id'] ?? 0);
        $stmt = db()->prepare('SELECT quote_no, status FROM quotations WHERE id=?');
        $stmt->execute([$id]);
        $quote = $stmt->fetch();
        if (!$quote) json_response(['error' => 'Quotation not found'], 404);
        if ($quote['status'] === 'CONVERTED') json_response(['error' => 'A converted quotation cannot be deleted'], 409);
        db()->beginTransaction();
        db()->prepare('DELETE FROM quotation_items WHERE quotation_id=?')->execute([$id]);
        db()->prepare('DELETE FROM quotations WHERE id=?')->execute([$id]);
        db()->commit();
        audit($user['username'], 'DELETE_QUOTATION', $quote['quote_no']);
        json_response(['ok' => true]);
    }

    if ($action === 'accept_quotation') {
        require_role(['ADMIN','MANAGER','EMPLOYEE']);
        $id = (int)(input_json()['id'] ?? 0);
        $stmt = db()->prepare('SELECT quote_no, status FROM quotations WHERE id=?');
        $stmt->execute([$id]);
        $quote = $stmt->fetch();
        if (!$quote) json_response(['error' => 'Quotation not found'], 404);
        if ($quote['status'] === 'CONVERTED') json_response(['error' => 'Quotation is already a sale'], 409);
        db()->prepare("UPDATE quotations SET status='ACCEPTED' WHERE id=?")->execute([$id]);
        audit($user['username'], 'ACCEPT_QUOTATION', $quote['quote_no']);
        json_response(['ok' => true]);
    }

    if ($action === 'convert_quotation') {
        require_role(['ADMIN','MANAGER','EMPLOYEE']);
        $d = input_json();
        $quotationId = (int)($d['id'] ?? 0);
        $paymentMethod = trim((string)($d['payment_method'] ?? 'Cash')) ?: 'Cash';
        if ($paymentMethod !== 'Cash') {
            json_response(['error' => 'Quotation conversion currently supports cash payment only'], 400);
        }
        $amountPaid = money_to_minor($d['amount_paid'] ?? 0);
        $pdo = db();
        db_begin_immediate();
        try {
            $stmt = $pdo->prepare('SELECT * FROM quotations WHERE id=?');
            $stmt->execute([$quotationId]);
            $quote = $stmt->fetch();
            if (!$quote) {
                db_rollback();
                json_response(['error' => 'Quotation not found'], 404);
            }
            if ($quote['status'] === 'CONVERTED') {
                $saleStmt = $pdo->prepare('SELECT id, receipt_no FROM sales WHERE quotation_id=?');
                $saleStmt->execute([$quotationId]);
                $sale = $saleStmt->fetch();
                db_commit();
                json_response(['ok' => true, 'sale_id' => (int)$sale['id'], 'receipt_no' => $sale['receipt_no'], 'idempotent_replay' => true]);
            }
            if ($quote['status'] !== 'ACCEPTED') {
                db_rollback();
                json_response(['error' => 'Accept the quotation before converting it to a sale'], 409);
            }
            $itemsStmt = $pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id=?');
            $itemsStmt->execute([$quotationId]);
            $items = $itemsStmt->fetchAll();
            if (!$items) {
                db_rollback();
                json_response(['error' => 'Quotation has no items'], 400);
            }
            $totalCents = (int)$quote['total'];
            $amountPaid = $amountPaid > 0 ? $amountPaid : $totalCents;
            if ($amountPaid < $totalCents) {
                db_rollback();
                json_response(['error' => 'Amount paid cannot be less than the quotation total'], 400);
            }
            foreach ($items as $item) {
                $stock = $pdo->prepare('UPDATE products SET quantity=quantity-? WHERE id=? AND quantity>=?');
                $stock->execute([(int)$item['quantity'], (int)$item['product_id'], (int)$item['quantity']]);
                if ($stock->rowCount() !== 1) {
                    db_rollback();
                    json_response(['error' => 'Insufficient stock for ' . $item['product_name']], 409);
                }
            }
            $receipt = receipt_no();
            $changeDue = $amountPaid - $totalCents;
            $effectiveVatRate = $totalCents > (int)$quote['tax'] ? (int)$quote['tax'] / ($totalCents - (int)$quote['tax']) : 0.0;
            $pdo->prepare('INSERT INTO sales(receipt_no, customer_id, customer_name, payment_method, subtotal, tax, discount, total, amount_paid, change_due, status, etims_status, cashier_name, created_by, quotation_id, paid_at, vat_amount, vat_rate) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$receipt, $quote['customer_id'], $quote['customer_name'], $paymentMethod, $quote['subtotal'], $quote['tax'], $quote['discount'], $totalCents, $amountPaid, $changeDue, 'COMPLETED', 'PENDING', $user['full_name'] ?: $user['username'], $user['id'], $quotationId, date('Y-m-d H:i:s'), $quote['tax'], $effectiveVatRate]);
            $saleId = (int)$pdo->lastInsertId();
            foreach ($items as $item) {
                $pdo->prepare('INSERT INTO sale_items(sale_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')->execute([$saleId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $item['total']]);
                $pdo->prepare('INSERT INTO stock_movements(product_id, quantity_delta, reason, reference, created_by) VALUES(?,?,?,?,?)')->execute([(int)$item['product_id'], -(int)$item['quantity'], 'SALE', $receipt, (int)$user['id']]);
            }
            $pdo->prepare('INSERT INTO sync_queue(entity, entity_id, action) VALUES(?,?,?)')->execute(['sale', $saleId, 'UPLOAD']);
            $pdo->prepare("UPDATE quotations SET status='CONVERTED', converted_sale_id=? WHERE id=?")->execute([$saleId, $quotationId]);
            db_commit();
        } catch (Throwable $e) {
            if (db_in_transaction()) {
                db_rollback();
            }
            throw $e;
        }
        audit($user['username'], 'CONVERT_QUOTATION', $quote['quote_no'] . ' -> ' . $receipt);
        json_response(['ok' => true, 'sale_id' => $saleId, 'receipt_no' => $receipt]);
    }

    if ($action === 'save_product') {
        require_role(['ADMIN','MANAGER','EMPLOYEE']);
        $d = input_json();
        $quantity = filter_var($d['quantity'] ?? null, FILTER_VALIDATE_INT);
        $buyingPrice = money_to_minor($d['buying_price'] ?? null);
        $sellingPrice = money_to_minor($d['selling_price'] ?? null);
        $vatRate = (float)($d['vat_rate'] ?? VAT_RATE);
        if ($quantity === false || $quantity < 0 || $buyingPrice < 0 || $sellingPrice < 0 || $vatRate < 0 || $vatRate > 1) {
            json_response(['error' => 'Product quantity, prices, or VAT rate are invalid'], 400);
        }
        $stmt = db()->prepare('INSERT INTO products(sku, barcode, name, category, brand, image_path, barcode_image_path, warehouse, branch, buying_price, selling_price, quantity, reorder_level, supplier_id, supplied_quantity, vat_rate) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$d['sku'], $d['barcode'] ?? '', $d['name'], $d['category'], $d['brand'] ?? '', $d['image_path'] ?? '', $d['barcode_image_path'] ?? '', $d['warehouse'] ?? 'Main', $d['branch'] ?? 'Main', $buyingPrice, $sellingPrice, $quantity, (int)($d['reorder_level'] ?? 5), !empty($d['supplier_id']) ? (int)$d['supplier_id'] : null, $quantity, $vatRate]);
        $productId = (int)db()->lastInsertId();
        if ($quantity > 0) {
            db()->prepare('INSERT INTO stock_movements(product_id, quantity_delta, reason, reference, created_by) VALUES(?,?,?,?,?)')
                ->execute([$productId, $quantity, 'OPENING', 'product-create', (int)$user['id']]);
        }
        audit($user['username'], 'CREATE_PRODUCT', $d['sku']);
        json_response(['ok' => true]);
    }

    if ($action === 'create_supplier') {
        require_role(['ADMIN','MANAGER','EMPLOYEE']);
        $d = input_json();
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            json_response(['error' => 'Supplier name is required'], 400);
        }
        $stmt = db()->prepare('INSERT INTO suppliers(name, phone, email, kra_pin) VALUES(?,?,?,?)');
        $stmt->execute([$name, trim((string)($d['phone'] ?? '')), trim((string)($d['email'] ?? '')), trim((string)($d['kra_pin'] ?? ''))]);
        $supplierId = (int)db()->lastInsertId();
        audit($user['username'], 'CREATE_SUPPLIER', $name);
        json_response(['ok' => true, 'id' => $supplierId, 'name' => $name]);
    }

    if ($action === 'save_simple') {
        $table = $_GET['table'] ?? '';
        $d = input_json();
        $defs = [
            'customers' => ['ADMIN','MANAGER','EMPLOYEE', ['name','phone','email','kra_pin']],
            'suppliers' => ['ADMIN','MANAGER', ['name','phone','email','kra_pin']],
            'employees' => ['ADMIN', ['name','phone','role','salary']],
            'branches' => ['ADMIN','MANAGER', ['name','location','phone']],
            'expenses' => ['ADMIN','MANAGER', ['title','category','amount','paid_by']],
            'cash_transactions' => ['ADMIN','MANAGER', ['type','category','description','amount','reference','created_by']],
        ];
        if (!isset($defs[$table])) {
            json_response(['error' => 'Unsupported save target'], 400);
        }
        $roles = array_slice($defs[$table], 0, -1);
        require_role($roles);
        $cols = end($defs[$table]);
        $values = array_map(fn($c) => $d[$c] ?? '', $cols);
        foreach (['salary', 'amount'] as $moneyColumn) {
            $position = array_search($moneyColumn, $cols, true);
            if ($position !== false) {
                $values[$position] = money_to_minor($values[$position]);
            }
        }
        if ($table === 'cash_transactions') {
            $values[array_search('created_by', $cols, true)] = $user['id'];
        }
        $sql = 'INSERT INTO ' . $table . '(' . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        db()->prepare($sql)->execute($values);
        audit($user['username'], 'CREATE_' . strtoupper($table));
        json_response(['ok' => true]);
    }

    if ($action === 'create_user') {
        require_role(['ADMIN']);
        $d = input_json();
        if (strlen((string)($d['password'] ?? '')) < 10) {
            json_response(['error' => 'Password must be at least 10 characters'], 400);
        }
        db()->prepare('INSERT INTO users(username, full_name, password_hash, role, branch, must_change_password) VALUES(?,?,?,?,?,0)')
            ->execute([$d['username'], $d['full_name'], hash_password_local((string)$d['password']), $d['role'], $d['branch'] ?? 'Main']);
        audit($user['username'], 'CREATE_USER', $d['username']);
        json_response(['ok' => true]);
    }

    if ($action === 'set_user_active') {
        require_role(['ADMIN']);
        $d = input_json();
        $accountId = (int)($d['id'] ?? 0);
        $active = (int)($d['active'] ?? 0) === 1 ? 1 : 0;
        if ($accountId < 1) {
            json_response(['error' => 'A valid account is required'], 400);
        }
           if ($accountId === 1) {
               json_response(['error' => 'The main admin account cannot be deactivated'], 409);
           }
        if ($accountId === (int)$user['id'] && $active === 0) {
            json_response(['error' => 'You cannot deactivate your own account'], 409);
        }
        $stmt = db()->prepare('UPDATE users SET active=? WHERE id=?');
        $stmt->execute([$active, $accountId]);
        if ($stmt->rowCount() < 1) {
            json_response(['error' => 'Account not found'], 404);
        }
        audit($user['username'], $active ? 'ACTIVATE_USER' : 'DEACTIVATE_USER', (string)$accountId);
        json_response(['ok' => true, 'active' => $active]);
    }

    if ($action === 'delete_user') {
        require_role(['ADMIN']);
        $d = input_json();
        $accountId = (int)($d['id'] ?? 0);
        if ($accountId < 1) {
            json_response(['error' => 'A valid account is required'], 400);
        }
           if ($accountId === 1) {
               json_response(['error' => 'The main admin account cannot be deleted'], 409);
           }
        if ($accountId === (int)$user['id']) {
            json_response(['error' => 'You cannot delete your own account'], 409);
        }
        $stmt = db()->prepare('SELECT username, role FROM users WHERE id=?');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            json_response(['error' => 'Account not found'], 404);
        }
        if ($account['role'] === 'ADMIN' && (int)db()->query("SELECT COUNT(*) FROM users WHERE role='ADMIN' AND active=1")->fetchColumn() <= 1) {
            json_response(['error' => 'At least one active admin account must remain'], 409);
        }
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$accountId]);
        audit($user['username'], 'DELETE_USER', $account['username']);
        json_response(['ok' => true]);
    }

    if ($action === 'reset_user_password') {
        require_role(['ADMIN']);
        $d = input_json();
        $accountId = (int)($d['id'] ?? 0);
        if ($accountId < 1) {
            json_response(['error' => 'A valid account is required'], 400);
        }
        $stmt = db()->prepare('SELECT username, full_name FROM users WHERE id = ?');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            json_response(['error' => 'Account not found'], 404);
        }
        $temporaryPassword = trim((string)($d['password'] ?? ''));
        if ($temporaryPassword === '') {
            $temporaryPassword = generate_temp_password();
        }
        if (strlen($temporaryPassword) < 10) {
            json_response(['error' => 'Temporary password must be at least 10 characters'], 400);
        }
        if ($temporaryPassword === 'admin123') {
            json_response(['error' => 'Choose a password other than the default'], 400);
        }
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1 WHERE id = ?')
            ->execute([hash_password_local($temporaryPassword), $accountId]);
        audit($user['username'], 'PASSWORD_RESET', $account['username']);
        json_response([
            'ok' => true,
            'username' => $account['username'],
            'full_name' => $account['full_name'],
            'temporary_password' => $temporaryPassword,
        ]);
    }

    if ($action === 'checkout') {
        $d = input_json();
        $cartInput = $d['cart'] ?? null;
        if (!is_array($cartInput) || $cartInput === [] || count($cartInput) > 200) {
            json_response(['error' => 'Cart must contain between 1 and 200 items'], 400);
        }
        $idempotencyKey = trim((string)($d['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey)) {
            json_response(['error' => 'A valid checkout idempotency key is required'], 400);
        }
        $paymentMethod = trim((string)($d['payment_method'] ?? ''));
        if (!in_array($paymentMethod, ['Cash', 'M-Pesa'], true)) {
            json_response(['error' => 'Select a supported payment method'], 400);
        }
        $cart = [];
        foreach ($cartInput as $item) {
            if (!is_array($item)) {
                json_response(['error' => 'Cart items are invalid'], 400);
            }
            $productId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1 || $quantity > 10000) {
                json_response(['error' => 'Each cart item needs a valid product and quantity'], 400);
            }
            $cart[$productId] = ($cart[$productId] ?? 0) + $quantity;
            if ($cart[$productId] > 10000) {
                json_response(['error' => 'Cart quantity is too large'], 400);
            }
        }
        ksort($cart);
        $discountCents = money_to_minor($d['discount'] ?? 0);
        if ($discountCents < 0) {
            json_response(['error' => 'Discount cannot be negative'], 400);
        }
        $customerName = trim((string)($d['customer_name'] ?? 'Walk-in Customer'));
        $customerName = $customerName !== '' ? $customerName : 'Walk-in Customer';
        if (strlen($customerName) > 120) {
            json_response(['error' => 'Customer name is too long'], 400);
        }
        $mpesaPhone = $paymentMethod === 'M-Pesa' ? normalize_phone((string)($d['mpesa_phone'] ?? '')) : '';
        if ($paymentMethod === 'M-Pesa' && !preg_match('/^254\d{9,10}$/', $mpesaPhone)) {
            json_response(['error' => 'Enter a valid 254-format mobile number.'], 400);
        }
        $amountPaidInput = money_to_minor($d['amount_paid'] ?? 0);
        $requestHash = hash('sha256', json_encode([
            'cart' => $cart,
            'payment_method' => $paymentMethod,
            'discount' => $discountCents,
            'amount_paid' => $amountPaidInput,
            'customer_name' => $customerName,
            'mpesa_phone' => $mpesaPhone,
        ], JSON_THROW_ON_ERROR));
        $pdo = db();
        db_begin_immediate();
        try {
            $existing = $pdo->prepare('SELECT request_hash, response_json FROM checkout_requests WHERE user_id = ? AND idempotency_key = ?');
            $existing->execute([(int)$user['id'], $idempotencyKey]);
            $previous = $existing->fetch();
            if ($previous) {
                if (!hash_equals((string)$previous['request_hash'], $requestHash)) {
                    db_rollback();
                    json_response(['error' => 'This checkout key was already used for different sale details'], 409);
                }
                $response = json_decode((string)$previous['response_json'], true);
                db_commit();
                if (!is_array($response)) {
                    throw new RuntimeException('Stored checkout response is invalid.');
                }
                if (isset($response['sale_id'])) {
                    $saleStatus = $pdo->prepare('SELECT status FROM sales WHERE id=?');
                    $saleStatus->execute([(int)$response['sale_id']]);
                    $currentStatus = $saleStatus->fetchColumn();
                    if ($currentStatus === 'COMPLETED') {
                        $response['payment_status'] = 'PAID';
                    } elseif ($currentStatus === 'PAYMENT_FAILED') {
                        $response['payment_status'] = 'PAYMENT_FAILED';
                    } elseif ($currentStatus === 'VOID') {
                        $response['payment_status'] = 'VOID';
                    }
                }
                $response['idempotent_replay'] = true;
                json_response($response);
            }

            $lines = [];
            $subtotalCents = 0;
            foreach ($cart as $productId => $quantity) {
                $stmt = $pdo->prepare('SELECT id, name, selling_price, vat_rate FROM products WHERE id = ?');
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                if (!$product) {
                    db_rollback();
                    json_response(['error' => 'A product in the cart no longer exists'], 409);
                }
                $stock = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');
                $stock->execute([$quantity, $productId, $quantity]);
                if ($stock->rowCount() !== 1) {
                    db_rollback();
                    json_response(['error' => 'Insufficient stock for ' . $product['name']], 409);
                }
                $unitCents = (int)$product['selling_price'];
                $lineCents = $unitCents * $quantity;
                $subtotalCents += $lineCents;
                $lines[] = ['product' => $product, 'quantity' => $quantity, 'unit_cents' => $unitCents, 'line_cents' => $lineCents];
            }
            if ($discountCents > $subtotalCents) {
                db_rollback();
                json_response(['error' => 'Discount cannot exceed the sale subtotal'], 400);
            }
            $rolePermissions = role_permissions_config();
            $discountLimit = (int)($rolePermissions[$user['role']]['discount_max_percent'] ?? 0);
            if ($discountCents * 100 > $subtotalCents * $discountLimit) {
                db_rollback();
                json_response(['error' => 'Discount exceeds the maximum allowed for your role'], 403);
            }

            $totalCents = $subtotalCents - $discountCents;
            $taxCents = 0;
            $allocatedDiscount = 0;
            foreach ($lines as $index => $line) {
                $lineDiscount = $index === count($lines) - 1
                    ? $discountCents - $allocatedDiscount
                    : intdiv($discountCents * $line['line_cents'], max(1, $subtotalCents));
                $allocatedDiscount += $lineDiscount;
                $rate = max(0.0, min(1.0, (float)$line['product']['vat_rate']));
                $taxCents += (int)round(($line['line_cents'] - $lineDiscount) * $rate / (1 + $rate), 0, PHP_ROUND_HALF_UP);
            }
            $effectiveVatRate = $totalCents > $taxCents ? $taxCents / ($totalCents - $taxCents) : 0.0;
            $amountPaidCents = 0;
            if ($paymentMethod === 'Cash') {
                $amountPaidCents = $amountPaidInput > 0 ? $amountPaidInput : $totalCents;
                if ($amountPaidCents < $totalCents) {
                    db_rollback();
                    json_response(['error' => 'Amount paid cannot be less than the sale total'], 400);
                }
            }
            $changeCents = $paymentMethod === 'Cash' ? $amountPaidCents - $totalCents : 0;
            $receipt = receipt_no();
            $paymentReference = $paymentMethod === 'M-Pesa' ? payment_reference() : $receipt;
            $saleStatus = $paymentMethod === 'M-Pesa' ? 'PENDING_PAYMENT' : 'COMPLETED';

            require_once __DIR__ . '/api/VsculEtims.php';
            $vscu = new VsculEtims(KRA_PIN, VSCU_SERIAL, VSCU_VERIFICATION_KEY, VSCU_DEVICE_ID, ETIMS_SANDBOX);
            $etimsUniqueId = $vscu->generateEtimsUniqueId();
            $etimsTransactionId = $vscu->generateEtimsTransactionId();
            $etimsStatus = ETIMS_ENABLED ? 'PENDING' : 'EXEMPTED';
            $qrCodeData = $vscu->generateQRCode([
                'receipt_no' => $receipt,
                'created_at' => date('Y-m-d H:i:s'),
                'total' => money_minor_to_major($totalCents),
                'tax' => money_minor_to_major($taxCents),
            ]);

            $pdo->prepare('INSERT INTO sales(receipt_no, customer_name, payment_method, subtotal, tax, discount, total, amount_paid, change_due, status, cashier_name, mpesa_phone, payment_reference, created_by, mpesa_status, paid_at, vat_amount, vat_rate, etims_status, etims_unique_id, etims_transaction_id, kra_pin, invoice_type, etims_qr_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$receipt, $customerName, $paymentMethod, $subtotalCents, $taxCents, $discountCents, $totalCents, $amountPaidCents, $changeCents, $saleStatus, $user['full_name'] ?: $user['username'], $mpesaPhone, $paymentReference, $user['id'], $paymentMethod === 'M-Pesa' ? 'PENDING' : 'NOT_SENT', $paymentMethod === 'M-Pesa' ? null : date('Y-m-d H:i:s'), $taxCents, $effectiveVatRate, $etimsStatus, $etimsUniqueId, $etimsTransactionId, KRA_PIN, 'NORMAL', $qrCodeData]);
            $saleId = (int)$pdo->lastInsertId();
            foreach ($lines as $line) {
                $product = $line['product'];
                $quantity = $line['quantity'];
                $pdo->prepare('INSERT INTO sale_items(sale_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
                    ->execute([$saleId, (int)$product['id'], $product['name'], $quantity, $line['unit_cents'], $line['line_cents']]);
                $pdo->prepare('INSERT INTO stock_movements(product_id, quantity_delta, reason, reference, created_by) VALUES(?,?,?,?,?)')
                    ->execute([(int)$product['id'], -$quantity, 'SALE', $receipt, (int)$user['id']]);
            }
            $pdo->prepare('INSERT INTO sync_queue(entity, entity_id, action) VALUES(?,?,?)')->execute(['sale', $saleId, 'UPLOAD']);
            $response = [
                'ok' => true,
                'payment_status' => $paymentMethod === 'M-Pesa' ? 'PENDING_PAYMENT' : 'PAID',
                'sale_id' => $saleId,
                'receipt_no' => $receipt,
                'payment_reference' => $paymentReference,
                'amount' => $totalCents,
                'phone' => $mpesaPhone,
                'poll_seconds' => 90,
            ];
            $pdo->prepare('INSERT INTO checkout_requests(user_id, idempotency_key, request_hash, response_json) VALUES(?,?,?,?)')
                ->execute([(int)$user['id'], $idempotencyKey, $requestHash, json_encode($response, JSON_THROW_ON_ERROR)]);
            db_commit();
        } catch (Throwable $e) {
            if (db_in_transaction()) {
                db_rollback();
            }
            throw $e;
        }
        audit($user['username'], 'CHECKOUT', $receipt);
        json_response($response);
    }

    if ($action === 'void_sale') {
        $user = require_permission('sale.void');
        $saleId = (int)(input_json()['sale_id'] ?? 0);
        if ($saleId < 1) {
            json_response(['error' => 'A valid sale is required'], 400);
        }
        db_begin_immediate();
        try {
            $saleStmt = db()->prepare('SELECT receipt_no, status, mpesa_status FROM sales WHERE id=?');
            $saleStmt->execute([$saleId]);
            $sale = $saleStmt->fetch();
            if (!$sale) {
                db_rollback();
                json_response(['error' => 'Sale not found'], 404);
            }
            if ($sale['status'] !== 'COMPLETED') {
                db_rollback();
                json_response(['error' => 'Only completed sales can be voided'], 409);
            }
            if ($sale['mpesa_status'] !== 'NOT_SENT' && $sale['mpesa_status'] !== 'PAID') {
                db_rollback();
                json_response(['error' => 'A pending or failed M-Pesa sale cannot be voided here'], 409);
            }
            $items = db()->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id=?');
            $items->execute([$saleId]);
            $saleItems = $items->fetchAll();
            if (!$saleItems) {
                db_rollback();
                json_response(['error' => 'Sale has no items to return to stock'], 409);
            }
            db()->prepare("UPDATE sales SET status='VOID' WHERE id=? AND status='COMPLETED'")->execute([$saleId]);
            foreach ($saleItems as $item) {
                db()->prepare('UPDATE products SET quantity=quantity+? WHERE id=?')
                    ->execute([(int)$item['quantity'], (int)$item['product_id']]);
                db()->prepare('INSERT INTO stock_movements(product_id, quantity_delta, reason, reference, created_by) VALUES(?,?,?,?,?)')
                    ->execute([(int)$item['product_id'], (int)$item['quantity'], 'VOID_SALE', $sale['receipt_no'], (int)$user['id']]);
            }
            audit($user['username'], 'VOID_SALE', $sale['receipt_no']);
            db_commit();
        } catch (Throwable $e) {
            if (db_in_transaction()) {
                db_rollback();
            }
            throw $e;
        }
        json_response(['ok' => true, 'receipt_no' => $sale['receipt_no']]);
    }

    if ($action === 'receipt') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$id]);
        $sale = $stmt->fetch();
        $items = [];
        if ($sale) {
            if ($sale['payment_method'] === 'M-Pesa' && $sale['status'] !== 'COMPLETED') {
                json_response(['error' => 'Receipt is available only after the M-Pesa payment is confirmed successful.'], 409);
            }
            $i = db()->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
            $i->execute([$id]);
            $items = $i->fetchAll();
        }
        
        // Generate QR code if not already present
        $qrCode = null;
        if ($sale && !$sale['etims_qr_code']) {
            require_once __DIR__ . '/api/VsculEtims.php';
            $vscu = new VsculEtims(KRA_PIN, VSCU_SERIAL, VSCU_VERIFICATION_KEY, VSCU_DEVICE_ID, ETIMS_SANDBOX);
            $qrSale = $sale;
            $qrSale['total'] = money_minor_to_major($sale['total']);
            $qrSale['tax'] = money_minor_to_major($sale['tax']);
            $qrCode = $vscu->generateQRCode($qrSale);
            // Update sale with QR code
            db()->prepare('UPDATE sales SET etims_qr_code = ? WHERE id = ?')->execute([$qrCode, $id]);
            $sale['etims_qr_code'] = $qrCode;
        }
        
        json_response(['sale' => $sale, 'items' => $items, 'company' => company_profile()]);
    }
    
    if ($action === 'generate_qr') {
        require_login();
        $id = (int)($_GET['sale_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$id]);
        $sale = $stmt->fetch();
        if (!$sale) {
            json_response(['error' => 'Sale not found'], 404);
        }
        
        require_once __DIR__ . '/api/VsculEtims.php';
        $vscu = new VsculEtims(KRA_PIN, VSCU_SERIAL, VSCU_VERIFICATION_KEY, VSCU_DEVICE_ID, ETIMS_SANDBOX);
        
        $qrSale = $sale;
        $qrSale['total'] = money_minor_to_major($sale['total']);
        $qrSale['tax'] = money_minor_to_major($sale['tax']);
        $qrData = $vscu->generateQRCode($qrSale);
        
        // Generate QR code image using a free QR library or service
        // For now, we'll return the data and let frontend generate the QR
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrData);
        
        // Update sale with QR code URL
        db()->prepare('UPDATE sales SET etims_qr_code = ?, etims_qr_url = ? WHERE id = ?')->execute([$qrData, $qrCodeUrl, $id]);
        
        json_response(['ok' => true, 'qr_data' => $qrData, 'qr_url' => $qrCodeUrl]);
    }

    if ($action === 'hold_sale') {
        $d = input_json();
        $holdNo = 'HOLD-' . date('Ymd-His');
        db()->prepare('INSERT INTO held_sales(hold_no, customer_name, cart_json, created_by) VALUES(?,?,?,?)')
            ->execute([$holdNo, $d['customer_name'] ?? '', json_encode($d['cart'] ?? []), $user['id']]);
        json_response(['ok' => true, 'hold_no' => $holdNo]);
    }

    if ($action === 'resume_hold') {
        $d = input_json();
        $id = (int)($d['id'] ?? 0);
        $stmt = db()->prepare('SELECT id, hold_no, customer_name, cart_json FROM held_sales WHERE id = ?');
        $stmt->execute([$id]);
        $held = $stmt->fetch();
        if (!$held) {
            json_response(['error' => 'Held sale not found'], 404);
        }
        $cart = json_decode($held['cart_json'], true);
        if (!is_array($cart) || !$cart) {
            json_response(['error' => 'Held sale has no items'], 400);
        }
        db()->prepare('DELETE FROM held_sales WHERE id = ?')->execute([$id]);
        json_response(['ok' => true, 'hold_no' => $held['hold_no'], 'customer_name' => $held['customer_name'], 'cart' => $cart]);
    }

    if ($action === 'backup') {
        require_role(['ADMIN','MANAGER']);
        $file = create_backup('manual');
        settings_set('backup_last_at', gmdate('c'));
        audit($user['username'], 'BACKUP', basename($file));
        json_response(['ok' => true, 'file' => basename($file)]);
    }

    if ($action === 'backup_list') {
        require_role(['ADMIN','MANAGER']);
        json_response([
            'backups' => backup_list(),
            'auto_enabled' => (string)(settings_get('backup_auto_enabled', '1') ?? '1') === '1',
            'keep' => (int)(settings_get('backup_keep', '30') ?? 30),
        ]);
    }

    if ($action === 'save_settings') {
        require_role(['ADMIN']);
        $d = input_json();
        $allowed = ['backup_auto_enabled', 'backup_keep'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $d)) {
                $value = (string)$d[$key];
                if ($key === 'backup_auto_enabled') {
                    $value = in_array($value, ['1', 'true', 'on'], true) ? '1' : '0';
                }
                if ($key === 'backup_keep') {
                    $value = (string)max(1, min(365, (int)$value));
                }
                settings_set($key, $value);
            }
        }
        audit($user['username'], 'SAVE_SETTINGS', implode(',', $allowed));
        json_response(['ok' => true]);
    }

    if ($action === 'save_setup') {
        require_role(['ADMIN']);
        $d = input_json();
        $name = trim((string)($d['company_name'] ?? ''));
        if ($name === '') {
            json_response(['error' => 'Company name is required'], 400);
        }
        if (strlen($name) > 120) {
            json_response(['error' => 'Company name is too long'], 400);
        }
        $email = trim((string)($d['company_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            json_response(['error' => 'Company email is not valid'], 400);
        }
        $logoPath = trim((string)($d['logo_path'] ?? LOGO_WEB_PATH));
        if (str_contains($logoPath, '..') || !preg_match('#^assets/[A-Za-z0-9._/-]+$#', $logoPath)) {
            json_response(['error' => 'Logo path must point inside the assets folder'], 400);
        }
        $brandColor = trim((string)($d['brand_color'] ?? '#a70437'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) {
            json_response(['error' => 'Brand colour must be a six-digit hex value like #a70437'], 400);
        }
        $settings = [
            'company_name' => $name,
            'company_email' => $email,
            'company_location' => trim((string)($d['company_location'] ?? '')),
            'company_phone' => trim((string)($d['company_phone'] ?? '')),
            'company_tagline' => mb_substr(trim((string)($d['company_tagline'] ?? '')), 0, 160),
            'receipt_footer' => mb_substr(trim((string)($d['receipt_footer'] ?? '')), 0, 240),
            'brand_color' => strtolower($brandColor),
            'logo_path' => $logoPath,
        ];
        foreach ($settings as $key => $value) {
            settings_set($key, $value);
        }

        $branchName = trim((string)($d['branch_name'] ?? ''));
        if ($branchName !== '') {
            $branchLocation = trim((string)($d['branch_location'] ?? $settings['company_location']));
            $branchPhone = trim((string)($d['branch_phone'] ?? $settings['company_phone']));
            $branch = db()->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetch();
            try {
                if ($branch) {
                    db()->prepare('UPDATE branches SET name = ?, location = ?, phone = ? WHERE id = ?')
                        ->execute([$branchName, $branchLocation, $branchPhone, (int)$branch['id']]);
                } else {
                    db()->prepare('INSERT INTO branches(name, location, phone) VALUES(?,?,?)')
                        ->execute([$branchName, $branchLocation, $branchPhone]);
                }
            } catch (Throwable $e) {
                json_response(['error' => 'That branch name is already in use'], 409);
            }
        }

        $licenseKey = trim((string)($d['license_key'] ?? ''));
        if ($licenseKey !== '') {
            try {
                license_activate($licenseKey);
            } catch (InvalidArgumentException $e) {
                json_response(['error' => $e->getMessage()], 400);
            }
        }

        settings_set('setup_completed', '1');
        audit($user['username'], 'SAVE_SETUP', $name);
        json_response([
            'ok' => true,
            'company' => company_profile(),
            'license' => license_status(),
            'setup_completed' => true,
        ]);
    }

    if ($action === 'import_template') {
        require_role(['ADMIN', 'MANAGER']);
        json_response(csv_import_template((string)($_GET['type'] ?? '')));
    }

    if ($action === 'import_preview') {
        require_role(['ADMIN', 'MANAGER']);
        $d = input_json();
        $result = csv_import_validate((string)($d['type'] ?? ''), (string)($d['csv'] ?? ''), (string)($d['mode'] ?? 'skip'));
        unset($result['records']);
        $result['row_count'] = $result['total'];
        unset($result['total']);
        json_response($result);
    }

    if ($action === 'import_commit') {
        require_role(['ADMIN', 'MANAGER']);
        $d = input_json();
        json_response(csv_import_commit(
            (string)($d['type'] ?? ''),
            (string)($d['csv'] ?? ''),
            (string)($d['mode'] ?? 'skip'),
            $user['username']
        ));
    }

    if ($action === 'backup_download') {
        require_role(['ADMIN','MANAGER']);
        $path = backup_path_for((string)($_GET['file'] ?? ''));
        audit($user['username'], 'BACKUP_DOWNLOAD', basename($path));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    if ($action === 'backup_delete') {
        require_role(['ADMIN']);
        $d = input_json();
        $path = backup_path_for((string)($d['file'] ?? ''));
        unlink($path);
        audit($user['username'], 'BACKUP_DELETE', basename($path));
        json_response(['ok' => true]);
    }

    if ($action === 'backup_restore') {
        require_role(['ADMIN']);
        $d = input_json();
        $confirmText = strtoupper(trim((string)($d['confirm'] ?? '')));
        if ($confirmText !== 'RESTORE') {
            json_response(['error' => 'Type RESTORE to confirm this action'], 400);
        }
        $currentPassword = trim((string)($d['password'] ?? ''));
        if ($currentPassword === '') {
            json_response(['error' => 'Enter your current password to restore a backup'], 400);
        }
        $currentUser = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $currentUser->execute([(int)$user['id']]);
        $currentRow = $currentUser->fetch();
        if (!$currentRow || !verify_password_local($currentPassword, (string)$currentRow['password_hash'])) {
            json_response(['error' => 'Current password is incorrect'], 400);
        }
        $name = (string)($d['file'] ?? '');
        $safety = backup_restore($name);
        audit($user['username'], 'BACKUP_RESTORE', basename($name));
        json_response(['ok' => true, 'safety_backup' => basename($safety)]);
    }

    if ($action === 'retry_sync') {
        require_role(['ADMIN','MANAGER']);
        db()->exec("UPDATE sync_queue SET retry_count = retry_count + 1, error_message = 'Waiting for real API credentials', status = 'PENDING' WHERE status != 'SYNCED'");
        json_response(['ok' => true]);
    }

    json_response(['error' => 'Unknown action'], 404);
} catch (Throwable $e) {
    app_log('API error [' . $action . ']: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    try {
        if (db_in_transaction()) {
            db_rollback();
        }
    } catch (Throwable $rollbackError) {
        app_log('Rollback failed: ' . $rollbackError->getMessage());
    }
    $isValidationError = $e instanceof InvalidArgumentException;
    // PDOException extends RuntimeException, but its messages can contain SQL,
    // file paths and column names. Only application-level messages are safe.
    $isSafeMessage = $isValidationError || ($e instanceof RuntimeException && !$e instanceof PDOException);
    json_response([
        'error' => $isSafeMessage ? $e->getMessage() : 'An unexpected error occurred. Check the application log.',
        'code' => $isValidationError ? 'VALIDATION_ERROR' : 'SERVER_ERROR',
    ], $isValidationError ? 400 : 500);
}
