<?php
declare(strict_types=1);

/**
 * End-to-end restore test (item 1).
 *
 * Runs against an isolated temporary data directory via SAJI_DATA_PATH so the
 * live database is never touched.
 *
 * Run: php -c .\php-runtime.ini tests\restore_e2e.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_restore_e2e_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

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

$cleanup = static function () use ($tmp): void {
    db_close();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($tmp . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-wal');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-shm');
    @rmdir($tmp . DIRECTORY_SEPARATOR . 'backups');
    @rmdir($tmp . DIRECTORY_SEPARATOR . 'logs');
    @rmdir($tmp);
};

try {
    init_db();
    check('sandbox database created', is_file(DB_PATH));
    check('admin seeded in sandbox', (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() >= 1);
    $usersBefore = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

    settings_set('__restore_marker', 'before');
    $backupFile = create_backup('restoretest');
    $backupName = basename($backupFile);
    check('backup created', is_file($backupFile), $backupName);
    check('backup is listed', in_array($backupName, array_column(backup_list(), 'file'), true));

    // Change the data after the snapshot so a successful restore is observable.
    settings_set('__restore_marker', 'after');
    check('marker changed before restore', settings_get('__restore_marker') === 'after');

    $safety = backup_restore($backupName);
    $safetyName = basename($safety);

    check('marker restored to snapshot value', settings_get('__restore_marker') === 'before', (string)settings_get('__restore_marker', 'null'));
    check('users preserved through restore', (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBefore);
    check('pre_restore safety backup created', is_file($safety), $safetyName);
    check('safety backup has pre_restore label', (bool)preg_match('/^backup_pre_restore_\d{8}_\d{6}\.sqlite3$/', $safetyName), $safetyName);
    check('safety backup is listed', in_array($safetyName, array_column(backup_list(), 'file'), true));
    check('backup_last_at updated after restore', (string)settings_get('backup_last_at', '') !== '');
    check('settings table still readable', (int)db()->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() > 0);
    check('license status still works after restore', isset(license_status()['state']));

    // A corrupt file must be rejected before the live database is replaced.
    $preRestoreBefore = count(glob(BACKUP_PATH . DIRECTORY_SEPARATOR . 'backup_pre_restore_*.sqlite3') ?: []);
    $corruptPath = BACKUP_PATH . DIRECTORY_SEPARATOR . 'backup_corrupt_' . date('Ymd_His') . '.sqlite3';
    file_put_contents($corruptPath, 'this is not a sqlite database');
    $corruptRejected = false;
    try {
        backup_restore(basename($corruptPath));
    } catch (RuntimeException $e) {
        $corruptRejected = str_contains($e->getMessage(), 'not a readable') || str_contains($e->getMessage(), 'integrity');
    }
    check('corrupt backup is rejected', $corruptRejected);
    check('live data unchanged after rejected restore', settings_get('__restore_marker') === 'before', (string)settings_get('__restore_marker', 'null'));
    $preRestoreAfter = count(glob(BACKUP_PATH . DIRECTORY_SEPARATOR . 'backup_pre_restore_*.sqlite3') ?: []);
    check('rejected restore creates no safety backup', $preRestoreAfter === $preRestoreBefore, $preRestoreBefore . ' -> ' . $preRestoreAfter);
} catch (Throwable $e) {
    check('restore completed without exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
