<?php
/**
 * DuaRTE — One-time migration: Palit-Piyesa (1-to-1 Defective Part Exchange) verification.
 *
 * Adds two columns to `requisitions`:
 *   - defective_part_surrendered TINYINT(1) — whether the old/damaged part was surrendered upon release
 *   - defective_part_note VARCHAR(255) — optional condition note (e.g. "burnt bulb", "cracked belt", "new install")
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = get_db();

function col_exists(PDO $pdo, string $col): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requisitions' AND COLUMN_NAME = :c");
    $stmt->execute(['c' => $col]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!col_exists($pdo, 'defective_part_surrendered')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN defective_part_surrendered TINYINT(1) NOT NULL DEFAULT 0 AFTER released_at");
    echo "Added requisitions.defective_part_surrendered\n";
} else {
    echo "requisitions.defective_part_surrendered already exists.\n";
}

if (!col_exists($pdo, 'defective_part_note')) {
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN defective_part_note VARCHAR(255) DEFAULT NULL AFTER defective_part_surrendered");
    echo "Added requisitions.defective_part_note\n";
} else {
    echo "requisitions.defective_part_note already exists.\n";
}

echo "Palit-Piyesa migration complete.\n";
