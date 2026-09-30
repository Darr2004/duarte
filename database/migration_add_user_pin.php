<?php
/**
 * DuaRTE — One-time migration: add PIN authentication columns to users table.
 *
 * Adds:
 *   - pin_hash                VARCHAR(255) NULL DEFAULT NULL (bcrypt hash of 4-digit PIN)
 *   - pin_set_at             TIMESTAMP NULL DEFAULT NULL
 *   - pin_failed_attempts    INT UNSIGNED NOT NULL DEFAULT 0
 *   - pin_locked_until       TIMESTAMP NULL DEFAULT NULL
 *
 * Safe to re-run: columns checked via INFORMATION_SCHEMA before adding.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    require_role(['admin']);
    header('Content-Type: text/plain');
}

$pdo = get_db();

if (!function_exists('duarte_pin_column_exists')) {
    function duarte_pin_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!duarte_pin_column_exists($pdo, 'users', 'pin_hash')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN pin_hash VARCHAR(255) NULL DEFAULT NULL AFTER password_hash");
    echo "Added users.pin_hash.\n";
} else {
    echo "users.pin_hash already exists.\n";
}

if (!duarte_pin_column_exists($pdo, 'users', 'pin_set_at')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN pin_set_at TIMESTAMP NULL DEFAULT NULL AFTER pin_hash");
    echo "Added users.pin_set_at.\n";
} else {
    echo "users.pin_set_at already exists.\n";
}

if (!duarte_pin_column_exists($pdo, 'users', 'pin_failed_attempts')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN pin_failed_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_set_at");
    echo "Added users.pin_failed_attempts.\n";
} else {
    echo "users.pin_failed_attempts already exists.\n";
}

if (!duarte_pin_column_exists($pdo, 'users', 'pin_locked_until')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN pin_locked_until TIMESTAMP NULL DEFAULT NULL AFTER pin_failed_attempts");
    echo "Added users.pin_locked_until.\n";
} else {
    echo "users.pin_locked_until already exists.\n";
}

echo "Migration migration_add_user_pin.php completed.\n";
