<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

session_set_cookie_params([
    'lifetime' => 1800,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
init_db();

$action = $_GET['action'] ?? '';

if (isset($_SESSION['user'])) {
    $lastSeen = (int)($_SESSION['last_seen'] ?? 0);
    if ($lastSeen > 0 && (time() - $lastSeen) > 1800) {
        $_SESSION = [];
        session_unset();
        session_destroy();
    } else {
        $_SESSION['last_seen'] = time();
    }
}

try {
    if ($action === 'login') {
        $data = input_json();
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $stmt->execute([trim($data['username'] ?? '')]);
        $user = $stmt->fetch();
        if (!$user || !verify_password_local((string)($data['password'] ?? ''), $user['password_hash'])) {
            json_response(['error' => 'Invalid username or password'], 401);
        }
        $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'branch' => $user['branch']];
        audit($user['username'], 'LOGIN');
        json_response(['user' => $_SESSION['user']]);
    }
    if ($action === 'logout') {
        session_destroy();
        json_response(['ok' => true]);
    }
    if ($action === 'me') {
        json_response(['user' => $_SESSION['user'] ?? null, 'company' => ['name' => COMPANY_NAME, 'email' => COMPANY_EMAIL, 'location' => COMPANY_LOCATION, 'logo' => LOGO_WEB_PATH]]);
    }

    if ($action === 'health') {
        json_response(['ok' => true, 'mode' => 'local', 'time' => date(DATE_ATOM)]);
    }

    $user = require_login();

    if ($action === 'dashboard') {
        $pdo = db();
        $today = date('Y-m-d');
        $sales = $pdo->query("SELECT COALESCE(SUM(total),0) total, COUNT(*) count FROM sales WHERE status='COMPLETED' AND date(created_at)=date('now')")->fetch();
        $month = $pdo->query("SELECT COALESCE(SUM(total),0) total FROM sales WHERE status='COMPLETED' AND strftime('%Y-%m', created_at)=strftime('%Y-%m','now')")->fetch();
        $low = $pdo->query('SELECT COUNT(*) FROM products WHERE quantity <= reorder_level')->fetchColumn();
        $stock = $pdo->query('SELECT COALESCE(SUM(quantity * buying_price),0) value FROM products')->fetchColumn();
        $recent = $pdo->query("SELECT receipt_no, customer_name, payment_method, total, created_at FROM sales WHERE status='COMPLETED' AND date(created_at)=date('now') ORDER BY id DESC LIMIT 8")->fetchAll();
        json_response(['today' => $sales, 'month_total' => $month['total'], 'low_stock' => $low, 'stock_value' => $stock, 'recent_sales' => $recent]);
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
        } elseif ($table === 'quotations') {
            $rows = db()->query('SELECT q.id, q.quote_no, q.customer_name, q.subtotal, q.tax, q.discount, q.total, q.status, q.valid_until, q.converted_sale_id, q.created_at, s.receipt_no FROM quotations q LEFT JOIN sales s ON s.id=q.converted_sale_id ORDER BY q.id DESC LIMIT 300')->fetchAll();
        } else {
            $query = $table === 'sales'
                ? "SELECT * FROM sales WHERE status='COMPLETED' ORDER BY id DESC LIMIT 300"
                : "SELECT * FROM {$table} ORDER BY id DESC LIMIT 300";
            $rows = db()->query($query)->fetchAll();
        }
        json_response(['rows' => $rows]);
    }

    if ($action === 'delete_person') {
        require_role(['ADMIN','MANAGER']);
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
        foreach (['payment_events','sync_queue','quotation_items','quotations','sale_items','sales','held_sales','returns','cash_transactions','expenses','purchase_orders','products','customers','suppliers','employees','branches','cash_shifts'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
        $pdo->exec('DELETE FROM audit_logs');
        $pdo->prepare('INSERT INTO branches(name, location, phone) VALUES(?,?,?)')->execute(['Main', COMPANY_LOCATION, '']);
        $pdo->prepare('INSERT INTO customers(name, phone, email) VALUES(?,?,?)')->execute(['Walk-in Customer', '', COMPANY_EMAIL]);
        audit($user['username'], 'RESET_BUSINESS_DATA', 'Business data reset; user accounts preserved');
        $pdo->commit();
        json_response(['ok' => true]);
    }

    if ($action === 'etims_summary') {
        require_role(['ADMIN','MANAGER']);
        $rows = db()->query("SELECT etims_status, COUNT(*) count, COALESCE(SUM(total),0) total FROM sales WHERE status='COMPLETED' GROUP BY etims_status")->fetchAll();
        json_response(['vat_rate' => VAT_RATE, 'rows' => $rows]);
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
        $subtotal = 0.0;
        $items = [];
        foreach ($cart as $item) {
            $stmt = $pdo->prepare('SELECT id, name, selling_price FROM products WHERE id=?');
            $stmt->execute([(int)($item['id'] ?? 0)]);
            $product = $stmt->fetch();
            $quantity = max(1, (int)($item['qty'] ?? 1));
            if (!$product) json_response(['error' => 'Product not found'], 404);
            $lineTotal = (float)$product['selling_price'] * $quantity;
            $subtotal += $lineTotal;
            $items[] = [$product, $quantity, $lineTotal];
        }
        $discount = max(0, (float)($d['discount'] ?? 0));
        $taxable = max(0, $subtotal - $discount);
        $tax = round($taxable * VAT_RATE / (1 + VAT_RATE), 2);
        $quoteNo = 'QT-' . date('Ymd-His') . '-' . random_int(100, 999);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO quotations(quote_no, customer_id, customer_name, subtotal, tax, discount, total, valid_until, created_by) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$quoteNo, !empty($d['customer_id']) ? (int)$d['customer_id'] : null, $d['customer_name'] ?? 'Walk-in Customer', $subtotal, $tax, $discount, $taxable, $d['valid_until'] ?? null, $user['id']]);
        $quoteId = (int)$pdo->lastInsertId();
        foreach ($items as [$product, $quantity, $lineTotal]) {
            $pdo->prepare('INSERT INTO quotation_items(quotation_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
                ->execute([$quoteId, $product['id'], $product['name'], $quantity, $product['selling_price'], $lineTotal]);
        }
        $pdo->commit();
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
        json_response(['quotation' => $quote, 'items' => $stmt->fetchAll(), 'company' => ['name' => COMPANY_NAME, 'email' => COMPANY_EMAIL, 'location' => COMPANY_LOCATION, 'logo' => LOGO_WEB_PATH]]);
    }

    if ($action === 'delete_quotation') {
        require_role(['ADMIN','MANAGER','EMPLOYEE']);
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
        $amountPaid = max(0, (float)($d['amount_paid'] ?? 0));
        $stmt = db()->prepare('SELECT * FROM quotations WHERE id=?');
        $stmt->execute([$quotationId]);
        $quote = $stmt->fetch();
        if (!$quote) json_response(['error' => 'Quotation not found'], 404);
        if ($quote['status'] === 'CONVERTED') json_response(['error' => 'Quotation is already a sale'], 409);
        if ($quote['status'] !== 'ACCEPTED') json_response(['error' => 'Accept the quotation before converting it to a sale'], 409);
        $itemsStmt = db()->prepare('SELECT * FROM quotation_items WHERE quotation_id=?');
        $itemsStmt->execute([$quotationId]);
        $items = $itemsStmt->fetchAll();
        if (!$items) json_response(['error' => 'Quotation has no items'], 400);
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($items as $item) {
            $stockStmt = $pdo->prepare('SELECT id, name, quantity FROM products WHERE id=?');
            $stockStmt->execute([(int)$item['product_id']]);
            $product = $stockStmt->fetch();
            if (!$product || (int)$product['quantity'] < (int)$item['quantity']) {
                $pdo->rollBack();
                json_response(['error' => 'Insufficient stock for ' . ($product['name'] ?? $item['product_name'])], 409);
            }
        }
        $receipt = receipt_no();
        $changeDue = $paymentMethod === 'Cash' ? max(0, $amountPaid - (float)$quote['total']) : 0;
        $pdo->prepare('INSERT INTO sales(receipt_no, customer_id, customer_name, payment_method, subtotal, tax, discount, total, amount_paid, change_due, status, etims_status, cashier_name, created_by, quotation_id, paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$receipt, $quote['customer_id'], $quote['customer_name'], $paymentMethod, $quote['subtotal'], $quote['tax'], $quote['discount'], $quote['total'], $amountPaid, $changeDue, 'COMPLETED', 'PENDING', $user['full_name'] ?: $user['username'], $user['id'], $quotationId, date('Y-m-d H:i:s')]);
        $saleId = (int)$pdo->lastInsertId();
        foreach ($items as $item) {
            $pdo->prepare('UPDATE products SET quantity=quantity-? WHERE id=?')->execute([(int)$item['quantity'], (int)$item['product_id']]);
            $pdo->prepare('INSERT INTO sale_items(sale_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')->execute([$saleId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $item['total']]);
        }
        $pdo->prepare('INSERT INTO sync_queue(entity, entity_id, action) VALUES(?,?,?)')->execute(['sale', $saleId, 'UPLOAD']);
        $pdo->prepare("UPDATE quotations SET status='CONVERTED', converted_sale_id=? WHERE id=?")->execute([$saleId, $quotationId]);
        $pdo->commit();
        audit($user['username'], 'CONVERT_QUOTATION', $quote['quote_no'] . ' -> ' . $receipt);
        json_response(['ok' => true, 'sale_id' => $saleId, 'receipt_no' => $receipt]);
    }

    if ($action === 'save_product') {
        require_role(['ADMIN','MANAGER','EMPLOYEE']);
        $d = input_json();
        $stmt = db()->prepare('INSERT INTO products(sku, barcode, name, category, brand, image_path, barcode_image_path, warehouse, branch, buying_price, selling_price, quantity, reorder_level, supplier_id, supplied_quantity, vat_rate) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $quantity = (int)$d['quantity'];
        $stmt->execute([$d['sku'], $d['barcode'] ?? '', $d['name'], $d['category'], $d['brand'] ?? '', $d['image_path'] ?? '', $d['barcode_image_path'] ?? '', $d['warehouse'] ?? 'Main', $d['branch'] ?? 'Main', (float)$d['buying_price'], (float)$d['selling_price'], $quantity, (int)($d['reorder_level'] ?? 5), !empty($d['supplier_id']) ? (int)$d['supplier_id'] : null, $quantity, (float)($d['vat_rate'] ?? VAT_RATE)]);
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
        if (strlen((string)$d['password']) < 6) {
            json_response(['error' => 'Password must be at least 6 characters'], 400);
        }
        db()->prepare('INSERT INTO users(username, full_name, password_hash, role, branch) VALUES(?,?,?,?,?)')
            ->execute([$d['username'], $d['full_name'], hash_password_local($d['password']), $d['role'], $d['branch'] ?? 'Main']);
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

    if ($action === 'change_password') {
        $d = input_json();
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !verify_password_local($d['current_password'] ?? '', $row['password_hash'])) {
            json_response(['error' => 'Current password is incorrect'], 400);
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([hash_password_local($d['new_password']), $user['id']]);
        audit($user['username'], 'CHANGE_PASSWORD');
        json_response(['ok' => true]);
    }

    if ($action === 'checkout') {
        $d = input_json();
        $cart = $d['cart'] ?? [];
        if (!$cart) {
            json_response(['error' => 'Cart is empty'], 400);
        }
        $pdo = db();
        $pdo->beginTransaction();
        $subtotal = 0.0;
        foreach ($cart as $item) {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([(int)$item['id']]);
            $p = $stmt->fetch();
            if (!$p || (int)$p['quantity'] < (int)$item['qty']) {
                throw new RuntimeException('Insufficient stock for ' . ($p['name'] ?? 'product'));
            }
            $line = (float)$p['selling_price'] * (int)$item['qty'];
            $subtotal += $line;
            $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([(int)$item['qty'], (int)$p['id']]);
        }
        $discount = (float)($d['discount'] ?? 0);
        $taxable = max(0, $subtotal - $discount);
        $tax = round($taxable * VAT_RATE / (1 + VAT_RATE), 2);
        $total = round($taxable, 2);
        $receipt = receipt_no();
        $paymentMethod = $d['payment_method'] ?? 'Cash';
        $paymentReference = $paymentMethod === 'M-Pesa' ? payment_reference() : $receipt;
        $paid = (float)($d['amount_paid'] ?? $total);
        $mpesaPhone = $paymentMethod === 'M-Pesa' ? normalize_phone((string)($d['mpesa_phone'] ?? '')) : '';
        if ($paymentMethod === 'M-Pesa' && !preg_match('/^254\d{9,10}$/', $mpesaPhone)) {
            json_response(['error' => 'Enter a valid 254-format mobile number.'], 400);
        }
        $changeDue = $paymentMethod === 'Cash' ? max(0, $paid - $total) : 0.0;
        $saleStatus = $paymentMethod === 'M-Pesa' ? 'PENDING_PAYMENT' : 'COMPLETED';
        $paid = $paymentMethod === 'M-Pesa' ? 0.0 : (float)($d['amount_paid'] ?? $total);
        $changeDue = $paymentMethod === 'Cash' ? max(0, $paid - $total) : 0.0;
        
        // Initialize ETIMS/VSCU
        require_once __DIR__ . '/api/VsculEtims.php';
        $vscu = new VsculEtims(KRA_PIN, VSCU_SERIAL, VSCU_VERIFICATION_KEY, VSCU_DEVICE_ID, ETIMS_SANDBOX);
        
        // Generate ETIMS data
        $etimsUniqueId = $vscu->generateEtimsUniqueId();
        $etimsTransactionId = $vscu->generateEtimsTransactionId();
        $etimsStatus = ETIMS_ENABLED ? 'PENDING' : 'EXEMPTED';
        
        // Generate QR code data (actual QR will be generated when receipt is printed)
        $qrCodeData = $vscu->generateQRCode([
            'receipt_no' => $receipt,
            'created_at' => date('Y-m-d H:i:s'),
            'total' => $total,
            'tax' => $tax
        ]);
        
        $pdo->prepare('INSERT INTO sales(receipt_no, customer_name, payment_method, subtotal, tax, discount, total, amount_paid, change_due, status, cashier_name, mpesa_phone, payment_reference, created_by, mpesa_status, paid_at, vat_amount, vat_rate, etims_status, etims_unique_id, etims_transaction_id, kra_pin, invoice_type, etims_qr_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$receipt, $d['customer_name'] ?? 'Walk-in Customer', $paymentMethod, $subtotal, $tax, $discount, $total, $paid, $changeDue, $saleStatus, $user['full_name'] ?: $user['username'], $mpesaPhone, $paymentReference, $user['id'], $paymentMethod === 'M-Pesa' ? 'PENDING' : 'NOT_SENT', $paymentMethod === 'M-Pesa' ? null : date('Y-m-d H:i:s'), $tax, VAT_RATE, $etimsStatus, $etimsUniqueId, $etimsTransactionId, KRA_PIN, 'NORMAL', $qrCodeData]);
        $saleId = (int)$pdo->lastInsertId();
        foreach ($cart as $item) {
            $stmt = $pdo->prepare('SELECT name, selling_price FROM products WHERE id = ?');
            $stmt->execute([(int)$item['id']]);
            $p = $stmt->fetch();
            $pdo->prepare('INSERT INTO sale_items(sale_id, product_id, product_name, quantity, unit_price, total) VALUES(?,?,?,?,?,?)')
                ->execute([$saleId, (int)$item['id'], $p['name'], (int)$item['qty'], (float)$p['selling_price'], (float)$p['selling_price'] * (int)$item['qty']]);
        }
        $pdo->prepare('INSERT INTO sync_queue(entity, entity_id, action) VALUES(?,?,?)')->execute(['sale', $saleId, 'UPLOAD']);
        $pdo->commit();
        audit($user['username'], 'CHECKOUT', $receipt);
        if ($paymentMethod === 'M-Pesa') {
            json_response(['ok' => true, 'payment_status' => 'PENDING_PAYMENT', 'sale_id' => $saleId, 'receipt_no' => $receipt, 'payment_reference' => $paymentReference, 'amount' => $total, 'phone' => $mpesaPhone, 'poll_seconds' => 90]);
        }
        json_response(['ok' => true, 'payment_status' => 'PAID', 'sale_id' => $saleId, 'receipt_no' => $receipt]);
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
            $qrCode = $vscu->generateQRCode($sale);
            // Update sale with QR code
            db()->prepare('UPDATE sales SET etims_qr_code = ? WHERE id = ?')->execute([$qrCode, $id]);
            $sale['etims_qr_code'] = $qrCode;
        }
        
        json_response(['sale' => $sale, 'items' => $items, 'company' => ['name' => COMPANY_NAME, 'email' => COMPANY_EMAIL, 'location' => COMPANY_LOCATION, 'logo' => LOGO_WEB_PATH]]);
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
        
        $qrData = $vscu->generateQRCode($sale);
        
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
        audit($user['username'], 'BACKUP', basename($file));
        json_response(['ok' => true, 'file' => $file]);
    }

    if ($action === 'retry_sync') {
        require_role(['ADMIN','MANAGER']);
        db()->exec("UPDATE sync_queue SET retry_count = retry_count + 1, error_message = 'Waiting for real API credentials', status = 'PENDING' WHERE status != 'SYNCED'");
        json_response(['ok' => true]);
    }

    json_response(['error' => 'Unknown action'], 404);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['error' => $e->getMessage()], 500);
}
