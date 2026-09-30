<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'inventory_staff']);

$pdo = get_db();

$type = $_GET['type'] ?? '';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
$from_ts = $from . ' 00:00:00';
$to_ts   = $to . ' 23:59:59';

if (!in_array($type, ['requisitions', 'movements', 'loans', 'fleet'], true)) {
    http_response_code(400);
    die('Unknown export type.');
}

if ($type === 'fleet' && current_user()['role'] !== 'admin') {
    http_response_code(403);
    die('Access Denied: Only administrators can export fleet records.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="duarte-' . $type . '-' . $from . '_to_' . $to . '.csv"');

$out = fopen('php://output', 'w');

if ($type === 'requisitions') {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.truck_plate_snapshot, u.full_name AS requester, r.status, r.purpose, r.created_at,
                d.full_name AS decided_by, r.decided_at, r.released_at
         FROM requisitions r
         JOIN users u ON u.id = r.requester_id
         LEFT JOIN users d ON d.id = r.decided_by
         WHERE r.created_at BETWEEN :f AND :t
         ORDER BY r.created_at ASC"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);

    fputcsv($out, ['ID', 'Truck Plate', 'Requester', 'Status', 'Purpose', 'Submitted', 'Decided By', 'Decided At', 'Released At']);
    foreach ($stmt as $row) {
        fputcsv($out, csv_row([
            $row['id'], $row['truck_plate_snapshot'] ?: 'None', $row['requester'], $row['status'], $row['purpose'],
            $row['created_at'], $row['decided_by'], $row['decided_at'], $row['released_at'],
        ]));
    }
} elseif ($type === 'movements') {
    $stmt = $pdo->prepare(
        "SELECT m.created_at, i.item_code, i.name, m.movement_type, m.quantity_change,
                m.quantity_before, m.quantity_after, u.full_name AS recorded_by, m.reference_type, m.reference_id, m.note
         FROM stock_movements m
         JOIN items i ON i.id = m.item_id
         JOIN users u ON u.id = m.recorded_by
         WHERE m.created_at BETWEEN :f AND :t
         ORDER BY m.created_at ASC"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);

    fputcsv($out, ['Date', 'Item Code', 'Item', 'Type', 'Change', 'Before', 'After', 'Recorded By', 'Reference Type', 'Reference ID', 'Note']);
    foreach ($stmt as $row) {
        fputcsv($out, csv_row([
            $row['created_at'], $row['item_code'], $row['name'], $row['movement_type'], $row['quantity_change'],
            $row['quantity_before'], $row['quantity_after'], $row['recorded_by'], $row['reference_type'], $row['reference_id'], $row['note'],
        ]));
    }
} elseif ($type === 'loans') {
    $stmt = $pdo->prepare(
        "SELECT i.item_code, i.name, a.asset_tag, u.full_name AS borrower, tl.quantity, tl.borrowed_at, tl.due_date,
                tl.returned_at, r.full_name AS returned_by
         FROM tool_loans tl
         JOIN items i ON i.id = tl.item_id
         LEFT JOIN assets a ON a.id = tl.asset_id
         JOIN users u ON u.id = tl.borrower_id
         LEFT JOIN users r ON r.id = tl.returned_by
         WHERE tl.borrowed_at BETWEEN :f AND :t
         ORDER BY tl.borrowed_at ASC"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);

    fputcsv($out, ['Item Code', 'Item', 'Asset Tag', 'Borrower', 'Quantity', 'Borrowed At', 'Due Date', 'Returned At', 'Returned By']);
    foreach ($stmt as $row) {
        fputcsv($out, csv_row([
            $row['item_code'], $row['name'], $row['asset_tag'] ?: 'Untagged', $row['borrower'], $row['quantity'],
            $row['borrowed_at'], $row['due_date'], $row['returned_at'], $row['returned_by'],
        ]));
    }
} elseif ($type === 'fleet') {
    $stmt = $pdo->prepare(
        "SELECT t.plate_number, t.model, t.status,
                COUNT(DISTINCT r.id) AS req_count,
                COALESCE(SUM(ri.quantity_requested), 0) AS total_parts_qty,
                MAX(r.created_at) AS last_requisition_at
         FROM trucks t
         LEFT JOIN requisitions r
                ON r.truck_id = t.id
               AND r.created_at >= :f
               AND r.created_at <= :t
         LEFT JOIN requisition_items ri
                ON ri.requisition_id = r.id
         GROUP BY t.id, t.plate_number, t.model, t.status
         ORDER BY req_count DESC, t.plate_number ASC"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);

    fputcsv($out, ['Plate Number', 'Model', 'Status', 'Parts Requisitions in Period', 'Total Parts Qty Requested', 'Last Requisition Date']);
    foreach ($stmt as $row) {
        $st_info = truck_status_info($row['status']);
        fputcsv($out, csv_row([
            $row['plate_number'], $row['model'], $st_info['label'] ?? $row['status'],
            $row['req_count'], $row['total_parts_qty'], $row['last_requisition_at'] ?? 'None',
        ]));
    }
}

fclose($out);
