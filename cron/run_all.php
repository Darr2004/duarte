<?php
/**
 * DuaRTE — Unified Cron & Background Task Runner
 *
 * Runs all routine maintenance operations:
 *   1. Overdue tool loans check & alerts (hourly or daily)
 *   2. Stale requisition auto-expiry (daily after midnight)
 *   3. Purge expired QR asset scan verification tokens (>24h)
 *   4. Clean up old read notifications (>90 days retention)
 *
 * Usage via CLI:
 *   C:\xampp\php\php.exe c:\xampp\htdocs\duarte\cron\run_all.php
 *
 * Safe to run as frequently as every 15-60 minutes.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access denied: Command-line execution only.\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/notifications.php';

$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "========================================================\n";
echo " DuaRTE Unified Maintenance Runner — {$timestamp}\n";
echo "========================================================\n";

try {
    $pdo = get_db();
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Task 1: Check overdue tool loans
try {
    $overdueCount = check_overdue_loans($pdo);
    echo "[OK] Overdue tool loans check completed: {$overdueCount} newly flagged/alerted.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Task 1 (overdue loans) failed: " . $e->getMessage() . "\n");
}

// Task 2: Expire stale requisitions from previous days
try {
    $expiredReqs = expire_stale_requisitions($pdo);
    echo "[OK] Stale requisitions check completed: {$expiredReqs} expired.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Task 2 (expire requisitions) failed: " . $e->getMessage() . "\n");
}

// Task 3: Purge expired asset scan verification tokens (>24h)
try {
    $purgedTokens = purge_expired_asset_scan_verifications($pdo, 24);
    echo "[OK] Expired QR asset scan tokens purge completed: {$purgedTokens} token(s) cleaned.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Task 3 (purge asset tokens) failed: " . $e->getMessage() . "\n");
}

// Task 4: Clean up old notifications (90 days retention)
try {
    $cleanedNotifs = cleanup_old_notifications($pdo, 90);
    echo "[OK] Old notifications cleanup completed: {$cleanedNotifs} notification(s) pruned.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Task 4 (cleanup notifications) failed: " . $e->getMessage() . "\n");
}

$elapsed = round((microtime(true) - $startTime) * 1000, 2);
echo "--------------------------------------------------------\n";
echo "All maintenance tasks finished in {$elapsed} ms.\n";
echo "========================================================\n\n";
exit(0);
