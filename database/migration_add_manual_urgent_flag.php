<?php
/**
 * DuaRTE — One-time migration: manual urgent flag on requisitions.
 *
 * includes/priority.php scores pending requisitions on wait time,
 * scarcity, contention, and reliability — all signals the system can
 * read from existing data. It has no way to know a request is urgent
 * for a reason none of those four criteria capture (a truck that
 * can't roll without this part today, a safety issue, etc.). Before
 * this, a Field Supervisor's only way to act on that was to switch
 * requisition/pending.php to "Oldest first" and decide out of the
 * priority ranking silently.
 *
 * Adds four columns to `requisitions`:
 *   - manual_urgent        TINYINT(1) — the flag itself
 *   - manual_urgent_reason VARCHAR(255) — required free-text reason,
 *                            logged so the override is accountable
 *   - manual_urgent_by     INT UNSIGNED — who flagged it (FK users)
 *   - manual_urgent_at     TIMESTAMP — when
 *
 * SAFE TO RE-RUN: each column is added only if it doesn't already exist.
 *
 *   php database/migration_add_manual_urgent_flag.php
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

if (!duarte_column_exists($pdo, 'requisitions', 'manual_urgent')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN manual_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER decision_note");
    echo "Added requisitions.manual_urgent.\n";
} else {
    echo "requisitions.manual_urgent already exists.\n";
}

if (!duarte_column_exists($pdo, 'requisitions', 'manual_urgent_reason')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN manual_urgent_reason VARCHAR(255) DEFAULT NULL AFTER manual_urgent");
    echo "Added requisitions.manual_urgent_reason.\n";
} else {
    echo "requisitions.manual_urgent_reason already exists.\n";
}

if (!duarte_column_exists($pdo, 'requisitions', 'manual_urgent_by')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN manual_urgent_by INT UNSIGNED DEFAULT NULL AFTER manual_urgent_reason");
    $pdo->exec("ALTER TABLE requisitions ADD CONSTRAINT fk_requisitions_manual_urgent_by FOREIGN KEY (manual_urgent_by) REFERENCES users(id)");
    echo "Added requisitions.manual_urgent_by (+ FK).\n";
} else {
    echo "requisitions.manual_urgent_by already exists.\n";
}

if (!duarte_column_exists($pdo, 'requisitions', 'manual_urgent_at')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN manual_urgent_at TIMESTAMP NULL DEFAULT NULL AFTER manual_urgent_by");
    echo "Added requisitions.manual_urgent_at.\n";
} else {
    echo "requisitions.manual_urgent_at already exists.\n";
}

echo "Done.\n";
