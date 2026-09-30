<?php
/**
 * DuaRTE — One-time migration: add partial release / short-picking support.
 *
 * Adds:
 *   - requisition_items.quantity_released   INT UNSIGNED NOT NULL DEFAULT 0
 *   - requisition_items.is_released         TINYINT(1)   NOT NULL DEFAULT 0
 *   - requisition_items.release_note        VARCHAR(255) NULL DEFAULT NULL
 *   - requisitions.is_partial_release       TINYINT(1)   NOT NULL DEFAULT 0
 *   - requisitions.partial_release_reason   VARCHAR(255) NULL DEFAULT NULL
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    require_role(['admin']);
    header('Content-Type: text/plain');
}

$pdo = get_db();

if (!function_exists('duarte_partial_col_exists')) {
    function duarte_partial_col_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

// 1. requisition_items columns
if (!duarte_partial_col_exists($pdo, 'requisition_items', 'quantity_released')) {
    $pdo->exec("ALTER TABLE requisition_items ADD COLUMN quantity_released INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity_requested");
    echo "Added requisition_items.quantity_released.\n";
} else {
    echo "requisition_items.quantity_released already exists.\n";
}

if (!duarte_partial_col_exists($pdo, 'requisition_items', 'is_released')) {
    $pdo->exec("ALTER TABLE requisition_items ADD COLUMN is_released TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity_released");
    echo "Added requisition_items.is_released.\n";
} else {
    echo "requisition_items.is_released already exists.\n";
}

if (!duarte_partial_col_exists($pdo, 'requisition_items', 'release_note')) {
    $pdo->exec("ALTER TABLE requisition_items ADD COLUMN release_note VARCHAR(255) NULL DEFAULT NULL AFTER is_released");
    echo "Added requisition_items.release_note.\n";
} else {
    echo "requisition_items.release_note already exists.\n";
}

// 2. requisitions columns
if (!duarte_partial_col_exists($pdo, 'requisitions', 'is_partial_release')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN is_partial_release TINYINT(1) NOT NULL DEFAULT 0 AFTER released_at");
    echo "Added requisitions.is_partial_release.\n";
} else {
    echo "requisitions.is_partial_release already exists.\n";
}

if (!duarte_partial_col_exists($pdo, 'requisitions', 'partial_release_reason')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN partial_release_reason VARCHAR(255) NULL DEFAULT NULL AFTER is_partial_release");
    echo "Added requisitions.partial_release_reason.\n";
} else {
    echo "requisitions.partial_release_reason already exists.\n";
}

// Backfill existing 'released' requisitions so past released items are marked is_released = 1 and quantity_released = quantity_requested
$pdo->exec("
    UPDATE requisition_items ri
    JOIN requisitions r ON r.id = ri.requisition_id
    SET ri.is_released = 1,
        ri.quantity_released = ri.quantity_requested
    WHERE r.status = 'released' AND ri.quantity_released = 0
");
echo "Backfilled past released requisition items.\n";

echo "Migration migration_add_partial_release_support.php completed.\n";
