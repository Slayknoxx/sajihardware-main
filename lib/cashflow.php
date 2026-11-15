<?php
declare(strict_types=1);

/**
 * Cash-flow ledger and editing.
 *
 * The ledger combines recorded expenses with manual cash movements so the
 * Cash Flow page shows every entry behind the summary cards. Admins can edit
 * either kind of entry; managers keep read-only access to the ledger.
 */

/**
 * Fetch expenses and manual cash movements for the active period.
 *
 * @param string $startExpr SQLite expression matching the period start.
 * @param string $endExpr   SQLite expression matching the period end.
 * @param string $anchor    Date used by the period expressions.
 * @param int|null $limit   Maximum rows to return, or null for all rows.
 * @return array Combined rows with a source column.
 */
function cash_flow_entries(string $startExpr, string $endExpr, string $anchor, ?int $limit = 300): array
{
    $limitSql = $limit === null ? '' : ' LIMIT ' . max(1, min(1000, $limit));
    $sql = "SELECT 'expense' AS source, id, 'EXPENSE' AS type, category,
                   title AS description, amount, paid_by AS reference, created_at
            FROM expenses
            WHERE {$startExpr} = {$endExpr}
            UNION ALL
            SELECT 'cash_transaction' AS source, id, type, category,
                   description, amount, reference, created_at
            FROM cash_transactions
            WHERE {$startExpr} = {$endExpr}
            ORDER BY created_at DESC, id DESC
            {$limitSql}";
    $stmt = db()->prepare($sql);
    $stmt->execute([$anchor, $anchor, $anchor, $anchor]);
    return $stmt->fetchAll();
}

function cash_flow_report(array $input, ?int $entryLimit = 300): array
{
    $period = in_array($input['period'] ?? 'month', ['day', 'month', 'year'], true)
        ? (string)$input['period']
        : 'month';
    $anchor = trim((string)($input['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
        $anchor = date('Y-m-d');
    }

    $formats = [
        'day' => ["date(?)", "date(?)", "strftime('%H:00', created_at)"],
        'month' => ["strftime('%Y-%m', ?)", "strftime('%Y-%m', ?)", "strftime('%d', created_at)"],
        'year' => ["strftime('%Y', ?)", "strftime('%Y', ?)", "strftime('%m', created_at)"],
    ];
    [$startExpr, $endExpr, $bucketFormat] = $formats[$period];
    $salesWhere = "status='COMPLETED' AND {$startExpr} = {$endExpr}";

    $salesStmt = db()->prepare(
        "SELECT COALESCE(SUM(CASE WHEN payment_method='Cash' THEN total ELSE 0 END),0) cash_sales,
                COALESCE(SUM(total),0) total_sales
         FROM sales WHERE {$salesWhere}"
    );
    $salesStmt->execute([$anchor, $anchor]);
    $sales = $salesStmt->fetch();

    $expenseStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) total_expenses FROM expenses WHERE {$startExpr} = {$endExpr}");
    $expenseStmt->execute([$anchor, $anchor]);
    $expenses = $expenseStmt->fetch();

    $movementStmt = db()->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type='INCOME' THEN amount ELSE 0 END),0) other_income,
                COALESCE(SUM(CASE WHEN type='EXPENSE' THEN amount ELSE 0 END),0) other_expenses
         FROM cash_transactions WHERE {$startExpr} = {$endExpr}"
    );
    $movementStmt->execute([$anchor, $anchor]);
    $movements = $movementStmt->fetch();

    $seriesStmt = db()->prepare(
        "SELECT bucket, COALESCE(SUM(income),0) income, COALESCE(SUM(expenses),0) expenses
         FROM (
             SELECT {$bucketFormat} bucket, CASE WHEN payment_method='Cash' THEN total ELSE 0 END income, 0 expenses
             FROM sales WHERE {$salesWhere}
             UNION ALL
             SELECT {$bucketFormat} bucket, 0 income, amount expenses FROM expenses WHERE {$startExpr} = {$endExpr}
             UNION ALL
             SELECT {$bucketFormat} bucket,
                    CASE WHEN type='INCOME' THEN amount ELSE 0 END income,
                    CASE WHEN type='EXPENSE' THEN amount ELSE 0 END expenses
             FROM cash_transactions WHERE {$startExpr} = {$endExpr}
         ) GROUP BY bucket ORDER BY bucket"
    );
    $seriesStmt->execute(array_merge([$anchor, $anchor], [$anchor, $anchor], [$anchor, $anchor]));
    $series = $seriesStmt->fetchAll();

    $totalExpenses = (float)$expenses['total_expenses'] + (float)$movements['other_expenses'];
    $totalIncome = (float)$sales['cash_sales'] + (float)$movements['other_income'];

    return [
        'period' => $period,
        'date' => $anchor,
        'summary' => [
            'cash_sales' => $sales['cash_sales'],
            'total_sales' => $sales['total_sales'],
            'expenses' => $totalExpenses,
            'other_income' => $movements['other_income'],
            'other_expenses' => $movements['other_expenses'],
            'net_cash' => $totalIncome - $totalExpenses,
        ],
        'series' => $series,
        'entries' => cash_flow_entries($startExpr, $endExpr, $anchor, $entryLimit),
    ];
}

/**
 * Update an expense or manual cash movement.
 *
 * @param array $user  Authenticated admin user (for the audit trail).
 * @param array $input source, id, category, description, amount, reference, type.
 * @return array Summary of the update.
 */
function cash_entry_update(array $user, array $input): array
{
    $source = (string)($input['source'] ?? '');
    if (!in_array($source, ['expense', 'cash_transaction'], true)) {
        throw new InvalidArgumentException('Choose an expense or cash movement to edit.');
    }

    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid entry to edit.');
    }

    $category = trim((string)($input['category'] ?? ''));
    if ($category === '') {
        throw new InvalidArgumentException('Category is required.');
    }
    if (mb_strlen($category) > 80) {
        throw new InvalidArgumentException('Category must be 80 characters or fewer.');
    }

    $description = trim((string)($input['description'] ?? ''));
    if ($source === 'expense' && $description === '') {
        throw new InvalidArgumentException('Title is required.');
    }
    if (mb_strlen($description) > 255) {
        throw new InvalidArgumentException('Description must be 255 characters or fewer.');
    }

    $reference = trim((string)($input['reference'] ?? ''));
    if (mb_strlen($reference) > 120) {
        throw new InvalidArgumentException('Reference must be 120 characters or fewer.');
    }

    $amount = money_to_minor($input['amount'] ?? '');
    if ($amount < 0) {
        throw new InvalidArgumentException('Amount cannot be negative.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        if ($source === 'expense') {
            $stmt = $pdo->prepare('SELECT id FROM expenses WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('Expense not found.');
            }
            $pdo->prepare('UPDATE expenses SET title = ?, category = ?, amount = ?, paid_by = ? WHERE id = ?')
                ->execute([$description, $category, $amount, $reference, $id]);
            $auditAction = 'UPDATE_EXPENSE';
        } else {
            $type = strtoupper(trim((string)($input['type'] ?? 'EXPENSE')));
            if (!in_array($type, ['INCOME', 'EXPENSE'], true)) {
                throw new InvalidArgumentException('Cash movement type must be INCOME or EXPENSE.');
            }
            $stmt = $pdo->prepare('SELECT id FROM cash_transactions WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('Cash movement not found.');
            }
            $pdo->prepare('UPDATE cash_transactions SET type = ?, category = ?, description = ?, amount = ?, reference = ? WHERE id = ?')
                ->execute([$type, $category, $description, $amount, $reference, $id]);
            $auditAction = 'UPDATE_CASH_TRANSACTION';
        }

        audit((string)($user['username'] ?? 'system'), $auditAction, $category . ' ' . money_minor_to_major($amount));

        db_commit();

        return [
            'ok' => true,
            'source' => $source,
            'id' => $id,
            'category' => $category,
            'description' => $description,
            'amount' => $amount,
        ];
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}
