<?php
/**
 * DuaRTE — Requester History JSON API.
 * Returns complete requisition and tool loan history for a specific requester.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$user = current_user();
$is_elevated = in_array($user['role'], ['admin', 'inventory_staff'], true);

$pdo = get_db();
$id = (int)($_GET['id'] ?? 0);

if (!$is_elevated) {
    // Non-admin / non-inventory users can strictly only view their own history
    $id = (int)$user['id'];
}

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid requester ID.']);
    exit;
}

// 1. Fetch user profile
$stmt = $pdo->prepare(
    "SELECT id, employee_id, full_name, email, role, position, contact_number, status, created_at
     FROM users WHERE id = :id"
);
$stmt->execute(['id' => $id]);
$requester = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requester) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Requester not found.']);
    exit;
}

// 2. Fetch requisitions history
$stmt = $pdo->prepare(
    "SELECT r.id, r.truck_plate_snapshot, r.purpose, r.status, r.created_at, r.decided_at, r.released_at,
            d.full_name AS decided_by_name
     FROM requisitions r
     LEFT JOIN users d ON d.id = r.decided_by
     WHERE r.requester_id = :id
     ORDER BY r.created_at DESC"
);
$stmt->execute(['id' => $id]);
$requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch item details for these requisitions
$req_ids = array_column($requisitions, 'id');
$items_by_req = [];

if (!empty($req_ids)) {
    $placeholders = implode(',', array_fill(0, count($req_ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT ri.requisition_id, ri.item_name_snapshot, ri.quantity_requested, ri.quantity_released,
                i.item_code, i.unit
         FROM requisition_items ri
         LEFT JOIN items i ON i.id = ri.item_id
         WHERE ri.requisition_id IN ($placeholders)
         ORDER BY ri.id ASC"
    );
    $stmt->execute($req_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items_by_req[$row['requisition_id']][] = $row;
    }
}

foreach ($requisitions as &$req) {
    $req['items'] = $items_by_req[$req['id']] ?? [];
    $req['item_count'] = count($req['items']);
    $req['formatted_date'] = date('M j, Y g:ia', strtotime($req['created_at']));
    $req['status_label'] = ucfirst($req['status']);
    $req['status_class'] = requisition_status_class($req['status']);
    $req['truck_plate'] = $req['truck_plate_snapshot'] ?: 'None';
}
unset($req);

// 3. Fetch tool loans history
$stmt = $pdo->prepare(
    "SELECT tl.id, tl.requisition_id, tl.quantity, tl.borrowed_at, tl.due_date, tl.returned_at, tl.initial_condition,
            i.name AS item_name, i.item_code, a.asset_tag,
            ret.full_name AS returned_by_name
     FROM tool_loans tl
     JOIN items i ON i.id = tl.item_id
     LEFT JOIN assets a ON a.id = tl.asset_id
     LEFT JOIN users ret ON ret.id = tl.returned_by
     WHERE tl.borrower_id = :id
     ORDER BY tl.borrowed_at DESC"
);
$stmt->execute(['id' => $id]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = time();
$active_loans_count = 0;
$overdue_loans_count = 0;

foreach ($loans as &$loan) {
    $is_returned = !empty($loan['returned_at']);
    $is_overdue = !$is_returned && !empty($loan['due_date']) && strtotime($loan['due_date']) < $now;
    
    if (!$is_returned) {
        $active_loans_count++;
        if ($is_overdue) {
            $overdue_loans_count++;
        }
    }

    $loan['is_returned'] = $is_returned;
    $loan['is_overdue'] = $is_overdue;
    $loan['status_label'] = $is_returned ? 'Returned' : ($is_overdue ? 'Overdue' : 'Active');
    $loan['status_class'] = $is_returned ? 'badge-ok' : ($is_overdue ? 'badge-danger' : 'badge-warn');
    $loan['borrowed_fmt'] = date('M j, Y', strtotime($loan['borrowed_at']));
    $loan['due_fmt'] = !empty($loan['due_date']) ? date('M j, Y', strtotime($loan['due_date'])) : '—';
    $loan['returned_fmt'] = !empty($loan['returned_at']) ? date('M j, Y g:ia', strtotime($loan['returned_at'])) : null;
}
unset($loan);

// 4. Aggregate stats
$status_counts = ['released' => 0, 'approved' => 0, 'pending' => 0, 'declined' => 0, 'cancelled' => 0];
$total_items_requested = 0;
foreach ($requisitions as $r) {
    if (isset($status_counts[$r['status']])) {
        $status_counts[$r['status']]++;
    }
    foreach ($r['items'] as $it) {
        $total_items_requested += (int)$it['quantity_requested'];
    }
}

$stats = [
    'total_requisitions'   => count($requisitions),
    'released_count'       => $status_counts['released'],
    'approved_count'       => $status_counts['approved'],
    'pending_count'        => $status_counts['pending'],
    'declined_count'       => $status_counts['declined'],
    'cancelled_count'      => $status_counts['cancelled'],
    'total_items_requested'=> $total_items_requested,
    'total_loans'          => count($loans),
    'active_loans'         => $active_loans_count,
    'overdue_loans'        => $overdue_loans_count,
];

echo json_encode([
    'success'      => true,
    'requester'    => [
        'id'             => (int)$requester['id'],
        'full_name'      => $requester['full_name'],
        'role'           => $requester['role'],
        'role_label'     => role_label($requester['role']),
        'position'       => $requester['position'] ? position_label($requester['position']) : '',
        'employee_id'    => $requester['employee_id'] ?: 'N/A',
        'email'          => $requester['email'] ?: 'N/A',
        'contact_number' => $requester['contact_number'] ?: 'N/A',
        'status'         => $requester['status'],
        'initials'       => item_initials($requester['full_name']),
    ],
    'stats'        => $stats,
    'requisitions' => $requisitions,
    'loans'        => $loans,
]);
