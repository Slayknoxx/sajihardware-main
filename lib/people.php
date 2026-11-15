<?php
declare(strict_types=1);

/**
 * Customer and supplier record editing.
 *
 * Sales keep their own customer_name snapshot, so renaming a customer here does
 * not rewrite historical receipts. Only the directory record changes.
 */

/**
 * Update a customer or supplier record.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input table, id, name, phone, email, kra_pin.
 * @return array Summary of the update.
 */
function person_update(array $user, array $input): array
{
    $table = (string)($input['table'] ?? '');
    if (!in_array($table, ['customers', 'suppliers'], true)) {
        throw new InvalidArgumentException('Choose a customer or supplier to edit.');
    }

    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid record to edit.');
    }

    $name = trim((string)($input['name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $kraPin = trim((string)($input['kra_pin'] ?? ''));

    if ($name === '') {
        throw new InvalidArgumentException('Name is required.');
    }
    if (mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Name must be 120 characters or fewer.');
    }
    if (mb_strlen($phone) > 40) {
        throw new InvalidArgumentException('Phone must be 40 characters or fewer.');
    }
    if (mb_strlen($email) > 120) {
        throw new InvalidArgumentException('Email must be 120 characters or fewer.');
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (mb_strlen($kraPin) > 40) {
        throw new InvalidArgumentException('KRA PIN must be 40 characters or fewer.');
    }

    $exists = db()->prepare("SELECT id FROM {$table} WHERE id = ?");
    $exists->execute([$id]);
    if ($exists->fetchColumn() === false) {
        throw new InvalidArgumentException('Record not found.');
    }

    db()->prepare("UPDATE {$table} SET name = ?, phone = ?, email = ?, kra_pin = ? WHERE id = ?")
        ->execute([$name, $phone, $email, $kraPin, $id]);

    audit(
        (string)($user['username'] ?? 'system'),
        $table === 'customers' ? 'UPDATE_CUSTOMER' : 'UPDATE_SUPPLIER',
        $name
    );

    return [
        'ok' => true,
        'table' => $table,
        'id' => $id,
        'name' => $name,
    ];
}
