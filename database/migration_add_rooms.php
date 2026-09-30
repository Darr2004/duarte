<?php
/**
 * DuaRTE — One-time migration: Inventory Rooms.
 *
 * Adds a `rooms` table as a new top-level storage location above
 * `stalls` (Room -> Stall -> Layer). For an already-running install:
 *
 *   1. Creates `rooms` if missing.
 *   2. Adds `stalls.room_id` if missing (nullable at first).
 *   3. Backfills: if any stall has no room yet, creates a default
 *      "Room 1" (reusing one that already exists with room_number 1)
 *      and assigns every room-less stall to it.
 *   4. Swaps the old global UNIQUE(stall_number) for
 *      UNIQUE(room_id, stall_number) — stall numbers only need to be
 *      unique within their own room now.
 *   5. Makes `room_id` NOT NULL and adds its foreign key to `rooms`.
 *
 * SAFE TO RE-RUN: every step checks first and only adds what's missing.
 *
 *   php database/migration_add_rooms.php
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

function duarte_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
    );
    $stmt->execute(['t' => $table, 'i' => $index]);
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

// 1. `rooms` table
if (!duarte_table_exists($pdo, 'rooms')) {
    $pdo->exec(
        "CREATE TABLE rooms (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_number   INT UNSIGNED NOT NULL UNIQUE,
            name          VARCHAR(60)  NOT NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    echo "Created rooms table.\n";
} else {
    echo "rooms table already exists.\n";
}

// 2. `stalls.room_id` column (nullable for now — backfilled in step 3)
if (!duarte_column_exists($pdo, 'stalls', 'room_id')) {
    $pdo->exec("ALTER TABLE stalls ADD COLUMN room_id INT UNSIGNED DEFAULT NULL AFTER id");
    echo "Added stalls.room_id column.\n";
} else {
    echo "stalls.room_id column already exists.\n";
}

// 3. Backfill any room-less stalls into a default room
$missing = (int)$pdo->query("SELECT COUNT(*) FROM stalls WHERE room_id IS NULL")->fetchColumn();
if ($missing > 0) {
    $room_stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_number = 1");
    $room_stmt->execute();
    $default_room_id = $room_stmt->fetchColumn();

    if (!$default_room_id) {
        $pdo->exec("INSERT INTO rooms (room_number, name) VALUES (1, 'Room 1')");
        $default_room_id = (int)$pdo->lastInsertId();
        echo "Created default Room 1 for existing stalls.\n";
    }

    $pdo->prepare("UPDATE stalls SET room_id = :rid WHERE room_id IS NULL")
        ->execute(['rid' => $default_room_id]);
    echo "Assigned {$missing} existing stall(s) to Room 1.\n";
} else {
    echo "No room-less stalls to backfill.\n";
}

// 4. Swap the old global unique(stall_number) for unique(room_id, stall_number)
if (duarte_index_exists($pdo, 'stalls', 'stall_number') && !duarte_index_exists($pdo, 'stalls', 'uniq_room_stall_number')) {
    $pdo->exec("ALTER TABLE stalls DROP INDEX stall_number");
    echo "Dropped old global unique index on stalls.stall_number.\n";
}
if (!duarte_index_exists($pdo, 'stalls', 'uniq_room_stall_number')) {
    $pdo->exec("ALTER TABLE stalls ADD UNIQUE KEY uniq_room_stall_number (room_id, stall_number)");
    echo "Added unique(room_id, stall_number) index.\n";
} else {
    echo "unique(room_id, stall_number) index already exists.\n";
}

// 5. room_id NOT NULL + foreign key
$still_missing = (int)$pdo->query("SELECT COUNT(*) FROM stalls WHERE room_id IS NULL")->fetchColumn();
if ($still_missing === 0) {
    $pdo->exec("ALTER TABLE stalls MODIFY room_id INT UNSIGNED NOT NULL");
    echo "stalls.room_id is now NOT NULL.\n";
}
if (!duarte_fk_exists($pdo, 'stalls', 'fk_stalls_room')) {
    $pdo->exec(
        "ALTER TABLE stalls ADD CONSTRAINT fk_stalls_room
         FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT"
    );
    echo "Added stalls.room_id foreign key.\n";
} else {
    echo "stalls.room_id foreign key already exists.\n";
}

echo "Done.\n";
