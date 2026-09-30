<?php
/**
 * DuaRTE — Migration: add system_settings table for dynamic configuration (MCDA weights, etc.)
 *
 * Adds:
 *   - system_settings table (key-value store with timestamps)
 *   - Seeds default MCDA algorithm parameters:
 *       - mcda_weight_scarcity: 0.45
 *       - mcda_weight_contention: 0.35
 *       - mcda_weight_reliability: 0.20
 *       - mcda_active_preset: 'standard'
 *
 * Safe to re-run: checks table and rows before inserting.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    require_role(['admin']);
    header('Content-Type: text/plain');
}

$pdo = get_db();

if (!function_exists('duarte_settings_table_exists')) {
    function duarte_settings_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
        );
        $stmt->execute(['t' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!duarte_settings_table_exists($pdo, 'system_settings')) {
    $pdo->exec(
        "CREATE TABLE system_settings (
            setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
            setting_value TEXT         NULL,
            description   VARCHAR(255) NULL,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "Created system_settings table.\n";
} else {
    echo "system_settings table already exists.\n";
}

$defaults = [
    'mcda_weight_scarcity'    => ['0.45', 'MCDA weight for inventory scarcity (0.00 - 1.00)'],
    'mcda_weight_contention'  => ['0.35', 'MCDA weight for competing demand contention (0.00 - 1.00)'],
    'mcda_weight_reliability' => ['0.20', 'MCDA weight for requester tool loan reliability (0.00 - 1.00)'],
    'mcda_active_preset'      => ['standard', 'Active MCDA preset (standard, crisis_rush, asset_protection, custom)'],
];

$ins = $pdo->prepare(
    "INSERT INTO system_settings (setting_key, setting_value, description)
     VALUES (:key, :val, :desc)
     ON DUPLICATE KEY UPDATE description = VALUES(description)"
);

foreach ($defaults as $key => [$val, $desc]) {
    $ins->execute([
        'key'  => $key,
        'val'  => $val,
        'desc' => $desc,
    ]);
}
echo "Seeded default MCDA settings.\n";
