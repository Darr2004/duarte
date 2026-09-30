<?php
/**
 * DuaRTE — One-time migration: maintenance-request flag on requisitions.
 *
 * requisition/cart.php, requisition/view.php, and api/requisitions.php
 * all block a requisition tied to a truck whose status is
 * 'under_maintenance' ("cannot be scheduled"). That's correct for a
 * normal trip requisition (fuel, tools, tires to load before dispatch)
 * but wrong for the one case where you actually NEED a truck under
 * maintenance selected: requesting the spare parts to repair it.
 *
 * Adds one column to `requisitions`:
 *   - is_maintenance_request TINYINT(1) — set when the requester marks
 *     the request as being for repairing the selected truck, not for
 *     a trip. When set, the under_maintenance block is skipped at
 *     submit, approve, and release.
 *
 * SAFE TO RE-RUN: the column is added only if it doesn't already exist.
 *
 *   php database/migration_add_maintenance_request_flag.php
 *
 * or visit it in the browser as an admin.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    require_role(['admin']);
    header('Content-Type: text/plain');
}

$pdo = get_db();

function duarte_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!duarte_column_exists($pdo, 'requisitions', 'is_maintenance_request')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN is_maintenance_request TINYINT(1) NOT NULL DEFAULT 0 AFTER truck_plate_snapshot");
    echo "Added requisitions.is_maintenance_request.\n";
} else {
    echo "requisitions.is_maintenance_request already exists.\n";
}

echo "Done.\n";
