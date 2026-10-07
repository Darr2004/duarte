<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/stock_alerts.php';

$pdo = get_db();
$api_user = require_api_auth($pdo); // resolves the caller from the Bearer token — never from a posted user_id/role
expire_stale_requisitions($pdo); // Sweep and auto-cancel unpicked requests older than today

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = (int)$api_user['id'];
    $role = $api_user['role'];

    // Disambiguate QR lookup tokens from user authentication tokens:
    // 'qr_token' and 'lookup' are dedicated parameters for requisition lookup.
    // 'token' may be passed by mobile clients as the auth session token or by scanners as the QR token.
    $token_lookup = trim($_GET['qr_token'] ?? $_GET['lookup'] ?? '');
    if ($token_lookup === '' && !empty($_GET['token'])) {
        $candidate = trim($_GET['token']);
        // If auth_token parameter is also supplied (e.g. mobile scanner lookup)
        if (!empty($_GET['auth_token'])) {
            $token_lookup = $candidate;
        } elseif (empty($_GET['tab'])) {
            // Check if this token matches caller's active auth token or exists in mobile_tokens
            $caller_token = $api_user['api_token'] ?? '';
            if ($caller_token !== '' && hash_equals($caller_token, $candidate)) {
                // It is the caller's auth token, not a requisition QR lookup
                $token_lookup = '';
            } else {
                $mt_stmt = $pdo->prepare('SELECT id FROM mobile_tokens WHERE user_id = :uid AND token = :t LIMIT 1');
                $mt_stmt->execute(['uid' => $user_id, 't' => $candidate]);
                if (!$mt_stmt->fetch()) {
                    // Not a mobile session token -> treat as QR token lookup
                    $token_lookup = $candidate;
                }
            }
        }
    }

    if ($token_lookup !== '') {
        if (strpos($token_lookup, '#') !== false) {
            $token_lookup = explode('#', $token_lookup)[0];
        }
        if (preg_match('/[?&]token=([^&]+)/', $token_lookup, $m)) {
            $token_lookup = urldecode($m[1]);
        }
        $where = " WHERE (r.qr_token = :t OR r.id = :tid) ";
        $params = ['t' => $token_lookup, 'tid' => is_numeric($token_lookup) ? (int)$token_lookup : 0];
        // BOLA Protection: driver_helper can only look up their own requisition tokens
        if ($role === 'driver_helper') {
            $where .= " AND r.requester_id = :uid ";
            $params['uid'] = $user_id;
        }
    } elseif (!empty($_GET['id'])) {
        $where = " WHERE r.id = :rid ";
        $params = ['rid' => (int)$_GET['id']];
        // BOLA Protection: driver_helper can only view their own requisition ID
        if ($role === 'driver_helper') {
            $where .= " AND r.requester_id = :uid ";
            $params['uid'] = $user_id;
        }
    } else {
        $tab = $_GET['tab'] ?? '';
        if ($tab === '') {
            $tab = ($role === 'driver_helper') ? 'my' : 'all';
        }

        if ($role === 'driver_helper') {
            // Strict IDOR Scope: Personnel can ONLY ever see their own requisitions regardless of tab
            $where = " WHERE r.requester_id = :uid ";
            $params = ['uid' => $user_id];
            if ($tab === 'pending') {
                $where .= " AND r.status = 'pending' ";
            } elseif ($tab === 'approved') {
                $where .= " AND r.status = 'approved' ";
            }
        } else {
            // Privileged operations roles (field_supervisor, inventory_staff, admin)
            if ($tab === 'pending') {
                $where = " WHERE r.status = 'pending' ";
                $params = [];
            } elseif ($tab === 'approved' || $tab === 'awaiting_release') {
                $where = " WHERE r.status = 'approved' ";
                $params = [];
            } elseif ($tab === 'all') {
                $where = " ";
                $params = [];
            } elseif ($tab === 'my') {
                $where = " WHERE r.requester_id = :uid ";
                $params = ['uid' => $user_id];
            } else {
                $where = " ";
                $params = [];
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT r.*, t.plate_number, t.model AS truck_model, t.status AS truck_status,
               u.full_name AS requester_name, u.employee_id, u.position AS requester_position,
               d.full_name AS decided_by_name, rel.full_name AS released_by_name
        FROM requisitions r
        LEFT JOIN trucks t ON t.id = r.truck_id
        JOIN users u ON u.id = r.requester_id
        LEFT JOIN users d ON d.id = r.decided_by
        LEFT JOIN users rel ON rel.id = r.released_by
        $where
        ORDER BY r.created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    if ($requests) {
        // Compute dynamic multi-criteria priority scores (MCDA)
        $pending_only = array_filter($requests, fn($r) => $r['status'] === 'pending');
        $scored = $pending_only ? score_pending_requisitions($pdo, array_values($pending_only)) : [];
        $scored_by_id = [];
        foreach ($scored as $s) {
            $scored_by_id[$s['id']] = $s;
        }

        $ids = array_column($requests, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $istmt = $pdo->prepare("
            SELECT ri.*, COALESCE(i.name, ri.item_name_snapshot) AS item_name, 
                   COALESCE(i.unit, ri.unit_snapshot) AS unit, i.item_code, i.image_filename
            FROM requisition_items ri
            LEFT JOIN items i ON i.id = ri.item_id
            WHERE ri.requisition_id IN ($ph)
            ORDER BY ri.id ASC
        ");
        $istmt->execute($ids);
        $items_by_req = [];
        foreach ($istmt->fetchAll() as $it) {
            $img_url = null;
            if (!empty($it['image_filename'])) {
                $img_url = $protocol . $host . BASE_URL . '/uploads/items/' . $it['image_filename'];
            }
            $it['image_url'] = $img_url;
            $items_by_req[$it['requisition_id']][] = $it;
        }

        $is_privileged_role = in_array($role, ['field_supervisor', 'admin', 'inventory_staff'], true);

        foreach ($requests as &$req) {
            if ($is_privileged_role) {
                $sc = $scored_by_id[$req['id']] ?? null;
                $req['priority_score'] = ($sc !== null && isset($sc['priority_score']))
                    ? number_format((float)$sc['priority_score'], 2)
                    : '0.00';
                $req['priority_stock'] = (int)($sc['priority_stock'] ?? 0);
                $req['priority_demand'] = (int)($sc['priority_demand'] ?? 0);
                $req['priority_trust'] = (int)($sc['priority_trust'] ?? 0);
                $req['mcda_details'] = $sc ? [
                    'score'       => (float)($sc['priority_score'] ?? 0),
                    'stock'       => (int)($sc['priority_stock'] ?? 0),
                    'demand'      => (int)($sc['priority_demand'] ?? 0),
                    'trust'       => (int)($sc['priority_trust'] ?? 0),
                    'weights'     => $sc['mcda_weights'] ?? null,
                ] : null;
            } else {
                // Strictly hidden from personnel / drivers: internal management decision tool only
                $req['priority_score'] = null;
                $req['priority_stock'] = null;
                $req['priority_demand'] = null;
                $req['priority_trust'] = null;
                $req['mcda_details'] = null;
            }
            $req['items'] = $items_by_req[$req['id']] ?? [];
            if (in_array($req['status'], ['approved', 'released'], true) && !empty($req['qr_token'])) {
                $req['pickup_qr_url'] = BASE_URL . '/inventory/verify.php?token=' . urlencode($req['qr_token']);
            } else {
                $req['qr_token'] = null;
                $req['pickup_qr_url'] = null;
            }
        }
        unset($req);

        // Sort pending requests by priority: urgent first, then highest score descending
        if ($tab === 'pending') {
            usort($requests, function ($a, $b) {
                $a_urgent = !empty($a['manual_urgent']);
                $b_urgent = !empty($b['manual_urgent']);
                if ($a_urgent !== $b_urgent) {
                    return $a_urgent ? -1 : 1;
                }
                $sa = (float)($a['priority_score'] ?? 0);
                $sb = (float)($b['priority_score'] ?? 0);
                if ($sb != $sa) {
                    return ($sb > $sa) ? 1 : -1;
                }
                return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
            });
        }
    }

    api_response(true, $requests);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = api_json_input();
    $action = $input['action'] ?? 'create';
    $user = $api_user;
    $user_id = (int)$user['id'];

    // Action: Cancel Requisition (Owner only, pending status)
    if ($action === 'cancel') {
        $req_id = (int)($input['requisition_id'] ?? 0);
        if ($req_id <= 0) {
            api_response(false, null, 'Requisition ID is required.', 400);
        }

        $pdo->beginTransaction();
        try {
            $r_stmt = $pdo->prepare("SELECT id, requester_id, status FROM requisitions WHERE id = :id FOR UPDATE");
            $r_stmt->execute(['id' => $req_id]);
            $req = $r_stmt->fetch();

            if (!$req) {
                $pdo->rollBack();
                api_response(false, null, 'Requisition not found.', 404);
            }
            if ((int)$req['requester_id'] !== $user_id) {
                $pdo->rollBack();
                api_response(false, null, 'Only the requester can cancel this request.', 403);
            }
            if ($req['status'] !== 'pending') {
                $pdo->rollBack();
                api_response(false, null, 'Only pending requisitions can be cancelled (current status: ' . $req['status'] . ').', 400);
            }

            $upd = $pdo->prepare("UPDATE requisitions SET status = 'cancelled' WHERE id = :id AND status = 'pending'");
            $upd->execute(['id' => $req_id]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                api_response(false, null, 'Concurrency conflict: Requisition status already changed.', 409);
            }

            $pdo->commit();

            log_audit_event($pdo, $user, 'requisition_cancel', 'requisition', $req_id,
                $user['full_name'] . ' cancelled requisition #' . $req_id . ' via Mobile App.');

            require_once __DIR__ . '/../includes/sms.php';
            notify_user_sms((int)$user['id'], "DuaRTE: Your Requisition #{$req_id} has been CANCELLED successfully.");
            notify_role_sms('field_supervisor', "DuaRTE Alert: Requisition #{$req_id} was CANCELLED by {$user['full_name']}.");

            api_response(true, ['message' => 'Requisition successfully cancelled.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            api_response(false, null, 'Cancellation failed: ' . $e->getMessage(), 500);
        }
    }

    // Action: Supervisor Decision (Approve or Reject)
    if ($action === 'decide') {
        if ($user['role'] !== 'field_supervisor' && $user['role'] !== 'admin') {
            api_response(false, null, 'Only Field Supervisors or Admins can approve or decline requisitions.', 403);
        }

        $req_id = (int)($input['requisition_id'] ?? 0);
        $decision = ($input['decision'] ?? '') === 'approved' ? 'approved' : 'declined';
        $note = trim($input['decision_note'] ?? '');

        $pdo->beginTransaction();
        try {
            $r_stmt = $pdo->prepare("SELECT id, requester_id, status, purpose, truck_id, is_maintenance_request FROM requisitions WHERE id = :id FOR UPDATE");
            $r_stmt->execute(['id' => $req_id]);
            $req = $r_stmt->fetch();

            if (!$req) {
                $pdo->rollBack();
                api_response(false, null, 'Requisition not found.', 404);
            }
            if ((int)$req['requester_id'] === $user_id) {
                $pdo->rollBack();
                api_response(false, null, 'Field Supervisors cannot approve their own requisitions (SoD policy).', 403);
            }
            if ($req['status'] !== 'pending') {
                $pdo->rollBack();
                api_response(false, null, 'This requisition is no longer in pending status (current: ' . $req['status'] . ').', 400);
            }

            $qr_token = null;
            if ($decision === 'approved') {
                // Concurrency shield: lock touched catalog items under FOR UPDATE
                $item_ids_stmt = $pdo->prepare(
                    'SELECT DISTINCT item_id FROM requisition_items WHERE requisition_id = :id AND item_id IS NOT NULL'
                );
                $item_ids_stmt->execute(['id' => $req_id]);
                $item_ids = array_column($item_ids_stmt->fetchAll(), 'item_id');
                if ($item_ids) {
                    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
                    $pdo->prepare("SELECT id FROM items WHERE id IN ($placeholders) FOR UPDATE")
                        ->execute($item_ids);
                }

                $shortfalls = requisition_stock_shortfalls($pdo, $req_id);
                if ($shortfalls) {
                    $pdo->rollBack();
                    api_response(false, null, 'Cannot approve — not enough stock left for: ' . implode(', ', $shortfalls) . '. Another request has reserved this stock.', 400);
                }

                if (!empty($req['truck_id']) && empty($req['is_maintenance_request'])) {
                    $t_chk = $pdo->prepare("SELECT plate_number, status FROM trucks WHERE id = :tid");
                    $t_chk->execute(['tid' => $req['truck_id']]);
                    $trk = $t_chk->fetch();
                    if ($trk && $trk['status'] === 'under_maintenance') {
                        $pdo->rollBack();
                        api_response(false, null, 'Cannot approve — Assigned Truck ' . $trk['plate_number'] . ' is currently under maintenance and out of service.', 400);
                    }
                }

                $qr_token = generate_unique_qr_token($pdo);
            }

            $upd = $pdo->prepare("
                UPDATE requisitions 
                SET status = :status, decided_by = :uid, decided_at = NOW(), decision_note = :note, qr_token = :qr_token 
                WHERE id = :id AND status = 'pending'
            ");
            $upd->execute([
                'status'   => $decision,
                'uid'      => $user_id,
                'note'     => $note ?: ($decision === 'approved' ? 'Approved via Mobile' : 'Declined via Mobile'),
                'qr_token' => $qr_token,
                'id'       => $req_id,
            ]);

            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                api_response(false, null, 'Concurrency conflict: Requisition was already decided by another user.', 409);
            }

            $pdo->commit();

            log_audit_event($pdo, $user, 'requisition_decide', 'requisition', $req_id,
                $user['full_name'] . ' ' . $decision . ' requisition #' . $req_id . ' via Mobile App.');

            require_once __DIR__ . '/../includes/sms.php';
            $sms_msg = $decision === 'approved'
                ? 'DuaRTE: Requisition #' . $req_id . ' was APPROVED by ' . $user['full_name'] . '. You may now pick up your items at the warehouse.'
                : 'DuaRTE: Requisition #' . $req_id . ' was DECLINED by ' . $user['full_name'] . '.' . ($note !== '' ? ' Reason: ' . $note : '');
            notify_user_sms((int)$req['requester_id'], $sms_msg);

            api_response(true, [
                'requisition_id' => $req_id,
                'status'         => $decision,
                'qr_token'       => $qr_token,
                'message'        => 'Requisition successfully ' . $decision . '.'
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            api_response(false, null, 'Decision failed: ' . $e->getMessage(), 500);
        }
    }

    // Action: Warehouse Release (Inventory Staff & Admin)
    if ($action === 'release' || $action === 'confirm_release') {
        if (!in_array($user['role'], ['inventory_staff', 'admin'])) {
            api_response(false, null, 'Only Inventory Staff or Admins can release items.', 403);
        }

        $req_id = (int)($input['requisition_id'] ?? 0);
        $qr_token = trim($input['qr_token'] ?? $input['token'] ?? '');
        if ($req_id <= 0 && $qr_token === '') {
            api_response(false, null, 'Requisition ID or QR Token is required.', 400);
        }

        if ($req_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = :id");
            $stmt->execute(['id' => $req_id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE qr_token = :t");
            $stmt->execute(['t' => $qr_token]);
        }
        $target = $stmt->fetch();

        if (!$target) {
            api_response(false, null, 'Requisition not found.', 404);
        }
        if ($target['status'] !== 'approved') {
            api_response(false, null, 'Requisition is not awaiting release (current status: ' . $target['status'] . ').', 400);
        }
        if ((int)$target['requester_id'] === $user_id) {
            api_response(false, null, 'Segregation of Duties: You cannot release your own requisition.', 403);
        }
        if ($target['decided_by'] !== null && (int)$target['decided_by'] === $user_id && $user['role'] !== 'admin') {
            api_response(false, null, 'Segregation of Duties: You cannot release a requisition you approved.', 403);
        }

        $lines_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id');
        $lines_stmt->execute(['id' => $target['id']]);
        $lines = $lines_stmt->fetchAll();

        $pdo->beginTransaction();
        try {
            // Concurrency lock: lock the requisition row FOR UPDATE to eliminate double-releases
            $lock_stmt = $pdo->prepare('SELECT id, status, requester_id, decided_by FROM requisitions WHERE id = :id FOR UPDATE');
            $lock_stmt->execute(['id' => $target['id']]);
            $locked_target = $lock_stmt->fetch();

            if (!$locked_target || $locked_target['status'] !== 'approved') {
                $pdo->rollBack();
                api_response(false, null, 'This requisition is no longer awaiting release (current status: ' . ($locked_target['status'] ?? 'deleted') . ').', 409);
            }

            // Two-Way Driver Handshake Protocol (Non-Repudiation Custody Transfer)
            $live_handshake = trim($input['live_handshake_token'] ?? $input['handshake_token'] ?? '');
            $driver_pin = trim($input['driver_pin'] ?? $input['pin'] ?? '');
            $override_reason = trim($input['override_reason'] ?? $input['driver_override_reason'] ?? '');

            $req_user_stmt = $pdo->prepare('SELECT id, full_name, role, pin_hash, pin_failed_attempts, pin_locked_until FROM users WHERE id = :uid');
            $req_user_stmt->execute(['uid' => $target['requester_id']]);
            $requester_user = $req_user_stmt->fetch();

            $handshake_verified = false;
            $handshake_method = null;
            $handshake_note = null;

            // Mode 1: Instant Live Rotating QR Code Handshake (Zero-Click, Frictionless & Anti-Screenshot)
            if ($live_handshake !== '') {
                $hp = explode(':', $live_handshake);
                if (count($hp) === 2) {
                    $slice = (int)$hp[0];
                    $sig = $hp[1];
                    $currentSlice = (int)floor(time() / 30);
                    // Allow current slice +/- 2 (up to 90 seconds window for slight mobile/server clock drift)
                    if (abs($currentSlice - $slice) <= 2) {
                        $raw = $target['qr_token'] . ':' . $target['requester_id'] . ':' . $slice . ':duarte_pos_handshake';
                        $expectedSig = substr(hash('sha256', $raw), 0, 12);
                        if (hash_equals($expectedSig, $sig)) {
                            $handshake_verified = true;
                            $handshake_method = 'dynamic_qr_scan';
                            $handshake_note = 'Verified in-person via live rotating QR code (slice ' . $slice . ')';
                        }
                    }
                }
            }

            // Mode 2: Clean 4-Digit Driver PIN Verification (Offline/Fallback)
            if (!$handshake_verified && $driver_pin !== '') {
                // Check Lockout
                if (!empty($requester_user['pin_locked_until'])) {
                    $lockTime = strtotime($requester_user['pin_locked_until']);
                    if ($lockTime > time()) {
                        $mins = max(1, (int)ceil(($lockTime - time()) / 60));
                        $pdo->rollBack();
                        api_response(false, null, "Naka-lock ang Driver PIN ng $mins minuto dahil sa sunod-sunod na maling subok. Gamitin ang Supervisor Override Note.", 429);
                    }
                }

                if (!empty($requester_user['pin_hash']) && password_verify($driver_pin, $requester_user['pin_hash'])) {
                    $handshake_verified = true;
                    $handshake_method = 'driver_pin';
                    // Reset failed counter
                    $pdo->prepare('UPDATE users SET pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id')
                        ->execute(['id' => $requester_user['id']]);
                } else {
                    $newFails = (int)($requester_user['pin_failed_attempts'] ?? 0) + 1;
                    $lockSql = ($newFails >= 5) ? ', pin_locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE)' : '';
                    $pdo->prepare("UPDATE users SET pin_failed_attempts = :f $lockSql WHERE id = :id")
                        ->execute(['f' => $newFails, 'id' => $requester_user['id']]);
                    $pdo->rollBack();
                    $rem = max(0, 5 - $newFails);
                    api_response(false, null, "Maling Driver PIN. " . ($rem > 0 ? "May natitirang $rem subok bago ma-lock." : "Naka-lock ang PIN ng 5 minuto. Gamitin ang Supervisor Override."), 401);
                }
            }

            // Mode 3: Supervisor / Authorized Override
            if (!$handshake_verified && $override_reason !== '') {
                $handshake_verified = true;
                $handshake_method = 'supervisor_override';
                $handshake_note = $override_reason;
            }

            if (!$handshake_verified) {
                $pdo->rollBack();
                api_response(false, null, 'Kailangan i-scan ang Live QR ng Driver o maglagay ng 4-Digit Driver PIN / Supervisor Override bago i-release ang mga gamit.', 422);
            }

            // Fleet vehicle check & dispatch
            if (!empty($target['truck_id'])) {
                $is_sqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
                $t_stmt = $pdo->prepare('SELECT id, plate_number, status FROM trucks WHERE id = :tid' . ($is_sqlite ? '' : ' FOR UPDATE'));
                $t_stmt->execute(['tid' => $target['truck_id']]);
                $trk = $t_stmt->fetch();
                if ($trk) {
                    if ($trk['status'] === 'under_maintenance' && empty($target['is_maintenance_request'])) {
                        $pdo->rollBack();
                        api_response(false, null, 'Cannot release: Truck ' . $trk['plate_number'] . ' is currently under maintenance.', 400);
                    }
                    // A repair request (parts to fix the truck) is not a trip:
                    // it must not be blocked by, or start, a dispatch.
                    if (empty($target['is_maintenance_request'])) {
                        $overlap = $pdo->prepare("
                            SELECT r.id FROM requisitions r
                            JOIN tool_loans tl ON tl.requisition_id = r.id
                            WHERE r.truck_id = :tid 
                              AND r.id != :current_id 
                              AND r.status = 'released' 
                              AND tl.returned_at IS NULL
                            LIMIT 1
                        ");
                        $overlap->execute(['tid' => $target['truck_id'], 'current_id' => $target['id']]);
                        if ($overlap->fetch()) {
                            $pdo->rollBack();
                            api_response(false, null, 'Cannot release: Truck ' . $trk['plate_number'] . ' is currently dispatched on another active trip with unreturned equipment.', 409);
                        }
                    }
                    if (requisition_dispatches_truck($pdo, $target)) {
                        $pdo->prepare("UPDATE trucks SET status = 'on_trip' WHERE id = :tid AND status = 'available'")
                            ->execute(['tid' => $target['truck_id']]);
                    }
                }
            }

            $defective_surrendered = !empty($input['defective_part_surrendered']) ? 1 : 0;
            $defective_note = trim($input['defective_part_note'] ?? '') ?: null;

            // Partial Release / Item Verification handling
            $verified_item_ids = isset($input['verified_item_ids']) && is_array($input['verified_item_ids'])
                ? array_map('intval', $input['verified_item_ids'])
                : null;
            $partial_note = trim($input['partial_release_reason'] ?? $input['partial_note'] ?? '') ?: null;

            if ($verified_item_ids !== null && empty($verified_item_ids)) {
                $pdo->rollBack();
                api_response(false, null, 'Wala pang na-verify na gamit para i-release. Paki-verify ang kahit isang item bago mag-release.', 422);
            }

            $total_lines = count($lines);
            $verified_count = ($verified_item_ids !== null) ? count($verified_item_ids) : $total_lines;
            $is_partial = ($verified_item_ids !== null) && ($verified_count < $total_lines);
            $partial_reason = $is_partial ? ($partial_note ?: 'Partial release executed from warehouse counter') : null;

            $upd = $pdo->prepare("
                UPDATE requisitions 
                SET status = 'released', released_by = :by, released_at = NOW(),
                    receiver_verified = :rv,
                    handshake_method = :hm,
                    handshake_note = :hn,
                    defective_part_surrendered = :surrendered,
                    defective_part_note = :dnote,
                    is_partial_release = :is_partial,
                    partial_release_reason = :preason
                WHERE id = :id AND status = 'approved'
            ");
            $upd->execute([
                'by'          => $user_id,
                'rv'          => $handshake_verified ? 1 : 0,
                'hm'          => $handshake_method,
                'hn'          => $handshake_note,
                'surrendered' => $defective_surrendered,
                'dnote'       => $defective_note,
                'is_partial'  => $is_partial ? 1 : 0,
                'preason'     => $partial_reason,
                'id'          => $target['id'],
            ]);

            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                api_response(false, null, 'Concurrency conflict: This requisition was already released by another staff member.', 409);
            }

            $alert_checks = [];
            $unfulfilled_lines = [];
            foreach ($lines as $l) {
                $line_id = (int)$l['id'];
                $is_line_verified = ($verified_item_ids === null) || in_array($line_id, $verified_item_ids, true);

                if (!$is_line_verified) {
                    // Line was skipped / unfulfilled in partial release: DO NOT deduct stock or create loan!
                    $pdo->prepare('UPDATE requisition_items SET is_released = 0, quantity_released = 0, release_note = :note WHERE id = :id')
                        ->execute(['note' => $partial_note ?: 'Item not fulfilled during counter release', 'id' => $line_id]);
                    $unfulfilled_lines[] = $l;
                    continue;
                }

                // Line is verified: mark as released
                $pdo->prepare('UPDATE requisition_items SET is_released = 1, quantity_released = quantity_requested WHERE id = :id')
                    ->execute(['id' => $line_id]);

                if ($l['item_id'] !== null) {
                    $line_variant_id = null;
                    if (!empty($l['variant_selected'])) {
                        $v = find_item_variant($pdo, (int)$l['item_id'], $l['variant_selected']);
                        $line_variant_id = $v ? $v['id'] : null;
                    }
                    $res = record_stock_movement(
                        $pdo,
                        (int)$l['item_id'],
                        'release',
                        -1 * (int)$l['quantity_requested'],
                        'requisition',
                        (int)$target['id'],
                        $user_id,
                        'Released for request #' . $target['id'] . ' via Mobile' . ($is_partial ? ' (Partial)' : ''),
                        $line_variant_id
                    );
                    $alert_checks[] = ['item_id' => (int)$l['item_id']] + $res;

                    // Synchronize physical assets tracking
                    $available_assets = get_available_assets_for_item($pdo, (int)$l['item_id'], $line_variant_id);
                    $chosen = null;
                    if ($available_assets) {
                        $chosen_id = (int)($input['asset_choice'][$l['id']] ?? ($input['assets'][$l['id']] ?? 0));
                        if ($chosen_id > 0) {
                            foreach ($available_assets as $a) {
                                if ((int)$a['id'] === $chosen_id) {
                                    $chosen = $a;
                                    break;
                                }
                            }
                        }
                        if (!$chosen && !empty($available_assets)) {
                            $chosen = $available_assets[0];
                        }
                    }

                    if (!empty($l['is_borrowable'])) {
                        $days = (int)($l['requested_days'] ?? 3);
                        $due_date = add_business_days(date('Y-m-d'), $days);
                        $qty_needed = (int)$l['quantity_requested'];

                        if ($available_assets) {
                            $allocated_assets = [];
                            $chosen_id = (int)($input['asset_choice'][$l['id']] ?? ($input['assets'][$l['id']] ?? 0));
                            if ($chosen_id > 0) {
                                foreach ($available_assets as $a) {
                                    if ((int)$a['id'] === $chosen_id) {
                                        $allocated_assets[] = $a;
                                        break;
                                    }
                                }
                            }
                            // Fill remaining units from available physical units pool
                            foreach ($available_assets as $a) {
                                if (count($allocated_assets) >= $qty_needed) {
                                    break;
                                }
                                if (!in_array($a['id'], array_column($allocated_assets, 'id'), true)) {
                                    $allocated_assets[] = $a;
                                }
                            }

                            $init_cond = trim($input['initial_condition'][$l['id']] ?? ($input['initial_condition'] ?? 'good'));

                            // Record 1 loan row per physical unit for granular individual accountability
                            foreach ($allocated_assets as $asset_row) {
                                $loan_stmt = $pdo->prepare('
                                    INSERT INTO tool_loans (requisition_id, requisition_item_id, item_id, asset_id, borrower_id, quantity, initial_condition, due_date)
                                    VALUES (:rid, :riid, :iid, :aid, :borrower, 1, :ic, :due_date)
                                ');
                                $loan_stmt->execute([
                                    'rid'      => $target['id'],
                                    'riid'     => $l['id'],
                                    'iid'      => $l['item_id'],
                                    'aid'      => (int)$asset_row['id'],
                                    'borrower' => $target['requester_id'],
                                    'ic'       => $init_cond,
                                    'due_date' => $due_date,
                                ]);

                                checkout_asset(
                                    $pdo, (int)$asset_row['id'], (int)$target['requester_id'], $user,
                                    'Released via requisition #' . $target['id'] . ' (Mobile App). Condition: ' . $init_cond,
                                    'requisition', (int)$target['id']
                                );
                            }

                            // If fewer physical tags registered than quantity requested, track remainder as untagged loan
                            $untracked_qty = $qty_needed - count($allocated_assets);
                            if ($untracked_qty > 0) {
                                $loan_stmt = $pdo->prepare('
                                    INSERT INTO tool_loans (requisition_id, requisition_item_id, item_id, asset_id, borrower_id, quantity, initial_condition, due_date)
                                    VALUES (:rid, :riid, :iid, NULL, :borrower, :qty, :ic, :due_date)
                                ');
                                $loan_stmt->execute([
                                    'rid'      => $target['id'],
                                    'riid'     => $l['id'],
                                    'iid'      => $l['item_id'],
                                    'borrower' => $target['requester_id'],
                                    'qty'      => $untracked_qty,
                                    'ic'       => $init_cond,
                                    'due_date' => $due_date,
                                ]);
                            }
                        } else {
                            // No physical asset tags registered for this item
                            $init_cond = trim($input['initial_condition'][$l['id']] ?? ($input['initial_condition'] ?? 'good'));
                            $loan_stmt = $pdo->prepare('
                                INSERT INTO tool_loans (requisition_id, requisition_item_id, item_id, asset_id, borrower_id, quantity, initial_condition, due_date)
                                VALUES (:rid, :riid, :iid, NULL, :borrower, :qty, :ic, :due_date)
                            ');
                            $loan_stmt->execute([
                                'rid'      => $target['id'],
                                'riid'     => $l['id'],
                                'iid'      => $l['item_id'],
                                'borrower' => $target['requester_id'],
                                'qty'      => $qty_needed,
                                'ic'       => $init_cond,
                                'due_date' => $due_date,
                            ]);
                        }
                    } elseif ($chosen) {
                        record_asset_consumption(
                            $pdo, (int)$chosen['id'], (int)$l['quantity_requested'], $user,
                            'Consumed via requisition #' . $target['id'] . ' (Mobile App).'
                        );
                    }
                }
            }

            // Short-Pick Backorder Requisition System:
            // Automatically clone unfulfilled lines into an approved Backorder Requisition
            $backorder_id = null;
            if ($is_partial && count($unfulfilled_lines) > 0) {
                $bo_qr = generate_unique_qr_token($pdo);
                $bo_stmt = $pdo->prepare("
                    INSERT INTO requisitions 
                        (requester_id, status, purpose, truck_id, truck_plate_snapshot, is_maintenance_request,
                         decided_by, decided_at, decision_note, qr_token, created_at)
                    VALUES 
                        (:uid, 'approved', :purpose, :tid, :plate, :is_maint, :dby, NOW(), :dnote, :token, NOW())
                ");
                $bo_stmt->execute([
                    'uid'      => (int)$target['requester_id'],
                    'purpose'  => '[Backorder para sa REQ-#' . $target['id'] . '] ' . ($target['purpose'] ?? ''),
                    'tid'      => !empty($target['truck_id']) ? (int)$target['truck_id'] : null,
                    'plate'    => $target['truck_plate_snapshot'] ?? null,
                    'is_maint' => !empty($target['is_maintenance_request']) ? 1 : 0,
                    'dby'      => $user_id,
                    'dnote'    => 'Awtomatikong Backorder mula sa Partial Release ng Requisition #' . $target['id'] . ($partial_reason ? " ($partial_reason)" : ''),
                    'token'    => $bo_qr,
                ]);
                $backorder_id = (int)$pdo->lastInsertId();

                $ins_item = $pdo->prepare("
                    INSERT INTO requisition_items 
                        (requisition_id, item_id, item_name_snapshot, unit_snapshot, quantity_requested, is_borrowable, requested_days, variant_selected)
                    VALUES 
                        (:rid, :iid, :name, :unit, :qty, :borrow, :days, :variant)
                ");
                foreach ($unfulfilled_lines as $ul) {
                    $ins_item->execute([
                        'rid'     => $backorder_id,
                        'iid'     => $ul['item_id'],
                        'name'    => $ul['item_name_snapshot'],
                        'unit'    => $ul['unit_snapshot'],
                        'qty'     => (int)$ul['quantity_requested'],
                        'borrow'  => (int)$ul['is_borrowable'],
                        'days'    => !empty($ul['requested_days']) ? (int)$ul['requested_days'] : null,
                        'variant' => $ul['variant_selected'] ?? null,
                    ]);
                }

                log_audit_event(
                    $pdo, $user, 'requisition_backorder_created', 'requisition', $backorder_id,
                    $user['full_name'] . ' automatically created Backorder Requisition #' . $backorder_id . ' for unfulfilled items from #' . $target['id'] . '.'
                );
            }

            $audit_action = $is_partial ? 'requisition_partial_release' : 'requisition_release';
            $audit_desc = $is_partial
                ? $user['full_name'] . ' executed PARTIAL release on requisition #' . $target['id'] . " ($verified_count of $total_lines items). Reason: $partial_reason" . ($backorder_id ? " (Created Backorder #$backorder_id)" : '')
                : $user['full_name'] . ' released requisition #' . $target['id'] . ' via Mobile App.';

            log_audit_event($pdo, $user, $audit_action, 'requisition', (int)$target['id'], $audit_desc);

            $notif_msg = $is_partial
                ? 'Your requisition #' . $target['id'] . " was partially released ($verified_count of $total_lines items). " . ($backorder_id ? "A pre-approved Backorder (#$backorder_id) was automatically created for remaining items." : '')
                : 'Your requisition #' . $target['id'] . ' items have been released by warehouse staff (' . $user['full_name'] . ').';

            notify_user((int)$target['requester_id'], $notif_msg, BASE_URL . '/requisition/view.php?id=' . $target['id']);

            $pdo->commit();

            foreach ($alert_checks as $c) {
                maybe_alert_stock_threshold(
                    $c['item_id'], $c['before'], $c['after'], $c['name'], $c['unit'],
                    $c['variant_before'] ?? null, $c['variant_after'] ?? null, $c['variant_value'] ?? null
                );
            }

            require_once __DIR__ . '/../includes/sms.php';
            $sms_body = $is_partial && $backorder_id
                ? 'DuaRTE: Requisition #' . $target['id'] . ' was partially released. Backorder #' . $backorder_id . ' created for unfulfilled items.'
                : 'DuaRTE: Requisition #' . $target['id'] . ' items have been released by warehouse staff. Please inspect items.';
            notify_user_sms((int)$target['requester_id'], $sms_body);

            api_response(true, [
                'requisition_id' => (int)$target['id'],
                'status'         => 'released',
                'is_partial'     => $is_partial,
                'backorder_id'   => $backorder_id,
                'message'        => $is_partial && $backorder_id
                    ? 'Items released. Backorder #' . $backorder_id . ' created for remaining items.'
                    : 'Items released successfully.'
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            api_response(false, null, 'Release failed: ' . $e->getMessage(), 500);
        }
    }

    // Action: Create Requisition
    $is_office_staff = ($user['position'] ?? null) === 'office_staff';
    $truck_id = !empty($input['truck_id']) ? (int)$input['truck_id'] : 0;
    if (!$is_office_staff && $truck_id <= 0) {
        api_response(false, null, 'Please select the assigned fleet truck/vehicle. Mandatory for fleet operations.', 400);
    }
    $purpose = trim($input['purpose'] ?? $input['note'] ?? '');
    $is_urgent = !empty($input['is_urgent']) ? 1 : 0;
    $urgent_reason = trim($input['urgent_reason'] ?? $input['manual_urgent_reason'] ?? '');
    if (!$is_urgent && !is_within_office_hours()) {
        api_response(false, null, office_hours_message(), 400);
    }
    if ($is_urgent && !is_within_office_hours() && $urgent_reason === '' && $purpose === '') {
        api_response(false, null, 'Dahilan ng Emergency / Urgent Request ay obligado kapag nagpapasa sa labas ng office hours (8:00 AM - 11:00 PM).', 422);
    }
    $items = $input['items'] ?? [];

    if (empty($items) || !is_array($items)) {
        api_response(false, null, 'At least one item is required in requisition.', 400);
    }

    // Lookup truck plate snapshot and validate availability if truck_id provided
    $is_maintenance_request = !empty($input['is_maintenance_request']) ? 1 : 0;
    $truck_plate = null;
    if ($truck_id) {
        $t_stmt = $pdo->prepare("SELECT id, plate_number, status FROM trucks WHERE id = :tid");
        $t_stmt->execute(['tid' => $truck_id]);
        $trk = $t_stmt->fetch();
        if (!$trk) {
            api_response(false, null, 'The selected truck is invalid.', 400);
        }
        if ($trk['status'] === 'under_maintenance' && !$is_maintenance_request) {
            api_response(false, null, 'Truck ' . $trk['plate_number'] . ' is currently under maintenance and cannot be scheduled. Set is_maintenance_request if this request is to repair it.', 400);
        }
        $truck_plate = $trk['plate_number'];
    }

    // Check consumable replenishment velocity for this truck (7-day anti-pilferage window)
    if ($truck_id > 0) {
        $velocity_warnings = check_truck_consumable_velocity($pdo, $truck_id, $items, 7);
        if (!empty($velocity_warnings)) {
            $warning_msgs = array_column($velocity_warnings, 'message');
            $v_note = implode(' | ', $warning_msgs);
            $purpose = ($purpose !== '' ? ($purpose . "\n") : '') . "[BABALA: Madalas na Pagpapalit ng Pyesa - " . $v_note . "]";
        }
    }

    $pdo->beginTransaction();
    try {
        $urgent_reason = $is_urgent ? ($purpose ?: 'Urgent request filed from mobile application') : null;
        $urgent_by = $is_urgent ? $user_id : null;
        $urgent_at = $is_urgent ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare("
            INSERT INTO requisitions 
                (requester_id, truck_id, truck_plate_snapshot, is_maintenance_request, purpose, manual_urgent, 
                 manual_urgent_reason, manual_urgent_by, manual_urgent_at, qr_token, status)
            VALUES (:uid, :tid, :plate, :is_maint, :purpose, :urgent, :urgent_reason, :urgent_by, :urgent_at, NULL, 'pending')
        ");
        $stmt->execute([
            'uid'           => $user_id,
            'tid'           => $truck_id,
            'plate'         => $truck_plate,
            'is_maint'      => $is_maintenance_request,
            'purpose'       => $purpose ?: null,
            'urgent'        => $is_urgent,
            'urgent_reason' => $urgent_reason,
            'urgent_by'     => $urgent_by,
            'urgent_at'     => $urgent_at,
        ]);
        $req_id = (int)$pdo->lastInsertId();

        $ri_stmt = $pdo->prepare("
            INSERT INTO requisition_items
                (requisition_id, item_id, item_name_snapshot, unit_snapshot, quantity_requested,
                 is_borrowable, requested_days, variant_selected)
            VALUES (:rid, :iid, :name, :unit, :qty, :is_borrowable, :days, :variant)
        ");

        foreach ($items as $item) {
            $item_id = (int)($item['item_id'] ?? 0);
            $qty = max(1, (int)($item['quantity'] ?? 1));
            
            // Fetch catalog details for item snapshots
            $cat_stmt = $pdo->prepare("
                SELECT i.name, i.unit, i.is_borrowable, i.borrow_mode, c.name AS category_name, c.code_prefix 
                FROM items i 
                LEFT JOIN categories c ON c.id = i.category_id 
                WHERE i.id = :iid
            ");
            $cat_stmt->execute(['iid' => $item_id]);
            $item_info = $cat_stmt->fetch();

            if ($is_office_staff && $item_info && $item_info['category_name'] !== 'Office Supplies' && $item_info['code_prefix'] !== 'OS') {
                $pdo->rollBack();
                api_response(false, null, 'Office Staff accounts can only request Office Supplies.', 400);
            }

            $item_name = $item_info['name'] ?? ($item['item_name'] ?? 'Item');
            $unit = $item_info['unit'] ?? ($item['unit'] ?? 'pc');
            $is_borrowable = !empty($item['is_borrowable']) ? 1 : (!empty($item_info['is_borrowable']) ? 1 : 0);
            $variant = $item['variant_selected'] ?? ($item['variant'] ?? null);
            $days = $is_borrowable ? (int)($item['requested_days'] ?? 3) : null;

            $ri_stmt->execute([
                'rid'           => $req_id,
                'iid'           => $item_id,
                'name'          => $item_name,
                'unit'          => $unit,
                'qty'           => $qty,
                'is_borrowable' => $is_borrowable,
                'days'          => $days,
                'variant'       => $variant
            ]);
        }

        $pdo->commit();

        require_once __DIR__ . '/../includes/sms.php';
        notify_role(
            'field_supervisor',
            $user['full_name'] . ' submitted a new' . ($is_maintenance_request ? ' TRUCK REPAIR' : '') . ' request (#' . $req_id . ') for approval.',
            BASE_URL . '/requisition/view.php?id=' . $req_id
        );
        notify_role_sms('field_supervisor', 'DuaRTE Alert: ' . $user['full_name'] . ' submitted ' . ($is_maintenance_request ? 'TRUCK REPAIR ' : '') . 'Requisition #' . $req_id . ' awaiting your approval.');

        api_response(true, [
            'requisition_id' => $req_id,
            'qr_token'       => null,
            'status'         => 'pending',
            'message'        => 'Requisition successfully submitted and queued for approval.'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        api_response(false, null, 'Failed to submit requisition: ' . $e->getMessage(), 500);
    }
}
