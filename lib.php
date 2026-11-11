<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class SqliteResult
{
    private array $rows;
    private int $index = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetch(): array|false
    {
        if (!isset($this->rows[$this->index])) {
            return false;
        }
        return $this->rows[$this->index++];
    }

    public function fetchAll(): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        $values = array_values($row);
        return $values[$column] ?? false;
    }
}

final class SqliteStatement
{
    private SQLite3 $db;
    private string $sql;
    private ?SqliteResult $lastResult = null;

    public function __construct(SQLite3 $db, string $sql)
    {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool
    {
        $stmt = $this->db->prepare($this->sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->lastErrorMsg());
        }
        foreach (array_values($params) as $index => $value) {
            $type = SQLITE3_TEXT;
            if (is_int($value)) {
                $type = SQLITE3_INTEGER;
            } elseif (is_float($value)) {
                $type = SQLITE3_FLOAT;
            } elseif ($value === null) {
                $type = SQLITE3_NULL;
            }
            $stmt->bindValue($index + 1, $value, $type);
        }
        $result = $stmt->execute();
        if ($result instanceof SQLite3Result) {
            $rows = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
            $this->lastResult = new SqliteResult($rows);
            $result->finalize();
        }
        $stmt->close();
        return true;
    }

    public function fetch(): array|false
    {
        return $this->lastResult?->fetch() ?? false;
    }

    public function fetchAll(): array
    {
        return $this->lastResult?->fetchAll() ?? [];
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->lastResult?->fetchColumn($column) ?? false;
    }

    public function rowCount(): int
    {
        return $this->lastResult !== null ? count($this->lastResult->fetchAll()) : 0;
    }
}

final class SqliteDb
{
    private SQLite3 $db;
    private bool $transactionOpen = false;

    public function __construct(string $path)
    {
        $this->db = new SQLite3($path);
        $this->db->enableExceptions(true);
        $this->exec('PRAGMA foreign_keys = ON');
        $this->exec('PRAGMA journal_mode = WAL');
        $this->exec('PRAGMA busy_timeout = 5000');
    }

    public function exec(string $sql): bool
    {
        return $this->db->exec($sql);
    }

    public function query(string $sql): SqliteResult
    {
        $result = $this->db->query($sql);
        if (!$result) {
            throw new RuntimeException($this->db->lastErrorMsg());
        }
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $result->finalize();
        return new SqliteResult($rows);
    }

    public function prepare(string $sql): SqliteStatement
    {
        return new SqliteStatement($this->db, $sql);
    }

    public function beginTransaction(): bool
    {
        $this->transactionOpen = true;
        return $this->exec('BEGIN IMMEDIATE TRANSACTION');
    }

    public function commit(): bool
    {
        $this->transactionOpen = false;
        return $this->exec('COMMIT');
    }

    public function rollBack(): bool
    {
        $this->transactionOpen = false;
        return $this->exec('ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return $this->transactionOpen;
    }

    public function lastInsertId(): string
    {
        return (string)$this->db->lastInsertRowID();
    }

    public function raw(): SQLite3
    {
        return $this->db;
    }
}

function db(): PDO|SqliteDb
{
    static $connection = null;
    if ($connection instanceof PDO || $connection instanceof SqliteDb) {
        return $connection;
    }
    if (!is_dir(DATA_PATH)) {
        mkdir(DATA_PATH, 0775, true);
    }
    if (!is_dir(BACKUP_PATH)) {
        mkdir(BACKUP_PATH, 0775, true);
    }
    if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $connection = new PDO('sqlite:' . DB_PATH);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA busy_timeout = 5000');
        return $connection;
    }
    if (class_exists('SQLite3')) {
        $connection = new SqliteDb(DB_PATH);
        return $connection;
    }
    throw new RuntimeException('SQLite is not enabled in PHP. Enable pdo_sqlite or sqlite3 in php.ini, then restart the PHP server.');
}

function hash_password_local(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password_local(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function ensure_table_columns(string $table, array $columns): void
{
    $db = db();
    $existing = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    $names = [];
    foreach ($existing as $column) {
        $names[] = $column['name'];
    }

    foreach ($columns as $name => $definition) {
        if (!in_array($name, $names, true)) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
}

function init_db(): void
{
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            full_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('ADMIN','MANAGER','EMPLOYEE')),
            branch TEXT NOT NULL DEFAULT 'Main',
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT NOT NULL UNIQUE,
            barcode TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT '',
            image_path TEXT NOT NULL DEFAULT '',
            barcode_image_path TEXT NOT NULL DEFAULT '',
            warehouse TEXT NOT NULL DEFAULT 'Main',
            branch TEXT NOT NULL DEFAULT 'Main',
            buying_price REAL NOT NULL DEFAULT 0,
            selling_price REAL NOT NULL DEFAULT 0,
            quantity INTEGER NOT NULL DEFAULT 0,
            reorder_level INTEGER NOT NULL DEFAULT 5,
            supplier_id INTEGER,
            supplied_quantity INTEGER NOT NULL DEFAULT 0,
            vat_rate REAL NOT NULL DEFAULT 0.16,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            kra_pin TEXT NOT NULL DEFAULT '',
            balance REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            kra_pin TEXT NOT NULL DEFAULT '',
            balance REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL,
            salary REAL NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS branches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            location TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS cash_shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            opened_by INTEGER NOT NULL,
            opening_float REAL NOT NULL,
            cash_declared REAL,
            status TEXT NOT NULL DEFAULT 'OPEN',
            opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT,
            FOREIGN KEY(opened_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_no TEXT NOT NULL UNIQUE,
            customer_id INTEGER,
            customer_name TEXT NOT NULL DEFAULT 'Walk-in Customer',
            payment_method TEXT NOT NULL,
            subtotal REAL NOT NULL,
            tax REAL NOT NULL,
            discount REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL,
            amount_paid REAL NOT NULL DEFAULT 0,
            change_due REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'COMPLETED',
            mpesa_status TEXT NOT NULL DEFAULT 'NOT_SENT',
            etims_status TEXT NOT NULL DEFAULT 'PENDING',
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(customer_id) REFERENCES customers(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            total REAL NOT NULL,
            FOREIGN KEY(sale_id) REFERENCES sales(id),
            FOREIGN KEY(product_id) REFERENCES products(id)
        );
        CREATE TABLE IF NOT EXISTS held_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hold_no TEXT NOT NULL UNIQUE,
            customer_name TEXT NOT NULL DEFAULT '',
            cart_json TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_no TEXT NOT NULL,
            amount REAL NOT NULL,
            reason TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'PENDING',
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            category TEXT NOT NULL,
            amount REAL NOT NULL,
            paid_by TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS cash_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL CHECK(type IN ('INCOME','EXPENSE')),
            category TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            amount REAL NOT NULL,
            reference TEXT NOT NULL DEFAULT '',
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS quotations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quote_no TEXT NOT NULL UNIQUE,
            customer_id INTEGER,
            customer_name TEXT NOT NULL DEFAULT 'Walk-in Customer',
            subtotal REAL NOT NULL DEFAULT 0,
            tax REAL NOT NULL DEFAULT 0,
            discount REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'DRAFT',
            valid_until TEXT,
            converted_sale_id INTEGER,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(customer_id) REFERENCES customers(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS quotation_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quotation_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            total REAL NOT NULL,
            FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES products(id)
        );
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NOT NULL,
            po_number TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'DRAFT',
            total REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(supplier_id) REFERENCES suppliers(id)
        );
        CREATE TABLE IF NOT EXISTS sync_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'PENDING',
            retry_count INTEGER NOT NULL DEFAULT 0,
            error_message TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            synced_at TEXT
        );
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor TEXT NOT NULL,
            action TEXT NOT NULL,
            details TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            local_sale_id INTEGER NOT NULL DEFAULT 0,
            receipt_no TEXT NOT NULL DEFAULT '',
            payment_reference TEXT NOT NULL UNIQUE,
            phone TEXT NOT NULL DEFAULT '',
            amount REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'PENDING',
            checkout_request_id TEXT NOT NULL DEFAULT '',
            merchant_request_id TEXT NOT NULL DEFAULT '',
            stk_response_json TEXT NOT NULL DEFAULT '',
            result_code TEXT NOT NULL DEFAULT '',
            result_description TEXT NOT NULL DEFAULT '',
            mpesa_receipt TEXT NOT NULL DEFAULT '',
            callback_json TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at TEXT
        );
        CREATE TABLE IF NOT EXISTS payment_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_id INTEGER,
            payment_reference TEXT NOT NULL DEFAULT '',
            provider_reference TEXT NOT NULL DEFAULT '',
            event_type TEXT NOT NULL,
            result_code TEXT NOT NULL DEFAULT '',
            result_description TEXT NOT NULL DEFAULT '',
            payload_json TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(payment_id) REFERENCES payments(id)
        );
    ");

    ensure_table_columns('sales', [
        'cashier_name' => 'TEXT NOT NULL DEFAULT ""',
        'mpesa_phone' => 'TEXT NOT NULL DEFAULT ""',
        'payment_reference' => 'TEXT NOT NULL DEFAULT ""',
        'mpesa_request_id' => 'TEXT NOT NULL DEFAULT ""',
        'mpesa_receipt_no' => 'TEXT NOT NULL DEFAULT ""',
        'paid_at' => 'TEXT',
        'vat_amount' => 'REAL NOT NULL DEFAULT 0',
        'vat_rate' => 'REAL NOT NULL DEFAULT 0.16',
        'etims_invoice_no' => 'TEXT NOT NULL DEFAULT ""',
        'etims_error' => 'TEXT NOT NULL DEFAULT ""',
        'etims_unique_id' => 'TEXT NOT NULL DEFAULT ""',
        'etims_transaction_id' => 'TEXT NOT NULL DEFAULT ""',
        'etims_qr_code' => 'TEXT NOT NULL DEFAULT ""',
        'etims_qr_url' => 'TEXT NOT NULL DEFAULT ""',
        'kra_pin' => 'TEXT NOT NULL DEFAULT ""',
        'invoice_type' => 'TEXT NOT NULL DEFAULT "NORMAL"',
        'quotation_id' => 'INTEGER',
    ]);
    ensure_table_columns('products', [
        'supplier_id' => 'INTEGER',
        'supplied_quantity' => 'INTEGER NOT NULL DEFAULT 0',
        'vat_rate' => 'REAL NOT NULL DEFAULT 0.16',
    ]);
    ensure_table_columns('quotations', [
        'converted_sale_id' => 'INTEGER',
    ]);

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_payment_reference ON sales(payment_reference) WHERE payment_reference != ""');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_mpesa_request_id ON sales(mpesa_request_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payments_payment_reference ON payments(payment_reference) WHERE payment_reference != ""');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_checkout_request_id ON payments(checkout_request_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_events_provider_reference ON payment_events(provider_reference) WHERE provider_reference != ""');
    $pdo->exec('UPDATE products SET supplied_quantity = quantity + COALESCE((SELECT SUM(quantity) FROM sale_items WHERE sale_items.product_id = products.id), 0) WHERE supplied_quantity = 0');

    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO users(username, full_name, password_hash, role, branch) VALUES(?,?,?,?,?)');
        $stmt->execute(['admin', 'System Administrator', hash_password_local('admin123'), 'ADMIN', 'Main']);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO branches(name, location, phone) VALUES(?,?,?)')->execute(['Main', COMPANY_LOCATION, '']);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO customers(name, phone, email) VALUES(?,?,?)')->execute(['Walk-in Customer', '', COMPANY_EMAIL]);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO products(sku, barcode, name, category, brand, buying_price, selling_price, quantity, reorder_level) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach ([
            ['SH-001', '600001', 'Cement 50kg', 'Building Materials', 'Bamburi', 680, 790, 80, 15],
            ['SH-002', '600002', 'Binding Wire 20kg', 'Steel', 'Generic', 2100, 2450, 25, 5],
            ['SH-003', '600003', 'PVC Pipe 1 inch', 'Plumbing', 'Pipelife', 240, 320, 70, 10],
            ['SH-004', '600004', 'Roofing Nails 1kg', 'Fasteners', 'Generic', 180, 260, 100, 20],
        ] as $row) {
            $stmt->execute($row);
        }
    }
}

function require_login(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user'])) {
        json_response(['error' => 'Authentication required'], 401);
    }
    return $_SESSION['user'];
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        json_response(['error' => 'You do not have permission for this action'], 403);
    }
    return $user;
}

function input_json(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $form = [];
        parse_str($raw, $form);
        if (!empty($form)) {
            return $form;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $digits = ltrim($digits, '+');

    if ($digits === '') {
        throw new InvalidArgumentException('Enter a valid 254-format mobile number.');
    }

    if (str_starts_with($digits, '254')) {
        $digits = substr($digits, 0, 12);
    } elseif (str_starts_with($digits, '0')) {
        $digits = '254' . substr($digits, 1);
    } elseif (preg_match('/^\d{9}$/', $digits)) {
        $digits = '254' . $digits;
    }

    if (!preg_match('/^254\d{9,10}$/', $digits)) {
        throw new InvalidArgumentException('Enter a valid 254-format mobile number.');
    }
    return $digits;
}

function payment_reference(): string
{
    return 'PAY-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
}

function audit(string $actor, string $action, string $details = ''): void
{
    db()->prepare('INSERT INTO audit_logs(actor, action, details) VALUES(?,?,?)')->execute([$actor, $action, $details]);
}

function create_backup(string $label = 'manual'): string
{
    init_db();
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('The SQLite3 PHP extension is required for database backup.');
    }
    if (!is_dir(BACKUP_PATH)) {
        mkdir(BACKUP_PATH, 0775, true);
    }
    db()->exec('PRAGMA wal_checkpoint(FULL)');
    $file = BACKUP_PATH . DIRECTORY_SEPARATOR . 'backup_' . preg_replace('/[^a-z0-9_-]/i', '-', $label) . '_' . date('Ymd_His') . '.sqlite3';
    $source = new SQLite3(DB_PATH);
    $backup = new SQLite3($file);
    $source->backup($backup);
    $backup->close();
    $source->close();
    return $file;
}

function receipt_no(): string
{
    return 'SH-' . date('Ymd-His') . '-' . random_int(100, 999);
}
