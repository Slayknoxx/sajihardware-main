<?php
declare(strict_types=1);

/**
 * Phase 1 smoke test: security foundation, settings, licensing, backups.
 * Run: php -c .\php-runtime.ini tests\phase1_smoke.php
 */

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/license.php';

$failures = 0;
$checks = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "PASS  {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

init_db();

$tables = db()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
$tableNames = array_column($tables, 'name');
check('app_settings table exists', in_array('app_settings', $tableNames, true));
check('login_attempts table exists', in_array('login_attempts', $tableNames, true));

$userColumns = db()->query('PRAGMA table_info(users)')->fetchAll();
check('users.must_change_password exists', in_array('must_change_password', array_column($userColumns, 'name'), true));

check('install_id seeded', (string)settings_get('install_id', '') !== '');
check('trial_started_at seeded', (string)settings_get('trial_started_at', '') !== '');

settings_set('__smoke_key', 'value-123');
check('settings round-trip', settings_get('__smoke_key') === 'value-123');
settings_forget('__smoke_key');

$status = license_status();
check('license status has required fields', isset($status['state'], $status['valid'], $status['machine_id']));
check('trial is valid for a fresh install', $status['valid'] === true && in_array($status['state'], ['trial', 'active', 'grace'], true), $status['state']);
check('licensing works offline (machine id present)', strlen((string)$status['machine_id']) === 64);
check('invalid license is rejected', license_decode('not-a-license') === null);

check('login throttle starts unlocked', security_login_lock_seconds('__smoke_user', '127.0.0.1') === 0);

$traversalRejected = false;
try {
    backup_path_for('..\\config.php');
} catch (Throwable $e) {
    $traversalRejected = true;
}
check('backup path traversal rejected', $traversalRejected);

$backups = backup_list();
check('backup_list returns an array', is_array($backups));
check('backup_list sees the pre-migration backup', count($backups) > 0);

$autoSetting = settings_get('backup_auto_enabled', '1');
check('automatic backup default enabled', $autoSetting === '1');

db_close();
check('db reconnects after close (restore path)', db() instanceof PDO || db() instanceof SqliteDb);
check('db still readable after reconnect', (int)db()->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() > 0);

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
