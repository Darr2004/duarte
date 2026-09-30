<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db();
$api_user = require_api_auth($pdo);
$user_id = (int)$api_user['id'];
$role = $api_user['role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    check_overdue_loans($pdo);

    $is_supervisor = in_array($role, ['field_supervisor', 'admin'], true);

    if ($is_supervisor) {
        require_once __DIR__ . '/../includes/priority.php';

        // 1. Pending count across entire fleet
        $pending_stmt = $pdo->query("SELECT COUNT(*) c FROM requisitions WHERE status = 'pending'");
        $pending_count = (int)$pending_stmt->fetch()['c'];

        // 2. Operations volume in last 7 days
        $by_status = $pdo->query(
            "SELECT status, COUNT(*) c FROM requisitions
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC);

        $week_counts = ['pending' => 0, 'approved' => 0, 'declined' => 0, 'released' => 0, 'cancelled' => 0];
        foreach ($by_status as $row) {
            $week_counts[$row['status']] = (int)$row['c'];
        }

        $decided_week = $week_counts['approved'] + $week_counts['declined'] + $week_counts['released'];
        $approval_rate_week = $decided_week > 0
            ? round((($week_counts['approved'] + $week_counts['released']) / $decided_week) * 100)
            : null;

        // 3. Average decision turnaround time in minutes
        $turnaround_stmt = $pdo->query(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, decided_at)) AS avg_mins
             FROM requisitions
             WHERE decided_at IS NOT NULL
               AND decided_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
               AND status IN ('approved', 'declined', 'released')"
        );
        $avg_decision_mins = $turnaround_stmt->fetch()['avg_mins'];
        $turnaround_label = '—';
        if ($avg_decision_mins !== null && (float)$avg_decision_mins > 0) {
            $m = (float)$avg_decision_mins;
            $turnaround_label = $m < 60 ? round($m) . 'm' : round($m / 60, 1) . 'h';
        }

        // 4. Scored Pending Approvals Queue
        $pending_list_all = $pdo->query(
            "SELECT r.*, u.full_name AS requester_name, u.position AS requester_position,
                (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
             FROM requisitions r
             JOIN users u ON u.id = r.requester_id
             WHERE r.status = 'pending'
             ORDER BY r.created_at ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $scored_pending = score_pending_requisitions($pdo, $pending_list_all);
        $top_pending = array_slice($scored_pending, 0, 6);

        $urgent_count = 0;
        foreach ($scored_pending as $p) {
            if (!empty($p['manual_urgent']) || ($p['priority_score'] ?? 0) >= 70) {
                $urgent_count++;
            }
        }

        // 5. Personal activity for the supervisor
        $total_reqs = $pdo->prepare("SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid");
        $total_reqs->execute(['uid' => $user_id]);
        $total_reqs_count = (int)$total_reqs->fetch()['c'];

        $borrowed_now = $pdo->prepare("SELECT COUNT(*) c FROM tool_loans WHERE borrower_id = :uid AND returned_at IS NULL");
        $borrowed_now->execute(['uid' => $user_id]);
        $borrowed_now_count = (int)$borrowed_now->fetch()['c'];

        $pending_po = $pdo->prepare("SELECT COUNT(*) c FROM item_requests WHERE requester_id = :uid AND status = 'pending'");
        $pending_po->execute(['uid' => $user_id]);
        $pending_po_count = (int)$pending_po->fetch()['c'];

        api_response(true, [
            'is_supervisor' => true,
            'user' => [
                'id' => $user_id,
                'full_name' => $api_user['full_name'],
                'username' => $api_user['username'],
                'role' => $api_user['role'],
                'position' => $api_user['position'] ?? null,
                'employee_id' => $api_user['employee_id'] ?? null,
            ],
            'supervisor_metrics' => [
                'pending_count' => $pending_count,
                'approved_7d' => $week_counts['approved'] + $week_counts['released'],
                'declined_7d' => $week_counts['declined'],
                'approval_rate' => $approval_rate_week,
                'turnaround_label' => $turnaround_label,
                'urgent_count' => $urgent_count,
            ],
            'priority_pending' => $top_pending,
            'activity_profile' => [
                'total_requisitions' => $total_reqs_count,
                'active_loans' => $borrowed_now_count,
                'pending_po_requests' => $pending_po_count,
            ],
        ]);
    } else {
        // Driver / Helper / Personnel Dashboard
        $active_requests = $pdo->prepare(
            "SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid AND status IN ('pending', 'approved')"
        );
        $active_requests->execute(['uid' => $user_id]);
        $active_requests_count = (int)$active_requests->fetch()['c'];

        $borrowed_now = $pdo->prepare(
            "SELECT COUNT(*) c FROM tool_loans WHERE borrower_id = :uid AND returned_at IS NULL"
        );
        $borrowed_now->execute(['uid' => $user_id]);
        $borrowed_now_count = (int)$borrowed_now->fetch()['c'];

        $overdue_now = $pdo->prepare(
            "SELECT COUNT(*) c FROM tool_loans WHERE borrower_id = :uid AND returned_at IS NULL AND due_date < CURDATE()"
        );
        $overdue_now->execute(['uid' => $user_id]);
        $overdue_now_count = (int)$overdue_now->fetch()['c'];

        $ready_for_pickup = $pdo->prepare(
            "SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid AND status = 'approved'"
        );
        $ready_for_pickup->execute(['uid' => $user_id]);
        $ready_for_pickup_count = (int)$ready_for_pickup->fetch()['c'];

        $recent_requests = $pdo->prepare(
            "SELECT r.id, r.status, r.purpose, r.created_at, r.qr_token,
                    (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
             FROM requisitions r 
             WHERE r.requester_id = :uid 
             ORDER BY r.created_at DESC LIMIT 5"
        );
        $recent_requests->execute(['uid' => $user_id]);
        $recent_requests_list = $recent_requests->fetchAll(PDO::FETCH_ASSOC);

        $total_reqs = $pdo->prepare("SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid");
        $total_reqs->execute(['uid' => $user_id]);
        $total_reqs_count = (int)$total_reqs->fetch()['c'];

        $released_reqs = $pdo->prepare("SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid AND status = 'released'");
        $released_reqs->execute(['uid' => $user_id]);
        $released_reqs_count = (int)$released_reqs->fetch()['c'];

        $total_loans = $pdo->prepare("SELECT COUNT(*) c FROM tool_loans WHERE borrower_id = :uid");
        $total_loans->execute(['uid' => $user_id]);
        $total_loans_count = (int)$total_loans->fetch()['c'];

        $pending_po = $pdo->prepare("SELECT COUNT(*) c FROM item_requests WHERE requester_id = :uid AND status = 'pending'");
        $pending_po->execute(['uid' => $user_id]);
        $pending_po_count = (int)$pending_po->fetch()['c'];

        api_response(true, [
            'is_supervisor' => false,
            'user' => [
                'id' => $user_id,
                'full_name' => $api_user['full_name'],
                'username' => $api_user['username'],
                'role' => $api_user['role'],
                'position' => $api_user['position'] ?? null,
                'employee_id' => $api_user['employee_id'] ?? null,
            ],
            'metrics' => [
                'active_requests' => $active_requests_count,
                'tools_borrowed' => $borrowed_now_count,
                'overdue_tools' => $overdue_now_count,
                'ready_for_pickup' => $ready_for_pickup_count,
            ],
            'recent_requests' => $recent_requests_list,
            'activity_profile' => [
                'total_requisitions' => $total_reqs_count,
                'released_requisitions' => $released_reqs_count,
                'active_requests' => $active_requests_count,
                'total_loans' => $total_loans_count,
                'active_loans' => $borrowed_now_count,
                'overdue_loans' => $overdue_now_count,
                'pending_po_requests' => $pending_po_count,
            ]
        ]);
    }
}
