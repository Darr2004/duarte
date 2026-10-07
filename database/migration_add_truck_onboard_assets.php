<?php
/**
 * DuaRTE — Migration: Truck Onboard Assets (Permanent Truck Kit)
 *
 * Adds permanent truck assignment capability for tools and equipment
 * (e.g. Hydraulic Jack, Tire Wrench, EWD, Fire Extinguisher) so they
 * remain permanently assigned to a vehicle without triggering daily
 * loan expiration countdowns or blocking drivers with overdue flags.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = get_db();

function duarte_column_exists_local(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
    ");
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function duarte_fk_exists_local(PDO $pdo, string $table, string $fk_name): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND CONSTRAINT_NAME = :fk
    ");
    $stmt->execute(['table' => $table, 'fk' => $fk_name]);
    return (int)$stmt->fetchColumn() > 0;
}

echo "Running migration: Add truck onboard asset tracking columns...\n";

// 1. Add assigned_truck_id to assets
if (!duarte_column_exists_local($pdo, 'assets', 'assigned_truck_id')) {
    $pdo->exec("ALTER TABLE assets ADD COLUMN assigned_truck_id INT UNSIGNED DEFAULT NULL AFTER current_holder_id");
    echo "Added assets.assigned_truck_id column.\n";
} else {
    echo "assets.assigned_truck_id already exists.\n";
}

// 2. Add assigned_to_truck_at to assets
if (!duarte_column_exists_local($pdo, 'assets', 'assigned_to_truck_at')) {
    $pdo->exec("ALTER TABLE assets ADD COLUMN assigned_to_truck_at TIMESTAMP NULL DEFAULT NULL AFTER assigned_truck_id");
    echo "Added assets.assigned_to_truck_at column.\n";
} else {
    echo "assets.assigned_to_truck_at already exists.\n";
}

// 3. Foreign key on assigned_truck_id
if (!duarte_fk_exists_local($pdo, 'assets', 'fk_assets_assigned_truck')) {
    $pdo->exec("
        ALTER TABLE assets
        ADD CONSTRAINT fk_assets_assigned_truck
        FOREIGN KEY (assigned_truck_id) REFERENCES trucks(id)
        ON DELETE SET NULL
    ");
    echo "Added fk_assets_assigned_truck constraint.\n";
} else {
    echo "fk_assets_assigned_truck constraint already exists.\n";
}

// 4. Update asset_events ENUM to include assigned_to_truck and unassigned_from_truck
try {
    $pdo->exec("
        ALTER TABLE asset_events MODIFY COLUMN event_type ENUM(
            'registered', 'checked_out', 'checked_in',
            'transferred', 'damage_reported',
            'maintenance_completed', 'retired',
            'audit_confirmed', 'audit_missing', 'consumed',
            'assigned_to_truck', 'unassigned_from_truck'
        ) NOT NULL
    ");
    echo "Updated asset_events.event_type ENUM successfully.\n";
} catch (Exception $e) {
    echo "Notice on asset_events ENUM: " . $e->getMessage() . "\n";
}

// 5. Seed sample onboard tools if hydraulic jack exists and truck 1 has none
$count_assigned = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE assigned_truck_id IS NOT NULL")->fetchColumn();
if ($count_assigned === 0) {
    // Let's find an available hydraulic jack or angle grinder and assign to truck 1 as an initial onboard kit example
    $sample_asset = $pdo->query("
        SELECT a.id, t.id AS truck_id, t.plate_number
        FROM assets a
        JOIN items i ON i.id = a.item_id
        CROSS JOIN (SELECT id, plate_number FROM trucks LIMIT 1) t
        WHERE a.status = 'available' AND a.assigned_truck_id IS NULL AND i.name LIKE '%Jack%'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if ($sample_asset) {
        $upd = $pdo->prepare("
            UPDATE assets 
            SET assigned_truck_id = :tid, 
                assigned_to_truck_at = NOW(), 
                location_note = :loc 
            WHERE id = :aid
        ");
        $upd->execute([
            'tid' => $sample_asset['truck_id'],
            'loc' => 'Onboard: ' . $sample_asset['plate_number'],
            'aid' => $sample_asset['id']
        ]);
        echo "Seeded sample onboard tool #{$sample_asset['id']} to truck {$sample_asset['plate_number']}.\n";
    }
}

echo "Truck onboard assets migration complete.\n";
