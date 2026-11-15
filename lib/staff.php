<?php
declare(strict_types=1);

/**
 * Employee and branch record editing.
 *
 * Admins and managers can update employee and branch records. Only admins may
 * delete them; the API enforces that boundary independently of the UI.
 */

/**
 * Update an employee or branch record.
 *
 * @param array $user  Authenticated user (for the audit trail).
 * @param array $input table, id, name, phone, role, salary, location, active.
 * @return array Summary of the update.
 */
function staff_update(array $user, array $input): array
{
    $table = (string)($input['table'] ?? '');
    if (!in_array($table, ['employees', 'branches'], true)) {
        throw new InvalidArgumentException('Choose an employee or branch to edit.');
    }

    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid record to edit.');
    }

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Name is required.');
    }
    if (mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Name must be 120 characters or fewer.');
    }

    $phone = trim((string)($input['phone'] ?? ''));
    if (mb_strlen($phone) > 40) {
        throw new InvalidArgumentException('Phone must be 40 characters or fewer.');
    }

    $pdo = db();
    db_begin_immediate();
    try {
        $exists = $pdo->prepare("SELECT id, active FROM {$table} WHERE id = ?");
        $exists->execute([$id]);
        $current = $exists->fetch();
        if (!$current) {
            throw new InvalidArgumentException('Record not found.');
        }
        $active = array_key_exists('active', $input)
            ? ((int)$input['active'] === 0 ? 0 : 1)
            : (int)($current['active'] ?? 1);

        if ($table === 'employees') {
            $role = trim((string)($input['role'] ?? ''));
            if ($role === '') {
                throw new InvalidArgumentException('Role is required.');
            }
            if (mb_strlen($role) > 80) {
                throw new InvalidArgumentException('Role must be 80 characters or fewer.');
            }
            $salary = money_to_minor($input['salary'] ?? 0);
            if ($salary < 0) {
                throw new InvalidArgumentException('Salary cannot be negative.');
            }

            $pdo->prepare('UPDATE employees SET name = ?, phone = ?, role = ?, salary = ?, active = ? WHERE id = ?')
                ->execute([$name, $phone, $role, $salary, $active, $id]);

            $auditAction = 'UPDATE_EMPLOYEE';
            $summary = ['role' => $role, 'salary' => $salary, 'active' => $active];
        } else {
            $location = trim((string)($input['location'] ?? ''));
            if (mb_strlen($location) > 120) {
                throw new InvalidArgumentException('Location must be 120 characters or fewer.');
            }

            $duplicate = $pdo->prepare('SELECT id FROM branches WHERE name = ? AND id <> ?');
            $duplicate->execute([$name, $id]);
            if ($duplicate->fetchColumn() !== false) {
                throw new InvalidArgumentException('A branch with that name already exists.');
            }

            $pdo->prepare('UPDATE branches SET name = ?, location = ?, phone = ?, active = ? WHERE id = ?')
                ->execute([$name, $location, $phone, $active, $id]);

            $auditAction = 'UPDATE_BRANCH';
            $summary = ['location' => $location, 'active' => $active];
        }

        audit((string)($user['username'] ?? 'system'), $auditAction, $name);

        db_commit();

        return array_merge([
            'ok' => true,
            'table' => $table,
            'id' => $id,
            'name' => $name,
            'phone' => $phone,
            'active' => $active,
        ], $summary);
    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        throw $e;
    }
}

/**
 * Delete an employee or branch record.
 *
 * @param array $user  Authenticated admin user (for the audit trail).
 * @param array $input table, id.
 * @return array Summary of the deletion.
 */
function staff_delete(array $user, array $input): array
{
    $table = (string)($input['table'] ?? '');
    if (!in_array($table, ['employees', 'branches'], true)) {
        throw new InvalidArgumentException('Choose an employee or branch to delete.');
    }

    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid record to delete.');
    }

    $stmt = db()->prepare("SELECT name FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    if ($name === false) {
        throw new InvalidArgumentException('Record not found.');
    }

    db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
    audit(
        (string)($user['username'] ?? 'system'),
        $table === 'employees' ? 'DELETE_EMPLOYEE' : 'DELETE_BRANCH',
        (string)$name
    );

    return ['ok' => true, 'table' => $table, 'id' => $id, 'name' => (string)$name];
}
