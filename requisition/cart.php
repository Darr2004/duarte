<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['driver_helper', 'field_supervisor']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['remove_id'])) {
        cart_remove((int)$_POST['remove_id']);
        $flash_success = 'Item removed from cart.';
    } elseif (isset($_POST['update_qty'])) {
        $days_input = $_POST['update_days'] ?? [];
        $variant_input = $_POST['update_variant'] ?? [];
        $mode_input = $_POST['update_mode'] ?? [];
        foreach ($_POST['update_qty'] as $item_id => $qty) {
            $days = isset($days_input[$item_id]) && $days_input[$item_id] !== ''
                ? max(1, (int)$days_input[$item_id]) : null;
            $variant = isset($variant_input[$item_id]) ? (trim($variant_input[$item_id]) ?: null) : null;
            $mode = isset($mode_input[$item_id]) && in_array($mode_input[$item_id], ['consume', 'borrow'], true)
                ? $mode_input[$item_id] : null;
            cart_update((int)$item_id, (int)$qty, $mode, $days, $variant);
        }
        $flash_success = 'Cart updated.';
    } elseif (isset($_POST['submit_requisition'])) {
        $cart = cart_get();
        if (!is_within_office_hours()) {
            $errors[] = office_hours_message();
        } elseif (!$cart) {
            $errors[] = 'Your cart is empty. Add items from the catalog first.';
        } else {
            $purpose = trim($_POST['purpose'] ?? '');
            $truck_id = !empty($_POST['truck_id']) ? (int)$_POST['truck_id'] : null;
            $is_maintenance_request = !empty($_POST['is_maintenance_request']);

            // Re-validate every line against current stock before committing.
            $ids = array_keys($cart);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders) AND status = 'active'");
            $stmt->execute($ids);
            $catalog_items = [];
            foreach ($stmt->fetchAll() as $row) {
                $catalog_items[$row['id']] = $row;
            }

            foreach ($cart as $item_id => $line) {
                if (!isset($catalog_items[$item_id])) {
                    $errors[] = 'One of the items in your cart is no longer available. Please review your cart.';
                    break;
                }
                $stock = available_stock_for($pdo, $catalog_items[$item_id], $line['variant'] ?? null);
                if (!$stock['ok'] || $line['qty'] > $stock['available']) {
                    $errors[] = $stock['label'] . ' now only has ' . $stock['available'] . ' available.';
                }
            }

            // Target truck plate number is required for fleet operations, but not for Office Supplies
            $cart_needs_truck = cart_requires_truck($pdo, $cart);
            if ($cart_needs_truck && !$truck_id) {
                $errors[] = 'Please select which Truck (Plate Number) this request is for.';
            } elseif ($truck_id) {
                $t_chk = $pdo->prepare('SELECT id, plate_number, status FROM trucks WHERE id = :id');
                $t_chk->execute(['id' => $truck_id]);
                $trk = $t_chk->fetch();
                if (!$trk) {
                    $errors[] = 'The selected truck is invalid.';
                } elseif ($trk['status'] === 'under_maintenance' && !$is_maintenance_request) {
                    $errors[] = 'Truck ' . htmlspecialchars((string)($trk['plate_number'] ?? '')) . ' is currently under maintenance and cannot be scheduled. Check "Para sa pag-aayos ng truck" below if this request is to repair it.';
                }
            }

            // Enforce Office Staff category restriction
            if (($user['position'] ?? null) === 'office_staff') {
                foreach ($catalog_items as $ci) {
                    // Check item's category name or code
                    $cat_chk = $pdo->prepare('SELECT c.name, c.code_prefix FROM categories c WHERE c.id = :cid');
                    $cat_chk->execute(['cid' => $ci['category_id']]);
                    $cat_info = $cat_chk->fetch();
                    if ($cat_info && $cat_info['name'] !== 'Office Supplies' && $cat_info['code_prefix'] !== 'OS') {
                        $errors[] = 'Office Staff accounts are restricted to requesting Office Supplies only.';
                        break;
                    }
                }
            }

            // Block ANY new requisition — borrow or consume-only — while
            // an earlier loan is overdue.
            if (user_has_overdue_loans($pdo, (int)$user['id'])) {
                $errors[] = 'You have an overdue borrowed item. Please return it before submitting any new request.';
            }

            if (!$errors) {
                try {
                    $requisition_id = submit_requisition($pdo, $user, $cart, $catalog_items, $purpose, $truck_id, $is_maintenance_request);
                    cart_clear();

                    if (requisition_needs_supervisor_approval($user)) {
                        notify_role(
                            'field_supervisor',
                            $user['full_name'] . ' submitted a new' . ($is_maintenance_request ? ' TRUCK REPAIR' : '') . ' request (#' . $requisition_id . ') for approval.',
                            BASE_URL . '/requisition/view.php?id=' . $requisition_id
                        );
                        notify_role_sms(
                            'field_supervisor',
                            'DuaRTE Alert: ' . $user['full_name'] . ' submitted ' . ($is_maintenance_request ? 'TRUCK REPAIR ' : '') . 'Requisition #' . $requisition_id . ' awaiting your approval.'
                        );
                    } else {
                        log_audit_event($pdo, null, 'requisition_approve', 'requisition', $requisition_id,
                            'Request #' . $requisition_id . ' from ' . $user['full_name'] . ' approved without review (no supervisor assigned).');
                        notify_role(
                            'inventory_staff',
                            $user['full_name'] . ' submitted request #' . $requisition_id . '. Ready for release.',
                            BASE_URL . '/requisition/view.php?id=' . $requisition_id
                        );
                    }

                    header('Location: ' . BASE_URL . '/requisition/view.php?id=' . $requisition_id . '&submitted=1');
                    exit;
                } catch (RequisitionShortfallException $e) {
                    $errors[] = clean_error_message($e);
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    $errors[] = 'Something went wrong submitting your request. Please try again.';
                }
            }
        }
    }
}

// Load current cart contents joined with live item data.
$cart = cart_get();
$cart_items = [];
$cart_variants_by_item = [];
if ($cart) {
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $cart_items[] = array_merge($row, [
            'qty_requested' => $cart[$row['id']]['qty'],
            'mode_requested' => resolve_line_borrow_mode($row['borrow_mode'], $cart[$row['id']]['mode'] ?? null),
            'days_requested' => $cart[$row['id']]['days'] ?? 3,
            'variant_selected' => $cart[$row['id']]['variant'] ?? null,
        ]);
    }
    $cart_variants_by_item = get_item_variants_for($pdo, $ids);
}

$needs_truck = cart_requires_truck($pdo, $cart);
$available_trucks = get_all_trucks($pdo);

$page_title = 'Cart';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Online requisition &amp; approval</div>
    <h1>Your Cart</h1>
  </div>
  <a href="<?= BASE_URL ?>/catalog/browse.php" class="btn btn-outline">+ Add more items</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>

<?php if (!$cart_items): ?>
  <div class="empty-state">Your cart is empty. Browse the catalog and add items to build a request.</div>
<?php else: ?>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="table-responsive">
<table class="data">
        <thead><tr><th>Item</th><th>Available now</th><th>Option</th><th>Quantity</th><th>How</th><th>Borrow for</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($cart_items as $ci): ?>
            <tr>
              <td data-label="Item"><?= htmlspecialchars((string)($ci['name'] ?? '')) ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($ci['item_code'] ?? '')) ?>)</span>
                <?php if ($ci['borrow_mode'] === 'borrow'): ?><span class="badge role ml-sm">Tool</span><?php endif; ?>
              </td>
              <td class="mono" data-label="Available now">
                <?php
                  $ci_variants = $cart_variants_by_item[$ci['id']] ?? [];
                  $sel_v_qty = null;
                  if ($ci_variants && $ci['variant_selected']) {
                      foreach ($ci_variants as $v) {
                          if ($v['variant_value'] === $ci['variant_selected']) {
                              $sel_v_qty = (int)$v['quantity_on_hand'];
                              break;
                          }
                      }
                  }
                ?>
                <?php if ($sel_v_qty !== null): ?>
                  <strong><?= $sel_v_qty ?></strong> <?= htmlspecialchars((string)($ci['unit'] ?? '')) ?>
                  <div style="font-size:0.72rem; color:var(--ink-soft); font-weight:normal;">(Total: <?= (int)$ci['quantity_on_hand'] ?>)</div>
                <?php else: ?>
                  <?= (int)$ci['quantity_on_hand'] ?> <?= htmlspecialchars((string)($ci['unit'] ?? '')) ?>
                <?php endif; ?>
              </td>
              <td data-label="Option">
                <?php if ($ci_variants): ?>
                  <select aria-label="Option for <?= htmlspecialchars((string)($ci['name'] ?? '')) ?>" name="update_variant[<?= $ci['id'] ?>]" style="padding:0.4rem;">
                    <?php foreach ($ci_variants as $v): ?>
                      <option value="<?= htmlspecialchars((string)($v['variant_value'] ?? '')) ?>" <?= $ci['variant_selected'] === $v['variant_value'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)($v['variant_value'] ?? '')) ?> (<?= (int)$v['quantity_on_hand'] ?> avail.)
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td data-label="Quantity">
                <input aria-label="Quantity for <?= htmlspecialchars((string)($ci['name'] ?? '')) ?>" type="text" inputmode="numeric" name="update_qty[<?= $ci['id'] ?>]" value="<?= (int)$ci['qty_requested'] ?>" style="width:4rem; text-align:center; padding:0.4rem;">
              </td>
              <td data-label="How">
                <?php if ($ci['borrow_mode'] === 'choice'): ?>
                  <select aria-label="Consume or borrow: <?= htmlspecialchars((string)($ci['name'] ?? '')) ?>" name="update_mode[<?= $ci['id'] ?>]" class="cart-mode-select" data-row="<?= $ci['id'] ?>" style="padding:0.4rem;">
                    <option value="consume" <?= $ci['mode_requested'] === 'consume' ? 'selected' : '' ?>>Consume</option>
                    <option value="borrow" <?= $ci['mode_requested'] === 'borrow' ? 'selected' : '' ?>>Borrow</option>
                  </select>
                <?php elseif ($ci['borrow_mode'] === 'borrow'): ?>
                  <span class="text-muted">Borrow (fixed)</span>
                <?php else: ?>
                  <span class="text-muted">Consume</span>
                <?php endif; ?>
              </td>
              <td data-label="Borrow for">
                <span class="cart-days-wrap" data-row="<?= $ci['id'] ?>" style="<?= $ci['mode_requested'] === 'borrow' ? '' : 'display:none;' ?>">
                  <select aria-label="Borrow days for <?= htmlspecialchars((string)($ci['name'] ?? '')) ?>" name="update_days[<?= $ci['id'] ?>]" style="padding:0.4rem;">
                    <option value="1" <?= (int)$ci['days_requested'] === 1 ? 'selected' : '' ?>>1 day</option>
                    <option value="3" <?= (int)$ci['days_requested'] === 3 ? 'selected' : '' ?>>3 days</option>
                    <option value="7" <?= (int)$ci['days_requested'] === 7 ? 'selected' : '' ?>>1 week</option>
                  </select>
                </span>
                <?php if ($ci['mode_requested'] !== 'borrow'): ?>
                  <span class="cart-days-placeholder" data-row="<?= $ci['id'] ?>" class="text-muted">— (consumable)</span>
                <?php endif; ?>
              </td>
              <td data-label="">
                <button type="submit" formaction="<?= BASE_URL ?>/requisition/cart.php" name="remove_id" value="<?= $ci['id'] ?>" class="btn btn-danger btn-sm">Remove</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
</div>
      <div class="mt-1">
        <button type="submit" class="btn btn-outline">Update quantities</button>
      </div>
    </form>
  </div>

  <div class="card" style="max-width:520px;">
    <?php if (!is_within_office_hours()): ?>
      <div class="alert alert-error"><?= htmlspecialchars(office_hours_message()) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="form-group" style="margin-bottom:1rem;">
          <label for="truck_id" style="font-weight:600; display:flex; align-items:center; gap:0.4rem; margin-bottom:0.4rem;">
            <span>Assign to Truck (Plate Number)</span>
            <?php if ($needs_truck): ?>
              <span class="badge inactive" style="font-size:0.75rem;">Required</span>
            <?php else: ?>
              <span class="text-muted-normal">(optional)</span>
            <?php endif; ?>
          </label>
          <select id="truck_id" name="truck_id" <?= $needs_truck ? 'required' : '' ?> style="width:100%; padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; font-family:var(--font-body); background:var(--surface);">
            <option value=""><?= $needs_truck ? '-- Choose Truck Plate Number --' : '-- No truck --' ?></option>
            <?php foreach ($available_trucks as $trk):
              $st_info = truck_status_info($trk['status']);
            ?>
              <option value="<?= (int)$trk['id'] ?>" data-status="<?= htmlspecialchars($trk['status']) ?>" <?= (isset($_POST['truck_id']) && (int)$_POST['truck_id'] === (int)$trk['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)($trk['plate_number'] ?? '')) ?> — <?= htmlspecialchars((string)($trk['model'] ?? '')) ?> [<?= htmlspecialchars((string)($st_info['label'] ?? '')) ?>]
              </option>
            <?php endforeach; ?>
          </select>

          <div id="maintReqGroup" style="margin-top:0.5rem; display:none;">
            <label style="display:flex; align-items:flex-start; gap:0.4rem; font-weight:500;">
              <input type="checkbox" id="is_maintenance_request" name="is_maintenance_request" value="1"
                <?= !empty($_POST['is_maintenance_request']) ? 'checked' : '' ?> style="margin-top:0.2rem;">
              <span>Truck maintenance request</span>
            </label>
            <div id="maintReqHint" class="text-muted" style="font-size:0.8rem; margin:0.25rem 0 0 1.4rem;"></div>
          </div>
        </div>

      <div class="form-group" style="margin-bottom:1.1rem;">
        <label for="purpose" style="font-weight:600; display:block; margin-bottom:0.35rem;">
          Purpose / Reason for Request <span class="text-muted-normal" style="font-weight:normal;">(optional)</span>
        </label>
        <select id="purpose_preset" class="form-control" style="width:100%; padding:0.5rem 0.65rem; border:1px solid var(--line); border-radius:6px; margin-bottom:0.45rem; font-size:0.85rem; background:var(--surface);">
          <option value="">-- Pumili ng karaniwang dahilan (Preset) o mag-type sa ibaba --</option>
          <option value="Delivery Run / Provincial Trip">Delivery Run / Provincial Trip</option>
          <option value="Routine Preventive Maintenance (PMS) / Change Oil">Routine Preventive Maintenance (PMS) / Change Oil</option>
          <option value="Emergency Roadside Repair / Breakdown">Emergency Roadside Repair / Breakdown</option>
          <option value="Site Loading &amp; Unloading Operations">Site Loading &amp; Unloading Operations</option>
          <option value="Restock Truck Tools &amp; Safety Equipment">Restock Truck Tools &amp; Safety Equipment</option>
          <option value="Restock Office &amp; Warehouse Supplies">Restock Office &amp; Warehouse Supplies</option>
          <option value="Scheduled Vehicle Inspection &amp; LTO Check">Scheduled Vehicle Inspection &amp; LTO Check</option>
          <option value="__custom__">Iba pa / Custom (Mag-type ng sariling dahilan)...</option>
        </select>
        <textarea id="purpose" name="purpose" rows="2" style="width:100%; padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; font-family:var(--font-body);" placeholder="Pumili sa itaas o mag-type ng sariling dahilan..."><?= htmlspecialchars($_POST['purpose'] ?? '') ?></textarea>
      </div>
      <button type="submit" name="submit_requisition" value="1" class="btn btn-primary" <?= is_within_office_hours() ? '' : 'disabled title="' . htmlspecialchars(office_hours_message()) . '"' ?>>Submit Request</button>
    </form>
  </div>
<?php endif; ?>

<script>
  // Toggle the "Borrow for" field live when the requester flips a
  // choice item's How between Consume and Borrow, without needing a
  // page reload first (the actual value is still only persisted once
  // "Update quantities" or "Submit request" is clicked).
  document.querySelectorAll('.cart-mode-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var row = sel.dataset.row;
      var wrap = document.querySelector('.cart-days-wrap[data-row="' + row + '"]');
      var placeholder = document.querySelector('.cart-days-placeholder[data-row="' + row + '"]');
      if (!wrap) return;
      var borrowing = sel.value === 'borrow';
      wrap.style.display = borrowing ? '' : 'none';
      if (placeholder) placeholder.style.display = borrowing ? 'none' : '';
    });
  });

  // Preset reason selector autofill for Purpose
  (function () {
    var preset = document.getElementById('purpose_preset');
    var input = document.getElementById('purpose');
    if (!preset || !input) return;
    preset.addEventListener('change', function () {
      if (this.value === '__custom__') {
        input.value = '';
        input.focus();
      } else if (this.value !== '') {
        input.value = this.value;
      }
    });
  })();

  // Show the "Para sa pag-aayos ng truck" checkbox for ANY selected
  // truck — a driver may need brake pads, a bulb, a rim, etc. for a
  // truck that is still marked Available. Under Maintenance trucks get
  // it auto-checked (a trip request is blocked for them anyway).
  (function () {
    var truckSelect = document.getElementById('truck_id');
    var maintGroup = document.getElementById('maintReqGroup');
    var maintCheckbox = document.getElementById('is_maintenance_request');
    var maintHint = document.getElementById('maintReqHint');
    if (!truckSelect || !maintGroup || !maintCheckbox) return;

    function sync(fromUser) {
      var opt = truckSelect.options[truckSelect.selectedIndex];
      var status = opt ? (opt.dataset.status || '') : '';
      var hasTruck = !!opt && opt.value !== '';
      maintGroup.style.display = hasTruck ? '' : 'none';
      if (!hasTruck) { maintCheckbox.checked = false; return; }
      if (status === 'under_maintenance') {
        if (fromUser === true) maintCheckbox.checked = true;
        maintHint.textContent = 'Repair only.';
      } else if (status === 'on_trip') {
        maintHint.textContent = 'Verify if repair.';
      } else {
        maintHint.textContent = 'e.g. Pads, bulbs, rims';
      }
    }

    truckSelect.addEventListener('change', function () { sync(true); });
    sync(false);
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
