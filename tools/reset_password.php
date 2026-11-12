<?php
declare(strict_types=1);

/**
 * Break-glass password recovery.
 *
 * Works without the web server or an active session and is intended for the
 * machine that holds the database:
 *
 *   php -c .\php-runtime.ini tools\reset_password.php --user=admin
 *   php -c .\php-runtime.ini tools\reset_password.php --user=admin --password="NewSecret123!"
 *
 * When --password is omitted a strong temporary password is generated and
 * printed once. The account is always forced to change it at next login.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../lib.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match)) {
        $options[$match[1]] = $match[2] ?? true;
    }
}

if (isset($options['help'])) {
    echo "Usage: php tools/reset_password.php --user=admin [--password=NEWPASSWORD]\n";
    echo "If --password is omitted, a temporary password is generated.\n";
    exit(0);
}

$username = trim((string)($options['user'] ?? 'admin'));
if ($username === '') {
    fwrite(STDERR, "A username is required. Use --user=admin\n");
    exit(1);
}

$password = isset($options['password']) && is_string($options['password']) ? $options['password'] : '';
$generated = false;
if ($password === '') {
    $password = generate_temp_password();
    $generated = true;
}
if (strlen($password) < 10) {
    fwrite(STDERR, "The new password must be at least 10 characters.\n");
    exit(1);
}
if ($password === 'admin123') {
    fwrite(STDERR, "Choose a password other than the default.\n");
    exit(1);
}

try {
    init_db();
    $stmt = db()->prepare('SELECT id, username, full_name FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $account = $stmt->fetch();
    if (!$account) {
        fwrite(STDERR, "User not found: {$username}\n");
        exit(1);
    }

    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, session_version = session_version + 1 WHERE id = ?')
        ->execute([hash_password_local($password), (int)$account['id']]);
    db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
    audit('cli', 'PASSWORD_RESET_CLI', (string)$account['username']);

    echo "Password reset for {$account['username']} ({$account['full_name']}).\n";
    if ($generated) {
        echo "Temporary password: {$password}\n";
    } else {
        echo "The supplied password is active.\n";
    }
    echo "The user must change it at next login.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Password reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}
