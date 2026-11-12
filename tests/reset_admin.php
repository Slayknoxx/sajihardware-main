<?php
declare(strict_types=1);

/**
 * Reset the live admin account to the documented default so the HTTP smoke
 * test starts and ends in a known state. Development helper only.
 */

require_once __DIR__ . '/../lib.php';
init_db();

$hash = hash_password_local('admin123');
db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, active = 1, session_version = session_version + 1 WHERE username = ?')
    ->execute([$hash, 'admin']);
db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute(['admin']);

echo "RESET_OK\n";
