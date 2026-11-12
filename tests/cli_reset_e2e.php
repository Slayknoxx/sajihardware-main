<?php
declare(strict_types=1);

/**
 * Break-glass CLI reset test. Runs the real tools/reset_password.php against
 * an isolated temporary database.
 *
 * Run: php -c .\php-runtime.ini tests\cli_reset_e2e.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saji_cli_reset_' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
putenv('SAJI_DATA_PATH=' . $tmp);

require_once __DIR__ . '/../lib.php';

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

function run_reset(array $arguments, string $dataPath): array
{
    $command = array_merge([PHP_BINARY, '-c', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'php-runtime.ini', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'reset_password.php'], $arguments);
    $env = getenv();
    $env['SAJI_DATA_PATH'] = $dataPath;
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) {
        return [1, '', 'Could not start PHP'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [$code, (string)$stdout, (string)$stderr];
}

$cleanup = static function () use ($tmp): void {
    db_close();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-wal');
    @unlink($tmp . DIRECTORY_SEPARATOR . 'saji_pos.sqlite3-shm');
    @rmdir($tmp . DIRECTORY_SEPARATOR . 'logs');
    @rmdir($tmp);
};

try {
    init_db();
    db_close();

    [$code, $stdout, $stderr] = run_reset(['--user=admin', '--password=NewSecret123!'], $tmp);
    check('CLI reset exits successfully', $code === 0, $stderr);
    check('CLI reset confirms the account', str_contains($stdout, 'Password reset for admin'), $stdout);
    $hash = (string)db()->query('SELECT password_hash FROM users WHERE username="admin"')->fetchColumn();
    check('CLI reset sets the supplied password', password_verify('NewSecret123!', $hash));
    check('CLI reset forces a password change', (int)db()->query('SELECT must_change_password FROM users WHERE username="admin"')->fetchColumn() === 1);
    check('CLI reset bumps the session version', (int)db()->query('SELECT session_version FROM users WHERE username="admin"')->fetchColumn() === 1);
    db_close();

    [$code, $stdout, $stderr] = run_reset(['--user=admin'], $tmp);
    check('CLI reset generates a password', $code === 0 && preg_match('/Temporary password: (\S+)/', $stdout, $match) === 1, $stderr . $stdout);
    $generated = $match[1] ?? '';
    check('generated password is long enough', strlen($generated) >= 10, $generated);
    $hash = (string)db()->query('SELECT password_hash FROM users WHERE username="admin"')->fetchColumn();
    check('generated password is stored', password_verify($generated, $hash));
    db_close();

    [$code, $stdout, $stderr] = run_reset(['--user=missing-user'], $tmp);
    check('unknown user is rejected', $code !== 0 && str_contains($stderr, 'User not found'), $stdout . $stderr);
} catch (Throwable $e) {
    check('CLI reset checks completed without exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
