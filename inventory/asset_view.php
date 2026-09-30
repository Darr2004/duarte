<?php
/**
 * DuaRTE — QR Code Asset Tracking: Asset detail / scan-landing page.
 * This is where scanning a physical asset's tag lands — any time, not
 * just at checkout/release. Shows the unit's (or lot's) current status
 * and full history.
 *
 * For a per-unit (borrowable) asset, offers whichever action makes
 * sense given its current state: manual checkout, check-in, transfer,
 * damage report, maintenance complete, retire.
 *
 * For a per-lot (consumable) asset, offers "Use from this lot"
 * (record_asset_consumption(), shrinks quantity, auto-retires at 0),
 * plus Move and Retire — checkout/check-in/damage/maintenance don't
 * apply to a lot the way they do a tool.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$tag = trim($_GET['tag'] ?? $_POST['tag'] ?? '');
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// Same defensive parsing verify.php uses for its QR tokens — if
// someone pastes the full scanned URL instead of the bare tag.
if ($tag !== '' && preg_match('/[?&]tag=([^&]+)/', $tag, $m)) {
    $tag = urldecode($m[1]);
}

function load_asset(PDO $pdo, string $tag, int $id): ?array
{
    if ($id > 0) {
        $stmt = $pdo->prepare(
            "SELECT a.*, i.name AS item_name, i.item_code, i.unit, i.borrow_mode, i.quantity_on_hand AS item_quantity_on_hand,
                    iv.quantity_on_hand AS variant_quantity_on_hand,
                    s_cat.stall_number AS catalog_stall_number, sl_cat.layer_name AS catalog_layer_name,
                    COALESCE(iv.image_filename, i.image_filename) AS image_filename,
                    iv.variant_value, iv.variant_note,
                    h.full_name AS holder_name, h.employee_id AS holder_employee_id
             FROM assets a JOIN items i ON i.id = a.item_id
             LEFT JOIN stall_layers sl_cat ON sl_cat.id = i.stall_layer_id
             LEFT JOIN stalls s_cat ON s_cat.id = sl_cat.stall_id
             LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
             LEFT JOIN users h ON h.id = a.current_holder_id
             WHERE a.id = :id"
        );
        $stmt->execute(['id' => $id]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT a.*, i.name AS item_name, i.item_code, i.unit, i.borrow_mode, i.quantity_on_hand AS item_quantity_on_hand,
                    iv.quantity_on_hand AS variant_quantity_on_hand,
                    s_cat.stall_number AS catalog_stall_number, sl_cat.layer_name AS catalog_layer_name,
                    COALESCE(iv.image_filename, i.image_filename) AS image_filename,
                    iv.variant_value, iv.variant_note,
                    h.full_name AS holder_name, h.employee_id AS holder_employee_id
             FROM assets a JOIN items i ON i.id = a.item_id
             LEFT JOIN stall_layers sl_cat ON sl_cat.id = i.stall_layer_id
             LEFT JOIN stalls s_cat ON s_cat.id = sl_cat.stall_id
             LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
             LEFT JOIN users h ON h.id = a.current_holder_id
             WHERE a.asset_tag = :tag"
        );
        $stmt->execute(['tag' => $tag]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

$asset = load_asset($pdo, $tag, $id);

if (!$asset) {
    $page_title = 'Asset Not Found';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="page-header"><div><div class="eyebrow">QR code asset tracking</div><h1>Asset not found</h1></div></div>
    <div class="alert alert-error">This QR tag does not match any registered asset.</div>
    <a href="<?= BASE_URL ?>/inventory/assets.php" class="btn btn-outline">Back to Assets</a>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$asset_id = (int)$asset['id'];
$is_lot = !borrow_mode_is_borrowable($asset['borrow_mode']);

// ---- Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['do'] ?? '';

        try {
            if ($action === 'consume' && $is_lot && $asset['status'] === 'available') {
                $amount = (int)($_POST['amount'] ?? 0);
                if ($amount < 1) {
                    $errors[] = 'Enter how many to use from this lot.';
                } elseif ($amount > (int)$asset['quantity']) {
                    $errors[] = 'Only ' . (int)$asset['quantity'] . ' remain in this lot.';
                } else {
                    $on_hand = $asset['item_variant_id'] !== null
                        ? (int)($asset['variant_quantity_on_hand'] ?? 0)
                        : (int)$asset['item_quantity_on_hand'];
                    $target_qty = $on_hand - $amount;
                    $safety = verify_stock_reduction_safety(
                        $pdo,
                        (int)$asset['item_id'],
                        $target_qty,
                        $asset['item_variant_id'] !== null ? (int)$asset['item_variant_id'] : null
                    );
                    if (!$safety['safe']) {
                        $errors[] = $safety['message'];
                    } else {
                        $pdo->beginTransaction();
                        record_asset_consumption($pdo, $asset_id, $amount, $user, 'Manual use (no requisition).');
                        $stock_result = record_stock_movement(
                            $pdo,
                            (int)$asset['item_id'],
                            'release',
                            -1 * $amount,
                            'asset_consume',
                            $asset_id,
                            $user['id'],
                            'Manual consumption of ' . $amount . ' from lot ' . $asset['asset_tag'] . ' (' . $asset['item_name'] . ').',
                            $asset['item_variant_id'] !== null ? (int)$asset['item_variant_id'] : null
                        );
                        log_audit_event($pdo, $user, 'asset_consume', 'asset', $asset_id,
                            $user['full_name'] . ' used ' . $amount . ' from lot ' . $asset['asset_tag'] . ' (' . $asset['item_name'] . ').');
                        $pdo->commit();

                        maybe_alert_stock_threshold(
                            (int)$asset['item_id'], $stock_result['before'], $stock_result['after'],
                            $stock_result['name'], $stock_result['unit'],
                            $stock_result['variant_before'] ?? null,
                            $stock_result['variant_after'] ?? null,
                            $stock_result['variant_value'] ?? null
                        );
                        resolve_stock_alert_notifications($pdo, (int)$asset['item_id']);

                        $remaining = (int)$asset['quantity'] - $amount;
                        $flash_success = $remaining > 0
                            ? 'Used ' . $amount . '. ' . $remaining . ' remaining in this lot.'
                            : 'Used ' . $amount . '. Lot is now empty and retired.';
                    }
                }
            } elseif ($action === 'checkout' && $asset['status'] === 'available') {
                $holder_id = (int)($_POST['holder_id'] ?? 0);
                $days = (int)($_POST['due_days'] ?? 3);
                $days = max(1, min(30, $days));
                $holder_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = :id AND status = 'active'");
                $holder_stmt->execute(['id' => $holder_id]);
                $holder = $holder_stmt->fetch();
                if (!$holder) {
                    $errors[] = 'Choose a valid, active person to check this out to.';
                } elseif (user_has_overdue_loans($pdo, $holder_id)) {
                    // Same rule the online requisition flow enforces —
                    // a direct handout shouldn't be a way around it.
                    $errors[] = $holder['full_name'] . ' has an overdue tool loan and needs to return it before borrowing anything else.';
                } else {
                    $checkout_qty = (int)$asset['quantity'];
                    $on_hand = $asset['item_variant_id'] !== null
                        ? (int)($asset['variant_quantity_on_hand'] ?? 0)
                        : (int)$asset['item_quantity_on_hand'];
                    $target_qty = $on_hand - $checkout_qty;
                    $safety = verify_stock_reduction_safety(
                        $pdo,
                        (int)$asset['item_id'],
                        $target_qty,
                        $asset['item_variant_id'] !== null ? (int)$asset['item_variant_id'] : null
                    );
                    if (!$safety['safe']) {
                        $errors[] = $safety['message'];
                    } else {
                        $pdo->beginTransaction();
                        checkout_asset($pdo, $asset_id, $holder_id, $user, 'Manual checkout (no requisition) to ' . $holder['full_name'] . '.');
                        // Gets the exact same due-date + overdue tracking as a
                        // requisition-released loan — see record_direct_checkout_loan().
                        record_direct_checkout_loan($pdo, $asset, $holder_id, $days);
                        // A direct checkout is still stock leaving the building —
                        // it must move quantity_on_hand exactly the way a
                        // requisition release does (record_stock_movement()),
                        // or the catalog keeps counting this unit as available
                        // and a requisition can be approved against a unit
                        // that's already out with someone else. Mirrored on
                        // return in return_tool_loan().
                        $stock_result = record_stock_movement(
                            $pdo,
                            (int)$asset['item_id'],
                            'release',
                            -1 * (int)$asset['quantity'],
                            'direct_checkout',
                            $asset_id,
                            $user['id'],
                            'Manual checkout of asset ' . $asset['asset_tag'] . ' to ' . $holder['full_name'] . '.',
                            $asset['item_variant_id'] !== null ? (int)$asset['item_variant_id'] : null
                        );
                        log_audit_event($pdo, $user, 'asset_checkout', 'asset', $asset_id,
                            $user['full_name'] . ' checked out asset ' . $asset['asset_tag'] . ' (' . $asset['item_name'] . ') to ' . $holder['full_name'] . '.');
                        $pdo->commit();
                        // Only alert once the release has actually committed —
                        // same rule every other stock-changing action follows.
                        maybe_alert_stock_threshold(
                            (int)$asset['item_id'], $stock_result['before'], $stock_result['after'],
                            $stock_result['name'], $stock_result['unit'],
                            $stock_result['variant_before'] ?? null,
                            $stock_result['variant_after'] ?? null,
                            $stock_result['variant_value'] ?? null
                        );
                        resolve_stock_alert_notifications($pdo, (int)$asset['item_id']);
                        $flash_success = 'Checked out to ' . $holder['full_name'] . '.';
                    }
                }
            } elseif ($action === 'checkin' && $asset['status'] === 'checked_out') {
                $damaged = !empty($_POST['damaged']);
                $condition_note = trim($_POST['condition_note'] ?? '') ?: null;

                // Close out whatever loan this unit is tied to — from a
                // requisition release or a direct checkout — so it stops
                // being "still borrowed" the moment the physical unit is
                // actually back, instead of sitting open and eventually
                // showing as overdue.
                $loan_stmt = $pdo->prepare(
                    'SELECT * FROM tool_loans WHERE asset_id = :id AND returned_at IS NULL ORDER BY borrowed_at DESC LIMIT 1'
                );
                $loan_stmt->execute(['id' => $asset_id]);
                $open_loan = $loan_stmt->fetch();

                $pdo->beginTransaction();
                $return_result = $open_loan ? return_tool_loan($pdo, $open_loan, $user) : null;
                checkin_asset($pdo, $asset_id, $user, $damaged, $condition_note);
                log_audit_event($pdo, $user, 'asset_checkin', 'asset', $asset_id,
                    $user['full_name'] . ' checked in asset ' . $asset['asset_tag'] . ' (' . $asset['item_name'] . ')' . ($damaged ? ' — flagged for maintenance.' : '.'));
                $pdo->commit();

                if ($return_result && $return_result['stock_restored']) {
                    maybe_alert_stock_threshold(
                        $return_result['item_id'], $return_result['before'], $return_result['after'],
                        $return_result['name'], $return_result['unit'],
                        $return_result['variant_before'] ?? null,
                        $return_result['variant_after'] ?? null,
                        $return_result['variant_value'] ?? null
                    );
                    resolve_stock_alert_notifications($pdo, $return_result['item_id']);
                }
                if ($return_result) {
                    notify_user(
                        $return_result['borrower_id'],
                        'Your returned ' . $return_result['name'] . ' has been checked in. Thanks!',
                        BASE_URL . '/requisition/my_loans.php'
                    );
                }

                $flash_success = $damaged ? 'Checked in and flagged for maintenance.' : 'Checked in — available again.';
            } elseif ($action === 'transfer' && in_array($asset['status'], ['available', 'under_maintenance'], true)) {
                $location = trim($_POST['location_note'] ?? '');
                if ($location !== '') {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE assets SET location_note = :loc WHERE id = :id')->execute(['loc' => $location, 'id' => $asset_id]);
                    record_asset_event($pdo, $asset_id, 'transferred', $user, 'Moved to: ' . $location . '.');
                    log_audit_event($pdo, $user, 'asset_transfer', 'asset', $asset_id,
                        $user['full_name'] . ' transferred asset ' . $asset['asset_tag'] . ' to ' . $location . '.');
                    $pdo->commit();
                    $flash_success = 'Location updated.';
                }
            } elseif ($action === 'report_damage' && $asset['status'] === 'available') {
                $note = trim($_POST['condition_note'] ?? '');
                if ($note === '') {
                    $errors[] = 'Describe the damage or issue.';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE assets SET status = 'under_maintenance', condition_note = :note WHERE id = :id")
                        ->execute(['note' => $note, 'id' => $asset_id]);
                    record_asset_event($pdo, $asset_id, 'damage_reported', $user, $note);
                    log_audit_event($pdo, $user, 'asset_damage_report', 'asset', $asset_id,
                        $user['full_name'] . ' flagged asset ' . $asset['asset_tag'] . ' for maintenance: ' . $note);
                    $pdo->commit();
                    $flash_success = 'Flagged for maintenance.';
                }
            } elseif ($action === 'mark_repaired' && $asset['status'] === 'under_maintenance') {
                $note = trim($_POST['condition_note'] ?? '') ?: null;
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE assets SET status = 'available', condition_note = :note WHERE id = :id")
                    ->execute(['note' => $note, 'id' => $asset_id]);
                record_asset_event($pdo, $asset_id, 'maintenance_completed', $user, $note ?? 'Repaired / cleared for use.');
                log_audit_event($pdo, $user, 'asset_maintenance_complete', 'asset', $asset_id,
                    $user['full_name'] . ' cleared asset ' . $asset['asset_tag'] . ' from maintenance.');
                $pdo->commit();
                $flash_success = 'Marked available again.';
            } elseif ($action === 'mark_found' && $asset['status'] === 'missing') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE assets SET status = 'available' WHERE id = :id")->execute(['id' => $asset_id]);
                record_asset_event($pdo, $asset_id, 'audit_confirmed', $user, 'Found — no longer missing.');
                log_audit_event($pdo, $user, 'asset_audit', 'asset', $asset_id,
                    $user['full_name'] . ' marked asset ' . $asset['asset_tag'] . ' as found (no longer missing).');
                $pdo->commit();
                $flash_success = 'Marked found — available again.';
            } elseif ($action === 'retire' && $asset['status'] !== 'retired') {
                $note = trim($_POST['condition_note'] ?? '') ?: null;
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE assets SET status = 'retired', current_holder_id = NULL, condition_note = :note WHERE id = :id")
                    ->execute(['note' => $note, 'id' => $asset_id]);
                record_asset_event($pdo, $asset_id, 'retired', $user, $note ?? 'Retired from service.');
                log_audit_event($pdo, $user, 'asset_retire', 'asset', $asset_id,
                    $user['full_name'] . ' retired asset ' . $asset['asset_tag'] . '.');
                $pdo->commit();
                $flash_success = 'Retired. It stays on record for history but is no longer available to check out.';
            } elseif ($action === 'delete_asset') {
                if ($asset['status'] === 'checked_out' || $asset['current_holder_id']) {
                    $errors[] = 'Cannot delete an asset while it is currently checked out to a borrower. Please check it in first.';
                } else {
                    $pdo->beginTransaction();
                    log_audit_event($pdo, $user, 'asset_delete', 'asset', $asset_id,
                        $user['full_name'] . ' permanently deleted asset ' . $asset['asset_tag'] . ' (' . $asset['item_name'] . ').');
                    $pdo->prepare('DELETE FROM assets WHERE id = :id')->execute(['id' => $asset_id]);
                    $pdo->commit();
                    header('Location: ' . BASE_URL . '/inventory/assets.php?deleted=1');
                    exit;
                }
            } else {
                $errors[] = 'That action is not available for this asset\'s current status.';
            }
        } catch (InsufficientStockException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Cannot check out — the catalog already shows this item at 0 on hand, so releasing it would take stock below zero. Reconcile the item\'s stock count before checking it out.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }

        if (!$errors) {
            if ($flash_success) {
                $_SESSION['flash_success'] = $flash_success;
            }
            header('Location: ' . BASE_URL . '/inventory/asset_view.php?id=' . $asset_id);
            exit;
        }
        // Refresh so the page reflects the new status even on error paths.
        $asset = load_asset($pdo, $tag, $asset_id);
    }
}

// Full history for this specific unit.
$history = $pdo->prepare(
    'SELECT * FROM asset_events WHERE asset_id = :id ORDER BY created_at DESC'
);
$history->execute(['id' => $asset_id]);
$history = $history->fetchAll();

// If checked out, find the loan (and requisition) this came from, if any.
$active_loan = null;
if ($asset['status'] === 'checked_out') {
    $stmt = $pdo->prepare(
        'SELECT tl.*, i.name AS item_name FROM tool_loans tl JOIN items i ON i.id = tl.item_id
         WHERE tl.asset_id = :id AND tl.returned_at IS NULL ORDER BY tl.borrowed_at DESC LIMIT 1'
    );
    $stmt->execute(['id' => $asset_id]);
    $active_loan = $stmt->fetch() ?: null;
}

// Candidate holders for a manual checkout — personnel who actually borrow tools.
$holders = $pdo->query(
    "SELECT id, full_name, employee_id FROM users WHERE role = 'driver_helper' AND status = 'active' ORDER BY full_name"
)->fetchAll();

// Stalls and layers for the "Transfer to" / Move dropdown selection.
$proper_locations = $pdo->query(
    "SELECT s.stall_number, sl.layer_name, CONCAT('Room ', r.room_number, ' · Stall ', s.stall_number, ' — ', sl.layer_name) AS label
     FROM stalls s
     JOIN rooms r ON r.id = s.room_id
     JOIN stall_layers sl ON sl.stall_id = s.id
     ORDER BY r.room_number, s.stall_number, sl.layer_number"
)->fetchAll();

$page_title = 'Asset ' . $asset['asset_tag'];
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= BASE_URL ?>/inventory/assets.php" class="back-link">&larr; Back to Assets</a>
<div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem;">
  <div>
    <div class="eyebrow">Asset Management</div>
    <h1 style="margin:0.15rem 0 0;"><?= htmlspecialchars((string)($asset['item_name'] ?? '')) ?><?php if ($asset['variant_value']): ?> — <?= htmlspecialchars((string)($asset['variant_value'] ?? '')) ?><?php endif; ?></h1>
  </div>
  <div style="display:flex; align-items:center; gap:0.65rem; flex-wrap:wrap;">
    <span class="badge <?= asset_status_class($asset['status']) ?>" style="font-size:0.82rem; padding:0.35rem 0.75rem; font-weight:700;"><?= asset_status_label($asset['status']) ?></span>
    <button type="button" class="btn btn-primary btn-sm" onclick="printAssetLabel()" style="display:inline-flex; align-items:center; gap:0.4rem; font-weight:600;">
      🖨️ Print QR Label
    </button>
  </div>
</div>

<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<style>
  .asset-profile-hero {
    display: flex;
    gap: 1.5rem;
    align-items: center;
  }
  .asset-hero-photo {
    width: 200px;
    flex-shrink: 0;
  }
  .asset-hero-details {
    flex: 1;
    min-width: 0;
  }
  .asset-photo-frame {
    width: 100%;
    aspect-ratio: 4 / 3;
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .asset-photo-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 6px;
    background: #ffffff;
    display: block;
  }
  .asset-photo-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    width: 100%;
    background: var(--surface-subtle);
    padding: 1rem;
    text-align: center;
  }
  .asset-photo-placeholder .placeholder-initials {
    color: var(--amber);
    font-family: var(--font-mono);
    font-size: 2.2rem;
    font-weight: 700;
    line-height: 1;
  }
  .asset-photo-placeholder .placeholder-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ink-soft);
    margin-top: 0.3rem;
  }
  .asset-clean-specs {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0.6rem 1.25rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--line-subtle);
  }
  .clean-spec-row {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
  }
  .cs-label {
    font-size: 0.72rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--ink-soft);
  }
  .cs-val {
    font-size: 0.9rem;
    color: var(--ink);
    font-weight: 600;
  }

  /* Minimalist Timeline for History */
  .asset-timeline {
    position: relative;
    padding-left: 1.25rem;
  }
  .asset-timeline::before {
    content: '';
    position: absolute;
    left: 4px;
    top: 6px;
    bottom: 12px;
    width: 2px;
    background: var(--line);
  }
  .timeline-entry {
    position: relative;
    padding-bottom: 0.9rem;
  }
  .timeline-entry:last-child {
    padding-bottom: 0;
  }
  .timeline-marker {
    position: absolute;
    left: -1.25rem;
    top: 4px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #ffffff;
    border: 2px solid var(--amber);
  }
  .timeline-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 0.5rem;
    flex-wrap: wrap;
  }
  .timeline-name {
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--ink);
  }
  .timeline-timestamp {
    font-size: 0.74rem;
    color: var(--ink-soft);
  }
  .timeline-details {
    font-size: 0.8rem;
    color: var(--ink-soft);
    margin-top: 0.15rem;
    line-height: 1.35;
  }
  .timeline-sep {
    margin: 0 0.35rem;
    opacity: 0.45;
  }

  @media (max-width: 768px) {
    .asset-profile-hero {
      flex-direction: column;
      align-items: center;
      gap: 1.25rem;
    }
    .asset-hero-photo {
      width: 100%;
      max-width: 220px;
    }
    .asset-clean-specs {
      grid-template-columns: 1fr 1fr;
    }
  }
</style>

<!-- TOP FULL-WIDTH ASSET PROFILE CARD -->
<div class="card" style="margin-bottom:1.25rem; padding:1.25rem;">
  <div class="asset-profile-hero">
    <!-- LEFT: ITEM PICTURE -->
    <div class="asset-hero-photo">
      <div class="asset-photo-frame">
        <?php if (!empty($asset['image_filename']) && file_exists(ITEM_UPLOAD_DIR . $asset['image_filename'])): ?>
          <img src="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($asset['image_filename'] ?? '')) ?>" alt="<?= htmlspecialchars((string)($asset['item_name'] ?? '')) ?>" class="asset-photo-img">
        <?php else: ?>
          <div class="asset-photo-placeholder">
            <span class="placeholder-initials"><?= htmlspecialchars(item_initials($asset['item_name'])) ?></span>
            <span class="placeholder-label">No photo</span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: KEY SPECIFICATIONS & ATTRIBUTES -->
    <div class="asset-hero-details">
      <!-- HEADER BADGE ROW -->
      <div style="display:flex; align-items:center; gap:0.65rem; flex-wrap:wrap; margin-bottom:0.75rem;">
        <span class="mono" style="font-weight:700; font-size:1.05rem; color:var(--amber); letter-spacing:0.03em;">
          <?= htmlspecialchars((string)($asset['asset_tag'] ?? '')) ?>
        </span>
        <span class="text-muted" style="font-size:0.85rem;">&bull;</span>
        <span class="mono text-muted" style="font-size:0.88rem;"><?= htmlspecialchars((string)($asset['item_code'] ?? '')) ?></span>
        <?php if (!empty($asset['location_note'])): ?>
          <span class="text-muted" style="font-size:0.85rem;">&bull;</span>
          <span style="font-size:0.88rem; font-weight:600; color:var(--ink); display:inline-flex; align-items:center; gap:0.25rem;">
            📍 <span>Location:</span> <strong style="color:var(--amber); font-weight:700;"><?= htmlspecialchars((string)($asset['location_note'] ?? '')) ?></strong>
          </span>
        <?php endif; ?>
      </div>

      <!-- SPECS TILES GRID (CLEAN & SIMPLE) -->
      <div class="asset-clean-specs">
        <div class="clean-spec-row">
          <span class="cs-label">Registered</span>
          <span class="cs-val mono"><?= $asset['acquired_at'] ? htmlspecialchars((string)($asset['acquired_at'] ?? '')) : htmlspecialchars(date('Y-m-d', strtotime($asset['created_at']))) ?></span>
        </div>
        <?php if ($asset['variant_value']): ?>
          <div class="clean-spec-row">
            <span class="cs-label">Option</span>
            <span class="cs-val"<?= $asset['variant_note'] ? ' title="' . htmlspecialchars((string)($asset['variant_note'] ?? '')) . '"' : '' ?>><?= htmlspecialchars((string)($asset['variant_value'] ?? '')) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($is_lot): ?>
          <div class="clean-spec-row">
            <span class="cs-label">Remaining</span>
            <span class="cs-val mono" style="font-weight:700; color:var(--amber);"><?= (int)$asset['quantity'] ?> <?= htmlspecialchars((string)($asset['unit'] ?? '')) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($asset['serial_number']): ?>
          <div class="clean-spec-row">
            <span class="cs-label">Serial Number</span>
            <span class="cs-val mono"><?= htmlspecialchars((string)($asset['serial_number'] ?? '')) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($asset['condition_note']): ?>
          <div class="clean-spec-row">
            <span class="cs-label">Condition</span>
            <span class="cs-val"><?= htmlspecialchars((string)($asset['condition_note'] ?? '')) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($asset['status'] === 'checked_out' && $asset['holder_name']): ?>
          <div class="clean-spec-row" style="grid-column: 1 / -1;">
            <span class="cs-label">Currently With</span>
            <span class="cs-val" style="color:var(--ink);">
              <?= htmlspecialchars((string)($asset['holder_name'] ?? '')) ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($asset['holder_employee_id'] ?? '')) ?>)</span>
              <?php if ($active_loan): ?>
                — Due <strong class="mono" style="color:var(--amber);"><?= htmlspecialchars((string)($active_loan['due_date'] ?? '')) ?></strong>
              <?php endif; ?>
            </span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- TWO COLUMNS: ACTIONS ON LEFT, HISTORY ON RIGHT -->
<div class="two-col-cards" style="align-items:flex-start; margin-bottom:1.5rem; gap:1.25rem;">
  <!-- LEFT COLUMN: ACTIONS -->
  <div class="card" style="flex:1; margin-bottom:0;">
    <h2 style="font-size:1rem; margin-top:0; margin-bottom:0.85rem; font-weight:700;">Actions</h2>

    <?php if ($is_lot && $asset['status'] === 'available'): ?>
      <form method="post" style="margin-bottom:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="consume">
        <div class="form-group">
          <label for="amount">Use from this lot</label>
          <input type="number" id="amount" name="amount" min="1" max="<?= (int)$asset['quantity'] ?>" value="1" required>
          <span style="font-size:0.78rem; color:var(--ink-soft);"><?= (int)$asset['quantity'] ?> <?= htmlspecialchars((string)($asset['unit'] ?? '')) ?> remaining. Using all of it retires this tag.</span>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Use</button>
      </form>

      <form method="post" style="margin-bottom:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="transfer">
        <div class="form-group">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem; flex-wrap:wrap; gap:0.25rem;">
            <label for="location_select_lot" style="margin:0; font-weight:600;">Transfer to</label>
            <?php if (!empty($asset['location_note'])): ?>
              <span style="font-size:0.75rem; color:var(--ink-soft); display:inline-flex; align-items:center; gap:0.25rem;">
                Current: <strong style="color:var(--amber); font-weight:700;">📍 <?= htmlspecialchars((string)($asset['location_note'] ?? '')) ?></strong>
              </span>
            <?php endif; ?>
          </div>
          <select id="location_select_lot" name="location_note" required style="width:100%;">
            <option value="">Select location…</option>
            <?php foreach ($proper_locations as $loc): 
              $loc_label = $loc['label'];
              $is_curr = ($asset['location_note'] === $loc_label || str_replace('—', '-', $asset['location_note'] ?? '') === str_replace('—', '-', $loc_label));
            ?>
              <option value="<?= htmlspecialchars($loc_label) ?>" <?= $is_curr ? 'selected' : '' ?> <?= $is_curr ? 'style="font-weight:700; background:#fef4e8; color:#8E5225;"' : '' ?>>
                <?= $is_curr ? '📍 ' : '　 ' ?><?= htmlspecialchars($loc_label) ?><?= $is_curr ? ' ◄ CURRENT LOCATION' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Move</button>
      </form>

    <?php elseif (!$is_lot && $asset['status'] === 'available'): ?>
      <form method="post" style="margin-bottom:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="checkout">
        <div class="form-group">
          <label for="holder_id">Check out to</label>
          <select id="holder_id" name="holder_id" required>
            <option value="">Select personnel…</option>
            <?php foreach ($holders as $h): ?>
              <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['full_name'] . ' (' . $h['employee_id'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <span style="font-size:0.78rem; color:var(--ink-soft);">For handing a tool out directly, outside the requisition flow (e.g. an internal job).</span>
        </div>
        <div class="form-group">
          <label for="due_days">Borrow for (business days)</label>
          <input type="number" id="due_days" name="due_days" min="1" max="30" step="1" value="3" style="width:100%; padding:0.5rem;">
          <span style="font-size:0.78rem; color:var(--ink-soft);">Sat/Sun hindi bilang. Tracked the same as a requisition loan — due date, overdue alerts, lahat.</span>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Check out</button>
      </form>

      <form method="post" style="margin-bottom:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="transfer">
        <div class="form-group">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem; flex-wrap:wrap; gap:0.25rem;">
            <label for="location_select_unit" style="margin:0; font-weight:600;">Transfer to</label>
            <?php if (!empty($asset['location_note'])): ?>
              <span style="font-size:0.75rem; color:var(--ink-soft); display:inline-flex; align-items:center; gap:0.25rem;">
                Current: <strong style="color:var(--amber); font-weight:700;">📍 <?= htmlspecialchars((string)($asset['location_note'] ?? '')) ?></strong>
              </span>
            <?php endif; ?>
          </div>
          <select id="location_select_unit" name="location_note" required style="width:100%;">
            <option value="">Select location…</option>
            <?php foreach ($proper_locations as $loc): 
              $loc_label = $loc['label'];
              $is_curr = ($asset['location_note'] === $loc_label || str_replace('—', '-', $asset['location_note'] ?? '') === str_replace('—', '-', $loc_label));
            ?>
              <option value="<?= htmlspecialchars($loc_label) ?>" <?= $is_curr ? 'selected' : '' ?> <?= $is_curr ? 'style="font-weight:700; background:#fef4e8; color:#8E5225;"' : '' ?>>
                <?= $is_curr ? '📍 ' : '　 ' ?><?= htmlspecialchars($loc_label) ?><?= $is_curr ? ' ◄ CURRENT LOCATION' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Move</button>
      </form>

      <form method="post" style="margin-bottom:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="report_damage">
        <div class="form-group">
          <label for="condition_note_dmg">Report damage / issue</label>
          <textarea id="condition_note_dmg" name="condition_note" rows="2" placeholder="Describe the issue"></textarea>
        </div>
        <button type="submit" class="btn btn-danger btn-sm">Send to maintenance</button>
      </form>

    <?php elseif ($asset['status'] === 'checked_out'): ?>
      <form method="post" style="margin-bottom:0.85rem;" onsubmit="return confirm('Check in this asset now?');">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="checkin">
        <div class="form-group">
          <label for="condition_note_in">Condition on return <span style="font-weight:400; color:var(--ink-soft);">(optional)</span></label>
          <textarea id="condition_note_in" name="condition_note" rows="2"></textarea>
        </div>
        <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.85rem; margin-bottom:0.75rem;">
          <input type="checkbox" name="damaged" value="1"> Damaged — send to maintenance
        </label>
        <button type="submit" class="btn btn-primary btn-sm">Check in</button>
      </form>
      <p style="font-size:0.8rem; color:var(--ink-soft);">Also marks the loan returned.</p>

    <?php elseif ($asset['status'] === 'under_maintenance'): ?>
      <form method="post" style="margin-bottom:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="mark_repaired">
        <div class="form-group">
          <label for="condition_note_fix">Repair note <span style="font-weight:400; color:var(--ink-soft);">(optional)</span></label>
          <textarea id="condition_note_fix" name="condition_note" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Mark repaired — available again</button>
      </form>

    <?php elseif ($asset['status'] === 'missing'): ?>
      <form method="post" style="margin-bottom:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $asset_id ?>">
        <input type="hidden" name="do" value="mark_found">
        <button type="submit" class="btn btn-primary btn-sm">Mark found</button>
      </form>

    <?php endif; ?>

    <div style="margin-top:0.85rem; padding-top:0.75rem; border-top:1px solid var(--line); display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
      <?php if ($asset['status'] !== 'retired'): ?>
        <form method="post" onsubmit="return confirm('Retire this asset? It will no longer be available to check out.');">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $asset_id ?>">
          <input type="hidden" name="do" value="retire">
          <input type="hidden" name="condition_note" value="Retired from service.">
          <button type="submit" class="btn btn-outline btn-sm">Retire this asset</button>
        </form>
      <?php else: ?>
        <span style="color:var(--ink-soft); font-size:0.85rem;">This asset is retired.</span>
      <?php endif; ?>

      <?php if ($asset['status'] !== 'checked_out' && empty($asset['current_holder_id'])): ?>
        <form method="post" style="margin-left:auto;" onsubmit="return confirm('⚠️ PERMANENT DELETE\n\nAre you sure you want to permanently delete this asset (<?= htmlspecialchars((string)($asset['asset_tag'] ?? '')) ?>)?\n\nThis will remove its QR code registration and history. This action cannot be undone.');">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $asset_id ?>">
          <input type="hidden" name="do" value="delete_asset">
          <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete Asset</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT COLUMN: HISTORY -->
  <div class="card" style="flex:1; margin-bottom:0;">
    <h2 style="font-size:1rem; margin-top:0; margin-bottom:1rem; font-weight:700; color:var(--ink);">History</h2>
    <?php if (!$history): ?>
      <p style="color:var(--ink-soft); font-size:0.88rem; padding:1.5rem 0; text-align:center;">No activity recorded yet.</p>
    <?php else: ?>
      <div class="asset-timeline" style="max-height:480px; overflow-y:auto; padding-right:0.35rem;">
        <?php foreach ($history as $ev): ?>
          <div class="timeline-entry">
            <div class="timeline-marker"></div>
            <div>
              <div class="timeline-head">
                <span class="timeline-name"><?= htmlspecialchars(asset_event_label($ev['event_type'])) ?></span>
                <span class="timeline-timestamp mono"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($ev['created_at']))) ?></span>
              </div>
              <div class="timeline-details">
                <span style="color:var(--ink); font-weight:500;"><?= htmlspecialchars((string)($ev['actor_name_snapshot'] ?? '')) ?></span><?php if ($ev['note']): ?><span class="timeline-sep">•</span><span><?= htmlspecialchars((string)($ev['note'] ?? '')) ?></span><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- OFF-SCREEN QR CODE GENERATOR (REQUIRED FOR PHYSICAL LABEL PRINTING) -->
<div id="assetQrCode" style="position:absolute; left:-9999px; top:-9999px; width:128px; height:128px;"></div>

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
  if (typeof QRCode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
  }
</script>
<script>
  (function () {
    var qrEl = document.getElementById('assetQrCode');
    if (qrEl && typeof QRCode !== 'undefined') {
      new QRCode(qrEl, {
        text: <?= json_encode(BASE_URL . '/inventory/asset_view.php?tag=' . $asset['asset_tag']) ?>,
        width: 128,
        height: 128,
        colorDark: '#241810',
        colorLight: '#ffffff'
      });
    }
  })();

  function printAssetLabel() {
    var qrImg = document.querySelector('#assetQrCode img');
    var qrCanvas = document.querySelector('#assetQrCode canvas');
    var qrSrc = '';
    if (qrImg && qrImg.src && qrImg.src.indexOf('data:image') === 0) {
      qrSrc = qrImg.src;
    } else if (qrCanvas && qrCanvas.toDataURL) {
      qrSrc = qrCanvas.toDataURL('image/png');
    } else if (qrImg && qrImg.src) {
      qrSrc = qrImg.src;
    }

    var printFrame = document.getElementById('assetPrintFrame');
    if (!printFrame) {
      printFrame = document.createElement('iframe');
      printFrame.id = 'assetPrintFrame';
      printFrame.style.position = 'fixed';
      printFrame.style.right = '0';
      printFrame.style.bottom = '0';
      printFrame.style.width = '0';
      printFrame.style.height = '0';
      printFrame.style.border = '0';
      document.body.appendChild(printFrame);
    }

    var doc = printFrame.contentWindow.document;
    doc.open();
    doc.write('<!DOCTYPE html><html><head><title>' + <?= json_encode($asset['asset_tag']) ?> + '</title>');
    doc.write('<style>');
    doc.write('@page { size: auto; margin: 4mm; }');
    doc.write('body { margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fff; }');
    doc.write('.sticker { border: 1.5px dashed #342217; border-radius: 8px; padding: 14px; width: 185px; text-align: center; box-sizing: border-box; }');
    doc.write('.sticker img { width: 128px; height: 128px; display: block; margin: 0 auto; }');
    doc.write('.name { font-size: 13px; font-weight: 700; color: #241810; margin-top: 8px; line-height: 1.25; }');
    doc.write('.variant { font-size: 11px; color: #614d3f; margin-top: 2px; }');
    doc.write('.tag { font-family: "Courier New", monospace; font-size: 10px; font-weight: 700; color: #8E5225; margin-top: 6px; letter-spacing: 0.03em; word-break: break-all; }');
    doc.write('</style></head><body>');
    doc.write('<div class="sticker">');
    if (qrSrc) {
      doc.write('<img src="' + qrSrc + '" alt="QR Code">');
    }
    doc.write('<div class="name">' + <?= json_encode($asset['item_name']) ?> + '</div>');
    <?php if ($asset['variant_value']): ?>
    doc.write('<div class="variant">' + <?= json_encode($asset['variant_value']) ?> + '</div>');
    <?php endif; ?>
    doc.write('<div class="tag">' + <?= json_encode($asset['asset_tag']) ?> + '</div>');
    doc.write('</div>');
    doc.write('</body></html>');
    doc.close();

    setTimeout(function () {
      printFrame.contentWindow.focus();
      printFrame.contentWindow.print();
    }, 250);
  }
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
