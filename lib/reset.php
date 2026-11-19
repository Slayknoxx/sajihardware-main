<?php
declare(strict_types=1);

/**
 * "Start afresh": wipe business data while keeping user accounts.
 *
 * Foreign keys are enforced, so tables are deleted children-first: line items
 * and payments before the rows they point at, then products, people, and
 * branches. User accounts, settings, and the audit trail are preserved.
 *
 * @param array $user Signed-in ADMIN (recorded in the audit trail).
 */
function business_data_reset(array $user): void
{
    $pdo = db();
    db_begin_immediate();
    try {
        foreach ([
            'payment_events', 'payments', 'checkout_requests', 'sync_queue',
            'quotation_items', 'quotations', 'purchase_order_items', 'purchase_orders',
            'sale_items', 'sales', 'stock_movements', 'held_sales', 'returns',
            'cash_transactions', 'expenses', 'customer_payments', 'supplier_payments',
            'products', 'customers', 'suppliers', 'employees', 'branches', 'cash_shifts',
        ] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
        $pdo->prepare('INSERT INTO branches(name, location, phone) VALUES(?,?,?)')
            ->execute(['Main', COMPANY_LOCATION, '']);
        $pdo->prepare('INSERT INTO customers(name, phone, email) VALUES(?,?,?)')
            ->execute(['Walk-in Customer', '', COMPANY_EMAIL]);

        audit(
            (string)($user['username'] ?? 'system'),
            'RESET_BUSINESS_DATA',
            'Business data reset; user accounts preserved'
        );
        db_commit();
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}
