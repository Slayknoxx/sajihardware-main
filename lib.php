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

final class DbHolder
{
    public static PDO|SqliteDb|null $connection = null;
}

function db(): PDO|SqliteDb
{
    if (DbHolder::$connection instanceof PDO || DbHolder::$connection instanceof SqliteDb) {
        return DbHolder::$connection;
    }
    if (!is_dir(DATA_PATH)) {
        mkdir(DATA_PATH, 0775, true);
    }
    if (!is_dir(BACKUP_PATH)) {
        mkdir(BACKUP_PATH, 0775, true);
    }
    if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        DbHolder::$connection = new PDO('sqlite:' . DB_PATH);
        DbHolder::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DbHolder::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        DbHolder::$connection->exec('PRAGMA foreign_keys = ON');
        DbHolder::$connection->exec('PRAGMA journal_mode = WAL');
        DbHolder::$connection->exec('PRAGMA busy_timeout = 5000');
        return DbHolder::$connection;
    }
    if (class_exists('SQLite3')) {
        DbHolder::$connection = new SqliteDb(DB_PATH);
        return DbHolder::$connection;
    }
    throw new RuntimeException('SQLite is not enabled in PHP. Enable pdo_sqlite or sqlite3 in php.ini, then restart the PHP server.');
}

/**
 * Release the current connection so the database file can be replaced (used by restore).
 */
function db_close(): void
{
    DbHolder::$connection = null;
}

/**
 * Read every application setting as a key => value map.
 */
function settings_all(): array
{
    $rows = db()->query('SELECT key, value FROM app_settings')->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[(string)$row['key']] = (string)$row['value'];
    }
    return $settings;
}

function settings_get(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM app_settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string)$value;
}

function settings_set(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO app_settings(key, value, updated_at) VALUES(?,?,CURRENT_TIMESTAMP)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([$key, $value]);
}

function settings_forget(string $key): void
{
    db()->prepare('DELETE FROM app_settings WHERE key = ?')->execute([$key]);
}

/**
 * Seed defaults once so licensing, branding and backups have stable values.
 */
function settings_seed(): void
{
    $defaults = [
        'company_name' => COMPANY_NAME,
        'company_email' => COMPANY_EMAIL,
        'company_location' => COMPANY_LOCATION,
        'company_phone' => '',
        'company_tagline' => 'Hardware, tools and building materials',
        'receipt_footer' => 'Thank you for shopping with ' . COMPANY_NAME . '.',
        'brand_color' => '#a70437',
        'logo_path' => LOGO_WEB_PATH,
        'setup_completed' => '0',
        'vat_rate' => (string)VAT_RATE,
        'license_key' => '',
        'license_revoked' => '0',
        'license_last_checkin' => '',
        'license_last_seen_at' => '',
        'license_clock_tampered' => '0',
        'backup_auto_enabled' => '1',
        'backup_keep' => '30',
        'backup_last_at' => '',
    ];
    foreach ($defaults as $key => $value) {
        if (settings_get($key) === null) {
            settings_set($key, $value);
        }
    }
    if (settings_get('install_id') === null) {
        settings_set('install_id', bin2hex(random_bytes(16)));
    }
    if (settings_get('trial_started_at') === null) {
        settings_set('trial_started_at', gmdate('c'));
    }
}

/**
 * Branding and company details used by the app shell and printed receipts.
 */
function company_profile(): array
{
    $name = (string)(settings_get('company_name', COMPANY_NAME) ?? COMPANY_NAME);
    return [
        'name' => $name,
        'email' => (string)(settings_get('company_email', COMPANY_EMAIL) ?? ''),
        'location' => (string)(settings_get('company_location', COMPANY_LOCATION) ?? ''),
        'phone' => (string)(settings_get('company_phone', '') ?? ''),
        'tagline' => (string)(settings_get('company_tagline', '') ?? ''),
        'footer' => (string)(settings_get('receipt_footer', 'Thank you for shopping with ' . $name . '.') ?? ''),
        'logo' => (string)(settings_get('logo_path', LOGO_WEB_PATH) ?? LOGO_WEB_PATH),
        'color' => (string)(settings_get('brand_color', '#a70437') ?? '#a70437'),
    ];
}


/**
 * Append a line to data/logs/app.log without ever throwing.
 */
function app_log(string $message, bool $sensitive = false): void
{
    try {
        $dir = DATA_PATH . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'app.log', $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        if (!$sensitive) {
            error_log($message);
        }
    }
}

function hash_password_local(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password_local(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * Temporary password handed to an admin once, then forced to be changed at
 * next login. Long enough to satisfy the 10-character policy.
 */
function generate_temp_password(): string
{
    return 'Saji-' . bin2hex(random_bytes(5)) . '!';
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            success INTEGER NOT NULL DEFAULT 0,
            attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(username, ip, attempted_at);
    ");

    ensure_table_columns('users', [
        'must_change_password' => 'INTEGER NOT NULL DEFAULT 0',
        'session_version' => 'INTEGER NOT NULL DEFAULT 0',
    ]);
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

    settings_seed();

    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO users(username, full_name, password_hash, role, branch, must_change_password) VALUES(?,?,?,?,?,1)');
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

function user_must_change_password(int $userId): bool
{
    $stmt = db()->prepare('SELECT must_change_password FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (int)($stmt->fetchColumn() ?: 0) === 1;
}

/**
 * Return the logged-in user, refreshed against the database.
 *
 * The session only stores an id; the account is re-read on every request so a
 * deleted/deactivated user, a changed role, or a database restored from a
 * different snapshot cannot keep using an old session cookie. Returns null when
 * the session is missing or no longer maps to an active account.
 */
function session_current_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || empty($sessionUser['id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, username, full_name, role, branch, session_version FROM users WHERE id = ? AND active = 1');
    $stmt->execute([(int)$sessionUser['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        $_SESSION = [];
        return null;
    }

    // Guard against id reuse after a restore: the stored username must still
    // identify the same account.
    if ((string)($sessionUser['username'] ?? '') !== (string)$row['username']) {
        $_SESSION = [];
        return null;
    }

    // A password reset (admin or CLI) bumps session_version and signs out
    // every existing session for that account.
    if ((int)($sessionUser['session_version'] ?? 0) !== (int)$row['session_version']) {
        $_SESSION = [];
        return null;
    }

    $user = [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'full_name' => (string)$row['full_name'],
        'role' => (string)$row['role'],
        'branch' => (string)$row['branch'],
        'session_version' => (int)$row['session_version'],
    ];
    $_SESSION['user'] = $user;
    return $user;
}

function require_login(): array
{
    $user = session_current_user();
    if ($user === null) {
        json_response(['error' => 'Authentication required'], 401);
    }
    return $user;
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

function backup_path_for(string $name): string
{
    $name = basename(trim($name));
    if (!preg_match('/^backup_[A-Za-z0-9_-]+\.sqlite3$/', $name)) {
        throw new InvalidArgumentException('Invalid backup file name');
    }
    $path = BACKUP_PATH . DIRECTORY_SEPARATOR . $name;
    $realPath = realpath($path);
    $realDir = realpath(BACKUP_PATH);
    if ($realPath === false || $realDir === false) {
        throw new InvalidArgumentException('Backup file not found');
    }
    $normalizedPath = strtolower(str_replace('/', '\\', $realPath));
    $normalizedDir = strtolower(rtrim(str_replace('/', '\\', $realDir), '\\')) . '\\';
    if (!str_starts_with($normalizedPath, $normalizedDir)) {
        throw new InvalidArgumentException('Backup file is outside the backup folder');
    }
    return $realPath;
}

function backup_list(): array
{
    if (!is_dir(BACKUP_PATH)) {
        return [];
    }
    $files = glob(BACKUP_PATH . DIRECTORY_SEPARATOR . 'backup_*.sqlite3') ?: [];
    $rows = [];
    foreach ($files as $file) {
        $name = basename($file);
        $label = 'manual';
        if (preg_match('/^backup_([A-Za-z0-9_-]+?)_\d{8}_\d{6}\.sqlite3$/', $name, $m)) {
            $label = $m[1];
        }
        $rows[] = [
            'file' => $name,
            'label' => $label,
            'size' => is_file($file) ? (int)filesize($file) : 0,
            'created_at' => is_file($file) ? date(DATE_ATOM, (int)filemtime($file)) : '',
            'modified' => is_file($file) ? (int)filemtime($file) : 0,
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
    return $rows;
}

function backup_prune(int $keep = 30): int
{
    $keep = max(1, $keep);
    $auto = array_values(array_filter(backup_list(), static fn(array $row): bool => $row['label'] === 'auto'));
    $deleted = 0;
    foreach (array_slice($auto, $keep) as $row) {
        $path = BACKUP_PATH . DIRECTORY_SEPARATOR . $row['file'];
        if (is_file($path) && unlink($path)) {
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Create at most one automatic backup every 24 hours, then prune old ones.
 */
function backup_auto_maybe(): void
{
    try {
        if ((string)(settings_get('backup_auto_enabled', '1') ?? '1') !== '1') {
            return;
        }
        $lastRaw = (string)(settings_get('backup_last_at', '') ?? '');
        $last = $lastRaw !== '' ? (int)strtotime($lastRaw) : 0;
        if ($last > 0 && (time() - $last) < 86400) {
            return;
        }
        create_backup('auto');
        settings_set('backup_last_at', gmdate('c'));
        backup_prune((int)(settings_get('backup_keep', '30') ?? 30));
    } catch (Throwable $e) {
        app_log('Automatic backup failed: ' . $e->getMessage());
    }
}

/**
 * Reject files that are not intact SAJI POS databases before live data is
 * replaced. Runs before the pre-restore safety copy so a bad upload cannot
 * consume a backup slot or leave a half-restored database.
 */
function backup_validate_file(string $path): void
{
    $required = ['users', 'products', 'sales', 'sale_items', 'app_settings'];
    try {
        $probe = new SQLite3($path, SQLITE3_OPEN_READONLY);
        try {
            $integrity = @$probe->querySingle('PRAGMA integrity_check');
            if (strtolower((string)$integrity) !== 'ok') {
                throw new RuntimeException('That backup failed its integrity check and was not restored.');
            }
            $stmt = $probe->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
            foreach ($required as $table) {
                $stmt->reset();
                $stmt->bindValue(':name', $table, SQLITE3_TEXT);
                $result = $stmt->execute();
                $found = $result instanceof SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : false;
                if (!$found) {
                    throw new RuntimeException('That file is not a complete SAJI POS backup (missing ' . $table . ').');
                }
            }
        } finally {
            $probe->close();
        }
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new RuntimeException('That file is not a readable SAJI POS backup.');
    }
}

/**
 * Restore a backup over the live database, keeping a safety copy first.
 */
function backup_restore(string $name): string
{
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('The SQLite3 PHP extension is required to restore backups.');
    }
    $path = backup_path_for($name);
    backup_validate_file($path);
    $safety = create_backup('pre_restore');

    db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    db_close();
    foreach ([DB_PATH . '-wal', DB_PATH . '-shm'] as $sidecar) {
        if (is_file($sidecar)) {
            @unlink($sidecar);
        }
    }

    $source = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $destination = new SQLite3(DB_PATH);
    $source->backup($destination);
    $destination->close();
    $source->close();

    init_db();
    settings_set('backup_last_at', gmdate('c'));
    return $safety;
}

function receipt_no(): string
{
    return 'SH-' . date('Ymd-His') . '-' . random_int(100, 999);
}
