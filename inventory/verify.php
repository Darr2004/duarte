<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/assets.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;
$req = null;
$items = [];

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

// If someone pasted the raw QR content (a full/relative URL) into the
// manual-entry box instead of the bare token, pull just the token back out
// rather than trying to match the whole path against qr_token in the DB.
if ($token !== '' && preg_match('/[?&]token=([^&]+)/', $token, $m)) {
    $token = urldecode($m[1]);
}

$notices = [];

// Confirm release
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_release'])) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($token === '') {
        $errors[] = 'Missing QR token.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM requisitions WHERE qr_token = :t');
        $stmt->execute(['t' => $token]);
        $target = $stmt->fetch();

        if (!$target) {
            $errors[] = 'This QR code does not match any request. It may be invalid or already used.';
        } elseif ($target['status'] !== 'approved') {
            $errors[] = 'This request is not awaiting release (current status: ' . $target['status'] . ').';
        } elseif ((int)$target['requester_id'] === (int)$user['id']) {
            log_audit_event($pdo, $user, 'sod_self_release_blocked', 'requisition', $target['id'],
                $user['full_name'] . ' attempted to self-release their own requisition #' . $target['id'] . ' — blocked by Segregation of Duties.');
            $errors[] = 'Segregation of Duties: You cannot release your own requisition. Another warehouse staff member must verify and hand over the items.';
        } elseif ($target['decided_by'] !== null && (int)$target['decided_by'] === (int)$user['id']) {
            log_audit_event($pdo, $user, 'sod_dual_role_blocked', 'requisition', $target['id'],
                $user['full_name'] . ' attempted to release requisition #' . $target['id'] . ' which they previously approved — blocked by Segregation of Duties.');
            $errors[] = 'Segregation of Duties: You approved this requisition. An independent inventory staff member must verify and hand over the items.';
        } else {
            $lines = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id');
            $lines->execute(['id' => $target['id']]);
            $lines = $lines->fetchAll();

            try {
                $pdo->beginTransaction();

                // Concurrency lock: lock the requisition row FOR UPDATE to block duplicate releases
                $lock_stmt = $pdo->prepare('SELECT id, status, requester_id, decided_by FROM requisitions WHERE id = :id FOR UPDATE');
                $lock_stmt->execute(['id' => $target['id']]);
                $locked_target = $lock_stmt->fetch();

                // Fleet vehicle check & dispatch
                $truck_maint_err = null;
                if (!empty($target['truck_id'])) {
                    $is_sqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
                    $t_stmt = $pdo->prepare('SELECT id, plate_number, status FROM trucks WHERE id = :tid' . ($is_sqlite ? '' : ' FOR UPDATE'));
                    $t_stmt->execute(['tid' => $target['truck_id']]);
                    $trk = $t_stmt->fetch();
                    if ($trk) {
                        if ($trk['status'] === 'under_maintenance' && empty($target['is_maintenance_request'])) {
                            $truck_maint_err = "Cannot release: Assigned Truck {$trk['plate_number']} is currently under maintenance.";
                        } elseif (empty($target['is_maintenance_request'])) {
                            // Repair requests are not trips — never blocked by (or starting) a dispatch.
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
                                $truck_maint_err = "Cannot release: Assigned Truck {$trk['plate_number']} is currently dispatched on another active trip with unreturned equipment.";
                            }
                        }
                    }
                }

                if (!$locked_target || $locked_target['status'] !== 'approved') {
                    $pdo->rollBack();
                    $errors[] = 'This requisition is no longer awaiting release (already released, cancelled, or modified).';
                } elseif ($truck_maint_err) {
                    $pdo->rollBack();
                    $errors[] = $truck_maint_err;
                } else {
                    // Two-Way Driver Handshake Protocol (Physical Custody Confirmation)
                    $driver_pin = trim($_POST['driver_pin'] ?? '');
                    $override_reason = trim($_POST['override_reason'] ?? '');

                    $req_user_stmt = $pdo->prepare('SELECT id, full_name, role, pin_hash, password_hash FROM users WHERE id = :uid');
                    $req_user_stmt->execute(['uid' => $target['requester_id']]);
                    $requester_user = $req_user_stmt->fetch();

                    $handshake_verified = false;
                    $handshake_method = null;
                    $handshake_note = null;

                    if ($driver_pin !== '') {
                        if (!empty($requester_user['pin_hash']) && password_verify($driver_pin, $requester_user['pin_hash'])) {
                            $handshake_verified = true;
                            $handshake_method = 'driver_pin';
                        } elseif (empty($requester_user['pin_hash']) && in_array($driver_pin, ['1111', '0000', '1234'])) {
                            $handshake_verified = true;
                            $handshake_method = 'driver_pin_default';
                            // Auto-initialize PIN for driver
                            $pdo->prepare('UPDATE users SET pin_hash = :h, pin_set_at = NOW() WHERE id = :id')
                                ->execute(['h' => password_hash($driver_pin, PASSWORD_BCRYPT), 'id' => $requester_user['id']]);
                        } elseif (!empty($requester_user['password_hash']) && password_verify($driver_pin, $requester_user['password_hash'])) {
                            $handshake_verified = true;
                            $handshake_method = 'driver_password';
                        } else {
                            $pdo->rollBack();
                            $errors[] = 'Maling Driver PIN o Password. Kailangang ilagay ng Driver ang kanyang tamang 4-digit PIN upang kumpirmahin ang pagtanggap ng gamit.';
                        }
                    } elseif ($override_reason !== '') {
                        $handshake_verified = true;
                        $handshake_method = 'supervisor_override';
                        $handshake_note = $override_reason;
                    } else {
                        $pdo->rollBack();
                        $errors[] = 'Kailangan ang 4-Digit PIN ng Driver o Authorized Override Reason upang kumpirmahin ang pisikal na pagtanggap ng kagamitan (Dual-Custody Handshake).';
                    }

                    if (empty($errors)) {
                        if (requisition_dispatches_truck($pdo, $target)) {
                            $pdo->prepare("UPDATE trucks SET status = 'on_trip' WHERE id = :tid AND status = 'available'")
                                ->execute(['tid' => $target['truck_id']]);
                        }

                        $defective_surrendered = !empty($_POST['defective_part_surrendered']) ? 1 : 0;
                        $defective_note = trim($_POST['defective_part_note'] ?? '') ?: null;

                        $upd = $pdo->prepare(
                            "UPDATE requisitions
                                SET status = 'released',
                                    released_by = :by,
                                    released_at = NOW(),
                                    receiver_verified = :rv,
                                    handshake_method = :hm,
                                    handshake_note = :hn,
                                    defective_part_surrendered = :surrendered,
                                    defective_part_note = :dnote
                              WHERE id = :id AND status = 'approved'"
                        );
                        $upd->execute([
                            'by'          => $user['id'],
                            'rv'          => $handshake_verified ? 1 : 0,
                            'hm'          => $handshake_method,
                            'hn'          => $handshake_note,
                            'surrendered' => $defective_surrendered,
                            'dnote'       => $defective_note,
                            'id'          => $target['id'],
                        ]);

                    if ($upd->rowCount() === 0) {
                        $pdo->rollBack();
                        $errors[] = 'Concurrency conflict: This requisition was already released by another staff member.';
                    } else {
                        $alert_checks = [];
                        foreach ($lines as $l) {
                    if ($l['item_id'] !== null) {
                        $line_variant_id = null;
                        if (!empty($l['variant_selected'])) {
                            $v = find_item_variant($pdo, (int)$l['item_id'], $l['variant_selected']);
                            // If the exact option is gone (renamed/removed since the
                            // request was made), fall back to the item's aggregate
                            // total rather than blocking the whole release.
                            $line_variant_id = $v ? $v['id'] : null;
                        }
                        $result = record_stock_movement(
                            $pdo,
                            (int)$l['item_id'],
                            'release',
                            -1 * (int)$l['quantity_requested'],
                            'requisition',
                            (int)$target['id'],
                            (int)$user['id'],
                            'Released for request #' . $target['id'],
                            $line_variant_id
                        );
                        $alert_checks[] = ['item_id' => (int)$l['item_id']] + $result;

                        // QR Code Asset Tracking: staff must scan or pick the
                        // SPECIFIC physical unit/lot being handed over for
                        // EVERY line item that has one registered — no more
                        // silently assuming the paperwork matches the shelf.
                        // If nothing has been QR-tagged for this item yet,
                        // release proceeds without a link, same as before
                        // (tagging is opt-in per item — nothing to scan if
                        // it was never registered).
                        $available_assets = get_available_assets_for_item($pdo, (int)$l['item_id'], $line_variant_id);
                        $chosen = null;
                        if ($available_assets) {
                            $chosen_id = (int)($_POST['asset_choice'][$l['id']] ?? 0);
                            foreach ($available_assets as $a) {
                                if ((int)$a['id'] === $chosen_id) {
                                    $chosen = $a;
                                    break;
                                }
                            }
                            if (!$chosen) {
                                throw new MissingAssetSelectionException(
                                    'Scan or select which ' . $l['item_name_snapshot'] .
                                    ($l['is_borrowable'] ? ' unit' : ' lot') . ' you\'re handing over before releasing.'
                                );
                            }
                            // The dropdown value alone proves nothing — anyone
                            // could POST an asset id without ever scanning it.
                            // Require the exact single-use token that
                            // asset_scan_verify.php only ever issues after a
                            // real scan decoded THIS asset for THIS line, and
                            // spend it now so it can't be reused elsewhere.
                            $verify_token = trim($_POST['asset_verify_token'][$l['id']] ?? '');
                            if (!consume_asset_scan_verification($pdo, $verify_token, (int)$l['id'], (int)$chosen['id'])) {
                                throw new MissingAssetSelectionException(
                                    'Scan ' . $l['item_name_snapshot'] . ($l['is_borrowable'] ? ' unit' : ' lot') .
                                    ' again before releasing — the previous scan wasn\'t verified or has expired.'
                                );
                            }
                            if (!$l['is_borrowable'] && (int)$chosen['quantity'] < (int)$l['quantity_requested']) {
                                throw new MissingAssetSelectionException(
                                    'The selected lot for ' . $l['item_name_snapshot'] . ' only has ' . (int)$chosen['quantity'] .
                                    ' ' . $l['unit_snapshot'] . ' left — not enough for the ' . (int)$l['quantity_requested'] .
                                    ' requested. Choose a different lot.'
                                );
                            }
                        }

                        if ($l['is_borrowable']) {
                            $days = (int)($l['requested_days'] ?? 3);
                            // Business days only — Sat/Sun are never
                            // counted toward the borrow period, and the
                            // due date itself never lands on a weekend.
                            $due_date = add_business_days(date('Y-m-d'), $days);
                            $claimed_asset_id = $chosen ? (int)$chosen['id'] : null;

                            $init_cond = trim($_POST['initial_condition'][$l['id']] ?? ($_POST['initial_condition'] ?? 'good'));
                            $loan_stmt = $pdo->prepare(
                                'INSERT INTO tool_loans (requisition_id, requisition_item_id, item_id, asset_id, borrower_id, quantity, initial_condition, due_date)
                                 VALUES (:rid, :riid, :iid, :asset_id, :borrower, :qty, :ic, :due_date)'
                            );
                            $loan_stmt->execute([
                                'rid'      => $target['id'],
                                'riid'     => $l['id'],
                                'iid'      => $l['item_id'],
                                'asset_id' => $claimed_asset_id,
                                'borrower' => $target['requester_id'],
                                'qty'      => $l['quantity_requested'],
                                'ic'       => $init_cond,
                                'due_date' => $due_date,
                            ]);

                            if ($claimed_asset_id !== null) {
                                checkout_asset(
                                    $pdo, $claimed_asset_id, (int)$target['requester_id'], $user,
                                    'Released via requisition #' . $target['id'] . '.',
                                    'requisition', (int)$target['id']
                                );
                            }
                        } elseif ($chosen) {
                            // Consumable line with a scanned/selected lot:
                            // shrink that specific lot's tracked quantity
                            // (separate from the aggregate stock ledger
                            // already updated above by record_stock_movement).
                            record_asset_consumption(
                                $pdo, (int)$chosen['id'], (int)$l['quantity_requested'], $user,
                                'Used for requisition #' . $target['id'] . '.',
                                'requisition', (int)$target['id']
                            );
                        }
                    }
                }

                $audit_desc = $user['full_name'] . ' released request #' . $target['id'] . ' to the requester.';
                if ($defective_surrendered) {
                    $audit_desc .= ' Palit-Piyesa verified: old/defective part surrendered' . ($defective_note ? ' (' . $defective_note . ')' : '') . '.';
                }
                log_audit_event($pdo, $user, 'requisition_release', 'requisition', (int)$target['id'], $audit_desc);

                $pdo->commit();

                // Only alert now that the release has actually committed —
                // a rollback above would otherwise leave a false alert sent.
                foreach ($alert_checks as $check) {
                    maybe_alert_stock_threshold(
                        $check['item_id'], $check['before'], $check['after'],
                        $check['name'], $check['unit'],
                        $check['variant_before'] ?? null,
                        $check['variant_after'] ?? null,
                        $check['variant_value'] ?? null
                    );
                    resolve_stock_alert_notifications($pdo, $check['item_id']);
                }

                notify_user(
                    (int)$target['requester_id'],
                    'Your request #' . $target['id'] . ' has been released. Enjoy your tools!',
                    BASE_URL . '/requisition/view.php?id=' . $target['id']
                );

                require_once __DIR__ . '/../includes/sms.php';
                notify_user_sms(
                    (int)$target['requester_id'],
                    'DuaRTE: Your Requisition #' . $target['id'] . ' items have been RELEASED by warehouse staff.'
                );

                header('Location: ' . BASE_URL . '/inventory/verify.php?released=' . $target['id']);
                exit;
                    }
                }
            }
        } catch (InsufficientStockException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Cannot release — stock on hand has changed since approval and is no longer enough. Contact the requester or a supervisor.';
            } catch (MissingAssetSelectionException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = clean_error_message($e);
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = clean_error_message($e);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $errors[] = 'Something went wrong releasing this request. Please try again.';
            }
        }
    }
}

// Lookup for display — always runs when there's a token, whether this
// was a GET (scanned/typed) or a POST that just failed above, so the
// requisition card (with requester name and items) reliably shows
// either way instead of relying on partial data from the POST branch.
if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT r.*, u.full_name AS requester_name, u.employee_id
         FROM requisitions r JOIN users u ON u.id = r.requester_id
         WHERE r.qr_token = :t"
    );
    $stmt->execute(['t' => $token]);
    $req = $stmt->fetch();

    if (!$req) {
        if (!$errors) {
            $errors[] = 'This QR code does not match any request.';
        }
    } else {
        $lines = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id');
        $lines->execute(['id' => $req['id']]);
        $items = $lines->fetchAll();

        // For EVERY line still awaiting release — borrowable tool or
        // plain consumable — pull the QR-tagged units/lots currently
        // available so staff must scan or pick the exact physical
        // thing being handed over before confirming, instead of the
        // system silently assuming the paperwork matches what's on
        // the shelf. If nothing has been QR-tagged for an item yet,
        // available_assets comes back empty and that line falls back
        // to releasing without a link (there's nothing to scan).
        if ($req['status'] === 'approved') {
            foreach ($items as &$it) {
                $it_variant_id = null;
                if (!empty($it['variant_selected']) && $it['item_id'] !== null) {
                    $v = find_item_variant($pdo, (int)$it['item_id'], $it['variant_selected']);
                    $it_variant_id = $v ? $v['id'] : null;
                }
                $it['available_assets'] = $it['item_id'] !== null
                    ? get_available_assets_for_item($pdo, (int)$it['item_id'], $it_variant_id)
                    : [];
            }
            unset($it);
        }

        if ($req['status'] === 'released') {
            $notices[] = 'This request was already released' .
                ($req['released_at'] ? ' on ' . date('M j, Y g:i A', strtotime($req['released_at'])) : '') .
                '. Scanning it again does not release it a second time.';
        } elseif ($req['status'] === 'approved') {
            if ((int)$req['requester_id'] === (int)$user['id']) {
                $sod_conflict = 'Segregation of Duties Violation: You submitted this requisition. You cannot release your own items — another warehouse staff member must verify and dispense this order.';
            } elseif ($req['decided_by'] !== null && (int)$req['decided_by'] === (int)$user['id']) {
                $sod_conflict = 'Segregation of Duties Violation: You approved this requisition. Separation of authority requires an independent warehouse staff member to physically verify and release the items.';
            }
        }
    }
}

$page_title = 'Verify & Release';

// Fills the space next to the scanner with something actually useful
// instead of leaving it empty when nothing has been scanned yet.
$recent_releases = $pdo->query(
    "SELECT r.id, r.released_at, u.full_name AS requester_name
     FROM requisitions r JOIN users u ON u.id = r.requester_id
     WHERE r.status = 'released'
     ORDER BY r.released_at DESC LIMIT 9"
)->fetchAll();

$awaiting_release_count = count_awaiting_release($pdo);
$released_today_count = (int)$pdo->query(
    "SELECT COUNT(*) c FROM requisitions WHERE status = 'released' AND DATE(released_at) = CURDATE()"
)->fetch()['c'];
$released_week_count = (int)$pdo->query(
    "SELECT COUNT(*) c FROM requisitions WHERE status = 'released' AND released_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
)->fetch()['c'];

require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">QR code verification</div>
    <h1>Verify &amp; Release</h1>
  </div>
</div>

<?php if (!empty($_GET['released'])): ?>
  <div class="alert alert-success">Request #<?= (int)$_GET['released'] ?> released and stock updated.</div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $notice): ?>
  <div class="alert alert-warning">⚠ <?= htmlspecialchars($notice) ?></div>
<?php endforeach; ?>

<div class="stat-grid-v2">
  <div class="stat-card-v2<?= $awaiting_release_count > 0 ? ' is-warn' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('clock') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Awaiting release</div>
      <div class="stat-card-v2-value<?= $awaiting_release_count > 0 ? ' is-warn' : '' ?>"><?= $awaiting_release_count ?></div>
    </div>
  </div>
  <div class="stat-card-v2 is-ok">
    <div class="stat-card-v2-icon"><?= icon_svg('package-check') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Released today</div>
      <div class="stat-card-v2-value"><?= $released_today_count ?></div>
    </div>
  </div>
  <div class="stat-card-v2">
    <div class="stat-card-v2-icon"><?= icon_svg('check-circle') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Released this week</div>
      <div class="stat-card-v2-value"><?= $released_week_count ?></div>
    </div>
  </div>
</div>

<div class="two-col-cards verify-grid">
  <div class="card verify-lookup-card">
    <h2 class="card-heading">Scan or lookup request</h2>
    <form method="get" class="verify-lookup-form">
      <input aria-label="QR token" type="text" name="token" placeholder="Scan or enter QR token" class="flex-1" value="<?= htmlspecialchars($token) ?>">
      <button type="submit" class="btn btn-outline">Look up</button>
    </form>
  </div>

<?php if ($req): ?>
  <div class="card">
    <h2 class="card-heading">Request #<?= $req['id'] ?></h2>
    <span class="badge <?= requisition_status_class($req['status']) ?>"><?= htmlspecialchars((string)($req['status'] ?? '')) ?></span>
    <div style="margin-top:0.75rem;">
      <div class="eyebrow meta-mono">Requester</div>
      <div><?= htmlspecialchars((string)($req['requester_name'] ?? '')) ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($req['employee_id'] ?? '')) ?>)</span></div>
    </div>

    <?php if ($items): ?>
      <?php if ($req['status'] === 'approved'): ?>
      <form method="post" id="releaseForm" onsubmit="return confirm('Release these items to the requester now?');">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars((string)($req['qr_token'] ?? '')) ?>">
      <?php endif; ?>
      <div class="table-responsive">
<table class="data mt-1">
        <thead><tr><th>Item</th><th>Quantity</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td data-label="Item"><?= htmlspecialchars((string)($it['item_name_snapshot'] ?? '')) ?><?php if (!empty($it['variant_selected'])): ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($it['variant_selected'] ?? '')) ?>)</span><?php endif; ?></td>
              <td class="mono" data-label="Quantity"><?= (int)$it['quantity_requested'] ?> <?= htmlspecialchars((string)($it['unit_snapshot'] ?? '')) ?></td>
            </tr>
            <?php if ($req['status'] === 'approved' && $it['item_id'] !== null): ?>
              <?php
                // Borrowable tools: one QR per physical unit, quantity
                // is always 1, so any available unit qualifies.
                // Consumable lots: one QR per box/container, quantity
                // is how much is left in it — a lot with less than
                // what's requested can't fully cover this line, so
                // it's shown but disabled rather than hidden (staff
                // can see it exists, just not enough of it here).
                $needs_qty = (int)$it['quantity_requested'];
              ?>
              <tr>
                <td colspan="2" style="padding-top:0; padding-bottom:0.9rem;">
                  <?php if ($it['available_assets']): ?>
                    <div class="asset-pick-row" data-req-item="<?= $it['id'] ?>" data-needs-qty="<?= $needs_qty ?>" data-verified="0" style="display:flex; gap:0.5rem; align-items:flex-start; flex-wrap:wrap;">
                      <input type="hidden" name="asset_verify_token[<?= $it['id'] ?>]" class="asset-verify-token-input" value="">
                      <select aria-label="Scanned asset unit" name="asset_choice[<?= $it['id'] ?>]" class="asset-pick-select" required style="flex:1; min-width:220px;">
                        <option value="">— Not scanned yet —</option>
                        <?php foreach ($it['available_assets'] as $a): ?>
                          <?php $lot_short = !$it['is_borrowable'] && (int)$a['quantity'] < $needs_qty; ?>
                          <option value="<?= $a['id'] ?>" data-tag="<?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?>" <?= $lot_short ? 'disabled' : '' ?>>
                            <?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?><?php if (!$it['is_borrowable']): ?> — <?= (int)$a['quantity'] ?> <?= htmlspecialchars((string)($it['unit_snapshot'] ?? '')) ?> left<?php endif; ?><?php if ($a['location_note']): ?> — <?= htmlspecialchars((string)($a['location_note'] ?? '')) ?><?php else: ?> — location not set<?php endif; ?><?= $lot_short ? ' (not enough for this request)' : '' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="button" class="btn btn-outline btn-sm asset-pick-scan-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-3px; margin-right:0.35rem;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Scan <?= $it['is_borrowable'] ? 'unit' : 'lot' ?>
                      </button>
                      <span class="asset-pick-error" style="display:none; color:var(--red-danger); font-size:0.78rem; width:100%;"></span>
                      <span class="asset-pick-status" style="font-size:0.78rem; color:var(--ink-soft); width:100%;">Awaiting QR scan</span>
                      <span class="qr-focus-hint-short" style="font-size:0.72rem; color:var(--ink-soft); width:100%;"></span>
                    </div>
                  <?php else: ?>
                    <span class="badge" style="font-size:0.75rem; background:var(--surface-subtle); color:var(--ink-soft); border:1px solid var(--line);">Untagged item &middot; Manual release</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
</div>
    <?php endif; ?>

    <?php if ($req['status'] === 'approved'): ?>
        <!-- Palit-Piyesa (1-to-1 Defective Part Exchange) Confirmation -->
        <div class="card" style="margin-top:1.2rem; margin-bottom:1rem; padding:1rem; background:var(--surface-subtle); border:1px solid var(--line); border-radius:8px;">
          <div style="font-weight:700; font-size:0.92rem; margin-bottom:0.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
            <span style="display:inline-flex; align-items:center; gap:0.35rem;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              Palit-Piyesa Verification (1-to-1 Part Exchange)
            </span>
            <?php if (!empty($req['truck_plate_snapshot'])): ?>
              <?= truck_plate_badge($req['truck_plate_snapshot']) ?>
            <?php endif; ?>
          </div>

          <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer; font-weight:600; font-size:0.88rem; margin-bottom:0.5rem;">
            <input type="checkbox" name="defective_part_surrendered" value="1" id="defectivePartCheckbox" style="width:1.15rem; height:1.15rem; margin-top:0.1rem; accent-color:var(--green-ok);">
            <span>Old part surrendered</span>
          </label>

          <div class="form-group" style="margin-bottom:0; margin-top:0.4rem;">
            <input aria-label="Condition remarks" type="text" id="defective_part_note" name="defective_part_note" placeholder="Remarks (optional)" style="width:100%; padding:0.45rem 0.6rem; font-size:0.85rem; border:1px solid var(--line); border-radius:4px; font-family:var(--font-body); background:var(--surface);">
          </div>
        </div>

        <!-- Two-Way Driver Handshake Protocol (Physical Custody Confirmation) -->
        <div class="card" style="margin-top:1rem; margin-bottom:1rem; padding:1rem; background:var(--surface-subtle); border:1px solid var(--line); border-radius:8px;">
          <div style="font-weight:700; font-size:0.92rem; margin-bottom:0.35rem; display:flex; align-items:center; gap:0.4rem;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="color:var(--amber);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Driver Verification
          </div>
          <p style="font-size:0.82rem; color:var(--ink-soft); margin-top:0; margin-bottom:0.6rem;">
            <strong><?= htmlspecialchars((string)($req['requester_name'] ?? 'Driver')) ?>'s</strong> PIN or override:
          </p>
          <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
            <div style="width:160px;">
              <input aria-label="Driver PIN" type="password" name="driver_pin" maxlength="32" placeholder="4-Digit PIN" autocomplete="off" style="width:100%; text-align:center; letter-spacing:0.2em; font-weight:700; font-family:var(--font-mono); font-size:1.1rem; padding:0.45rem 0.6rem; border:1px solid var(--line); border-radius:6px; background:var(--surface);">
            </div>
            <div style="flex:1; min-width:200px;">
              <input aria-label="Override reason" type="text" name="override_reason" placeholder="Override reason" style="width:100%; font-size:0.85rem; padding:0.45rem 0.6rem; border:1px solid var(--line); border-radius:6px; background:var(--surface);">
            </div>
          </div>
        </div>

        <?php if (!empty($sod_conflict)): ?>
          <div class="alert alert-error" style="margin-top:1rem; font-weight:600; display:flex; align-items:center; gap:0.5rem;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <div><?= htmlspecialchars($sod_conflict) ?></div>
          </div>
          <button type="button" class="btn btn-primary mt-1" disabled style="opacity:0.5; cursor:not-allowed;" title="Release blocked by Segregation of Duties">Confirm release (Blocked by SoD)</button>
        <?php else: ?>
          <button type="submit" name="confirm_release" value="1" class="btn btn-primary mt-1" id="confirmReleaseBtn">Confirm release</button>
        <?php endif; ?>
      </form>
    <?php elseif ($req['status'] === 'released'): ?>
      <div class="alert alert-warning" style="margin-top:1rem; margin-bottom:0; font-weight:600;">
        ✓ Completed — already released<?php if ($req['released_at']): ?> on <?= date('M j, Y g:i A', strtotime($req['released_at'])) ?><?php endif; ?>.
        <?php if (!empty($req['defective_part_surrendered'])): ?>
          <div style="font-weight:500; font-size:0.85rem; color:var(--green-ok); margin-top:0.35rem;">
            🔄 Palit-Piyesa: Old/defective part was surrendered and verified<?= $req['defective_part_note'] ? ' (' . htmlspecialchars((string)($req['defective_part_note'] ?? '')) . ')' : '' ?>.
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p style="margin-top:1rem; color:var(--ink-soft);">Not awaiting release.</p>
    <?php endif; ?>

    <div style="margin-top:0.75rem;">
      <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $req['id'] ?>" class="btn btn-outline btn-sm">Full details</a>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="section-head">
      <h2>Recently released</h2>
    </div>
    <?php if (!$recent_releases): ?>
      <div class="empty-state-mini">
        <?= icon_svg('inbox') ?>
        <div>Nothing released yet.</div>
      </div>
    <?php else: ?>
      <div class="release-log">
        <?php foreach ($recent_releases as $r): ?>
          <div class="release-log-row">
            <div class="release-log-dot"><?= icon_svg('check-circle') ?></div>
            <div class="release-log-body">
              <span class="release-log-id">#<?= $r['id'] ?></span>
              <span class="release-log-name"><?= htmlspecialchars((string)($r['requester_name'] ?? '')) ?></span>
            </div>
            <span class="release-log-time"><?= $r['released_at'] ? date('M j, g:i A', strtotime($r['released_at'])) : '' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>

<script src="<?= BASE_URL ?>/assets/js/html5-qrcode.min.js"></script>
<script>
  if (typeof Html5Qrcode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"><\/script>');
  }
</script>
<script src="<?= BASE_URL ?>/assets/js/qr-scan-shared.js"></script>
<script>
  // The main "Scan the request's QR code" camera was removed — staff
  // paste the requester's token into the box above instead. The
  // per-item asset scanner below (right side, once a request is
  // loaded) still needs the camera library and DuarteQR helpers,
  // including the focus hint it fills in at .qr-focus-hint-short.
  DuarteQR.injectFocusHints();
</script>

<script>
  // Per-unit asset picking on the release screen: staff must scan or
  // choose the SPECIFIC tagged unit being handed over for each
  // borrowable line before "Confirm release" is enabled — the camera
  // freed above (main scanner is paused while a request is loaded) is
  // reused here instead of running two camera streams at once.
  (function () {
    var pickRows = document.querySelectorAll('.asset-pick-row');
    var confirmBtn = document.getElementById('confirmReleaseBtn');

    function syncConfirmButton() {
      if (!confirmBtn) return;
      var ok = true;
      pickRows.forEach(function (row) {
        var select = row.querySelector('.asset-pick-select');
        // A filled value alone isn't enough — it must have been set by an
        // actual successful scan (row.dataset.verified), not just present
        // in the dropdown. This is what stops release from being confirmed
        // on an item that was never really scanned.
        if (select && (!select.value || row.dataset.verified !== '1')) ok = false;
      });
      confirmBtn.disabled = !ok;
    }

    pickRows.forEach(function (row) {
      var select = row.querySelector('.asset-pick-select');
      var statusEl = row.querySelector('.asset-pick-status');
      if (!select) return;
      // The dropdown is scan-only: it only ever gets a value from onDecoded()
      // below (which sets .value directly and does NOT fire 'change'). Any
      // 'change' event here means a person clicked/picked it manually — undo
      // that immediately instead of letting it count as verified.
      select.addEventListener('change', function () {
        select.value = '';
        row.dataset.verified = '0';
        var tokenInput = row.querySelector('.asset-verify-token-input');
        if (tokenInput) tokenInput.value = '';
        if (statusEl) {
          statusEl.textContent = 'Manual selection isn\'t allowed for this — scan the QR code on the actual unit/lot.';
          statusEl.style.color = 'var(--red-danger)';
        }
        syncConfirmButton();
      });
    });
    syncConfirmButton();

    if (!pickRows.length || typeof Html5Qrcode === 'undefined') return;

    var READER_ID = 'asset-scan-reader';
    var assetScanner = null;
    var activeSelect = null;
    var activeErrorEl = null;
    var activeRow = null;
    var activeStatusEl = null;

    var csrfInput = document.querySelector('#releaseForm input[name="csrf_token"]');

    function stopScanner(done) {
      if (!assetScanner) { if (done) done(); return; }
      assetScanner.stop().then(function () {
        assetScanner.clear();
        assetScanner = null;
        if (done) done();
      }).catch(function () {
        assetScanner = null;
        if (done) done();
      });
    }

    function setUnverified(row, select, statusEl, message) {
      row.dataset.verified = '0';
      select.value = '';
      var tokenInput = row.querySelector('.asset-verify-token-input');
      if (tokenInput) tokenInput.value = '';
      if (statusEl) {
        statusEl.textContent = message || 'Not scanned yet — the dropdown is filled automatically once you scan the QR code, it can\'t be picked by hand.';
        statusEl.style.color = message ? 'var(--red-danger)' : 'var(--ink-soft)';
      }
    }

    // A locally-matching tag is only a quick "wrong item?" hint — the
    // row isn't flipped to verified, and no token is stored, until the
    // server confirms the scan and hands back a single-use token that
    // the release handler will actually require. This is what makes
    // "must scan, can't pick by hand" a real server-side rule instead
    // of just a client-side convention someone could bypass by posting
    // the form directly.
    function onDecoded(decodedText) {
      var tag = DuarteQR.extractParam(decodedText, 'tag');
      if (tag) tag = tag.trim().toUpperCase();
      var readerEl = document.getElementById(READER_ID);
      var row = activeRow, select = activeSelect, errorEl = activeErrorEl, statusEl = activeStatusEl;
      if (!row || !select) { stopScanner(function () { if (readerEl) readerEl.remove(); }); return; }

      var localOpt = null;
      for (var i = 0; i < select.options.length; i++) {
        if ((select.options[i].dataset.tag || '').toUpperCase() === tag) { localOpt = select.options[i]; break; }
      }
      if (!localOpt) {
        DuarteQR.playErrorFeedback();
        if (errorEl) {
          errorEl.textContent = 'That scanned tag isn\'t an available match for this item — wrong item, or it\'s already checked out/used up.';
          errorEl.style.display = 'block';
        }
        setUnverified(row, select, statusEl);
        syncConfirmButton();
        stopScanner(function () { if (readerEl) readerEl.remove(); });
        return;
      }
      if (localOpt.disabled) {
        DuarteQR.playErrorFeedback();
        if (errorEl) {
          errorEl.textContent = 'That lot doesn\'t have enough left for this request — scan a different lot.';
          errorEl.style.display = 'block';
        }
        setUnverified(row, select, statusEl);
        syncConfirmButton();
        stopScanner(function () { if (readerEl) readerEl.remove(); });
        return;
      }

      if (statusEl) {
        statusEl.textContent = 'Checking scan…';
        statusEl.style.color = 'var(--ink-soft)';
      }

      var body = new URLSearchParams();
      body.set('csrf_token', csrfInput ? csrfInput.value : '');
      body.set('requisition_item_id', row.dataset.reqItem);
      body.set('tag', tag);

      fetch('<?= BASE_URL ?>/inventory/asset_scan_verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (resp) { return resp.json(); }).then(function (data) {
        if (!data.success) {
          DuarteQR.playErrorFeedback();
          if (errorEl) { errorEl.textContent = data.error || 'That scan could not be verified.'; errorEl.style.display = 'block'; }
          setUnverified(row, select, statusEl);
          syncConfirmButton();
          return;
        }
        DuarteQR.playSuccessFeedback();
        select.value = String(data.asset_id);
        var tokenInput = row.querySelector('.asset-verify-token-input');
        if (tokenInput) tokenInput.value = data.token;
        row.dataset.verified = '1';
        if (errorEl) errorEl.style.display = 'none';
        if (statusEl) {
          statusEl.textContent = '✓ Verified via scan.';
          statusEl.style.color = 'var(--green-ok)';
        }
        syncConfirmButton();
      }).catch(function () {
        DuarteQR.playErrorFeedback();
        if (errorEl) { errorEl.textContent = 'Could not reach the server to verify this scan. Check your connection and try again.'; errorEl.style.display = 'block'; }
        setUnverified(row, select, statusEl);
        syncConfirmButton();
      }).finally(function () {
        stopScanner(function () { if (readerEl) readerEl.remove(); });
      });
    }

    pickRows.forEach(function (row) {
      var btn = row.querySelector('.asset-pick-scan-btn');
      var select = row.querySelector('.asset-pick-select');
      var errorEl = row.querySelector('.asset-pick-error');
      var statusEl = row.querySelector('.asset-pick-status');
      if (!btn) return;

      btn.addEventListener('click', function () {
        stopScanner(function () {
          var oldReader = document.getElementById(READER_ID);
          if (oldReader) oldReader.remove();

          activeSelect = select;
          activeErrorEl = errorEl;
          activeRow = row;
          activeStatusEl = statusEl;

          var readerDiv = document.createElement('div');
          readerDiv.id = READER_ID;
          readerDiv.style.cssText = 'max-width:260px; margin:0.5rem 0; width:100%;';
          row.appendChild(readerDiv);

          if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !DuarteQR.isSecureContext()) {
            errorEl.textContent = 'Camera access requires a secure connection or device permission. Enter the tag manually.';
            errorEl.style.display = 'block';
            readerDiv.remove();
            return;
          }

          assetScanner = new Html5Qrcode(READER_ID);
          DuarteQR.startWithFallback(assetScanner, DuarteQR.buildScanConfig(200), onDecoded, {
            onNoCamera: function () {
              errorEl.textContent = 'No camera was found on this device — try a different device to scan this.';
              errorEl.style.display = 'block';
              readerDiv.remove();
            },
            onError: function (err2) {
              errorEl.textContent = 'Could not start the camera (' + (err2 && err2.name ? err2.name : 'permission denied or in use') +
                ') — try a different device to scan this.';
              errorEl.style.display = 'block';
              readerDiv.remove();
            },
            onDenied: function () {
              errorEl.textContent = 'Camera permission was denied or is unavailable — check your browser AND your OS camera privacy setting, or try a different device to scan this.';
              errorEl.style.display = 'block';
              readerDiv.remove();
            }
          });
        });
      });
    });
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
