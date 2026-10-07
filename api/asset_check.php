<?php
/**
 * DuaRTE — Mobile & Web API: Asset Health & Condition Inspection
 *
 * GET  ?tag=AST-... or ?id=123
 *      Returns full real-time asset health status, location, condition note,
 *      current holder, active loan details, and recent event timeline.
 *
 * POST { tag/asset_id, action: "report_damage" | "mark_repaired", condition_note: "..." }
 *      Allows staff/admin to flag an asset as damaged / under maintenance or
 *      mark it repaired directly from the mobile app or scanner.
 *
 * Restricted to: inventory_staff, admin
 */
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = get_db();

// ── 1. Authenticate caller ──
$api_user = require_api_auth($pdo);
$role = $api_user['role'] ?? '';
if (!in_array($role, ['inventory_staff', 'admin'], true)) {
    api_response(false, null, 'Access denied. Inventory Staff or Admin role required.', 403);
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https://' : 'http://';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

/**
 * Extracts and cleans an asset tag from bare tag string, query parameter, or full scanned URL.
 */
function normalize_asset_tag(?string $raw): string
{
    if ($raw === null) return '';
    $raw = trim($raw);
    if ($raw === '') return '';

    // If a full URL was scanned/pasted:
    if (preg_match('/[?&]tag=([^&]+)/i', $raw, $m)) {
        return strtoupper(trim(urldecode($m[1])));
    }
    if (preg_match('/[?&]code=([^&]+)/i', $raw, $m)) {
        $candidate = strtoupper(trim(urldecode($m[1])));
        if (preg_match('/^AST-[0-9A-F]{6,16}$/i', $candidate)) {
            return $candidate;
        }
    }

    // Direct tag match
    if (preg_match('/(AST-[0-9A-Fa-f]{6,16})/i', $raw, $m)) {
        return strtoupper($m[1]);
    }

    return strtoupper($raw);
}

/**
 * Loads full asset data by tag or ID.
 */
function fetch_asset_detail(PDO $pdo, string $tag, int $id, string $protocol, string $host): ?array
{
    $sql = "
        SELECT a.*,
               i.name AS item_name, i.item_code, i.brand, i.description,
               i.specification, i.unit, i.borrow_mode, i.quantity_on_hand AS item_stock,
               c.name AS category_name,
               s.name AS stall_name, s.stall_number, sl.layer_name,
               iv.variant_value, iv.variant_note,
               COALESCE(iv.image_filename, i.image_filename) AS image_filename,
               h.full_name AS holder_name, h.employee_id AS holder_employee_id, h.email AS holder_email,
               tr.plate_number AS assigned_truck_plate, tr.model AS assigned_truck_model, tr.status AS assigned_truck_status
        FROM assets a
        JOIN items i ON i.id = a.item_id
        LEFT JOIN categories c ON c.id = i.category_id
        LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
        LEFT JOIN stalls s ON s.id = sl.stall_id
        LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
        LEFT JOIN users h ON h.id = a.current_holder_id
        LEFT JOIN trucks tr ON tr.id = a.assigned_truck_id
    ";

    if ($id > 0) {
        $stmt = $pdo->prepare($sql . " WHERE a.id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
    } elseif ($tag !== '') {
        $stmt = $pdo->prepare($sql . " WHERE a.asset_tag = :tag LIMIT 1");
        $stmt->execute(['tag' => $tag]);
    } else {
        return null;
    }

    $asset = $stmt->fetch();
    if (!$asset) {
        return null;
    }

    $asset_id = (int)$asset['id'];

    // Image URL
    $img_url = !empty($asset['image_filename'])
        ? $protocol . $host . BASE_URL . '/uploads/items/' . $asset['image_filename']
        : null;

    // Location label
    $location = 'Warehouse Main';
    if (!empty($asset['stall_name'])) {
        $location = $asset['stall_name'] . (!empty($asset['layer_name']) ? ' (' . $asset['layer_name'] . ')' : '');
    }
    if (!empty($asset['location_note'])) {
        $location .= ' • ' . $asset['location_note'];
    }

    // Health status mapping for quick UI indicators
    $status = $asset['status'];
    $health_status = 'ok';
    $health_title = 'Maayos ang Kondisyon';
    $health_desc = !empty($asset['condition_note'])
        ? $asset['condition_note']
        : 'Handang gamitin sa operasyon o hiramin sa bodega.';

    if (!empty($asset['assigned_truck_id']) && $status === 'available') {
        $health_status = 'onboard_truck';
        $health_title = 'Gamit ng Truck (' . $asset['assigned_truck_plate'] . ')';
        $health_desc = 'Nakatalagang gamit sa truck ' . $asset['assigned_truck_plate'] . '. Hindi nag-eexpire at hindi kailangang i-extend.';
    } elseif ($status === 'under_maintenance') {
        $health_status = 'maintenance';
        $health_title = 'Kasalukuyang Kinukumpuni';
        $health_desc = !empty($asset['condition_note'])
            ? $asset['condition_note']
            : 'Kailangang suriin o kumpunihin bago magamit muli.';
    } elseif ($status === 'checked_out') {
        $health_status = 'checked_out';
        $health_title = 'Kasalukuyang Hiniram';
        $health_desc = !empty($asset['holder_name'])
            ? 'Hawak ni ' . $asset['holder_name']
            : 'Kasalukuyang ginagamit sa labas.';
    } elseif ($status === 'missing') {
        $health_status = 'missing';
        $health_title = 'Nawawala sa Imbentaryo';
        $health_desc = 'Hindi natagpuan sa pisikal na inspeksyon sa bodega.';
    } elseif ($status === 'retired') {
        $health_status = 'retired';
        $health_title = 'Inalis na sa Imbentaryo';
        $health_desc = 'Tuluyang inalis na sa aktibong listahan ng mga gamit.';
    }

    // Active loan details if checked out
    $active_loan = null;
    if ($status === 'checked_out') {
        $loan_stmt = $pdo->prepare("
            SELECT tl.*, u.full_name AS borrower_name, u.employee_id AS borrower_emp_id,
                   r.purpose AS req_purpose, r.truck_plate_snapshot
            FROM tool_loans tl
            JOIN users u ON u.id = tl.borrower_id
            LEFT JOIN requisitions r ON r.id = tl.requisition_id
            WHERE tl.asset_id = :aid AND tl.returned_at IS NULL
            ORDER BY tl.borrowed_at DESC
            LIMIT 1
        ");
        $loan_stmt->execute(['aid' => $asset_id]);
        $loan_row = $loan_stmt->fetch();
        if ($loan_row) {
            $is_overdue = !empty($loan_row['due_date']) && strtotime($loan_row['due_date']) < time();
            $active_loan = [
                'id'            => (int)$loan_row['id'],
                'borrower_name' => $loan_row['borrower_name'],
                'due_date'      => $loan_row['due_date'],
                'due_date_fmt'  => !empty($loan_row['due_date']) ? date('M j, Y', strtotime($loan_row['due_date'])) : 'N/A',
                'is_overdue'    => $is_overdue,
                'borrowed_at'   => !empty($loan_row['borrowed_at']) ? date('M j, Y g:i A', strtotime($loan_row['borrowed_at'])) : null,
                'purpose'       => $loan_row['req_purpose'] ?? 'General Duty',
            ];
        }
    }

    // Recent events timeline (up to 10)
    $events = [];
    try {
        $ev_stmt = $pdo->prepare("
            SELECT ae.*
            FROM asset_events ae
            WHERE ae.asset_id = :aid
            ORDER BY ae.created_at DESC
            LIMIT 10
        ");
        $ev_stmt->execute(['aid' => $asset_id]);
        while ($ev = $ev_stmt->fetch()) {
            $rawNote = $ev['note'] ?? '';
            // Sanitize any robotic/AI developer phrases into natural phrasing
            $cleanNote = preg_replace('/^Auto-synced from Catalog Management as lot of\s+(\d+)\s+/i', 'Nairehistro sa imbentaryo mula sa Katalogo (Batch: $1 ', $rawNote);
            $cleanNote = preg_replace('/^Auto-synced from Catalog Management:\s*/i', 'Nairehistro sa imbentaryo mula sa Katalogo: ', $cleanNote);
            $cleanNote = preg_replace('/^Auto-expired/i', 'Nag-expire', $cleanNote);
            $cleanNote = preg_replace('/^Auto-approved/i', 'Naaprubahan', $cleanNote);

            $events[] = [
                'id'         => (int)$ev['id'],
                'event_type' => $ev['event_type'],
                'actor_name' => $ev['actor_name_snapshot'] ?? 'Staff',
                'note'       => $cleanNote,
                'created_at' => date('M j, Y g:i A', strtotime($ev['created_at'])),
            ];
        }
    } catch (Exception $e) {
        error_log('[asset_check] events query error: ' . $e->getMessage());
    }

    return [
        'asset' => [
            'id'             => $asset_id,
            'asset_tag'      => $asset['asset_tag'],
            'status'         => $status,
            'health_status'  => $health_status,
            'health_title'   => $health_title,
            'health_desc'    => $health_desc,
            'condition_note' => $asset['condition_note'] ?? '',
            'serial_number'  => $asset['serial_number'] ?? '',
            'location_note'  => $asset['location_note'] ?? '',
            'location'       => $location,
            'quantity'       => (int)$asset['quantity'],
            'is_onboard_truck' => !empty($asset['assigned_truck_id']),
            'assigned_truck' => !empty($asset['assigned_truck_id']) ? [
                'id'           => (int)$asset['assigned_truck_id'],
                'plate_number' => $asset['assigned_truck_plate'],
                'model'        => $asset['assigned_truck_model'],
                'status'       => $asset['assigned_truck_status'],
            ] : null,
            'created_at'     => date('M j, Y', strtotime($asset['created_at'])),
            'updated_at'     => !empty($asset['updated_at']) ? date('M j, Y g:i A', strtotime($asset['updated_at'])) : null,
        ],
        'item' => [
            'id'            => (int)$asset['item_id'],
            'item_code'     => $asset['item_code'],
            'name'          => $asset['item_name'],
            'brand'         => $asset['brand'] ?? '',
            'category_name' => $asset['category_name'] ?? 'General',
            'unit'          => $asset['unit'],
            'borrow_mode'   => $asset['borrow_mode'],
            'image_url'     => $img_url,
            'variant_value' => $asset['variant_value'] ?? null,
            'variant_note'  => $asset['variant_note'] ?? null,
        ],
        'holder' => !empty($asset['holder_name']) ? [
            'name'        => $asset['holder_name'],
            'employee_id' => $asset['holder_employee_id'],
            'email'       => $asset['holder_email'] ?? '',
        ] : null,
        'active_loan' => $active_loan,
        'events'      => $events,
    ];
}

// ─────────────────────────────────────────────────────────────
// GET: Inspect Asset Health by Tag or ID
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tag = normalize_asset_tag($_GET['tag'] ?? $_GET['code'] ?? null);
    $id  = (int)($_GET['id'] ?? 0);

    if ($tag === '' && $id <= 0) {
        api_response(false, null, 'Provide an asset tag or ID to inspect.', 400);
    }

    $detail = fetch_asset_detail($pdo, $tag, $id, $protocol, $host);
    if (!$detail) {
        api_response(false, null, 'Asset not found with that QR tag.', 404);
    }

    api_response(true, $detail);
}

// ─────────────────────────────────────────────────────────────
// POST: Report Damage or Mark Repaired
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = !empty($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = trim($input['action'] ?? '');
    $tag    = normalize_asset_tag($input['tag'] ?? $input['asset_tag'] ?? null);
    $id     = (int)($input['asset_id'] ?? $input['id'] ?? 0);
    $note   = trim($input['condition_note'] ?? $input['note'] ?? '');

    if ($tag === '' && $id <= 0) {
        api_response(false, null, 'Missing asset tag or ID.', 400);
    }

    $detail = fetch_asset_detail($pdo, $tag, $id, $protocol, $host);
    if (!$detail) {
        api_response(false, null, 'Asset not found with that QR tag.', 404);
    }

    $asset_id = $detail['asset']['id'];
    $current_status = $detail['asset']['status'];

    if ($action === 'report_damage') {
        if ($note === '') {
            api_response(false, null, 'Describe the damage or condition issue.', 422);
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE assets SET status = 'under_maintenance', condition_note = :note WHERE id = :id");
            $stmt->execute(['note' => $note, 'id' => $asset_id]);

            record_asset_event($pdo, $asset_id, 'damage_reported', $api_user, $note);
            log_audit_event(
                $pdo, $api_user, 'asset_damage_report', 'asset', $asset_id,
                $api_user['full_name'] . ' flagged asset ' . $detail['asset']['asset_tag'] . ' for maintenance via mobile: ' . $note
            );
            $pdo->commit();

            $updated = fetch_asset_detail($pdo, '', $asset_id, $protocol, $host);
            if ($updated) {
                $updated['message'] = 'Asset flagged for maintenance.';
            }
            api_response(true, $updated);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[asset_check] damage report failed: ' . $e->getMessage());
            api_response(false, null, 'Failed to record damage report.', 500);
        }
    } elseif ($action === 'mark_repaired') {
        if ($current_status !== 'under_maintenance') {
            api_response(false, null, 'Only assets currently under maintenance can be marked repaired.', 422);
        }

        $repair_note = $note !== '' ? $note : 'Repaired and cleared for service via mobile.';

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE assets SET status = 'available', condition_note = :note WHERE id = :id");
            $stmt->execute(['note' => $repair_note, 'id' => $asset_id]);

            record_asset_event($pdo, $asset_id, 'maintenance_completed', $api_user, $repair_note);
            log_audit_event(
                $pdo, $api_user, 'asset_maintenance_complete', 'asset', $asset_id,
                $api_user['full_name'] . ' cleared asset ' . $detail['asset']['asset_tag'] . ' from maintenance via mobile.'
            );
            $pdo->commit();

            $updated = fetch_asset_detail($pdo, '', $asset_id, $protocol, $host);
            if ($updated) {
                $updated['message'] = 'Asset marked repaired and available.';
            }
            api_response(true, $updated);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[asset_check] repair update failed: ' . $e->getMessage());
            api_response(false, null, 'Failed to update repair status.', 500);
        }
    } else {
        api_response(false, null, 'Unknown action. Allowed: report_damage, mark_repaired.', 400);
    }
}

api_response(false, null, 'Method not allowed.', 405);
