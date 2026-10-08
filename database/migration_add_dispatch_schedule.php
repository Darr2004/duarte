<?php
require_once __DIR__ . '/../config/database.php';

$pdo = get_db();
echo "Checking requisitions table for dispatch_schedule column...\n";

$check = $pdo->query("SHOW COLUMNS FROM requisitions LIKE 'dispatch_schedule'")->fetchAll();
if (empty($check)) {
    echo "Adding dispatch_schedule column to requisitions table...\n";
    $pdo->exec("ALTER TABLE requisitions ADD COLUMN dispatch_schedule VARCHAR(20) DEFAULT 'standby' AFTER is_maintenance_request");
    echo "Successfully added dispatch_schedule column.\n";
} else {
    echo "Column dispatch_schedule already exists.\n";
}
