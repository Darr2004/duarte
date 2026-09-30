<?php
/**
 * DuaRTE — One-time migration: server-verified asset scans.
 *
 * Adds `asset_scan_verifications` — a short-lived, single-use token
 * minted by inventory/asset_scan_verify.php the moment a QR scan
 * actually decodes a matching asset tag, and required + consumed by
 * inventory/verify.php's release handler. Before this table existed,
 * "must scan, can't pick by hand" was enforced only in the browser
 * (the <select> was reset on a manual 'change' event) — a posted
 * asset_choice[] value was trusted as-is server-side, so the rule was
 * cosmetic and could be bypassed by anyone posting the form directly.
 *
 * SAFE TO RE-RUN: checks first and only adds what's missing.
 *
 *   php database/migration_add_asset_scan_verifications.php
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

function duarte_table_exists_2(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $stmt->execute(['t' => $table]);
    return (bool)$stmt->fetchColumn();
}

if (!duarte_table_exists_2($pdo, 'asset_scan_verifications')) {
    $pdo->exec(
        "CREATE TABLE asset_scan_verifications (
            id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            requisition_item_id    INT UNSIGNED NOT NULL,
            asset_id               INT UNSIGNED NOT NULL,
            verify_token           VARCHAR(64)  NOT NULL UNIQUE,
            scanned_by             INT UNSIGNED NOT NULL,
            created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            consumed_at            TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (requisition_item_id) REFERENCES requisition_items(id) ON DELETE CASCADE,
            FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
            FOREIGN KEY (scanned_by) REFERENCES users(id),
            INDEX idx_scan_verif_token (verify_token),
            INDEX idx_scan_verif_item (requisition_item_id)
        ) ENGINE=InnoDB"
    );
    echo "Created asset_scan_verifications table.\n";
} else {
    echo "asset_scan_verifications table already exists.\n";
}

echo "Done.\n";
