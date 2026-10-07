<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/priority.php';
require_once __DIR__ . '/../includes/sms.php';
require_login();

$pdo = get_db();
$user = current_user();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$flash_success = null;

$stmt = $pdo->prepare(
    "SELECT r.*, u.full_name AS requester_name, u.employee_id, u.email, u.position AS requester_position,
        d.full_name AS decided_by_name, rel.full_name AS released_by_name, mu.full_name AS manual_urgent_by_name
     FROM requisitions r
     JOIN users u ON u.id = r.requester_id
     LEFT JOIN users d ON d.id = r.decided_by
     LEFT JOIN users rel ON rel.id = r.released_by
     LEFT JOIN users mu ON mu.id = r.manual_urgent_by
     WHERE r.id = :id"
);
$stmt->execute(['id' => $id]);
$req = $stmt->fetch();

if (!$req) {
    http_response_code(404);
    die('Request not found.');
}

$is_supervisor = in_array($user['role'], ['field_supervisor', 'admin'], true);
$is_owner = (int)$req['requester_id'] === (int)$user['id'];
// Deciding (approve/decline) stays Field-Supervisor-only — that's the
// actual authority over the request. Flagging urgent is a separate,
// narrower action: Inventory Staff are the ones who'd actually notice
// a stockout or a safety issue first-hand from the warehouse side, so
// they can raise the flag even though they don't decide it — keeping
// "who notices" and "who decides" as two different people instead of
// the Field Supervisor flagging their own decision as urgent right
// before making it.
$can_flag_urgent = in_array($user['role'], ['field_supervisor', 'inventory_staff'], true) && !$is_owner;
// Inventory Staff/Admin land here from notification links (e.g. an
// overdue-tool alert) even though they don't decide or own the
// requisition — they still need read access, just not the decision UI.
$can_view = $is_supervisor || $is_owner || in_array($user['role'], ['admin', 'inventory_staff'], true);

if (!$can_view) {
    http_response_code(403);
    die("403 — You don't have permission to view this request.");
}

// Office staff requisitions are auto-approved by submit_requisition()
// with no decided_by (nobody decided it — see
// requisition_needs_supervisor_approval()). Computed here, before the
// decision/cancel handlers below, so the cancel handler can tell "a
// supervisor approved this" apart from "nobody did — the system did,
// because there's no supervisor over this requester" and let the
// owner cancel only the latter after the fact.
$is_auto_approved = $req['status'] === 'approved'
    && !$req['decided_by']
    && requisition_is_no_review_note($req['decision_note']);

// Handle manual urgent flag / unflag. Field Supervisor or Inventory
// Staff can mark a pending request urgent for a reason none of
// includes/priority.php's four scored criteria can see — the reason
// is required so this stays an accountable, logged override rather
// than a silent reorder.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['urgent_action'])) {
    if (!$can_flag_urgent) {
        $errors[] = 'Only a Field Supervisor or Inventory Staff can flag another requester\'s request as urgent.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($req['status'] !== 'pending') {
        $errors[] = 'This request has already been decided.';
    } elseif ($_POST['urgent_action'] === 'flag') {
        $urgent_reason = trim($_POST['urgent_reason'] ?? '');
        if ($urgent_reason === '') {
            $errors[] = 'Give a reason before flagging this request as urgent.';
        } else {
            $upd = $pdo->prepare(
                'UPDATE requisitions SET manual_urgent = 1, manual_urgent_reason = :reason,
                 manual_urgent_by = :by, manual_urgent_at = NOW() WHERE id = :id'
            );
            $upd->execute(['reason' => $urgent_reason, 'by' => $user['id'], 'id' => $id]);
            log_audit_event($pdo, $user, 'requisition_urgent_flag', 'requisition', $id,
                $user['full_name'] . ' flagged requisition #' . $id . ' from ' . $req['requester_name'] . ' as urgent. Reason: ' . $urgent_reason);
            header('Location: ' . BASE_URL . '/requisition/view.php?id=' . $id);
            exit;
        }
    } else {
        $upd = $pdo->prepare(
            'UPDATE requisitions SET manual_urgent = 0, manual_urgent_reason = NULL,
             manual_urgent_by = NULL, manual_urgent_at = NULL WHERE id = :id'
        );
        $upd->execute(['id' => $id]);
        log_audit_event($pdo, $user, 'requisition_urgent_unflag', 'requisition', $id,
            $user['full_name'] . ' removed the urgent flag from requisition #' . $id . ' from ' . $req['requester_name'] . '.');
        header('Location: ' . BASE_URL . '/requisition/view.php?id=' . $id);
        exit;
    }
}

// Where this request currently sits in the priority queue — shown on
// the decision form below so a supervisor sees, before submitting,
// whether deciding it now means skipping ahead of something else.
// Advisory only: the POST handler below re-snapshots the rank right
// before actually deciding, since the pending set can change between
// page load and submit.
$current_rank = null;
$current_queue_size = null;
if ($req['status'] === 'pending' && $is_supervisor && !$is_owner) {
    $rank_queue_stmt = $pdo->query(
        "SELECT r.id, r.created_at, r.requester_id, r.manual_urgent FROM requisitions r WHERE r.status = 'pending'"
    );
    $rank_queue = score_pending_requisitions($pdo, $rank_queue_stmt->fetchAll());
    $current_queue_size = count($rank_queue);
    foreach ($rank_queue as $i => $row) {
        if ((int)$row['id'] === $id) {
            $current_rank = $i + 1;
            $current_priority_row = $row;
            break;
        }
    }
}

// Handle approve/decline decision
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decision'])) {
    if (!$is_supervisor) {
        $errors[] = 'Only a Field Supervisor or Administrator can decide on requisitions.';
    } elseif ($is_owner) {
        // Defense in depth: a Field Supervisor's own requisitions are
        // auto-approved on submission (see submit_requisition()) and so
        // should never actually reach 'pending' — but if one ever does
        // (a legacy row, or a status manually reverted by an admin),
        // this still blocks a supervisor from approving their own
        // request, which submit_requisition() alone can't guarantee.
        // Logged as a security-relevant event even though nothing
        // actually changed, so a self-approval *attempt* still shows
        // up in the audit trail rather than only successful bypasses.
        log_audit_event($pdo, $user, 'requisition_self_decide_blocked', 'requisition', $id,
            $user['full_name'] . ' attempted to decide on their own requisition #' . $id . ' — blocked.');
        $errors[] = 'You cannot decide on your own requisition.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($req['status'] !== 'pending') {
        $errors[] = 'This request has already been decided.';
    } else {
        $decision = $_POST['decision'] === 'approved' ? 'approved' : 'declined';
        $note = trim($_POST['decision_note'] ?? '');
        $skip_reason = trim($_POST['priority_skip_reason'] ?? '');

        // Snapshot where this requisition ranked in the priority queue
        // at the exact moment of decision, for the audit trail. Must
        // run here, before the status UPDATE below, since
        // score_pending_requisitions() only scores rows still
        // 'pending' — after the update this row would no longer be in
        // that set and its own rank couldn't be recovered.
        $queue_stmt = $pdo->query(
            "SELECT r.id, r.created_at, r.requester_id, r.manual_urgent
             FROM requisitions r
             WHERE r.status = 'pending'"
        );
        $queue = score_pending_requisitions($pdo, $queue_stmt->fetchAll());
        $queue_size = count($queue);
        $priority_snapshot = '';
        $rank = null;
        foreach ($queue as $i => $row) {
            if ((int)$row['id'] === $id) {
                $rank = $i + 1;
                $priority_snapshot = sprintf(
                    ' [Priority %.1f, rank %d of %d pending — stock %d%%, demand %d%%, trust %d%%]',
                    $row['priority_score'], $rank, $queue_size,
                    $row['priority_stock'] ?? $row['priority_scarcity'],
                    $row['priority_demand'] ?? $row['priority_contention'],
                    $row['priority_trust'] ?? $row['priority_reliability']
                );
                break;
            }
        }

        // Deciding anything other than the top of the queue skips
        // whoever's ranked ahead of it — that used to happen silently
        // (just switch requisition/pending.php to "Oldest first" and
        // decide whatever). Now it requires a reason, logged below,
        // instead of being invisible in the audit trail.
        $is_out_of_order = $rank !== null && $rank > 1;

        if ($is_out_of_order && $skip_reason === '') {
            $errors[] = "This request is rank $rank of $queue_size in the priority queue right now — deciding it means skipping ahead of others. Give a short reason, or open the top-ranked request instead.";
        } else {

        // Everything from here is one transaction. Before checking
        // shortfalls, lock every item row this requisition touches
        // (SELECT ... FOR UPDATE) so a second supervisor deciding a
        // *different* requisition that competes for the same item can't
        // read the same "reserved vs on-hand" snapshot at the same time —
        // they'll block on the lock and see this decision's effect once
        // it commits, instead of both passing the check and only one of
        // them discovering the shortage later at release. Mirrors the
        // same FOR UPDATE pattern record_stock_movement() already uses.
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status FROM requisitions WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            $locked_status = $lock->fetch()['status'] ?? null;

            if ($locked_status !== 'pending') {
                // Someone else decided it (or it auto-expired) in the
                // instant between our page load and this click.
                $pdo->rollBack();
                $errors[] = 'This request has already been decided.';
            } else {
                $shortfalls = [];
                if ($decision === 'approved') {
                    $item_ids_stmt = $pdo->prepare(
                        'SELECT DISTINCT item_id FROM requisition_items WHERE requisition_id = :id AND item_id IS NOT NULL'
                    );
                    $item_ids_stmt->execute(['id' => $id]);
                    $item_ids = array_column($item_ids_stmt->fetchAll(), 'item_id');

                    if ($item_ids) {
                        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
                        $pdo->prepare("SELECT id FROM items WHERE id IN ($placeholders) FOR UPDATE")
                            ->execute($item_ids);
                    }

                    $shortfalls = requisition_stock_shortfalls($pdo, $id);
                }

                $truck_maint_error = false;
                $maint_plate = '';
                if ($decision === 'approved' && !empty($req['truck_id']) && empty($req['is_maintenance_request'])) {
                    $t_chk = $pdo->prepare('SELECT plate_number, status FROM trucks WHERE id = :tid');
                    $t_chk->execute(['tid' => $req['truck_id']]);
                    $trk = $t_chk->fetch();
                    if ($trk && $trk['status'] === 'under_maintenance') {
                        $truck_maint_error = true;
                        $maint_plate = $trk['plate_number'];
                    }
                }

                if ($shortfalls) {
                    $pdo->rollBack();
                    $errors[] = 'Cannot approve — not enough stock left for: ' . implode(', ', $shortfalls)
                        . '. Another request already has a claim on it; decline this one or check with Inventory Staff before approving.';
                } elseif ($truck_maint_error) {
                    $pdo->rollBack();
                    $errors[] = 'Cannot approve — Assigned Truck ' . htmlspecialchars($maint_plate) . ' is currently under maintenance and out of service.';
                } else {
                    $qr_token = $decision === 'approved' ? generate_unique_qr_token($pdo) : null;

                    $upd = $pdo->prepare(
                        'UPDATE requisitions SET status = :status, decided_by = :by, decision_note = :note,
                         decided_at = NOW(), qr_token = :qr_token WHERE id = :id'
                    );
                    $upd->execute([
                        'status'   => $decision,
                        'by'       => $user['id'],
                        'note'     => $note ?: null,
                        'qr_token' => $qr_token,
                        'id'       => $id,
                    ]);

                    $pdo->commit();

                    // Notifications/audit only fire once the decision has
                    // actually committed — same reasoning as verify.php's
                    // release step: a rollback above must never be
                    // followed by a "you were approved" message.
                    $msg = $decision === 'approved'
                        ? 'Your request #' . $id . ' was approved by ' . $user['full_name'] . '. Show your QR code at the tool room to pick up your items.'
                        : 'Your request #' . $id . ' was declined by ' . $user['full_name'] . '.';
                    notify_user((int)$req['requester_id'], $msg, BASE_URL . '/requisition/view.php?id=' . $id);

                    $sms_msg = $decision === 'approved'
                        ? 'DuaRTE: Requisition #' . $id . ' was APPROVED by ' . $user['full_name'] . '. You may now pick up your items at the warehouse.'
                        : 'DuaRTE: Requisition #' . $id . ' was DECLINED by ' . $user['full_name'] . '.' . ($note !== '' ? ' Reason: ' . $note : '');
                    notify_user_sms((int)$req['requester_id'], $sms_msg);

                    log_audit_event($pdo, $user, $decision === 'approved' ? 'requisition_approve' : 'requisition_decline', 'requisition', $id,
                        $user['full_name'] . ' ' . $decision . ' requisition #' . $id . ' from ' . $req['requester_name'] . '.' . ($note !== '' ? ' Note: ' . $note : '') . $priority_snapshot
                        . ($is_out_of_order ? ' [Decided out of order — reason: ' . $skip_reason . ']' : ''));

                    header('Location: ' . BASE_URL . '/requisition/view.php?id=' . $id . '&decided=1');
                    exit;
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $errors[] = 'Something went wrong deciding this request. Please try again.';
        }
        }
    }
}

// Owner can cancel their own pending request — or their own
// auto-approved (no-supervisor) request, as long as it hasn't been
// released yet. A supervisor-approved request is excluded on purpose:
// that approval was a human decision, and the requester shouldn't be
// able to unilaterally undo it without the supervisor's say.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    $cancellable = $req['status'] === 'pending' || $is_auto_approved;
    if (!$is_owner) {
        $errors[] = 'Only the requester can cancel this request.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$cancellable) {
        $errors[] = $req['status'] === 'approved'
            ? 'This request was approved by a Field Supervisor and can no longer be cancelled here — contact them directly.'
            : 'This request has already been decided.';
    } else {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id, status, decided_by, decision_note FROM requisitions WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            $locked = $lock->fetch();

            $locked_auto = $locked && $locked['status'] === 'approved' && !$locked['decided_by'] && requisition_is_no_review_note($locked['decision_note']);
            $locked_cancellable = $locked && ($locked['status'] === 'pending' || $locked_auto);

            if (!$locked_cancellable) {
                $pdo->rollBack();
                $errors[] = 'This request can no longer be cancelled (it has already been decided or released).';
            } else {
                $allowed_status = $locked['status'];
                $upd = $pdo->prepare("UPDATE requisitions SET status = 'cancelled' WHERE id = :id AND status = :status");
                $upd->execute(['id' => $id, 'status' => $allowed_status]);
                if ($upd->rowCount() === 0) {
                    $pdo->rollBack();
                    $errors[] = 'Concurrency conflict: Requisition status was changed by another user.';
                } else {
                    $pdo->commit();
                    log_audit_event($pdo, $user, 'requisition_cancel', 'requisition', $id,
                        $user['full_name'] . ' cancelled requisition #' . $id . '.');

                    notify_user_sms((int)$user['id'], "DuaRTE: Your Requisition #{$id} has been CANCELLED successfully.");
                    notify_role_sms('field_supervisor', "DuaRTE Alert: Requisition #{$id} was CANCELLED by {$user['full_name']}.");

                    header('Location: ' . BASE_URL . '/requisition/my_requests.php');
                    exit;
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $errors[] = 'Something went wrong cancelling this request. Please try again.';
        }
    }
}

// A row that was 'approved' and then swept up by expire_stale_requisitions()
// still had decided_by set (from the original approval) on installs running
// the old version of that function — this flag makes the view defensive
// against that leftover data instead of just trusting the fresh code path.
$is_auto_expired = $req['status'] === 'cancelled'
    && $req['decision_note']
    && (str_starts_with($req['decision_note'], 'Nag-expire') || str_starts_with($req['decision_note'], 'Auto-expired'));


$items = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id');
$items->execute(['id' => $id]);
$items = $items->fetchAll();

// This page is reached from several different lists depending on role
// (Pending Approvals, All Requisitions, My Requests, or a notification
// link) with no single "parent" page — so there's no one nav item that
// obviously represents it. $active_nav_hint tells includes/header.php
// which sidebar item to highlight, and $back_url/$back_label give a
// real way back instead of relying on the browser's back button.
if ($user['role'] === 'field_supervisor') {
    $active_nav_hint = $req['status'] === 'pending' ? 'pending.php' : 'all.php';
    $back_url   = BASE_URL . '/requisition/' . $active_nav_hint;
    $back_label = $req['status'] === 'pending' ? 'Pending Approvals' : 'All Requests';
} elseif ($user['role'] === 'driver_helper') {
    $active_nav_hint = 'my_requests.php';
    $back_url   = BASE_URL . '/requisition/my_requests.php';
    $back_label = 'My Requests';
} elseif ($user['role'] === 'inventory_staff') {
    $back_url   = BASE_URL . '/inventory/loans.php';
    $back_label = 'Tool Loans';
} else {
    $back_url   = BASE_URL . '/admin/dashboard.php';
    $back_label = 'Dashboard';
}

// Refresh status in case it changed above without redirecting (error path keeps old $req; that's fine)
$page_title = 'Request #' . $id;
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= $back_url ?>" class="back-link">&larr; Back to <?= htmlspecialchars($back_label) ?></a>
<div class="page-header">
  <div>
    <div class="eyebrow">Online requisition &amp; approval</div>
    <h1>Request #<?= $id ?></h1>
  </div>
  <span class="badge <?= requisition_status_class($req['status']) ?>" style="font-size:0.8rem;"><?= htmlspecialchars($req['status'] ?? '') ?></span>
</div>

<?php if (!empty($_GET['submitted'])): ?>
  <?php if ($req['status'] === 'pending'): ?>
    <div class="alert alert-success">Your request was submitted. You'll be notified once it's reviewed.</div>
  <?php elseif (in_array($req['status'], ['approved', 'released'], true)): ?>
    <div class="alert alert-success">Your request has already been approved.</div>
  <?php elseif ($req['status'] === 'declined'): ?>
    <div class="alert alert-error">Your request was declined.</div>
  <?php elseif ($req['status'] === 'cancelled'): ?>
    <div class="alert alert-error">Your request was cancelled.</div>
  <?php endif; ?>
<?php endif; ?>
<?php if (!empty($_GET['decided'])): ?>
  <div class="alert alert-success">Decision recorded.</div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php
// Fleet heads-up for staff: a repair request against a truck that is still
// "Available" (or a trip request against a truck with an open repair request)
// is easy to miss — nothing changes the truck's status automatically.
if (!empty($req['truck_id']) && in_array($req['status'], ['pending', 'approved'], true)
    && ($is_supervisor || in_array($user['role'], ['admin', 'inventory_staff'], true))) {
    $fl_stmt = $pdo->prepare('SELECT plate_number, status FROM trucks WHERE id = :id');
    $fl_stmt->execute(['id' => $req['truck_id']]);
    $fl_truck = $fl_stmt->fetch();
    if ($fl_truck):
        if (!empty($req['is_maintenance_request'])):
            if ($fl_truck['status'] === 'available'): ?>
  <div class="alert alert-warning" role="status">
    <strong>Repair request.</strong> Truck still Available &mdash;
    <a href="<?= BASE_URL ?>/inventory/trucks.php">set Under Maintenance?</a>
  </div>
<?php       endif;
        else:
            $rp_stmt = $pdo->prepare(
                "SELECT id FROM requisitions
                  WHERE truck_id = :tid AND is_maintenance_request = 1
                    AND status IN ('pending', 'approved') AND id != :rid
                  ORDER BY id"
            );
            $rp_stmt->execute(['tid' => $req['truck_id'], 'rid' => $req['id']]);
            $rp_ids = $rp_stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($rp_ids): ?>
  <div class="alert alert-warning" role="status">
    <strong>Open repair request:</strong>
    <?php foreach ($rp_ids as $i => $rp_id): ?><?= $i ? ', ' : '' ?><a href="?id=<?= (int)$rp_id ?>">#<?= (int)$rp_id ?></a><?php endforeach; ?>
  </div>
<?php       endif;
        endif;
    endif;
} ?>

<div class="card">
  <div style="display:flex; gap:3rem; flex-wrap:wrap;">
    <div>
      <div class="eyebrow meta-mono">Requester</div>
      <div><?= htmlspecialchars($req['requester_name'] ?? '') ?> <span class="mono text-muted">(<?= htmlspecialchars($req['employee_id'] ?? '') ?>)</span></div>
      <?php $req_pos = position_label($req['requester_position']); if ($req_pos !== ''): ?>
        <div style="font-size:0.85rem; color:var(--ink-soft);"><?= htmlspecialchars($req_pos) ?></div>
      <?php endif; ?>
    </div>
    <div>
      <div class="eyebrow meta-mono">Submitted</div>
      <div class="mono"><?= htmlspecialchars($req['created_at'] ?? '') ?></div>
    </div>
    <?php if ($is_auto_expired): ?>
    <div>
      <div class="eyebrow meta-mono">Decided by</div>
      <div class="text-muted">Nag-expire (Sistema) — <span class="mono"><?= htmlspecialchars($req['decided_at'] ?? '') ?></span></div>
    </div>
    <?php elseif ($is_auto_approved): ?>
    <div>
      <div class="eyebrow meta-mono">Approved by</div>
      <div class="text-muted">Aprubado ng Sistema — <span class="mono"><?= htmlspecialchars($req['decided_at'] ?? '') ?></span></div>
    </div>
    <?php elseif ($req['decided_by_name']): ?>
    <div>
      <div class="eyebrow meta-mono"><?= $req['status'] === 'declined' ? 'Declined by' : 'Approved by' ?></div>
      <div><?= htmlspecialchars($req['decided_by_name'] ?? '') ?> — <span class="mono"><?= htmlspecialchars($req['decided_at'] ?? '') ?></span></div>
    </div>
    <?php endif; ?>
    <?php if ($req['released_by_name']): ?>
    <div>
      <div class="eyebrow meta-mono">Given out by</div>
      <div><?= htmlspecialchars($req['released_by_name'] ?? '') ?> — <span class="mono"><?= htmlspecialchars($req['released_at'] ?? '') ?></span></div>
    </div>
    <?php if ($req['status'] === 'released'): ?>
    <div>
      <div class="eyebrow meta-mono">Palit-Piyesa (1-to-1 Exchange)</div>
      <div style="display:flex; align-items:center; gap:0.4rem; margin-top:0.2rem; flex-wrap:wrap;">
        <?php if (!empty($req['defective_part_surrendered'])): ?>
          <span class="badge active" style="font-weight:600; font-size:0.82rem;">✓ Surrendered &amp; Inspected</span>
          <?php if (!empty($req['defective_part_note'])): ?>
            <span class="text-muted" style="font-size:0.82rem;">(<?= htmlspecialchars($req['defective_part_note'] ?? '') ?>)</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="badge" style="font-size:0.8rem; background:var(--bg-subtle, #f1f5f9); color:var(--ink-soft); border:1px solid var(--line);">Not Surrendered / New Install</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($req['truck_plate_snapshot'])):
      $trk_info = null;
      if (!empty($req['truck_id'])) {
          $tstmt = $pdo->prepare('SELECT * FROM trucks WHERE id = :id');
          $tstmt->execute(['id' => $req['truck_id']]);
          $trk_info = $tstmt->fetch();
      }
    ?>
    <div>
      <div class="eyebrow meta-mono">Assigned Truck</div>
      <div style="display:flex; align-items:center; gap:0.65rem; margin-top:0.25rem;">
        <?= truck_plate_badge($req['truck_plate_snapshot']) ?>
        <?php if ($trk_info):
          $st_b = truck_status_info($trk_info['status']);
        ?>
          <span class="badge <?= $st_b['class'] ?>" title="Current Fleet Status"><?= htmlspecialchars((string)($st_b['label'] ?? '')) ?></span>
          <span class="text-muted" style="font-size:0.82rem;">(<?= htmlspecialchars((string)($trk_info['model'] ?? '')) ?>)</span>
        <?php endif; ?>
        <?php if (!empty($req['is_maintenance_request'])): ?>
          <span class="badge role" title="Hindi para sa trip — para ayusin ang truck">For Truck Repair</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($req['purpose']): ?>
    <div class="mt-1">
      <div class="eyebrow meta-mono">Purpose</div>
      <div><?= htmlspecialchars($req['purpose'] ?? '') ?></div>
    </div>
  <?php endif; ?>
  <?php if ($req['decision_note']): ?>
    <div class="mt-1">
      <div class="eyebrow meta-mono">Note from approver</div>
      <div><?= htmlspecialchars($req['decision_note'] ?? '') ?></div>
    </div>
  <?php endif; ?>
</div>

<?php if ($is_owner && in_array($req['status'], ['approved', 'released'], true) && $req['qr_token']): ?>
  <div class="card" style="max-width:320px; text-align:center;">
    <h2 class="card-heading">Pickup QR Code</h2>
    <?php if ($req['status'] === 'released'): ?>
      <p style="color:var(--green-ok); font-weight:600;">Already released — items received.</p>
    <?php else: ?>
      <div id="qr-canvas" style="display:flex; justify-content:center; margin-bottom:0.75rem;"></div>
      <div style="background:var(--paper, #f8fafc); border:1px solid var(--line, #e2e8f0); border-radius:8px; padding:6px 14px; margin:0 auto 0.6rem auto; display:inline-block;">
        <span style="font-size:0.72rem; text-transform:uppercase; color:var(--ink-soft); letter-spacing:0.5px; display:block; font-weight:600;">Reference Code</span>
        <strong style="font-size:1.15rem; color:var(--ink); font-family:monospace; letter-spacing:1px;">REQ-<?= str_pad((string)$req['id'], 3, '0', STR_PAD_LEFT) ?></strong>
      </div>
      <p style="font-size:0.8rem; color:var(--ink-soft); margin-bottom:0.75rem;">Ipakita o sabihin ang code sa bodega kung hindi ma-scan ang QR.</p>
      <button type="button" id="qr-download-btn" class="btn btn-outline btn-sm">Download QR Code</button>
    <?php endif; ?>
  </div>
  <script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
  <script>
    if (typeof QRCode === 'undefined') {
      document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
    }
  </script>
  <script>
    var qrEl = document.getElementById('qr-canvas');
    if (qrEl) {
      new QRCode(qrEl, {
        text: <?= json_encode($req['qr_token']) ?>,
        width: 200,
        height: 200,
        colorDark: '#1A2129',
        colorLight: '#ffffff'
      });
    }
    var qrDownloadBtn = document.getElementById('qr-download-btn');
    if (qrDownloadBtn && qrEl) {
      qrDownloadBtn.addEventListener('click', function () {
        var canvas = qrEl.querySelector('canvas');
        var img = qrEl.querySelector('img');
        var dataUrl = canvas ? canvas.toDataURL('image/png') : (img ? img.src : null);
        if (!dataUrl) return;
        var link = document.createElement('a');
        link.href = dataUrl;
        link.download = 'requisition-<?= (int)$req['id'] ?>-pickup-qr.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      });
    }
  </script>
<?php endif; ?>

<div class="card">
  <h2 class="card-heading">Requested items</h2>
  <div class="table-responsive">
<table class="data">
    <thead><tr><th style="min-width:240px;">Item</th><th style="min-width:110px;">Quantity</th><th style="min-width:140px;">Type</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td data-label="Item"><?= htmlspecialchars((string)($it['item_name_snapshot'] ?? '')) ?><?php if (!empty($it['variant_selected'])): ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($it['variant_selected'] ?? '')) ?>)</span><?php endif; ?></td>
          <td class="mono" data-label="Quantity"><?= (int)$it['quantity_requested'] ?> <?= htmlspecialchars((string)($it['unit_snapshot'] ?? '')) ?></td>
          <td data-label="Type">
            <?php if ($it['is_borrowable']): ?>
              <span class="badge role">Tool — borrow <?= (int)($it['requested_days'] ?? 3) ?> day(s)</span>
            <?php else: ?>
              <span style="color:var(--ink-soft); font-size:0.85rem;">Consumable</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
  <?php if ($req['status'] === 'released'): ?>
    <p style="font-size:0.85rem; color:var(--ink-soft); margin-top:0.75rem;">
      Tracked in
      <?= $is_owner ? '<a href="' . BASE_URL . '/requisition/my_loans.php">My Borrowed Tools</a>' : '<a href="' . BASE_URL . '/inventory/loans.php">Tool Loans</a>' ?>.
    </p>
  <?php endif; ?>
</div>

<?php if ($can_flag_urgent && $req['status'] === 'pending'): ?>
  <div class="card" style="max-width:520px;">
    <h2 class="card-heading">Urgent flag</h2>
    <?php if (!empty($req['manual_urgent'])): ?>
      <p style="margin-top:0;">
        <span class="badge inactive">Urgent</span>
        flagged by <?= htmlspecialchars($req['manual_urgent_by_name'] ?? '—') ?> — <span class="mono" style="font-size:0.8rem;"><?= htmlspecialchars($req['manual_urgent_at'] ?? '') ?></span>
      </p>
      <p style="font-size:0.85rem; color:var(--ink-soft);"><?= htmlspecialchars($req['manual_urgent_reason'] ?? '') ?></p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" name="urgent_action" value="unflag" class="btn btn-outline btn-sm">Remove urgent flag</button>
      </form>
    <?php else: ?>
      <p style="font-size:0.85rem; color:var(--ink-soft); margin-top:0;">Moves this request to the top of the queue. Action is logged.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-group">
          <label for="urgent_reason">Urgency reason <span class="text-muted-normal">(required)</span></label>
          <textarea id="urgent_reason" name="urgent_reason" rows="2" style="width:100%; padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; font-family:var(--font-body);" required></textarea>
        </div>
        <button type="submit" name="urgent_action" value="flag" class="btn btn-outline btn-sm">Flag as urgent</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($is_supervisor && !$is_owner && $req['status'] === 'pending'): ?>
  <div class="card" style="max-width:520px;">
    <h2 class="card-heading">Decision</h2>

    <?php if (!empty($current_priority_row)): ?>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.85rem; padding:0.65rem 0.85rem; background:var(--panel-soft, #FAF6F0); border-radius:8px; border:1px solid var(--border-color, #E6DACA);">
        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
          <span style="font-weight:700; font-size:0.86rem; color:var(--ink);">Priority Score:</span>
          <span class="badge <?= priority_score_class($current_priority_row['priority_score']) ?>" style="font-weight:700; font-size:0.85rem;">
            <?= $current_priority_row['priority_score'] ?> / 100
          </span>
          <span style="font-size:0.78rem; color:var(--ink-soft);">(Rank #<?= $current_rank ?> of <?= $current_queue_size ?>)</span>
        </div>
        <button type="button" class="btn btn-outline btn-sm" 
                style="display:inline-flex; align-items:center; gap:0.3rem;"
                onclick='openPriorityModal(<?= htmlspecialchars(json_encode([
                    "id" => $req["id"],
                    "requester" => $req["requester_name"],
                    "score" => $current_priority_row["priority_score"],
                    "stock" => $current_priority_row["priority_stock"] ?? $current_priority_row["priority_scarcity"],
                    "demand" => $current_priority_row["priority_demand"] ?? $current_priority_row["priority_contention"],
                    "trust" => $current_priority_row["priority_trust"] ?? $current_priority_row["priority_reliability"],
                    "manual_urgent" => !empty($req["manual_urgent"]),
                    "manual_urgent_reason" => $req["manual_urgent_reason"] ?? "",
                    "score_class" => priority_score_class($current_priority_row["priority_score"]),
                ]), ENT_QUOTES, "UTF-8") ?>)'>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
          Score Breakdown
        </button>
      </div>
    <?php endif; ?>

    <?php if ($current_rank !== null && $current_rank > 1): ?>
      <div class="alert alert-warning">Rank <?= $current_rank ?> of <?= $current_queue_size ?> in queue. Out-of-order reason required.</div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="form-group">
        <label for="decision_note">Note <span class="text-muted-normal">(optional)</span></label>
        <textarea id="decision_note" name="decision_note" rows="2" style="width:100%; padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; font-family:var(--font-body);"></textarea>
      </div>
      <?php if ($current_rank !== null && $current_rank > 1): ?>
      <div class="form-group">
        <label for="priority_skip_reason">Skip reason <span class="text-muted-normal">(required)</span></label>
        <textarea id="priority_skip_reason" name="priority_skip_reason" rows="2" style="width:100%; padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; font-family:var(--font-body);" required></textarea>
      </div>
      <?php endif; ?>
      <div style="display:flex; gap:0.6rem;">
        <button type="submit" name="decision" value="approved" class="btn btn-primary">Approve</button>
        <button type="submit" name="decision" value="declined" class="btn btn-danger">Decline</button>
      </div>
    </form>
  </div>
<?php elseif ($is_owner && ($req['status'] === 'pending' || $is_auto_approved)): ?>
  <div class="card" style="max-width:420px;">
    <?php if ($is_auto_approved): ?>
      <p style="font-size:0.85rem; color:var(--ink-soft); margin-top:0;">Cancel allowed until released.</p>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('Cancel this request?');">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" name="cancel" value="1" class="btn btn-outline">Cancel request</button>
    </form>
  </div>
<?php endif; ?>

<!-- Priority Score Breakdown Modal (Defense & MCDA Transparency) -->
<div id="priorityModal" style="display:none; position:fixed; inset:0; background:rgba(20,15,10,0.65); z-index:9999; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(3px);">
  <div style="background:var(--surface, #FFFFFF); border:1px solid var(--border-color, #E6DACA); border-radius:14px; max-width:540px; width:100%; box-shadow:0 12px 36px rgba(0,0,0,0.22); overflow:hidden; animation:modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);">
    <div style="background:var(--panel-soft, #FAF6F0); padding:1.25rem 1.5rem; border-bottom:1px solid var(--border-color, #E6DACA); display:flex; align-items:center; justify-content:space-between;">
      <div style="display:flex; align-items:center; gap:0.6rem;">
        <div style="width:34px; height:34px; border-radius:8px; background:var(--accent-tint, #FDF6EC); color:var(--accent, #9E5B10); display:flex; align-items:center; justify-content:center;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>
        </div>
        <div>
          <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--ink-dark, #2B2018);" id="pmTitle">Priority Score Breakdown</h3>
          <div style="font-size:0.78rem; color:var(--ink-soft, #7A6A58);" id="pmSubtitle">Multi-Criteria Resource Allocation</div>
        </div>
      </div>
      <button type="button" onclick="closePriorityModal()" style="background:none; border:none; font-size:1.3rem; line-height:1; cursor:pointer; color:var(--ink-soft, #7A6A58); padding:0.2rem 0.5rem; border-radius:6px;">&times;</button>
    </div>

    <div style="padding:1.5rem;">
      <div style="display:flex; align-items:center; justify-content:space-between; padding:0.85rem 1.1rem; background:var(--surface-soft, #F7F2EA); border-radius:10px; margin-bottom:1.25rem; border:1px solid var(--border-color, #E8DFD3);">
        <div>
          <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--ink-soft, #7A6A58); font-weight:600;">Composite MCDA Score</div>
          <div style="font-size:1.45rem; font-weight:800; color:var(--ink-dark, #2B2018); font-family:var(--font-mono, monospace);" id="pmScoreVal">0.0 <span style="font-size:0.85rem; font-weight:500; color:var(--ink-soft);">/ 100 pts</span></div>
        </div>
        <div id="pmUrgentBadge"></div>
      </div>

      <div style="display:flex; flex-direction:column; gap:1.1rem; margin-bottom:1.25rem;">
        <div>
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.3rem;">
            <span style="font-weight:700; font-size:0.86rem; color:var(--ink); display:flex; align-items:center; gap:0.4rem;">
              <span style="width:8px; height:8px; border-radius:50%; background:var(--red-danger); display:inline-block;"></span>
              1. Stock Scarcity (45%)
            </span>
            <span class="mono" style="font-weight:700; font-size:0.86rem; color:var(--ink);" id="pmStockVal">0%</span>
          </div>
          <div style="height:6px; background:var(--surface-subtle); border-radius:4px; overflow:hidden; margin-bottom:0.25rem; border:1px solid var(--line);">
            <div id="pmStockBar" style="height:100%; background:var(--red-danger); width:0%; transition:width 0.4s ease;"></div>
          </div>
          <div style="font-size:0.75rem; color:var(--ink-soft);">
            Stock vs buffer level.
          </div>
        </div>

        <div>
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.3rem;">
            <span style="font-weight:700; font-size:0.86rem; color:var(--ink); display:flex; align-items:center; gap:0.4rem;">
              <span style="width:8px; height:8px; border-radius:50%; background:var(--amber); display:inline-block;"></span>
              2. Demand Contention (35%)
            </span>
            <span class="mono" style="font-weight:700; font-size:0.86rem; color:var(--ink);" id="pmDemandVal">0%</span>
          </div>
          <div style="height:6px; background:var(--surface-subtle); border-radius:4px; overflow:hidden; margin-bottom:0.25rem; border:1px solid var(--line);">
            <div id="pmDemandBar" style="height:100%; background:var(--amber); width:0%; transition:width 0.4s ease;"></div>
          </div>
          <div style="font-size:0.75rem; color:var(--ink-soft);">
            Competing requests, same item.
          </div>
        </div>

        <div>
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.3rem;">
            <span style="font-weight:700; font-size:0.86rem; color:var(--ink); display:flex; align-items:center; gap:0.4rem;">
              <span style="width:8px; height:8px; border-radius:50%; background:var(--green-ok); display:inline-block;"></span>
              3. Borrower Reliability (20%)
            </span>
            <span class="mono" style="font-weight:700; font-size:0.86rem; color:var(--ink);" id="pmTrustVal">0%</span>
          </div>
          <div style="height:6px; background:var(--surface-subtle); border-radius:4px; overflow:hidden; margin-bottom:0.25rem; border:1px solid var(--line);">
            <div id="pmTrustBar" style="height:100%; background:var(--green-ok); width:0%; transition:width 0.4s ease;"></div>
          </div>
          <div style="font-size:0.75rem; color:var(--ink-soft);">
            Past return record.
          </div>
        </div>
      </div>

      <div style="padding:0.75rem 0.9rem; background:var(--surface-subtle); border-radius:8px; border:1px solid var(--line); font-size:0.76rem; color:var(--ink-soft);">
        <strong style="color:var(--ink);">Model:</strong> MCDA scoring.
      </div>
    </div>

    <div style="background:var(--panel-soft, #FAF6F0); padding:0.85rem 1.5rem; border-top:1px solid var(--border-color, #E6DACA); text-align:right;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closePriorityModal()">Close</button>
    </div>
  </div>
</div>

<style>
@keyframes modalPop {
  0% { opacity:0; transform:scale(0.95) translateY(8px); }
  100% { opacity:1; transform:scale(1) translateY(0); }
}
</style>

<script>
function openPriorityModal(r) {
  document.getElementById('pmTitle').textContent = 'Priority Breakdown: Request #' + r.id;
  document.getElementById('pmSubtitle').textContent = 'Requester: ' + r.requester;
  document.getElementById('pmScoreVal').innerHTML = r.score + ' <span style="font-size:0.85rem; font-weight:500; color:var(--ink-soft);">/ 100 pts</span>';
  
  var urgentDiv = document.getElementById('pmUrgentBadge');
  if (r.manual_urgent) {
    urgentDiv.innerHTML = '<span class="badge inactive" style="font-weight:700; font-size:0.82rem;">⚡ Supervisor Override (Urgent)</span>' +
      (r.manual_urgent_reason ? '<div style="font-size:0.72rem; color:var(--danger,#c0392b); margin-top:2px;">' + r.manual_urgent_reason + '</div>' : '');
  } else {
    urgentDiv.innerHTML = '<span class="badge ' + r.score_class + '" style="font-weight:600; font-size:0.8rem;">Priority Rank Verified</span>';
  }

  document.getElementById('pmStockVal').textContent = r.stock + '%';
  document.getElementById('pmStockBar').style.width = Math.min(100, Math.max(2, r.stock)) + '%';

  document.getElementById('pmDemandVal').textContent = r.demand + '%';
  document.getElementById('pmDemandBar').style.width = Math.min(100, Math.max(2, r.demand)) + '%';

  document.getElementById('pmTrustVal').textContent = r.trust + '%';
  document.getElementById('pmTrustBar').style.width = Math.min(100, Math.max(2, r.trust)) + '%';

  var modal = document.getElementById('priorityModal');
  modal.style.display = 'flex';
}

function closePriorityModal() {
  document.getElementById('priorityModal').style.display = 'none';
}

document.getElementById('priorityModal').addEventListener('click', function(e) {
  if (e.target === this) closePriorityModal();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
