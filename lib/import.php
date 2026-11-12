<?php
declare(strict_types=1);

/**
 * CSV import for Phase 2.
 *
 * Validation and writing are separate steps: the UI previews a file first and
 * only commits when every row is valid. Writes happen in one transaction so a
 * failed import never leaves half the file behind.
 */

function csv_import_catalog(): array
{
    return [
        'products' => [
            'label' => 'Products',
            'file' => 'products-template.csv',
            'columns' => ['sku', 'barcode', 'name', 'category', 'brand', 'buying_price', 'selling_price', 'quantity', 'reorder_level', 'warehouse', 'branch', 'supplier'],
            'required' => ['sku', 'name', 'category', 'selling_price'],
        ],
        'customers' => [
            'label' => 'Customers',
            'file' => 'customers-template.csv',
            'columns' => ['name', 'phone', 'email', 'kra_pin'],
            'required' => ['name'],
        ],
        'suppliers' => [
            'label' => 'Suppliers',
            'file' => 'suppliers-template.csv',
            'columns' => ['name', 'phone', 'email', 'kra_pin'],
            'required' => ['name'],
        ],
    ];
}

function csv_import_template(string $type): array
{
    $catalog = csv_import_catalog();
    if (!isset($catalog[$type])) {
        throw new InvalidArgumentException('Unsupported import type.');
    }
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $catalog[$type]['columns'], ',', '"', '');
    rewind($stream);
    $csv = (string)stream_get_contents($stream);
    fclose($stream);
    return ['filename' => $catalog[$type]['file'], 'csv' => $csv];
}

/**
 * Parse CSV text into a header row and data rows. Handles quoted fields,
 * escaped quotes, commas and embedded newlines.
 */
function csv_import_parse(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $csv = str_replace(["\r\n", "\r"], "\n", $csv);
    $rows = [];
    $row = [];
    $field = '';
    $inQuotes = false;
    $length = strlen($csv);

    for ($i = 0; $i < $length; $i++) {
        $char = $csv[$i];
        if ($inQuotes) {
            if ($char === '"') {
                if (($csv[$i + 1] ?? '') === '"') {
                    $field .= '"';
                    $i++;
                } else {
                    $inQuotes = false;
                }
            } else {
                $field .= $char;
            }
            continue;
        }
        if ($char === '"') {
            $inQuotes = true;
        } elseif ($char === ',') {
            $row[] = $field;
            $field = '';
        } elseif ($char === "\n") {
            $row[] = $field;
            $rows[] = $row;
            $row = [];
            $field = '';
        } else {
            $field .= $char;
        }
    }
    if ($field !== '' || $row !== []) {
        $row[] = $field;
        $rows[] = $row;
    }

    $headers = [];
    if ($rows !== []) {
        $headers = array_map(static fn($value): string => strtolower(trim((string)$value)), array_shift($rows));
    }

    $data = [];
    foreach ($rows as $values) {
        $values = array_map(static fn($value): string => trim((string)$value), $values);
        if (implode('', $values) === '') {
            continue;
        }
        $data[] = $values;
    }
    return [$headers, $data];
}

function csv_import_header_map(array $headers): array
{
    $aliases = [
        'stock' => 'quantity',
        'qty' => 'quantity',
        'supplier_name' => 'supplier',
        'buying' => 'buying_price',
        'selling' => 'selling_price',
        'reorder' => 'reorder_level',
        'pin' => 'kra_pin',
    ];
    $map = [];
    foreach ($headers as $index => $header) {
        $key = str_replace([' ', '-'], '_', $header);
        $key = $aliases[$key] ?? $key;
        if ($key !== '' && !isset($map[$key])) {
            $map[$key] = $index;
        }
    }
    return $map;
}

/**
 * Validate a CSV without writing anything. The returned "records" key is
 * internal and is stripped by the API before the preview is sent to the UI.
 */
function csv_import_validate(string $type, string $csv, string $mode = 'skip'): array
{
    $catalog = csv_import_catalog();
    if (!isset($catalog[$type])) {
        throw new InvalidArgumentException('Unsupported import type.');
    }
    $mode = $mode === 'update' ? 'update' : 'skip';
    [$headers, $rawRows] = csv_import_parse($csv);
    if ($headers === []) {
        throw new InvalidArgumentException('The CSV file is empty.');
    }
    $map = csv_import_header_map($headers);
    foreach ($catalog[$type]['required'] as $required) {
        if (!isset($map[$required])) {
            throw new InvalidArgumentException('Missing required column: ' . $required);
        }
    }
    if (count($rawRows) > 5000) {
        throw new InvalidArgumentException('Import at most 5000 rows at a time.');
    }

    $errors = [];
    $preview = [];
    $records = [];
    $seen = [];
    $existingProducts = [];
    $existingCustomers = [];
    $existingSuppliers = [];
    $supplierIds = [];

    if ($type === 'products') {
        foreach (db()->query('SELECT id, sku FROM products')->fetchAll() as $row) {
            // Keep the stored SKU so updates match case-insensitively without
            // rewriting the row's original casing.
            $existingProducts[strtolower((string)$row['sku'])] = (string)$row['sku'];
        }
        foreach (db()->query('SELECT id, name FROM suppliers')->fetchAll() as $row) {
            $supplierIds[strtolower((string)$row['name'])] = (int)$row['id'];
        }
    } elseif ($type === 'customers') {
        foreach (db()->query('SELECT id, name, phone FROM customers')->fetchAll() as $row) {
            $existingCustomers[strtolower((string)$row['name']) . "\0" . (string)$row['phone']] = (int)$row['id'];
        }
    } else {
        foreach (db()->query('SELECT id, name, phone FROM suppliers')->fetchAll() as $row) {
            $existingSuppliers[strtolower((string)$row['name']) . "\0" . (string)$row['phone']] = (int)$row['id'];
        }
    }

    $value = static function (array $row, array $map, string $key, string $default = ''): string {
        return isset($map[$key]) ? trim((string)($row[$map[$key]] ?? '')) : $default;
    };

    foreach ($rawRows as $offset => $row) {
        $line = $offset + 2;
        $rowErrors = [];

        if ($type === 'products') {
            $sku = $value($row, $map, 'sku');
            $name = $value($row, $map, 'name');
            $category = $value($row, $map, 'category');
            $selling = $value($row, $map, 'selling_price');
            $buying = $value($row, $map, 'buying_price', '0');
            $quantity = $value($row, $map, 'quantity', '0');
            $reorder = $value($row, $map, 'reorder_level', '5');
            $supplierName = $value($row, $map, 'supplier');

            if ($sku === '') $rowErrors[] = 'sku is required';
            if ($name === '') $rowErrors[] = 'name is required';
            if ($category === '') $rowErrors[] = 'category is required';
            if ($selling === '' || !is_numeric($selling) || (float)$selling < 0) $rowErrors[] = 'selling_price must be a non-negative number';
            if (!is_numeric($buying) || (float)$buying < 0) $rowErrors[] = 'buying_price must be a non-negative number';
            if (!is_numeric($quantity) || (int)$quantity < 0) $rowErrors[] = 'quantity must be a non-negative whole number';
            if (!is_numeric($reorder) || (int)$reorder < 0) $rowErrors[] = 'reorder_level must be a non-negative whole number';

            $supplierId = null;
            if ($supplierName !== '') {
                $supplierId = $supplierIds[strtolower($supplierName)] ?? null;
                if ($supplierId === null) {
                    $rowErrors[] = 'supplier not found: ' . $supplierName;
                }
            }

            if ($sku !== '') {
                $key = strtolower($sku);
                if (isset($seen[$key])) {
                    $rowErrors[] = 'duplicate sku in file: ' . $sku;
                }
                $seen[$key] = true;
            }

            if ($rowErrors !== []) {
                $errors[] = 'Line ' . $line . ': ' . implode('; ', $rowErrors);
                $preview[] = ['line' => $line, 'action' => 'error', 'summary' => $sku . ' ' . $name, 'message' => implode('; ', $rowErrors)];
                continue;
            }

            $existingId = $existingProducts[strtolower($sku)] ?? null;
            $action = $existingId === null ? 'insert' : ($mode === 'update' ? 'update' : 'skip');
            if ($existingId !== null && $mode === 'skip') {
                $preview[] = ['line' => $line, 'action' => 'skip', 'summary' => $sku . ' ' . $name, 'message' => 'SKU already exists; skipped'];
            } else {
                $preview[] = ['line' => $line, 'action' => $action, 'summary' => $sku . ' ' . $name, 'message' => $action === 'update' ? 'Existing SKU will be updated (stock is not changed)' : 'New product'];
            }
            $records[] = [
                'action' => $action,
                'id' => $existingId,
                'data' => [
                    'sku' => $sku,
                    'barcode' => $value($row, $map, 'barcode'),
                    'name' => $name,
                    'category' => $category,
                    'brand' => $value($row, $map, 'brand'),
                    'buying_price' => (float)$buying,
                    'selling_price' => (float)$selling,
                    'quantity' => (int)$quantity,
                    'reorder_level' => (int)$reorder,
                    'warehouse' => $value($row, $map, 'warehouse', 'Main'),
                    'branch' => $value($row, $map, 'branch', 'Main'),
                    'supplier_id' => $supplierId,
                ],
            ];
            continue;
        }

        $name = $value($row, $map, 'name');
        $phone = $value($row, $map, 'phone');
        $email = $value($row, $map, 'email');
        $kra = $value($row, $map, 'kra_pin');
        if ($name === '') $rowErrors[] = 'name is required';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) $rowErrors[] = 'email is not valid';

        $key = strtolower($name) . "\0" . $phone;
        if ($name !== '' && isset($seen[$key])) {
            $rowErrors[] = 'duplicate record in file: ' . $name;
        }
        if ($name !== '') {
            $seen[$key] = true;
        }

        if ($rowErrors !== []) {
            $errors[] = 'Line ' . $line . ': ' . implode('; ', $rowErrors);
            $preview[] = ['line' => $line, 'action' => 'error', 'summary' => $name, 'message' => implode('; ', $rowErrors)];
            continue;
        }

        $existingMap = $type === 'customers' ? $existingCustomers : $existingSuppliers;
        $existingId = $existingMap[$key] ?? null;
        $action = $existingId === null ? 'insert' : ($mode === 'update' ? 'update' : 'skip');
        if ($existingId !== null && $mode === 'skip') {
            $preview[] = ['line' => $line, 'action' => 'skip', 'summary' => $name, 'message' => 'Contact already exists; skipped'];
        } else {
            $preview[] = ['line' => $line, 'action' => $action, 'summary' => $name, 'message' => $action === 'update' ? 'Existing contact will be updated' : 'New contact'];
        }
        $records[] = [
            'action' => $action,
            'id' => $existingId,
            'data' => ['name' => $name, 'phone' => $phone, 'email' => $email, 'kra_pin' => $kra],
        ];
    }

    $inserts = count(array_filter($records, static fn(array $record): bool => $record['action'] === 'insert'));
    $updates = count(array_filter($records, static fn(array $record): bool => $record['action'] === 'update'));
    $skips = count(array_filter($records, static fn(array $record): bool => $record['action'] === 'skip'));

    return [
        'type' => $type,
        'mode' => $mode,
        'headers' => $catalog[$type]['columns'],
        'total' => count($rawRows),
        'valid' => $inserts + $updates,
        'insert' => $inserts,
        'update' => $updates,
        'skip' => $skips,
        'errors' => $errors,
        'rows' => array_slice($preview, 0, 200),
        'records' => $records,
    ];
}

function csv_import_commit(string $type, string $csv, string $mode, string $actor): array
{
    $result = csv_import_validate($type, $csv, $mode);
    if ($result['errors'] !== []) {
        throw new InvalidArgumentException($result['errors'][0]);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($type === 'products') {
            $insert = $pdo->prepare('INSERT INTO products(sku, barcode, name, category, brand, buying_price, selling_price, quantity, reorder_level, warehouse, branch, supplier_id, supplied_quantity, vat_rate) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $update = $pdo->prepare('UPDATE products SET barcode = ?, name = ?, category = ?, brand = ?, buying_price = ?, selling_price = ?, reorder_level = ?, warehouse = ?, branch = ?, supplier_id = ? WHERE sku = ?');
            foreach ($result['records'] as $record) {
                $d = $record['data'];
                if ($record['action'] === 'insert') {
                    $insert->execute([$d['sku'], $d['barcode'], $d['name'], $d['category'], $d['brand'], $d['buying_price'], $d['selling_price'], $d['quantity'], $d['reorder_level'], $d['warehouse'], $d['branch'], $d['supplier_id'], $d['quantity'], VAT_RATE]);
                } elseif ($record['action'] === 'update') {
                    $update->execute([$d['barcode'], $d['name'], $d['category'], $d['brand'], $d['buying_price'], $d['selling_price'], $d['reorder_level'], $d['warehouse'], $d['branch'], $d['supplier_id'], $record['id']]);
                }
            }
        } else {
            $insert = $pdo->prepare('INSERT INTO ' . $type . '(name, phone, email, kra_pin) VALUES(?,?,?,?)');
            $update = $pdo->prepare('UPDATE ' . $type . ' SET phone = ?, email = ?, kra_pin = ? WHERE id = ?');
            foreach ($result['records'] as $record) {
                $d = $record['data'];
                if ($record['action'] === 'insert') {
                    $insert->execute([$d['name'], $d['phone'], $d['email'], $d['kra_pin']]);
                } elseif ($record['action'] === 'update') {
                    $update->execute([$d['phone'], $d['email'], $d['kra_pin'], $record['id']]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit($actor, 'IMPORT_' . strtoupper($type), 'insert=' . $result['insert'] . ' update=' . $result['update'] . ' skip=' . $result['skip']);
    return [
        'ok' => true,
        'type' => $type,
        'insert' => $result['insert'],
        'update' => $result['update'],
        'skip' => $result['skip'],
        'total' => $result['total'],
    ];
}
