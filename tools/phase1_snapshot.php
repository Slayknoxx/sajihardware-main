<?php
declare(strict_types=1);

/**
 * Phase 1 sign-off snapshot (item 8).
 *
 * 1. Creates a `phase1_complete` database backup.
 * 2. Runs PRAGMA integrity_check on the live database and the new backup.
 * 3. Compares row counts against the pre-Phase-1 snapshot (additive-only check).
 * 4. Copies the license private key into tools/keys/backups with a SHA-256 sum.
 *
 * This operates on the live data directory on purpose. Run it while the POS is
 * idle:
 *
 *   php -c .\php-runtime.ini scripts\phase1_snapshot.php
 */

require_once __DIR__ . '/../lib.php';

function sha256_file(string $path): string
{
    return hash_file('sha256', $path) ?: '';
}

function table_counts(string $dbPath): array
{
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $counts = [];
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    while ($row = $tables->fetchArray(SQLITE3_ASSOC)) {
        $name = (string)$row['name'];
        $counts[$name] = (int)$db->querySingle('SELECT COUNT(*) FROM "' . str_replace('"', '""', $name) . '"');
    }
    $db->close();
    return $counts;
}

$oldBackup = BACKUP_PATH . DIRECTORY_SEPARATOR . 'backup_pre_phase1_20261111_203843.sqlite3';

echo "Phase 1 sign-off snapshot\n";
echo "=========================\n\n";

if (!is_file(DB_PATH)) {
    fwrite(STDERR, "Live database not found: " . DB_PATH . "\n");
    exit(1);
}

$backupFile = create_backup('phase1_complete');
$backupHash = sha256_file($backupFile);
echo "Backup created: {$backupFile}\n";
echo "Backup SHA-256: {$backupHash}\n";
echo "Backup size: " . number_format((float)filesize($backupFile)) . " bytes\n\n";

$failures = 0;

// Integrity of the live database.
$live = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
$liveIntegrity = (string)$live->querySingle('PRAGMA integrity_check');
$live->close();
echo "Live integrity_check: {$liveIntegrity}\n";
if ($liveIntegrity !== 'ok') {
    $failures++;
}

// Integrity of the new backup.
$copy = new SQLite3($backupFile, SQLITE3_OPEN_READONLY);
$copyIntegrity = (string)$copy->querySingle('PRAGMA integrity_check');
$copy->close();
echo "Backup integrity_check: {$copyIntegrity}\n\n";
if ($copyIntegrity !== 'ok') {
    $failures++;
}

// Row-count comparison against the pre-Phase-1 snapshot: Phase 1 was additive,
// so pre-existing tables must still hold at least as many rows as before.
if (is_file($oldBackup)) {
    $before = table_counts($oldBackup);
    $after = table_counts(DB_PATH);
    echo "Row counts vs " . basename($oldBackup) . ":\n";
    printf("  %-28s %10s %10s  %s\n", 'table', 'before', 'after', 'status');
    foreach ($before as $table => $count) {
        $now = $after[$table] ?? null;
        if ($now === null) {
            printf("  %-28s %10d %10s  MISSING\n", $table, $count, '—');
            $failures++;
            continue;
        }
        $status = $now >= $count ? 'ok' : 'LOST ROWS';
        if ($now < $count) {
            $failures++;
        }
        printf("  %-28s %10d %10d  %s\n", $table, $count, $now, $status);
    }
    $added = array_diff_key($after, $before);
    if ($added) {
        echo "  New tables: " . implode(', ', array_keys($added)) . "\n";
    }
} else {
    echo "Pre-Phase-1 snapshot not found, skipping row comparison.\n";
}
echo "\n";

// License key safety copy.
$keyFile = BASE_PATH . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'private.key';
if (is_file($keyFile)) {
    $keyDir = dirname($keyFile) . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($keyDir)) {
        mkdir($keyDir, 0700, true);
    }
    $keyCopy = $keyDir . DIRECTORY_SEPARATOR . 'private-' . date('Ymd_His') . '.key';
    if (!copy($keyFile, $keyCopy)) {
        fwrite(STDERR, "Could not copy the private key.\n");
        $failures++;
    } else {
        $keyHash = sha256_file($keyCopy);
        file_put_contents($keyDir . DIRECTORY_SEPARATOR . 'SHA256SUMS.txt', $keyHash . '  ' . basename($keyCopy) . PHP_EOL, FILE_APPEND);
        echo "License key copy: {$keyCopy}\n";
        echo "License key SHA-256: {$keyHash}\n\n";
        echo "Move this key copy OFF this machine (password manager or encrypted USB) and\n";
        echo "delete it from tools/keys/backups afterwards. Never ship or commit it.\n\n";
    }
} else {
    echo "No private key at {$keyFile}; nothing to back up.\n\n";
}

echo $failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
