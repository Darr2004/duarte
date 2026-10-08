<?php
/**
 * DuaRTE — Truck Complaints API
 * Endpoint for Field Supervisors, Drivers, and Mechanics to submit,
 * track, and resolve vehicle complaints from the mobile app or web.
 *
 * Connected directly to MCDA Priority Scoring (Urgency / Sira ng Sasakyan).
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

$pdo = get_db();
$api_user = require_api_auth($pdo);

// ─────────────────────────────────────────────────────────────
// GET: Fetch complaints
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $truck_id = isset($_GET['truck_id']) ? (int)$_GET['truck_id'] : 0;
    $status_filter = trim($_GET['status'] ?? 'active'); // 'active', 'open', 'resolved', 'all'

    $sql = "
        SELECT 
            c.*,
            t.plate_number,
            t.model AS truck_model,
            t.brand AS truck_brand,
            t.status AS truck_status,
            u.username AS reported_by_username,
            u.full_name AS reported_by_fullname
        FROM truck_complaints c
        JOIN trucks t ON t.id = c.truck_id
        LEFT JOIN users u ON u.id = c.reported_by
        WHERE 1=1
    ";
    $params = [];

    if ($truck_id > 0) {
        $sql .= " AND c.truck_id = :truck_id";
        $params['truck_id'] = $truck_id;
    }

    if ($status_filter === 'active') {
        $sql .= " AND c.status != 'resolved'";
    } elseif (in_array($status_filter, ['open', 'in_progress', 'resolved'], true)) {
        $sql .= " AND c.status = :st";
        $params['st'] = $status_filter;
    }

    $sql .= " ORDER BY CASE c.urgency_level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, c.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_response(true, [
        'count'      => count($complaints),
        'complaints' => $complaints,
    ]);
}

// ─────────────────────────────────────────────────────────────
// POST: Submit or update complaints
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = api_json_input();
    $action = $input['action'] ?? 'report';

    if ($action === 'report' || $action === 'create') {
        $truck_id = (int)($input['truck_id'] ?? 0);
        $category = trim($input['complaint_category'] ?? $input['category'] ?? 'other');
        $text = trim($input['complaint_text'] ?? $input['text'] ?? '');
        $urgency = trim($input['urgency_level'] ?? $input['urgency'] ?? 'medium');
        $set_maintenance = !empty($input['set_maintenance']) || !empty($input['set_under_maintenance']);

        if ($truck_id <= 0) {
            api_response(false, null, 'Pumili ng sasakyan (Truck is required).', 400);
        }

        if ($text === '') {
            api_response(false, null, 'Ilagay ang detalye ng sira o reklamo (Complaint description is required).', 400);
        }

        // Validate truck
        $t_stmt = $pdo->prepare('SELECT id, plate_number, status FROM trucks WHERE id = :id');
        $t_stmt->execute(['id' => $truck_id]);
        $truck = $t_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$truck) {
            api_response(false, null, 'Hindi natagpuan ang sasakyan (Truck not found).', 404);
        }

        // Validate and normalize urgency
        if (!in_array($urgency, ['low', 'medium', 'high'], true)) {
            $urgency = 'medium';
        }

        // Dynamic evaluation against Admin's Urgency Matrix
        require_once __DIR__ . '/../includes/priority.php';
        $matrix = get_complaint_urgency_matrix($pdo);
        $classification = classify_complaint_urgency($text, $urgency, $matrix);

        if ($classification['tier'] === 'emergency') {
            $urgency = 'high';
        } elseif ($classification['tier'] === 'routine') {
            $urgency = 'low';
        } else {
            $urgency = 'medium';
        }

        // Valid categories matching physical checklist
        $valid_categories = ['electrical', 'engine', 'brakes_chassis', 'body_glass', 'other'];
        if (!in_array($category, $valid_categories, true)) {
            $category = 'other';
        }

        $ins = $pdo->prepare("
            INSERT INTO truck_complaints 
                (truck_id, reported_by, complaint_category, complaint_text, urgency_level, status, created_at)
            VALUES 
                (:truck_id, :reported_by, :category, :text, :urgency, 'open', NOW())
        ");
        $ins->execute([
            'truck_id'    => $truck_id,
            'reported_by' => $api_user['id'],
            'category'    => $category,
            'text'        => $text,
            'urgency'     => $urgency,
        ]);
        $complaint_id = (int)$pdo->lastInsertId();

        // Optionally set truck status to under_maintenance
        $updated_truck_status = null;
        if ($set_maintenance || $urgency === 'high') {
            if ($truck['status'] === 'available') {
                $u_stmt = $pdo->prepare("UPDATE trucks SET status = 'under_maintenance' WHERE id = :id");
                $u_stmt->execute(['id' => $truck_id]);
                $updated_truck_status = 'under_maintenance';
            }
        }

        log_audit_event(
            $pdo,
            $api_user,
            'truck_complaint_reported',
            'truck',
            $truck_id,
            "Iniulat ang sira sa {$truck['plate_number']} [{$urgency}]: {$text}"
        );

        api_response(true, [
            'complaint_id'         => $complaint_id,
            'truck_id'             => $truck_id,
            'plate_number'         => $truck['plate_number'],
            'urgency_level'        => $urgency,
            'updated_truck_status' => $updated_truck_status,
            'message'              => "Matagumpay na naitala ang reklamo para sa {$truck['plate_number']}.",
        ]);
    }

    if ($action === 'resolve') {
        $complaint_id = (int)($input['complaint_id'] ?? 0);
        $notes = trim($input['resolution_notes'] ?? $input['notes'] ?? '');

        if ($complaint_id <= 0) {
            api_response(false, null, 'Invalid complaint ID.', 400);
        }

        $stmt = $pdo->prepare('SELECT c.*, t.plate_number FROM truck_complaints c JOIN trucks t ON t.id = c.truck_id WHERE c.id = :id');
        $stmt->execute(['id' => $complaint_id]);
        $complaint = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            api_response(false, null, 'Complaint record not found.', 404);
        }

        $upd = $pdo->prepare("
            UPDATE truck_complaints 
            SET status = 'resolved', resolution_notes = :notes, resolved_at = NOW() 
            WHERE id = :id
        ");
        $upd->execute([
            'notes' => $notes ?: 'Resolved via Mobile App',
            'id'    => $complaint_id,
        ]);

        log_audit_event(
            $pdo,
            $api_user,
            'truck_complaint_resolved',
            'truck',
            (int)$complaint['truck_id'],
            "Naresolba ang reklamo sa {$complaint['plate_number']}: {$complaint['complaint_text']}"
        );

        api_response(true, [
            'complaint_id' => $complaint_id,
            'message'      => "Naresolba na ang reklamo para sa {$complaint['plate_number']}.",
        ]);
    }

    if ($action === 'update_status') {
        $complaint_id = (int)($input['complaint_id'] ?? 0);
        $status = trim($input['status'] ?? 'open');
        $valid = ['open', 'in_progress', 'resolved'];

        if (!in_array($status, $valid, true)) {
            api_response(false, null, 'Invalid status.', 400);
        }

        if ($status === 'resolved') {
            $notes = trim($input['resolution_notes'] ?? '');
            $upd = $pdo->prepare("UPDATE truck_complaints SET status = 'resolved', resolution_notes = :notes, resolved_at = NOW() WHERE id = :id");
            $upd->execute(['notes' => $notes ?: 'Resolved', 'id' => $complaint_id]);
        } else {
            $upd = $pdo->prepare("UPDATE truck_complaints SET status = :st WHERE id = :id");
            $upd->execute(['st' => $status, 'id' => $complaint_id]);
        }

        api_response(true, [
            'complaint_id' => $complaint_id,
            'status'       => $status,
            'message'      => "Status updated to $status.",
        ]);
    }

    if ($action === 'update_urgency') {
        $complaint_id = (int)($input['complaint_id'] ?? 0);
        $urgency_level = trim($input['urgency_level'] ?? 'medium');
        $valid = ['low', 'medium', 'high'];

        if (!in_array($urgency_level, $valid, true)) {
            api_response(false, null, 'Invalid urgency level.', 400);
        }

        $stmt = $pdo->prepare('SELECT c.*, t.plate_number FROM truck_complaints c JOIN trucks t ON t.id = c.truck_id WHERE c.id = :id');
        $stmt->execute(['id' => $complaint_id]);
        $complaint = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            api_response(false, null, 'Complaint record not found.', 404);
        }

        $upd = $pdo->prepare("UPDATE truck_complaints SET urgency_level = :lvl WHERE id = :id");
        $upd->execute(['lvl' => $urgency_level, 'id' => $complaint_id]);

        log_audit_event(
            $pdo,
            $api_user,
            'truck_complaint_urgency_override',
            'truck',
            (int)$complaint['truck_id'],
            "Binago ang urgency ng reklamo sa {$complaint['plate_number']} patungong [{$urgency_level}]: {$complaint['complaint_text']}"
        );

        api_response(true, [
            'complaint_id'  => $complaint_id,
            'urgency_level' => $urgency_level,
            'message'       => "Na-update ang urgency level sa $urgency_level.",
        ]);
    }

    api_response(false, null, "Unknown action '$action'.", 400);
}

api_response(false, null, 'Method not allowed.', 405);
