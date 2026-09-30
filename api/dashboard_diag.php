<?php
/**
 * Dashboard load diagnostic — tests every query the dashboard runs
 */
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== Dashboard Load Diagnostic ===\n\n";

// Load config
echo "1. Loading config... ";
try {
    require_once __DIR__ . '/../config/config.php';
    echo "OK (BASE_URL: '" . BASE_URL . "', APP_ENV: " . APP_ENV . ")\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

// Load functions
echo "2. Loading functions.php... ";
try {
    require_once __DIR__ . '/../includes/functions.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

// Load loans
echo "3. Loading loans.php... ";
try {
    require_once __DIR__ . '/../includes/loans.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

// Load item_requests
echo "4. Loading item_requests.php... ";
try {
    require_once __DIR__ . '/../includes/item_requests.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

// Load auth (skip require_role check)
echo "5. Loading auth.php... ";
try {
    require_once __DIR__ . '/../includes/auth.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

// Get DB
echo "6. Getting DB connection... ";
try {
    $pdo = get_db();
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

// Dashboard queries
echo "7. Active items count... ";
try {
    $active_items = $pdo->query("SELECT COUNT(*) c FROM items WHERE status = 'active'")->fetch()['c'];
    echo "OK ($active_items)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "8. count_stock_alerts()... ";
try {
    $stock_alerts = count_stock_alerts($pdo);
    echo "OK ($stock_alerts)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "9. count_overdue_loans()... ";
try {
    $overdue_loans = count_overdue_loans($pdo);
    echo "OK ($overdue_loans)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "10. count_awaiting_release()... ";
try {
    $awaiting = count_awaiting_release($pdo);
    echo "OK ($awaiting)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "11. count_pending_item_requests()... ";
try {
    $pending = count_pending_item_requests($pdo);
    echo "OK ($pending)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "12. Staging releases query... ";
try {
    $staging = $pdo->query(
        "SELECT r.id, r.purpose, r.truck_plate_snapshot, r.decided_at,
                u.full_name AS requester_name,
                (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
         FROM requisitions r
         JOIN users u ON u.id = r.requester_id
         WHERE r.status = 'approved'
         ORDER BY r.decided_at ASC
         LIMIT 4"
    )->fetchAll();
    echo "OK (" . count($staging) . " rows)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "13. Active loans due query... ";
try {
    $active_loans = $pdo->query(
        "SELECT tl.id, tl.due_date, tl.requisition_id,
                i.name AS item_name, a.asset_tag,
                u.full_name AS borrower_name
         FROM tool_loans tl
         JOIN items i ON i.id = tl.item_id
         JOIN users u ON u.id = tl.borrower_id
         LEFT JOIN assets a ON a.id = tl.asset_id
         WHERE tl.returned_at IS NULL
         ORDER BY tl.due_date ASC
         LIMIT 4"
    )->fetchAll();
    echo "OK (" . count($active_loans) . " rows)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "14. Stock movements sparkline... ";
try {
    $daily = $pdo->query(
        "SELECT DATE(created_at) d, COUNT(*) c FROM stock_movements
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(created_at)"
    )->fetchAll();
    echo "OK (" . count($daily) . " rows)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "15. expire_stale_requisitions()... ";
try {
    $expired = expire_stale_requisitions($pdo);
    echo "OK ($expired expired)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "16. Include header.php... ";
try {
    // Check if header.php can be loaded (check for syntax/include errors)
    // We can't actually include it (it outputs HTML), but we can check the file exists
    $header_path = realpath(__DIR__ . '/../includes/header.php');
    echo $header_path ? "OK (exists: $header_path)\n" : "MISSING!\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "\n=== ALL DASHBOARD CHECKS COMPLETE ===\n";
