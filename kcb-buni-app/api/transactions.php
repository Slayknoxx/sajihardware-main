<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$requestedStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
$status = in_array($requestedStatus, ['SUCCESS', 'FAILED', 'PENDING'], true)
    ? $requestedStatus
    : null;

try {
    $rows = array_map(static function (array $row): array {
        $phone = (string) $row['phone_number'];
        $row['phone'] = strlen($phone) > 4
            ? str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4)
            : $phone;
        unset($row['phone_number']);
        return $row;
    }, list_transactions($status));

    echo json_encode(['success' => true, 'transactions' => $rows]);
} catch (Throwable $e) {
    error_log('Transaction listing error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Transaction storage is unavailable. Enable PDO SQLite in PHP.']);
}