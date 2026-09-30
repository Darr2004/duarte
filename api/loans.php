<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db();
$api_user = require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    check_overdue_loans($pdo);
    $user_id = (int)$api_user['id'];
    $role = $api_user['role'];

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $tab = $_GET['tab'] ?? '';
    if (in_array($role, ['inventory_staff', 'admin']) && $tab !== 'my') {
        $where = " WHERE tl.returned_at IS NULL ";
        if ($tab === 'extensions') {
            $where .= " AND tl.extension_status = 'pending' ";
        } elseif ($tab === 'overdue') {
            $where .= " AND tl.due_date < CURDATE() ";
        }
        $params = [];
    } else {
        $where = " WHERE tl.borrower_id = :uid ";
        $params = ['uid' => $user_id];
    }

    $stmt = $pdo->prepare("
        SELECT tl.*, i.name AS item_name, i.item_code, i.unit, i.image_filename,
               r.purpose AS requisition_purpose, r.truck_plate_snapshot,
               t.plate_number, u.full_name AS borrower_name, u.employee_id AS borrower_employee_id
        FROM tool_loans tl
        JOIN items i ON i.id = tl.item_id
        JOIN users u ON u.id = tl.borrower_id
        LEFT JOIN requisitions r ON r.id = tl.requisition_id
        LEFT JOIN trucks t ON t.id = r.truck_id
        $where
        ORDER BY (tl.extension_status = 'pending') DESC, (tl.returned_at IS NULL) DESC, tl.due_date ASC
    ");
    $stmt->execute($params);
    $loans = $stmt->fetchAll();

    foreach ($loans as &$l) {
        $l['status'] = loan_status($l['due_date'], $l['returned_at'], $l['extension_status'] ?? 'none');
        $l['days_left'] = $l['returned_at'] ? null : (int)ceil((strtotime($l['due_date']) - time()) / 86400);
        $l['image_url'] = !empty($l['image_filename']) ? ($protocol . $host . BASE_URL . '/uploads/items/' . $l['image_filename']) : null;
    }
    unset($l);

    api_response(true, $loans);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    if ($action === 'request_extension') {
        $loan_id = (int)($input['loan_id'] ?? 0);
        $days = max(1, min(14, (int)($input['days'] ?? $input['extension_days'] ?? 1)));
        $reason = trim($input['reason'] ?? $input['extension_reason'] ?? '');

        if ($loan_id <= 0) {
            api_response(false, null, 'Missing loan ID.', 400);
        }

        try {
            $res = request_tool_loan_extension($pdo, $loan_id, (int)$api_user['id'], $days, $reason);
            api_response(true, ['message' => 'Extension requested successfully']);
        } catch (Exception $e) {
            api_response(false, null, $e->getMessage(), 400);
        }
    }

    if ($action === 'request_trip_extension') {
        $req_id = (int)($input['requisition_id'] ?? 0);
        $days = max(1, min(14, (int)($input['days'] ?? $input['extension_days'] ?? 1)));
        $reason = trim($input['reason'] ?? $input['extension_reason'] ?? '');

        if ($req_id <= 0) {
            api_response(false, null, 'Missing requisition ID.', 400);
        }

        try {
            $res = request_trip_tools_extension($pdo, $req_id, (int)$api_user['id'], $days, $reason);
            $count = count($res);
            api_response(true, ['message' => "Trip extension submitted for $count tool(s)"]);
        } catch (Exception $e) {
            api_response(false, null, $e->getMessage(), 400);
        }
    }

    if ($action === 'decide_extension') {
        if (!in_array($api_user['role'], ['inventory_staff', 'admin'])) {
            api_response(false, null, 'Unauthorized. Only Inventory Staff and Admin can approve loan extensions.', 403);
        }

        $loan_id = (int)($input['loan_id'] ?? 0);
        $approve = !empty($input['approve']);
        $note = !empty($input['note']) ? trim($input['note']) : null;

        if ($loan_id <= 0) {
            api_response(false, null, 'Missing loan ID.', 400);
        }

        try {
            $res = decide_tool_loan_extension($pdo, $loan_id, $api_user, $approve, $note);
            api_response(true, ['message' => $approve ? 'Extension approved' : 'Extension declined']);
        } catch (Exception $e) {
            api_response(false, null, $e->getMessage(), 400);
        }
    }

    if ($action === 'return_tool') {
        if (!in_array($api_user['role'], ['inventory_staff', 'admin'])) {
            api_response(false, null, 'Unauthorized. Only Inventory Staff and Admin can record tool returns.', 403);
        }

        $loan_id = (int)($input['loan_id'] ?? 0);
        if ($loan_id <= 0) {
            api_response(false, null, 'Missing loan ID.', 400);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM tool_loans WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $loan_id]);
            $loan = $stmt->fetch();

            if (!$loan) {
                $pdo->rollBack();
                api_response(false, null, 'Tool loan not found.', 404);
            }
            if ($loan['returned_at'] !== null) {
                $pdo->rollBack();
                api_response(false, null, 'This tool has already been returned.', 400);
            }

            $res = return_tool_loan($pdo, $loan, $api_user);

            // Synchronize physical asset status so tag is marked available again
            if (!empty($loan['asset_id'])) {
                $damaged = !empty($input['damaged']);
                $condition_note = trim($input['condition_note'] ?? $input['note'] ?? '') ?: null;
                checkin_asset(
                    $pdo, (int)$loan['asset_id'], $api_user, $damaged, $condition_note,
                    'tool_loan', (int)$loan['id']
                );
            }

            $pdo->commit();

            notify_user(
                (int)$loan['borrower_id'],
                'Your borrowed ' . $res['name'] . ' (qty ' . $loan['quantity'] . ') has been checked back in by warehouse staff (' . $api_user['full_name'] . ').',
                BASE_URL . '/inventory/loans.php'
            );

            api_response(true, ['message' => 'Tool successfully checked back into warehouse stock.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            api_response(false, null, $e->getMessage(), 400);
        }
    }

    api_response(false, null, 'Invalid action.', 400);
}
