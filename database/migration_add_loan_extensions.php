<?php
/**
 * Migration: Add extension tracking columns to tool_loans
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = get_db();

echo "Running migration: Add loan extension columns to tool_loans...\n";

$columns = [
    'extension_days' => "ALTER TABLE tool_loans ADD COLUMN extension_days INT DEFAULT NULL AFTER due_date",
    'extension_reason' => "ALTER TABLE tool_loans ADD COLUMN extension_reason VARCHAR(255) DEFAULT NULL AFTER extension_days",
    'extension_status' => "ALTER TABLE tool_loans ADD COLUMN extension_status ENUM('none', 'pending', 'approved', 'declined') NOT NULL DEFAULT 'none' AFTER extension_reason",
    'extension_requested_at' => "ALTER TABLE tool_loans ADD COLUMN extension_requested_at TIMESTAMP NULL DEFAULT NULL AFTER extension_status",
    'extension_decided_at' => "ALTER TABLE tool_loans ADD COLUMN extension_decided_at TIMESTAMP NULL DEFAULT NULL AFTER extension_requested_at",
    'extension_decided_by' => "ALTER TABLE tool_loans ADD COLUMN extension_decided_by INT UNSIGNED NULL DEFAULT NULL AFTER extension_decided_at",
    'extension_decision_note' => "ALTER TABLE tool_loans ADD COLUMN extension_decision_note VARCHAR(255) DEFAULT NULL AFTER extension_decided_by",
];

// Check existing columns
$existing_cols = $pdo->query("DESCRIBE tool_loans")->fetchAll(PDO::FETCH_COLUMN);

foreach ($columns as $col_name => $sql) {
    if (!in_array($col_name, $existing_cols, true)) {
        $pdo->exec($sql);
        echo " - Added column $col_name\n";
    } else {
        echo " - Column $col_name already exists, skipping.\n";
    }
}

echo "Migration completed successfully!\n";
