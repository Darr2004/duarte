<?php
/**
 * DuaRTE — Migration: Add truck_complaints table
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db();

$sql = "CREATE TABLE IF NOT EXISTS truck_complaints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    truck_id INT UNSIGNED NOT NULL,
    reported_by INT UNSIGNED NOT NULL,
    complaint_category VARCHAR(50) NOT NULL DEFAULT 'other',
    complaint_text VARCHAR(255) NOT NULL,
    urgency_level ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    status ENUM('open', 'in_progress', 'resolved') NOT NULL DEFAULT 'open',
    resolution_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_truck_status (truck_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$pdo->exec($sql);
echo "truck_complaints table created or already exists.\n";
