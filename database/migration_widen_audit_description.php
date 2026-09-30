<?php
/**
 * DuaRTE — One-time migration: widen audit_logs.description.
 *
 * requisition/view.php now appends a priority-queue snapshot (score,
 * rank, and the four criteria breakdown) to the audit description
 * logged on every approve/decline decision — see includes/priority.php
 * for what those criteria are and why they're recorded here. Combined
 * with a supervisor's free-text decision note (up to VARCHAR(255) on
 * requisitions.decision_note) and both parties' full names, the
 * worst-case combined string comfortably exceeds the original
 * VARCHAR(255) — MySQL either truncates that silently or rejects it
 * outright depending on sql_mode.
 *
 * Widened to TEXT rather than a larger VARCHAR: admin/audit_logs.php
 * and audit_logs_export.php only ever match this column with
 * `LIKE '%...%'`, which doesn't use an index either way, so there's no
 * fixed-length index to lose and no upper bound worth guessing at.
 *
 * SAFE TO RE-RUN: checks the current column type first and only
 * alters it if it isn't already TEXT (or larger).
 *
 *   php database/migration_widen_audit_description.php
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

$stmt = $pdo->prepare(
    "SELECT DATA_TYPE
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'audit_logs'
        AND COLUMN_NAME = 'description'"
);
$stmt->execute();
$current_type = $stmt->fetchColumn();

if ($current_type === false) {
    echo "Could not find audit_logs.description — is the audit_logs table installed?\n";
} elseif (in_array($current_type, ['text', 'mediumtext', 'longtext'], true)) {
    echo "audit_logs.description is already {$current_type} — nothing to do.\n";
} else {
    $pdo->exec("ALTER TABLE audit_logs MODIFY description TEXT NOT NULL");
    echo "Widened audit_logs.description from {$current_type} to TEXT.\n";
}

echo "Done.\n";
