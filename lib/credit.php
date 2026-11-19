<?php
declare(strict_types=1);

/**
 * Credit sales, customer payments, and receivables.
 *
 * A Credit checkout adds the unpaid remainder to the customer's balance.
 * Payments reduce the balance and are allocated to the customer's unpaid
 * credit sales oldest-first, so every sale's amount_paid keeps tracking the
 * money actually received.
 */

/**
 * Record a payment from a customer against their credit balance.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input customer_id, amount, method, reference.
 * @return array Summary of the payment.
 */
function customer_payment_record(array $user, array $input): array
{
    $customerId = filter_var($input['customer_id'] ?? null, FILTER_VALIDATE_INT);
    if ($customerId === false || $customerId < 1) {
        throw new InvalidArgumentException('Choose a valid customer.');
    }

    $amount = money_to_minor($input['amount'] ?? null);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Enter a payment amount greater than zero.');
    }

    $method = trim((string)($input['method'] ?? 'Cash')) ?: 'Cash';
    if (!in_array($method, ['Cash', 'M-Pesa', 'Card', 'Bank', 'Mobile Money', 'Voucher'], true)) {
        throw new InvalidArgumentException('Choose a supported payment method.');
    }

    $reference = trim((string)($input['reference'] ?? ''));
    if (mb_strlen($reference) > 120) {
        throw new InvalidArgumentException('Reference must be 120 characters or fewer.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $lookup = $pdo->prepare('SELECT id, name, balance FROM customers WHERE id = ?');
        $lookup->execute([$customerId]);
        $customer = $lookup->fetch();
        if (!$customer) {
            throw new InvalidArgumentException('Customer not found.');
        }

        $balance = (int)$customer['balance'];
        if ($balance <= 0) {
            throw new InvalidArgumentException('This customer has no outstanding balance.');
        }
        if ($amount > $balance) {
            throw new InvalidArgumentException('Payment cannot exceed the outstanding balance.');
        }

        $remaining = $amount;
        $allocations = [];
        $unpaid = $pdo->prepare(
            "SELECT id, receipt_no, total, amount_paid FROM sales
             WHERE customer_id = ? AND payment_method = 'Credit' AND status = 'COMPLETED'
                   AND amount_paid < total
             ORDER BY created_at, id"
        );
        $unpaid->execute([$customerId]);
        $update = $pdo->prepare('UPDATE sales SET amount_paid = amount_paid + ? WHERE id = ?');
        foreach ($unpaid->fetchAll() as $sale) {
            if ($remaining <= 0) {
                break;
            }
            $due = (int)$sale['total'] - (int)$sale['amount_paid'];
            $applied = min($due, $remaining);
            $update->execute([$applied, (int)$sale['id']]);
            $remaining -= $applied;
            $allocations[] = ['receipt_no' => $sale['receipt_no'], 'amount' => $applied];
        }

        $pdo->prepare('INSERT INTO customer_payments(customer_id, amount, method, reference, created_by) VALUES(?,?,?,?,?)')
            ->execute([$customerId, $amount, $method, $reference, (int)($user['id'] ?? 0)]);

        $newBalance = $balance - $amount;
        $pdo->prepare('UPDATE customers SET balance = ? WHERE id = ?')
            ->execute([$newBalance, $customerId]);

        if ($method === 'Cash') {
            $pdo->prepare('INSERT INTO cash_transactions(type, category, description, amount, reference, created_by) VALUES(?,?,?,?,?,?)')
                ->execute([
                    'INCOME',
                    'Customer payment',
                    'Payment from ' . $customer['name'],
                    $amount,
                    $reference !== '' ? $reference : (string)$customerId,
                    (int)($user['id'] ?? 0),
                ]);
        }

        audit(
            (string)($user['username'] ?? 'system'),
            'CUSTOMER_PAYMENT',
            ($customer['name'] ?? '') . ' ' . number_format(money_minor_to_major($amount), 2, '.', '') . ' ' . $method
                . ($reference !== '' ? ' ref ' . $reference : '')
        );

        db_commit();

        return [
            'ok' => true,
            'customer_id' => $customerId,
            'customer' => (string)$customer['name'],
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'previous_balance' => $balance,
            'balance' => $newBalance,
            'allocations' => $allocations,
        ];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}

/**
 * Outstanding credit balance and ageing for the receivables view.
 *
 * @return array Summary with debtor totals and ageing buckets in minor units.
 */
function receivables_summary(): array
{
    $pdo = db();

    $totals = $pdo->query(
        "SELECT COALESCE(SUM(balance),0) AS outstanding, COUNT(*) AS debtors
         FROM customers WHERE balance > 0"
    )->fetch() ?: ['outstanding' => 0, 'debtors' => 0];

    $buckets = $pdo->query(
        "SELECT CASE
                WHEN julianday('now') - julianday(s.created_at) <= 30 THEN 'current'
                WHEN julianday('now') - julianday(s.created_at) <= 60 THEN '31_60'
                WHEN julianday('now') - julianday(s.created_at) <= 90 THEN '61_90'
                ELSE 'over_90'
            END AS bucket,
            COALESCE(SUM(s.total - s.amount_paid),0) AS amount,
            COUNT(*) AS sales
         FROM sales s
         WHERE s.payment_method = 'Credit' AND s.status = 'COMPLETED' AND s.amount_paid < s.total
         GROUP BY bucket"
    )->fetchAll();

    $ageing = ['current' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
    foreach ($buckets as $bucket) {
        $ageing[(string)$bucket['bucket']] = (int)$bucket['amount'];
    }

    $debtors = $pdo->query(
        "SELECT id, name, phone, balance FROM customers WHERE balance > 0 ORDER BY balance DESC LIMIT 50"
    )->fetchAll();

    return [
        'outstanding' => (int)$totals['outstanding'],
        'debtors' => (int)$totals['debtors'],
        'ageing' => $ageing,
        'debtors_list' => $debtors,
    ];
}
