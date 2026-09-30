<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/item_requests.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db();
$api_user = require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = (int)$api_user['id'];

    $stmt = $pdo->prepare("
        SELECT ir.*, d.full_name AS decided_by_name, i.unit AS catalog_unit
        FROM item_requests ir
        LEFT JOIN users d ON d.id = ir.decided_by
        LEFT JOIN items i ON i.id = ir.item_id
        WHERE ir.requester_id = :uid
        ORDER BY ir.created_at DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['resolved'] = item_request_resolve($r, $r['catalog_unit']);
    }
    unset($r);

    api_response(true, $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = api_json_input();
    $user_id = (int)$api_user['id'];
    $item_id = !empty($input['item_id']) ? (int)$input['item_id'] : null;
    $item_name = trim($input['item_name'] ?? '');
    $quantity = max(1, (int)($input['quantity'] ?? 1));
    $reason = trim($input['reason'] ?? '');
    $unit = trim($input['unit'] ?? 'pc');
    $pieces_per_unit = isset($input['pieces_per_unit']) && (int)$input['pieces_per_unit'] > 0
        ? (int)$input['pieces_per_unit']
        : null;

    if ($item_name === '') {
        api_response(false, null, 'Item name is required.', 400);
    }

    // Free-text requests encode unit and container pieces metadata into the reason
    $stored_reason = $reason;
    if (!$item_id) {
        $stored_reason = item_request_encode_reason($reason, $unit, $pieces_per_unit);
    }

    $stmt = $pdo->prepare("
        INSERT INTO item_requests (requester_id, item_id, item_name, quantity, reason, status)
        VALUES (:uid, :item_id, :item_name, :qty, :reason, 'pending')
    ");
    $stmt->execute([
        'uid'       => $user_id,
        'item_id'   => $item_id,
        'item_name' => $item_name,
        'qty'       => $quantity,
        'reason'    => $stored_reason
    ]);

    $req_id = (int)$pdo->lastInsertId();

    notify_role(
        'inventory_staff',
        $api_user['full_name'] . ' requested "' . $item_name . '" (qty ' . $quantity . ' ' . $unit . ').',
        BASE_URL . '/inventory/item_requests.php'
    );

    api_response(true, [
        'id'      => $req_id,
        'message' => 'Purchase Request (PO) submitted successfully.'
    ]);
}

api_response(false, null, 'Method Not Allowed', 405);
