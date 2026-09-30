<?php
/**
 * DuaRTE — One-time migration: Direct Checkout Loan Tracking.
 *
 * Manual "Check out to" on the asset detail page (inventory/asset_view.php)
 * used to just flip the asset's status/holder with no due date, no overdue
 * tracking, and no entry in Tool Loans / My Borrowed Tools — unlike a
 * requisition-released borrow, which gets all of that. This migration
 * closes that gap by letting a tool_loans row exist WITHOUT a requisition
 * behind it, so a direct handout is tracked exactly the same way as a
 * paperwork one.
 *
 * Changes:
 *   - `tool_loans.requisition_id`      — made NULLable (was NOT NULL).
 *   - `tool_loans.requisition_item_id` — made NULLable (was NOT NULL).
 *     NULL in both means "direct/manual checkout, no requisition" — see
 *     record_direct_checkout_loan() in includes/loans.php.
 *
 * SAFE TO RE-RUN: MODIFY COLUMN is idempotent, and each step checks the
 * column's current nullability first so re-running just confirms it's
 * already done.
 *
 *   php database/migration_direct_checkout_loans.php
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

function duarte_column_is_nullable(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return $stmt->fetchColumn() === 'YES';
}

// 1. `tool_loans.requisition_id` — allow NULL
if (!duarte_column_is_nullable($pdo, 'tool_loans', 'requisition_id')) {
    $pdo->exec("ALTER TABLE tool_loans MODIFY COLUMN requisition_id INT UNSIGNED DEFAULT NULL");
    echo "tool_loans.requisition_id is now nullable.\n";
} else {
    echo "tool_loans.requisition_id is already nullable.\n";
}

// 2. `tool_loans.requisition_item_id` — allow NULL
if (!duarte_column_is_nullable($pdo, 'tool_loans', 'requisition_item_id')) {
    $pdo->exec("ALTER TABLE tool_loans MODIFY COLUMN requisition_item_id INT UNSIGNED DEFAULT NULL");
    echo "tool_loans.requisition_item_id is now nullable.\n";
} else {
    echo "tool_loans.requisition_item_id is already nullable.\n";
}

echo "Done.\n";
