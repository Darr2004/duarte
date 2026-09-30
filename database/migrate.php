<?php
/**
 * DuaRTE — Version-Tracked Database Migration Runner
 *
 * Discovers and applies all outstanding schema migrations idempotently,
 * tracking executed migrations in the `schema_migrations` table.
 *
 * Usage via CLI:
 *   C:\xampp\php\php.exe c:\xampp\htdocs\duarte\database\migrate.php
 *
 * Or visit in browser while logged in as an administrator:
 *   http://localhost/duarte/database/migrate.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    require_role(['admin']);
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo = get_db();

// 1. Ensure schema_migrations table exists
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration   VARCHAR(191) NOT NULL UNIQUE,
        batch       INT UNSIGNED NOT NULL DEFAULT 1,
        executed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// 2. Fetch already applied migrations
$stmt = $pdo->query("SELECT migration FROM schema_migrations");
$applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

// 3. Current max batch number
$maxBatch = (int)$pdo->query("SELECT IFNULL(MAX(batch), 0) FROM schema_migrations")->fetchColumn();
$currentBatch = $maxBatch + 1;

// 4. Ordered canonical migrations list
$orderedMigrations = [
    'migration_add_rooms.php',
    'migration_add_assets_tables.php',
    'migration_add_asset_scan_verifications.php',
    'migration_add_mobile_tokens.php',
    'migration_add_loan_extensions.php',
    'migration_add_defective_part_exchange.php',
    'migration_add_manual_urgent_flag.php',
    'migration_direct_checkout_loans.php',
    'migration_widen_audit_description.php',
];

// Discover any new migration_*.php files not in the canonical list
$allFiles = glob(__DIR__ . '/migration_*.php');
$discovered = [];
foreach ($allFiles as $file) {
    $base = basename($file);
    if (!in_array($base, $orderedMigrations, true)) {
        $discovered[] = $base;
    }
}
sort($discovered);
$allMigrations = array_merge($orderedMigrations, $discovered);

echo "========================================================\n";
echo " DuaRTE Database Migration Runner\n";
echo " Database: " . DB_NAME . " on " . DB_HOST . "\n";
echo "========================================================\n\n";

// Locate PHP binary for isolated subprocess execution
$phpBinary = PHP_BINARY;
if (empty($phpBinary) || !is_file($phpBinary) || stripos($phpBinary, 'httpd') !== false) {
    $candidate = 'C:\\xampp\\php\\php.exe';
    if (is_file($candidate)) {
        $phpBinary = $candidate;
    } else {
        $phpBinary = 'php';
    }
}

$pending = [];
foreach ($allMigrations as $m) {
    if (!isset($appliedSet[$m])) {
        $pending[] = $m;
    }
}

if (empty($pending)) {
    echo "[OK] No pending migrations. Database schema is fully up to date.\n\n";
} else {
    echo "Found " . count($pending) . " pending migration(s) (Batch {$currentBatch}):\n";
    foreach ($pending as $m) {
        echo "  - {$m}\n";
    }
    echo "\nExecuting migrations...\n";
    echo "--------------------------------------------------------\n";

    $insStmt = $pdo->prepare("INSERT INTO schema_migrations (migration, batch) VALUES (:m, :b)");

    foreach ($pending as $m) {
        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . $m;
        if (!is_file($fullPath)) {
            echo "[SKIPPED] Migration file not found: {$m}\n";
            continue;
        }

        echo "Running: {$m} ... ";
        $startTime = microtime(true);

        // Execute in isolated process to prevent function redeclaration collisions
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($fullPath);
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        if ($exitCode === 0) {
            $insStmt->execute(['m' => $m, 'b' => $currentBatch]);
            echo "DONE ({$elapsed} ms)\n";
            foreach ($output as $line) {
                if (trim($line) !== '') {
                    echo "    > " . trim($line) . "\n";
                }
            }
        } else {
            echo "FAILED (exit code {$exitCode})\n";
            foreach ($output as $line) {
                echo "    ! " . $line . "\n";
            }
            echo "\n[ERROR] Migration aborted due to failure in {$m}.\n";
            exit(1);
        }
    }
    echo "--------------------------------------------------------\n";
    echo "[SUCCESS] All pending migrations executed successfully.\n\n";
}

// 5. Display summary report
echo "Current Migration Status:\n";
echo str_repeat('-', 65) . "\n";
printf("%-40s | %-6s | %-15s\n", "Migration Name", "Batch", "Executed At");
echo str_repeat('-', 65) . "\n";

$records = $pdo->query("SELECT migration, batch, executed_at FROM schema_migrations ORDER BY id ASC")->fetchAll();
foreach ($records as $r) {
    printf("%-40s | %-6d | %-15s\n", $r['migration'], (int)$r['batch'], substr($r['executed_at'], 0, 16));
}
echo str_repeat('-', 65) . "\n";
echo "Total migrations applied: " . count($records) . "\n";
echo "========================================================\n";
