<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

try {
    init_db();
    $result = run_integrity_checks();
    settings_set('integrity_last_run_date', gmdate('Y-m-d'));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['status'] === 'OK' ? 0 : 1);
} catch (Throwable $e) {
    app_log('Scheduled integrity check failed: ' . $e->getMessage(), false, 'critical');
    fwrite(STDERR, 'Integrity check failed. See data/logs/app.log for details.' . PHP_EOL);
    exit(2);
}
