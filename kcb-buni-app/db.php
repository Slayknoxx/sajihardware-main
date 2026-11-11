<?php
/**
 * db.php
 * Tiny SQLite wrapper to persist transactions so we can track
 * pending / successful / failed payments and let the frontend poll status.
 */

declare(strict_types=1);

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PDO SQLite extension is not enabled.');
    }

    $dbFile = __DIR__ . '/data/transactions.sqlite';
    $dataDir = dirname($dbFile);
    if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Could not create the data directory. Check filesystem permissions.');
    }
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            merchant_request_id TEXT,
            checkout_request_id TEXT UNIQUE,
            phone_number TEXT NOT NULL,
            amount TEXT NOT NULL,
            invoice_number TEXT,
            status TEXT NOT NULL DEFAULT 'PENDING', -- PENDING, SUCCESS, FAILED
            result_code TEXT,
            result_desc TEXT,
            mpesa_receipt TEXT,
            raw_callback TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    return $pdo;
}

function create_transaction(string $merchantRequestId, string $checkoutRequestId, string $phone, string $amount, string $invoice): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare("
        INSERT INTO transactions (merchant_request_id, checkout_request_id, phone_number, amount, invoice_number, status, created_at, updated_at)
        VALUES (:mrid, :crid, :phone, :amount, :invoice, 'PENDING', :now, :now)
    ");
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        ':mrid' => $merchantRequestId,
        ':crid' => $checkoutRequestId,
        ':phone' => $phone,
        ':amount' => $amount,
        ':invoice' => $invoice,
        ':now' => $now,
    ]);
}

function update_transaction_from_callback(string $checkoutRequestId, int $resultCode, string $resultDesc, ?string $mpesaReceipt, string $rawJson): void
{
    $pdo = get_db();
    $status = $resultCode === 0 ? 'SUCCESS' : 'FAILED';
    $stmt = $pdo->prepare("
        UPDATE transactions
        SET status = :status,
            result_code = :code,
            result_desc = :desc,
            mpesa_receipt = :receipt,
            raw_callback = :raw,
            updated_at = :now
        WHERE checkout_request_id = :crid
    ");
    $stmt->execute([
        ':status' => $status,
        ':code' => (string) $resultCode,
        ':desc' => $resultDesc,
        ':receipt' => $mpesaReceipt,
        ':raw' => $rawJson,
        ':now' => date('Y-m-d H:i:s'),
        ':crid' => $checkoutRequestId,
    ]);
}

function get_transaction(string $checkoutRequestId): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE checkout_request_id = :crid LIMIT 1");
    $stmt->execute([':crid' => $checkoutRequestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function list_transactions(?string $status = null): array
{
    $pdo = get_db();
    $sql = 'SELECT amount, phone_number, status, result_desc, mpesa_receipt, created_at, updated_at
            FROM transactions';
    $params = [];

    if ($status !== null) {
        $sql .= ' WHERE status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
