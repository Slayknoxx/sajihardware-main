<?php
declare(strict_types=1);

/**
 * Business analytics aggregation.
 *
 * Every figure is derived from confirmed money (`sales.status = 'COMPLETED'`)
 * unless it is explicitly an exposure metric (pending payments, voids).
 * Timestamps are stored in UTC; the shop timezone comes from PHP's configured
 * timezone, so period boundaries are converted to UTC once and reused.
 *
 * Money values are returned in minor units. `json_response()` converts the
 * known money keys to major units at the HTTP boundary.
 */

function analytics_timezone(): DateTimeZone
{
    return new DateTimeZone(date_default_timezone_get() ?: 'UTC');
}

/**
 * Build UTC comparison strings for a local date range, inclusive of both days.
 */
function analytics_utc_bounds(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $utc = new DateTimeZone('UTC');
    return [
        'start' => $start->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
        'end' => $end->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

function analytics_period(string $key, ?string $start = null, ?string $end = null): array
{
    $timezone = analytics_timezone();
    $today = new DateTimeImmutable('today', $timezone);
    $allowed = ['today', '7d', 'mtd', 'last_month', 'qtd', 'ytd', 'custom'];
    if (!in_array($key, $allowed, true)) {
        $key = 'mtd';
    }

    $parse = static function (?string $value) use ($timezone): ?DateTimeImmutable {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        return $date instanceof DateTimeImmutable ? $date : null;
    };

    $monthStart = $today->modify('first day of this month');
    $comparisonLabel = 'previous period';

    switch ($key) {
        case 'today':
            $startDate = $today;
            $endDate = $today;
            $compareStart = $today->modify('-1 day');
            $compareEnd = $compareStart;
            $label = 'Today';
            $comparisonLabel = 'yesterday';
            break;

        case '7d':
            $startDate = $today->modify('-6 days');
            $endDate = $today;
            $compareStart = $startDate->modify('-7 days');
            $compareEnd = $startDate->modify('-1 day');
            $label = 'Last 7 days';
            $comparisonLabel = 'previous 7 days';
            break;

        case 'last_month':
            $startDate = $monthStart->modify('-1 month');
            $endDate = $startDate->modify('last day of this month');
            $compareStart = $startDate->modify('-1 month');
            $compareEnd = $compareStart->modify('last day of this month');
            $label = $startDate->format('F Y');
            $comparisonLabel = $compareStart->format('F Y');
            break;

        case 'qtd':
            $month = (int)$today->format('n');
            $quarterStartMonth = intdiv($month - 1, 3) * 3 + 1;
            $startDate = $today->setDate((int)$today->format('Y'), $quarterStartMonth, 1);
            $endDate = $today;
            $elapsedDays = (int)$startDate->diff($today)->days;
            $compareStart = $startDate->modify('-3 months');
            $previousQuarterEnd = $compareStart->modify('last day of this month')
                ->modify('+2 months')->modify('last day of this month');
            $compareEnd = $compareStart->modify('+' . $elapsedDays . ' days');
            if ($compareEnd > $previousQuarterEnd) {
                $compareEnd = $previousQuarterEnd;
            }
            $label = 'Q' . (int)ceil($month / 3) . ' ' . $today->format('Y');
            $comparisonLabel = 'previous quarter to date';
            break;

        case 'ytd':
            $startDate = $today->setDate((int)$today->format('Y'), 1, 1);
            $endDate = $today;
            $compareStart = $startDate->modify('-1 year');
            $compareEnd = $today->modify('-1 year');
            $label = 'Year to date';
            $comparisonLabel = 'same period last year';
            break;

        case 'custom':
            $startDate = $parse($start) ?? $today->modify('-29 days');
            $endDate = $parse($end) ?? $today;
            if ($endDate < $startDate) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }
            if ((int)$startDate->diff($endDate)->days > 3660) {
                $startDate = $endDate->modify('-3660 days');
            }
            $span = (int)$startDate->diff($endDate)->days;
            $compareEnd = $startDate->modify('-1 day');
            $compareStart = $compareEnd->modify('-' . $span . ' days');
            $label = $startDate->format('d M Y') . ' – ' . $endDate->format('d M Y');
            $comparisonLabel = 'previous ' . ($span + 1) . ' days';
            break;

        case 'mtd':
        default:
            $startDate = $monthStart;
            $endDate = $today;
            $previousMonth = $monthStart->modify('-1 month');
            $dayOfMonth = (int)$today->format('j');
            $previousMonthDays = (int)$previousMonth->format('t');
            $compareStart = $previousMonth;
            $compareEnd = $previousMonth->setDate(
                (int)$previousMonth->format('Y'),
                (int)$previousMonth->format('n'),
                min($dayOfMonth, $previousMonthDays)
            );
            $label = 'Month to date';
            $comparisonLabel = 'same period last month';
            break;
    }

    $lastYearStart = $startDate->modify('-1 year');
    $lastYearEnd = $endDate->modify('-1 year');

    return [
        'key' => $key,
        'label' => $label,
        'start' => $startDate->format('Y-m-d'),
        'end' => $endDate->format('Y-m-d'),
        'days' => (int)$startDate->diff($endDate)->days + 1,
        'bounds' => analytics_utc_bounds($startDate, $endDate),
        'comparison_label' => $comparisonLabel,
        'comparison' => [
            'label' => $comparisonLabel,
            'start' => $compareStart->format('Y-m-d'),
            'end' => $compareEnd->format('Y-m-d'),
            'bounds' => analytics_utc_bounds($compareStart, $compareEnd),
        ],
        'last_year' => [
            'label' => 'same period last year',
            'start' => $lastYearStart->format('Y-m-d'),
            'end' => $lastYearEnd->format('Y-m-d'),
            'bounds' => analytics_utc_bounds($lastYearStart, $lastYearEnd),
        ],
        'offset_seconds' => $timezone->getOffset($startDate),
    ];
}

function analytics_change(float|int|null $current, float|int|null $previous): ?float
{
    if ($previous === null || (float)$previous === 0.0) {
        return null;
    }
    return round((((float)$current - (float)$previous) / abs((float)$previous)) * 100, 1);
}

function analytics_sales_totals(array $bounds): array
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(total),0) AS revenue,
                COUNT(*) AS transactions,
                COALESCE(SUM(subtotal),0) AS gross_subtotal,
                COALESCE(SUM(discount),0) AS discounts,
                COALESCE(SUM(vat_amount),0) AS vat,
                COALESCE(SUM(CASE WHEN payment_method='Cash' THEN total ELSE 0 END),0) AS cash_revenue,
                COALESCE(SUM(CASE WHEN payment_method='Cash' THEN 1 ELSE 0 END),0) AS cash_count,
                COALESCE(SUM(CASE WHEN payment_method='M-Pesa' THEN total ELSE 0 END),0) AS mpesa_revenue,
                COALESCE(SUM(CASE WHEN payment_method='M-Pesa' THEN 1 ELSE 0 END),0) AS mpesa_count,
                MAX(created_at) AS last_sale_at
         FROM sales
         WHERE status='COMPLETED' AND created_at BETWEEN ? AND ?"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    $row = $stmt->fetch() ?: [];
    $transactions = (int)($row['transactions'] ?? 0);
    $row['transactions'] = $transactions;
    $row['avg_basket'] = $transactions > 0 ? round((float)$row['revenue'] / $transactions, 2) : 0.0;
    return $row;
}

function analytics_profit_totals(array $bounds): array
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(si.total),0) AS line_revenue,
                COALESCE(SUM(CASE WHEN p.buying_price > 0 THEN si.total ELSE 0 END),0) AS costed_revenue,
                COALESCE(SUM(si.quantity * p.buying_price),0) AS cost
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         JOIN products p ON p.id = si.product_id
         WHERE s.status='COMPLETED' AND s.created_at BETWEEN ? AND ?"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    return $stmt->fetch() ?: ['line_revenue' => 0, 'costed_revenue' => 0, 'cost' => 0];
}

function analytics_top_products(array $bounds, string $order, int $limit = 10): array
{
    $orderBy = $order === 'units' ? 'units DESC, revenue DESC' : 'revenue DESC, units DESC';
    $stmt = db()->prepare(
        "SELECT si.product_name AS name,
                COALESCE(SUM(si.quantity),0) AS units,
                COALESCE(SUM(si.total),0) AS revenue
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE s.status='COMPLETED' AND s.created_at BETWEEN ? AND ?
         GROUP BY si.product_id, si.product_name
         ORDER BY {$orderBy}
         LIMIT {$limit}"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    return $stmt->fetchAll();
}

function analytics_top_cashiers(array $bounds, int $limit = 5): array
{
    $stmt = db()->prepare(
        "SELECT COALESCE(NULLIF(cashier_name,''), 'Unassigned') AS name,
                COUNT(*) AS transactions,
                COALESCE(SUM(total),0) AS revenue
         FROM sales
         WHERE status='COMPLETED' AND created_at BETWEEN ? AND ?
         GROUP BY name
         ORDER BY revenue DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    return $stmt->fetchAll();
}

function analytics_series(DateTimeImmutable $start, DateTimeImmutable $end, int $offsetSeconds): array
{
    $days = (int)$start->diff($end)->days + 1;
    $bucket = $days > 45 ? 'month' : 'day';
    $format = $bucket === 'day' ? '%Y-%m-%d' : '%Y-%m';
    $modifier = sprintf('%+d seconds', $offsetSeconds);
    $bounds = analytics_utc_bounds($start, $end);
    $stmt = db()->prepare(
        "SELECT strftime('{$format}', created_at, ?) AS bucket,
                COALESCE(SUM(total),0) AS revenue,
                COUNT(*) AS transactions
         FROM sales
         WHERE status='COMPLETED' AND created_at BETWEEN ? AND ?
         GROUP BY bucket
         ORDER BY bucket"
    );
    $stmt->execute([$modifier, $bounds['start'], $bounds['end']]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(string)$row['bucket']] = $row;
    }

    $points = [];
    $cursor = $bucket === 'day' ? $start : $start->modify('first day of this month');
    $guard = 0;
    while ($cursor <= $end && $guard++ < 400) {
        $key = $cursor->format($bucket === 'day' ? 'Y-m-d' : 'Y-m');
        $row = $rows[$key] ?? null;
        $points[] = [
            'label' => $cursor->format($bucket === 'day' ? 'j M' : 'M y'),
            'revenue' => (int)($row['revenue'] ?? 0),
            'transactions' => (int)($row['transactions'] ?? 0),
        ];
        $cursor = $cursor->modify($bucket === 'day' ? '+1 day' : '+1 month');
    }

    return ['bucket' => $bucket, 'points' => $points];
}

function analytics_hourly(array $bounds, int $offsetSeconds): array
{
    $modifier = sprintf('%+d seconds', $offsetSeconds);
    $stmt = db()->prepare(
        "SELECT strftime('%H', created_at, ?) AS hour,
                COALESCE(SUM(total),0) AS revenue,
                COUNT(*) AS transactions
         FROM sales
         WHERE status='COMPLETED' AND created_at BETWEEN ? AND ?
         GROUP BY hour
         ORDER BY hour"
    );
    $stmt->execute([$modifier, $bounds['start'], $bounds['end']]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(int)$row['hour']] = $row;
    }
    $points = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $row = $rows[$hour] ?? null;
        $points[] = [
            'label' => sprintf('%02d:00', $hour),
            'revenue' => (int)($row['revenue'] ?? 0),
            'transactions' => (int)($row['transactions'] ?? 0),
        ];
    }
    return $points;
}

function analytics_open_payments(array $bounds): array
{
    $stmt = db()->prepare(
        "SELECT status, COUNT(*) AS count, COALESCE(SUM(total),0) AS amount
         FROM sales
         WHERE status IN ('PENDING_PAYMENT','PAYMENT_FAILED') AND created_at BETWEEN ? AND ?
         GROUP BY status"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    $result = [
        'pending' => ['count' => 0, 'amount' => 0],
        'failed' => ['count' => 0, 'amount' => 0],
    ];
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['status'] === 'PAYMENT_FAILED' ? 'failed' : 'pending';
        $result[$key] = ['count' => (int)$row['count'], 'amount' => (int)$row['amount']];
    }
    return $result;
}

function analytics_voids(array $bounds, int $limit = 5): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS value
         FROM sales
         WHERE status='VOID' AND created_at BETWEEN ? AND ?"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    $summary = $stmt->fetch() ?: ['count' => 0, 'value' => 0];

    $byCashier = db()->prepare(
        "SELECT COALESCE(NULLIF(cashier_name,''), 'Unassigned') AS name,
                COUNT(*) AS count,
                COALESCE(SUM(total),0) AS void_value
         FROM sales
         WHERE status='VOID' AND created_at BETWEEN ? AND ?
         GROUP BY name
         ORDER BY void_value DESC
         LIMIT {$limit}"
    );
    $byCashier->execute([$bounds['start'], $bounds['end']]);

    return [
        'count' => (int)$summary['count'],
        'void_value' => (int)$summary['value'],
        'by_cashier' => $byCashier->fetchAll(),
    ];
}

function analytics_vat_breakdown(array $bounds): array
{
    $stmt = db()->prepare(
        "SELECT vat_rate AS rate,
                COUNT(*) AS invoices,
                COALESCE(SUM(vat_amount),0) AS vat,
                COALESCE(SUM(total),0) AS gross
         FROM sales
         WHERE status='COMPLETED' AND vat_amount > 0 AND created_at BETWEEN ? AND ?
         GROUP BY vat_rate
         ORDER BY vat DESC"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    return $stmt->fetchAll();
}

function analytics_inventory(bool $canViewCost): array
{
    $row = db()->query(
        "SELECT COUNT(*) AS products,
                COALESCE(SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END),0) AS low_stock,
                COALESCE(SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END),0) AS out_of_stock,
                COALESCE(SUM(quantity * buying_price),0) AS at_cost,
                COALESCE(SUM(quantity * selling_price),0) AS at_retail
         FROM products"
    )->fetch() ?: [];

    $atCost = (float)($row['at_cost'] ?? 0);
    $atRetail = (float)($row['at_retail'] ?? 0);

    return [
        'products' => (int)($row['products'] ?? 0),
        'low_stock' => (int)($row['low_stock'] ?? 0),
        'out_of_stock' => (int)($row['out_of_stock'] ?? 0),
        'at_cost' => $canViewCost ? $atCost : null,
        'at_retail' => $atRetail,
        'potential_margin' => $canViewCost ? $atRetail - $atCost : null,
    ];
}

function analytics_expenses(array $bounds): array
{
    $summary = db()->prepare(
        "SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
         FROM expenses
         WHERE created_at BETWEEN ? AND ?"
    );
    $summary->execute([$bounds['start'], $bounds['end']]);
    $totals = $summary->fetch() ?: ['count' => 0, 'total' => 0];

    $byCategory = db()->prepare(
        "SELECT category AS name, COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
         FROM expenses
         WHERE created_at BETWEEN ? AND ?
         GROUP BY category
         ORDER BY total DESC"
    );
    $byCategory->execute([$bounds['start'], $bounds['end']]);

    return [
        'count' => (int)$totals['count'],
        'total' => (int)$totals['total'],
        'by_category' => $byCategory->fetchAll(),
    ];
}

function analytics_sales_by(array $bounds, string $column, int $limit = 12): array
{
    if (!in_array($column, ['category', 'brand'], true)) {
        return [];
    }
    $stmt = db()->prepare(
        "SELECT COALESCE(NULLIF(TRIM(p.{$column}),''),'Uncategorised') AS name,
                COALESCE(SUM(si.quantity),0) AS units,
                COALESCE(SUM(si.total),0) AS revenue
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         JOIN products p ON p.id = si.product_id
         WHERE s.status='COMPLETED' AND s.created_at BETWEEN ? AND ?
         GROUP BY name
         ORDER BY revenue DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    return $stmt->fetchAll();
}

function analytics_customers(array $bounds): array
{
    $new = db()->prepare(
        "SELECT COUNT(*) FROM (
            SELECT customer_id, MIN(created_at) AS first_sale
            FROM sales
            WHERE status='COMPLETED' AND customer_id IS NOT NULL
            GROUP BY customer_id
            HAVING first_sale BETWEEN ? AND ?
        )"
    );
    $new->execute([$bounds['start'], $bounds['end']]);

    $returning = db()->prepare(
        "SELECT COUNT(DISTINCT s.customer_id)
         FROM sales s
         WHERE s.status='COMPLETED' AND s.created_at BETWEEN ? AND ? AND s.customer_id IS NOT NULL
           AND EXISTS (
               SELECT 1 FROM sales earlier
               WHERE earlier.customer_id = s.customer_id
                 AND earlier.status='COMPLETED'
                 AND earlier.created_at < ?
           )"
    );
    $returning->execute([$bounds['start'], $bounds['end'], $bounds['start']]);

    $walkIn = db()->prepare(
        "SELECT COUNT(*) FROM sales
         WHERE status='COMPLETED' AND created_at BETWEEN ? AND ?
           AND (customer_id IS NULL OR customer_name = 'Walk-in Customer')"
    );
    $walkIn->execute([$bounds['start'], $bounds['end']]);

    $top = db()->prepare(
        "SELECT COALESCE(NULLIF(customer_name,''),'Walk-in Customer') AS name,
                COUNT(*) AS transactions,
                COALESCE(SUM(total),0) AS revenue,
                MAX(created_at) AS last_purchase
         FROM sales
         WHERE status='COMPLETED' AND created_at BETWEEN ? AND ? AND customer_id IS NOT NULL
         GROUP BY customer_id, customer_name
         ORDER BY revenue DESC
         LIMIT 10"
    );
    $top->execute([$bounds['start'], $bounds['end']]);

    return [
        'new' => (int)$new->fetchColumn(),
        'returning' => (int)$returning->fetchColumn(),
        'walk_in_sales' => (int)$walkIn->fetchColumn(),
        'top' => $top->fetchAll(),
    ];
}

function analytics_compliance(array $bounds): array
{
    $stmt = db()->prepare(
        "SELECT etims_status AS status, COUNT(*) AS count, COALESCE(SUM(total),0) AS amount
         FROM sales
         WHERE status='COMPLETED' AND created_at BETWEEN ? AND ?
         GROUP BY etims_status
         ORDER BY count DESC"
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    $statuses = $stmt->fetchAll();

    $filed = 0;
    $pending = 0;
    $failed = 0;
    $exempted = 0;
    $pendingAmount = 0;
    foreach ($statuses as $row) {
        $status = strtoupper((string)$row['status']);
        if ($status === 'PENDING') {
            $pending += (int)$row['count'];
            $pendingAmount += (int)$row['amount'];
        } elseif ($status === 'FAILED') {
            $failed += (int)$row['count'];
        } elseif ($status === 'EXEMPTED') {
            $exempted += (int)$row['count'];
        } else {
            $filed += (int)$row['count'];
        }
    }

    $backlog = db()->query(
        "SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS amount, MIN(created_at) AS oldest
         FROM sales
         WHERE status='COMPLETED' AND etims_status='PENDING'"
    )->fetch() ?: ['count' => 0, 'amount' => 0, 'oldest' => null];

    $oldestDays = null;
    if (!empty($backlog['oldest'])) {
        try {
            $oldest = new DateTimeImmutable((string)$backlog['oldest'], new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $oldestDays = max(0, (int)$oldest->diff($now)->days);
        } catch (Throwable $e) {
            $oldestDays = null;
        }
    }

    return [
        'statuses' => $statuses,
        'filed' => $filed,
        'pending' => $pending,
        'failed' => $failed,
        'exempted' => $exempted,
        'pending_amount' => $pendingAmount,
        'backlog' => [
            'count' => (int)($backlog['count'] ?? 0),
            'amount' => (int)($backlog['amount'] ?? 0),
            'oldest_days' => $oldestDays,
        ],
    ];
}

/**
 * Build the full analytics payload for the requested period.
 *
 * @param array $user  Authenticated user (role drives cost visibility).
 * @param array $input Request body: period, start, end.
 */
function analytics_report(array $user, array $input): array
{
    $period = analytics_period(
        (string)($input['period'] ?? 'mtd'),
        isset($input['start']) ? (string)$input['start'] : null,
        isset($input['end']) ? (string)$input['end'] : null
    );
    $canViewCost = role_has_permission((string)($user['role'] ?? ''), 'cost.view');

    $sales = analytics_sales_totals($period['bounds']);
    $previous = analytics_sales_totals($period['comparison']['bounds']);
    $lastYear = analytics_sales_totals($period['last_year']['bounds']);

    $profit = analytics_profit_totals($period['bounds']);
    $profitPrevious = analytics_profit_totals($period['comparison']['bounds']);

    $netRevenue = (float)$sales['revenue'] - (float)$sales['vat'];
    $netRevenuePrevious = (float)$previous['revenue'] - (float)$previous['vat'];
    $costedCoverage = (float)$profit['line_revenue'] > 0
        ? min(100.0, round((float)$profit['costed_revenue'] / (float)$profit['line_revenue'] * 100, 1))
        : null;

    $grossProfit = null;
    $grossMargin = null;
    $grossProfitPrevious = null;
    $grossProfitChange = null;
    if ($canViewCost && (float)$profit['cost'] > 0) {
        $grossProfit = $netRevenue - (float)$profit['cost'];
        $grossMargin = $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 1) : null;
        $previousNetRevenue = (float)$profitPrevious['line_revenue'] - (float)$previous['vat'];
        if ((float)$profitPrevious['cost'] > 0) {
            $grossProfitPrevious = $previousNetRevenue - (float)$profitPrevious['cost'];
            $grossProfitChange = analytics_change($grossProfit, $grossProfitPrevious);
        }
    }

    $openPayments = analytics_open_payments($period['bounds']);
    $voids = analytics_voids($period['bounds']);
    $discountPct = (float)$sales['gross_subtotal'] > 0
        ? round((float)$sales['discounts'] / (float)$sales['gross_subtotal'] * 100, 1)
        : null;

    $startLocal = new DateTimeImmutable($period['start'], analytics_timezone());
    $endLocal = new DateTimeImmutable($period['end'], analytics_timezone());
    $trendStart = $period['key'] === 'today' ? $endLocal->modify('-6 days') : $startLocal;
    $trend = analytics_series($trendStart, $endLocal, (int)$period['offset_seconds']);
    $trend['label'] = $period['key'] === 'today' ? 'Last 7 days' : $period['label'];

    return [
        'generated_at' => date(DATE_ATOM),
        'period' => $period,
        'restricted' => !$canViewCost,
        'sales' => [
            'revenue' => (int)$sales['revenue'],
            'transactions' => (int)$sales['transactions'],
            'avg_basket' => (float)$sales['avg_basket'],
            'gross_subtotal' => (int)$sales['gross_subtotal'],
            'discounts' => (int)$sales['discounts'],
            'discount_pct' => $discountPct,
            'vat' => (int)$sales['vat'],
            'last_sale_at' => $sales['last_sale_at'] ?? null,
            'gross_profit' => $grossProfit,
            'gross_margin_pct' => $grossMargin,
            'cost_coverage_pct' => $canViewCost ? $costedCoverage : null,
            'previous' => [
                'revenue' => (int)$previous['revenue'],
                'transactions' => (int)$previous['transactions'],
                'avg_basket' => (float)$previous['avg_basket'],
                'label' => $period['comparison_label'],
            ],
            'last_year' => [
                'revenue' => (int)$lastYear['revenue'],
                'transactions' => (int)$lastYear['transactions'],
                'avg_basket' => (float)$lastYear['avg_basket'],
                'label' => 'same period last year',
            ],
            'change' => [
                'revenue_pct' => analytics_change($sales['revenue'], $previous['revenue']),
                'transactions' => (int)$sales['transactions'] - (int)$previous['transactions'],
                'avg_basket_pct' => analytics_change($sales['avg_basket'], $previous['avg_basket']),
                'revenue_last_year_pct' => analytics_change($sales['revenue'], $lastYear['revenue']),
                'gross_profit_pct' => $grossProfitChange,
            ],
        ],
        'money' => [
            'cash' => ['revenue' => (int)$sales['cash_revenue'], 'count' => (int)$sales['cash_count']],
            'mpesa' => ['revenue' => (int)$sales['mpesa_revenue'], 'count' => (int)$sales['mpesa_count']],
            'pending' => $openPayments['pending'],
            'failed' => $openPayments['failed'],
            'voids' => $voids,
            'discounts' => (int)$sales['discounts'],
            'discount_pct' => $discountPct,
            'vat' => (int)$sales['vat'],
            'vat_breakdown' => analytics_vat_breakdown($period['bounds']),
        ],
        'trend' => $trend,
        'hourly' => $period['key'] === 'today'
            ? analytics_hourly($period['bounds'], (int)$period['offset_seconds'])
            : null,
        'top_products' => [
            'by_revenue' => analytics_top_products($period['bounds'], 'revenue'),
            'by_units' => analytics_top_products($period['bounds'], 'units'),
        ],
        'top_cashiers' => analytics_top_cashiers($period['bounds']),
        'inventory' => analytics_inventory($canViewCost),
        'expenses' => analytics_expenses($period['bounds']),
        'categories' => analytics_sales_by($period['bounds'], 'category'),
        'brands' => analytics_sales_by($period['bounds'], 'brand'),
        'customers' => analytics_customers($period['bounds']),
        'compliance' => analytics_compliance($period['bounds']),
    ];
}
