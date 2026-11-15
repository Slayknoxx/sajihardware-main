<?php
declare(strict_types=1);

function csv_export_value(mixed $value): string|int|float
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_int($value) || is_float($value)) {
        return $value;
    }

    $text = is_array($value)
        ? (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (string)$value;
    if (preg_match('/^[\x00-\x20]*[=+\-@]/', $text)) {
        return "'" . $text;
    }
    return $text;
}

function csv_export_encode(array $headers, array $rows): string
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Unable to prepare the CSV export.');
    }

    fputcsv($stream, array_map('csv_export_value', $headers), ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($stream, array_map('csv_export_value', $row), ',', '"', '');
    }
    rewind($stream);
    $csv = "\xEF\xBB\xBF" . (string)stream_get_contents($stream);
    fclose($stream);
    return $csv;
}

function csv_export_flatten(array $value, string $prefix = '', array &$rows = []): array
{
    foreach ($value as $key => $item) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
        if (is_array($item)) {
            csv_export_flatten($item, $path, $rows);
        } else {
            $rows[] = [$path, $item];
        }
    }
    return $rows;
}

function csv_export_money(array $row, array $fields): array
{
    foreach ($fields as $field) {
        if (isset($row[$field]) && is_numeric($row[$field])) {
            $row[$field] = money_minor_to_major($row[$field]);
        }
    }
    return $row;
}

/**
 * Build a fresh, permission-aware CSV export from the current database state.
 *
 * @return array{type:string,filename:string,csv:string}
 */
function csv_export_file(array $user, string $type, array $filters = []): array
{
    $pdo = db();
    $rows = [];
    $headers = [];
    $suffix = date('Ymd-His');

    switch ($type) {
        case 'products':
            $canViewCost = role_has_permission((string)$user['role'], 'cost.view');
            $headers = ['id', 'sku', 'barcode', 'name', 'category', 'brand'];
            if ($canViewCost) {
                $headers[] = 'buying_price';
            }
            array_push(
                $headers,
                'selling_price', 'vat_rate', 'quantity', 'reorder_level', 'warehouse',
                'branch', 'supplier_id', 'supplier', 'supplied_quantity', 'created_at'
            );
            $select = $canViewCost ? 'p.buying_price, ' : '';
            $products = $pdo->query(
                "SELECT p.id, p.sku, p.barcode, p.name, p.category, p.brand, {$select}
                        p.selling_price, p.vat_rate, p.quantity, p.reorder_level, p.warehouse,
                        p.branch, p.supplier_id, COALESCE(s.name, '') supplier,
                        p.supplied_quantity, p.created_at
                 FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id
                 ORDER BY p.id"
            )->fetchAll();
            foreach ($products as $product) {
                $product = csv_export_money($product, ['buying_price', 'selling_price']);
                $rows[] = array_map(static fn(string $column) => $product[$column] ?? '', $headers);
            }
            break;

        case 'customers':
        case 'suppliers':
            $headers = ['id', 'name', 'phone', 'email', 'kra_pin', 'balance', 'created_at'];
            $stmt = $pdo->query("SELECT id, name, phone, email, kra_pin, balance, created_at FROM {$type} ORDER BY id");
            foreach ($stmt->fetchAll() as $record) {
                $record = csv_export_money($record, ['balance']);
                $rows[] = array_map(static fn(string $column) => $record[$column] ?? '', $headers);
            }
            break;

        case 'quotations':
            $headers = [
                'id', 'quote_no', 'customer_name', 'customer_kra_pin', 'status', 'created_at',
                'valid_until', 'subtotal', 'tax', 'discount', 'total', 'converted_sale_id',
                'receipt_no', 'product_name', 'quantity', 'unit_price', 'line_total',
            ];
            $quotes = $pdo->query(
                'SELECT q.id, q.quote_no, q.customer_name, q.customer_kra_pin, q.status,
                        q.created_at, q.valid_until, q.subtotal, q.tax, q.discount, q.total,
                        q.converted_sale_id, s.receipt_no, qi.product_name, qi.quantity,
                        qi.unit_price, qi.total AS line_total
                 FROM quotations q
                 LEFT JOIN sales s ON s.id = q.converted_sale_id
                 LEFT JOIN quotation_items qi ON qi.quotation_id = q.id
                 ORDER BY q.id, qi.id'
            )->fetchAll();
            foreach ($quotes as $quote) {
                $quote = csv_export_money($quote, ['subtotal', 'tax', 'discount', 'total', 'unit_price', 'line_total']);
                $rows[] = array_map(static fn(string $column) => $quote[$column] ?? '', $headers);
            }
            break;

        case 'cash_flow':
            $report = cash_flow_report([
                'period' => (string)($filters['period'] ?? 'month'),
                'date' => (string)($filters['date'] ?? date('Y-m-d')),
            ], null);
            $headers = [
                'record_type', 'period', 'date_or_bucket', 'type', 'category',
                'description', 'amount', 'income', 'expenses', 'reference', 'source',
            ];
            foreach ($report['summary'] as $name => $amount) {
                $rows[] = [
                    'summary', $report['period'], $report['date'], $name, '', '',
                    money_minor_to_major($amount), '', '', '', '',
                ];
            }
            foreach ($report['series'] as $point) {
                $rows[] = [
                    'series', $report['period'], $point['bucket'], '', '', '',
                    '', money_minor_to_major($point['income']), money_minor_to_major($point['expenses']), '', '',
                ];
            }
            foreach ($report['entries'] as $entry) {
                $rows[] = [
                    'entry', $report['period'], $entry['created_at'], $entry['type'],
                    $entry['category'], $entry['description'], money_minor_to_major($entry['amount']),
                    '', '', $entry['reference'], $entry['source'],
                ];
            }
            $suffix = $report['period'] . '-' . str_replace('-', '', $report['date']) . '-' . $suffix;
            break;

        case 'analytics':
            $report = analytics_report($user, [
                'period' => (string)($filters['period'] ?? 'mtd'),
                'start' => (string)($filters['start'] ?? ''),
                'end' => (string)($filters['end'] ?? ''),
            ]);
            $flat = csv_export_flatten(money_json_format($report));
            $headers = ['metric', 'value'];
            $rows = $flat;
            $suffix = (string)($report['period']['key'] ?? 'analytics') . '-' . $suffix;
            break;

        default:
            throw new InvalidArgumentException('Choose a supported CSV export.');
    }

    return [
        'type' => $type,
        'filename' => $type . '-' . $suffix . '.csv',
        'csv' => csv_export_encode($headers, $rows),
    ];
}
