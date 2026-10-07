<?php
/**
 * DuaRTE — Fleet Trucks API
 * Dedicated API endpoint for Field Supervisors, Admins, and Warehouse Staff
 * to monitor and update company fleet vehicles.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/assets.php';

$pdo = get_db();
$api_user = require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 1. Fetch metrics
    $counts = [
        'total'             => 0,
        'available'         => 0,
        'on_trip'           => 0,
        'under_maintenance' => 0,
    ];

    $c_stmt = $pdo->query("SELECT status, COUNT(*) c FROM trucks GROUP BY status");
    foreach ($c_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int)$row['c'];
        }
        $counts['total'] += (int)$row['c'];
    }

    // 2. Fetch all trucks with active repair requisitions count
    $stmt = $pdo->query("
        SELECT t.*,
            (SELECT COUNT(*) 
             FROM requisitions r 
             WHERE r.truck_id = t.id 
               AND r.is_maintenance_request = 1 
               AND r.status IN ('pending', 'approved')
            ) AS active_repairs,
            (SELECT GROUP_CONCAT(r.id ORDER BY r.id ASC SEPARATOR ',') 
             FROM requisitions r 
             WHERE r.truck_id = t.id 
               AND r.is_maintenance_request = 1 
               AND r.status IN ('pending', 'approved')
            ) AS active_repair_ids,
            (SELECT COUNT(*) 
             FROM requisitions r 
             WHERE r.truck_id = t.id 
               AND (r.is_maintenance_request = 0 OR r.is_maintenance_request IS NULL)
               AND r.status IN ('pending', 'approved')
            ) AS active_trip_requisitions,
            (SELECT COUNT(*) 
             FROM requisitions r 
             JOIN tool_loans tl ON tl.requisition_id = r.id 
             WHERE r.truck_id = t.id 
               AND r.status = 'released' 
               AND tl.returned_at IS NULL
            ) AS unreturned_loans
        FROM trucks t
        ORDER BY 
            CASE t.status 
                WHEN 'under_maintenance' THEN 1 
                WHEN 'on_trip' THEN 2 
                WHEN 'available' THEN 3 
                ELSE 4 
            END,
            t.plate_number ASC
    ");
    $trucks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($trucks as &$t) {
        $t['onboard_tools'] = get_truck_onboard_assets($pdo, (int)$t['id']);
    }
    unset($t);

    api_response(true, [
        'metrics' => $counts,
        'trucks'  => $trucks,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = api_json_input();
    $action = $input['action'] ?? 'update_status';
    $can_manage = in_array($api_user['role'], ['admin', 'field_supervisor'], true);

    if (!$can_manage) {
        api_response(false, null, 'Permission denied. Only Field Supervisors and Admins can manage fleet trucks.', 403);
    }

    if ($action === 'update_status') {
        $truck_id = (int)($input['truck_id'] ?? 0);
        $new_status = trim($input['status'] ?? '');
        $valid_statuses = ['available', 'on_trip', 'under_maintenance'];

        if ($truck_id <= 0) {
            api_response(false, null, 'Invalid truck ID.');
        }

        if (!in_array($new_status, $valid_statuses, true)) {
            api_response(false, null, 'Invalid status. Must be available, on_trip, or under_maintenance.');
        }

        $t_stmt = $pdo->prepare('SELECT * FROM trucks WHERE id = :id');
        $t_stmt->execute(['id' => $truck_id]);
        $truck = $t_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$truck) {
            api_response(false, null, 'Truck not found.');
        }

        if ($new_status === 'under_maintenance') {
            // Guard: Check if truck is currently out on active trip with unreturned tools
            $active_trips = $pdo->prepare("
                SELECT COUNT(*) FROM requisitions r
                JOIN tool_loans tl ON tl.requisition_id = r.id
                WHERE r.truck_id = :tid AND r.status = 'released' AND tl.returned_at IS NULL
            ");
            $active_trips->execute(['tid' => $truck_id]);
            if ((int)$active_trips->fetchColumn() > 0) {
                api_response(false, null, "Truck {$truck['plate_number']} has active unreturned tools. Return borrowed tools first before placing vehicle under maintenance.");
            }
        }

        $upd = $pdo->prepare('UPDATE trucks SET status = :st WHERE id = :id');
        $upd->execute(['st' => $new_status, 'id' => $truck_id]);

        $st_labels = [
            'available' => 'Available',
            'on_trip' => 'On Trip',
            'under_maintenance' => 'Under Maintenance'
        ];
        $label = $st_labels[$new_status] ?? $new_status;

        log_audit_event(
            $pdo,
            $api_user,
            'truck_status_update',
            'truck',
            $truck_id,
            "Updated truck {$truck['plate_number']} status to {$label} via Mobile App."
        );

        api_response(true, [
            'message' => "Truck {$truck['plate_number']} status updated to {$label}.",
            'status'  => $new_status,
        ]);
    }

    if ($action === 'add_truck') {
        $plate = sanitize_license_plate($input['plate_number'] ?? '');
        $model = trim($input['model'] ?? '');
        $status = $input['status'] ?? 'available';
        $notes = trim($input['notes'] ?? '');

        if ($plate === '') {
            api_response(false, null, 'Plate number is required.');
        }
        if (!validate_license_plate($plate)) {
            api_response(false, null, 'Invalid plate number format (e.g. DUA-1102, ABC 123).');
        }
        if ($model === '') {
            api_response(false, null, 'Truck model/type is required.');
        }

        $chk = $pdo->prepare('SELECT id FROM trucks WHERE plate_number = :p');
        $chk->execute(['p' => $plate]);
        if ($chk->fetch()) {
            api_response(false, null, "Truck with plate '$plate' already exists.");
        }

        $ins = $pdo->prepare('INSERT INTO trucks (plate_number, model, status, notes) VALUES (:p, :m, :s, :n)');
        $ins->execute([
            'p' => $plate,
            'm' => $model,
            's' => $status,
            'n' => $notes ?: null,
        ]);
        $new_id = (int)$pdo->lastInsertId();

        log_audit_event($pdo, $api_user, 'truck_add', 'truck', $new_id, "Added new truck {$plate} ({$model}) via Mobile App.");

        api_response(true, [
            'message'  => "Truck {$plate} added successfully.",
            'truck_id' => $new_id,
        ]);
    }

    if ($action === 'assign_onboard_tool') {
        $truck_id = (int)($input['truck_id'] ?? 0);
        $asset_id = (int)($input['asset_id'] ?? 0);
        $tag = trim($input['asset_tag'] ?? '');

        if ($asset_id <= 0 && $tag !== '') {
            $ast_stmt = $pdo->prepare("SELECT id FROM assets WHERE asset_tag = :tag");
            $ast_stmt->execute(['tag' => $tag]);
            $asset_id = (int)$ast_stmt->fetchColumn();
        }

        if ($truck_id <= 0 || $asset_id <= 0) {
            api_response(false, null, 'Invalid truck ID or asset tool ID/tag.');
        }

        try {
            assign_asset_to_truck($pdo, $asset_id, $truck_id, $api_user, $input['note'] ?? null);
            api_response(true, ['message' => 'Gamit na-assign na bilang permanenteng onboard kit ng sasakyan.']);
        } catch (Exception $e) {
            api_response(false, null, $e->getMessage(), 400);
        }
    }

    if ($action === 'unassign_onboard_tool') {
        $asset_id = (int)($input['asset_id'] ?? 0);
        if ($asset_id <= 0) {
            api_response(false, null, 'Invalid asset tool ID.');
        }

        try {
            unassign_asset_from_truck($pdo, $asset_id, $api_user, $input['note'] ?? null);
            api_response(true, ['message' => 'Gamit naibalik na sa bodega mula sa truck kit.']);
        } catch (Exception $e) {
            api_response(false, null, $e->getMessage(), 400);
        }
    }

    api_response(false, null, 'Unknown action.');
}
