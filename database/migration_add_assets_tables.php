<?php
/**
 * DuaRTE — One-time migration: QR Code Asset Tracking.
 *
 * Adds:
 *   - `assets`       — one row per PHYSICAL UNIT of a borrowable item
 *                      (not per item type), each with its own permanent
 *                      QR tag (asset_tag), status, and current holder.
 *   - `asset_events` — append-only history log per asset (registered,
 *                      checked out, checked in, transferred, damage
 *                      reported, maintenance completed, retired, audit
 *                      confirmed/missing), the same pattern
 *                      stock_movements and audit_logs already use.
 *   - `tool_loans.asset_id` — links a loan to the specific physical
 *                      unit released, not just the item type, so
 *                      overdue tracking can say which exact unit is
 *                      late instead of only which item type.
 *   - `assets.item_variant_id` — links an asset/lot to a specific
 *                      catalog option (item_variants row) when the
 *                      item has them, so a QR tag points at one exact
 *                      part instead of being pooled across options
 *                      that aren't actually interchangeable.
 *
 * See the comment block above the `assets` table in schema.sql for the
 * full reasoning; this migration exists only so an already-running
 * install (created before this module existed) can catch up without a
 * fresh import.
 *
 * SAFE TO RE-RUN: every step checks first and only adds what's missing.
 *
 *   php database/migration_add_assets_tables.php
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

function duarte_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $stmt->execute(['t' => $table]);
    return (bool)$stmt->fetchColumn();
}

function duarte_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (bool)$stmt->fetchColumn();
}

function duarte_fk_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $constraint]);
    return (bool)$stmt->fetchColumn();
}

// 1. `assets` table
if (!duarte_table_exists($pdo, 'assets')) {
    $pdo->exec(
        "CREATE TABLE assets (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id           INT UNSIGNED NOT NULL,
            asset_tag         VARCHAR(64)   NOT NULL UNIQUE,
            serial_number     VARCHAR(80)   DEFAULT NULL,
            quantity          INT UNSIGNED  NOT NULL DEFAULT 1,
            status            ENUM('available', 'checked_out', 'under_maintenance', 'missing', 'retired')
                                  NOT NULL DEFAULT 'available',
            condition_note    VARCHAR(255)  DEFAULT NULL,
            location_note     VARCHAR(100)  DEFAULT NULL,
            current_holder_id INT UNSIGNED  DEFAULT NULL,
            acquired_at       DATE          DEFAULT NULL,
            registered_by     INT UNSIGNED  NOT NULL,
            created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES items(id),
            FOREIGN KEY (current_holder_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (registered_by) REFERENCES users(id),
            INDEX idx_assets_item (item_id),
            INDEX idx_assets_status (status)
        ) ENGINE=InnoDB"
    );
    echo "Created assets table.\n";
} else {
    echo "assets table already exists.\n";
}

// 2. `asset_events` table
if (!duarte_table_exists($pdo, 'asset_events')) {
    $pdo->exec(
        "CREATE TABLE asset_events (
            id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asset_id              INT UNSIGNED NOT NULL,
            event_type            ENUM(
                                      'registered', 'checked_out', 'checked_in',
                                      'transferred', 'damage_reported',
                                      'maintenance_completed', 'retired',
                                      'audit_confirmed', 'audit_missing', 'consumed'
                                  ) NOT NULL,
            actor_id              INT UNSIGNED DEFAULT NULL,
            actor_name_snapshot   VARCHAR(100) NOT NULL,
            reference_type        VARCHAR(30)  DEFAULT NULL,
            reference_id          INT UNSIGNED DEFAULT NULL,
            note                  VARCHAR(255) DEFAULT NULL,
            created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
            FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_asset_events_asset (asset_id, created_at)
        ) ENGINE=InnoDB"
    );
    echo "Created asset_events table.\n";
} else {
    echo "asset_events table already exists.\n";
}

// 3. `tool_loans.asset_id` column + FK
if (!duarte_column_exists($pdo, 'tool_loans', 'asset_id')) {
    $pdo->exec("ALTER TABLE tool_loans ADD COLUMN asset_id INT UNSIGNED DEFAULT NULL AFTER item_id");
    echo "Added tool_loans.asset_id column.\n";
} else {
    echo "tool_loans.asset_id column already exists.\n";
}

if (!duarte_fk_exists($pdo, 'tool_loans', 'fk_tool_loans_asset')) {
    $pdo->exec(
        "ALTER TABLE tool_loans ADD CONSTRAINT fk_tool_loans_asset
         FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL"
    );
    echo "Added tool_loans.asset_id foreign key.\n";
} else {
    echo "tool_loans.asset_id foreign key already exists.\n";
}

// 4. `assets.quantity` — for installs that already had the assets
// tables (steps 1-2 above were no-ops for them) from before lot-based
// consumable tracking existed.
if (!duarte_column_exists($pdo, 'assets', 'quantity')) {
    $pdo->exec("ALTER TABLE assets ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER serial_number");
    echo "Added assets.quantity column.\n";
} else {
    echo "assets.quantity column already exists.\n";
}

// 5. `asset_events.event_type` — widen to include 'consumed'. A plain
// MODIFY is safe to re-run, so no existence guard needed.
$pdo->exec(
    "ALTER TABLE asset_events MODIFY COLUMN event_type ENUM(
        'registered', 'checked_out', 'checked_in',
        'transferred', 'damage_reported',
        'maintenance_completed', 'retired',
        'audit_confirmed', 'audit_missing', 'consumed'
    ) NOT NULL"
);
echo "asset_events.event_type now includes 'consumed'.\n";

// 6. `assets.item_variant_id` — lets a QR tag point at one specific
// catalog option (e.g. a specific Part #) instead of being pooled
// under the item's aggregate stock. NULL for items with no options.
if (!duarte_column_exists($pdo, 'assets', 'item_variant_id')) {
    $pdo->exec("ALTER TABLE assets ADD COLUMN item_variant_id INT UNSIGNED DEFAULT NULL AFTER item_id");
    echo "Added assets.item_variant_id column.\n";
} else {
    echo "assets.item_variant_id column already exists.\n";
}

if (!duarte_fk_exists($pdo, 'assets', 'fk_assets_variant')) {
    $pdo->exec(
        "ALTER TABLE assets ADD CONSTRAINT fk_assets_variant
         FOREIGN KEY (item_variant_id) REFERENCES item_variants(id) ON DELETE SET NULL"
    );
    echo "Added assets.item_variant_id foreign key.\n";
} else {
    echo "assets.item_variant_id foreign key already exists.\n";
}

// Index on item_variant_id — guarded manually since ADD INDEX IF NOT
// EXISTS isn't supported on older MySQL.
$idx_check = $pdo->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND INDEX_NAME = 'idx_assets_variant'"
);
$idx_check->execute();
if (!(bool)$idx_check->fetchColumn()) {
    $pdo->exec("ALTER TABLE assets ADD INDEX idx_assets_variant (item_variant_id)");
    echo "Added assets.idx_assets_variant index.\n";
} else {
    echo "assets.idx_assets_variant index already exists.\n";
}

echo "Done.\n";
