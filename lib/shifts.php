<?php
declare(strict_types=1);

/**
 * Cash shift reconciliation.
 *
 * A cashier opens a shift with an opening float, sells through the day, and
 * closes it by declaring the cash in the till. The expected figure is the
 * opening float plus the cashier's cash sales and cash income, minus cash
 * expenses paid from the till during the shift; the variance is declared
 * minus expected.
 */

/**
 * Cash movement breakdown for a shift window.
 *
 * Cash sales, cash INCOME rows, and cash refunds paid back on returns put money
 * in the drawer; cash EXPENSE rows (manual cash movements, supplier payments,
 * and other cash movements) take money out. All are scoped to the cashier who
 * opened the shift.
 *
 * @return array{sales:int,income:int,expenses:int,refunds:int}
 */
function shift_cash_breakdown(array $shift, ?string $closedAt = null): array
{
    $until = $closedAt ?? date('Y-m-d H:i:s');
    $userId = (int)$shift['opened_by'];
    $openedAt = (string)$shift['opened_at'];

    $stmt = db()->prepare(
        "SELECT
            (SELECT COALESCE(SUM(total),0) FROM sales
              WHERE created_by = ? AND payment_method = 'Cash' AND status = 'COMPLETED'
                    AND created_at > ? AND created_at <= ?) AS sales,
            (SELECT COALESCE(SUM(amount),0) FROM cash_transactions
              WHERE created_by = ? AND type = 'INCOME'
                    AND created_at > ? AND created_at <= ?) AS income,
            (SELECT COALESCE(SUM(amount),0) FROM cash_transactions
              WHERE created_by = ? AND type = 'EXPENSE'
                    AND created_at > ? AND created_at <= ?) AS expenses,
            (SELECT COALESCE(SUM(amount),0) FROM returns
              WHERE created_by = ? AND refund_method = 'Cash' AND status = 'COMPLETED'
                    AND created_at > ? AND created_at <= ?) AS refunds"
    );
    $stmt->execute([
        $userId, $openedAt, $until,
        $userId, $openedAt, $until,
        $userId, $openedAt, $until,
        $userId, $openedAt, $until,
    ]);
    $row = $stmt->fetch() ?: ['sales' => 0, 'income' => 0, 'expenses' => 0, 'refunds' => 0];

    return [
        'sales' => (int)$row['sales'],
        'income' => (int)$row['income'],
        'expenses' => (int)$row['expenses'],
        'refunds' => (int)$row['refunds'],
    ];
}

/**
 * Expected cash in the till for a shift.
 *
 * Opening float plus the cashier's cash sales and cash income, minus any cash
 * paid out of the till during the shift: supplier payments, manual cash
 * movements, and cash refunds on returns.
 */
function shift_expected_cash(array $shift, ?string $closedAt = null): int
{
    $cash = shift_cash_breakdown($shift, $closedAt);
    return (int)$shift['opening_float'] + $cash['sales'] + $cash['income']
        - $cash['expenses'] - $cash['refunds'];
}

/**
 * Open a cash shift for the signed-in user.
 *
 * @param array $user Authenticated user.
 * @return array Summary of the opened shift.
 */
function shift_open(array $user, array $input): array
{
    $openingFloat = money_to_minor($input['opening_float'] ?? 0);
    if ($openingFloat < 0) {
        throw new InvalidArgumentException('Opening float cannot be negative.');
    }

    $pdo = db();
    $open = $pdo->prepare("SELECT id FROM cash_shifts WHERE opened_by = ? AND status = 'OPEN'");
    $open->execute([(int)$user['id']]);
    if ($open->fetchColumn() !== false) {
        throw new InvalidArgumentException('You already have an open cash shift.');
    }

    db_begin_immediate();
    try {
        $pdo->prepare('INSERT INTO cash_shifts(opened_by, opening_float) VALUES(?,?)')
            ->execute([(int)$user['id'], $openingFloat]);
        $shiftId = (int)$pdo->lastInsertId();
        audit((string)($user['username'] ?? 'system'), 'SHIFT_OPEN', 'float ' . number_format(money_minor_to_major($openingFloat), 2, '.', ''));
        db_commit();
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }

    return [
        'ok' => true,
        'shift_id' => $shiftId,
        'opening_float' => $openingFloat,
        'status' => 'OPEN',
    ];
}

/**
 * Close the signed-in user's open shift with the declared cash amount.
 *
 * @param array $user Authenticated user.
 * @param array $input cash_declared.
 * @return array Summary with expected cash and variance.
 */
function shift_close(array $user, array $input): array
{
    $declared = money_to_minor($input['cash_declared'] ?? null);
    if ($declared < 0) {
        throw new InvalidArgumentException('Declared cash cannot be negative.');
    }

    $pdo = db();
    $open = $pdo->prepare("SELECT * FROM cash_shifts WHERE opened_by = ? AND status = 'OPEN'");
    $open->execute([(int)$user['id']]);
    $shift = $open->fetch();
    if (!$shift) {
        throw new InvalidArgumentException('You have no open cash shift.');
    }

    db_begin_immediate();
    try {
        $closedAt = date('Y-m-d H:i:s');
        $expected = shift_expected_cash($shift, $closedAt);
        $variance = $declared - $expected;

        $pdo->prepare('UPDATE cash_shifts SET status = ?, closed_at = ?, closed_by = ?, cash_declared = ?, cash_expected = ?, variance = ? WHERE id = ?')
            ->execute(['CLOSED', $closedAt, (int)$user['id'], $declared, $expected, $variance, (int)$shift['id']]);

        audit(
            (string)($user['username'] ?? 'system'),
            'SHIFT_CLOSE',
            'expected ' . number_format(money_minor_to_major($expected), 2, '.', '')
            . ' declared ' . number_format(money_minor_to_major($declared), 2, '.', '')
            . ' variance ' . number_format(money_minor_to_major($variance), 2, '.', '')
        );

        db_commit();
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }

    return [
        'ok' => true,
        'shift_id' => (int)$shift['id'],
        'cash_expected' => $expected,
        'cash_declared' => $declared,
        'variance' => $variance,
        'closed_at' => $closedAt,
    ];
}

/**
 * The signed-in user's open shift with live expected cash, or null.
 *
 * @param array $user Authenticated user.
 * @return array|null Current shift summary.
 */
function shift_current(array $user): ?array
{
    $stmt = db()->prepare("SELECT * FROM cash_shifts WHERE opened_by = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$user['id']]);
    $shift = $stmt->fetch();
    if (!$shift) {
        return null;
    }

    $cash = shift_cash_breakdown($shift);

    return [
        'shift_id' => (int)$shift['id'],
        'opened_at' => (string)$shift['opened_at'],
        'opening_float' => (int)$shift['opening_float'],
        'cash_sales' => $cash['sales'],
        'cash_in' => $cash['income'],
        'cash_out' => $cash['expenses'],
        'cash_refunds' => $cash['refunds'],
        'cash_expected' => (int)$shift['opening_float'] + $cash['sales'] + $cash['income']
            - $cash['expenses'] - $cash['refunds'],
        'status' => (string)$shift['status'],
    ];
}

/**
 * Recent shifts with cashier names, for the Cash Flow reconciliation view.
 *
 * @param int $limit Maximum rows.
 * @return array<int, array<string, mixed>> Newest shifts first.
 */
function shift_history(int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->query(
        "SELECT cs.*, ou.username AS opened_by_name, cu.username AS closed_by_name
         FROM cash_shifts cs
         LEFT JOIN users ou ON ou.id = cs.opened_by
         LEFT JOIN users cu ON cu.id = cs.closed_by
         ORDER BY cs.id DESC
         LIMIT {$limit}"
    );
    return $stmt->fetchAll();
}
