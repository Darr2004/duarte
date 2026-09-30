<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$selected_item_id = $_GET['item_id'] ?? $_POST['item_id'] ?? '';
$selected_variant_id = $_GET['variant_id'] ?? $_POST['variant_id'] ?? '';

// Adjustments are only ever meant to be started from a specific item's
// "Adjust stock" link in Catalog Management (or Edit item) — landing
// here with no item picked yet (e.g. a bookmarked/typed URL) would
// otherwise let staff adjust stock through a page Catalog Management
// isn't managing.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $selected_item_id === '') {
    header('Location: ' . BASE_URL . '/inventory/items.php');
    exit;
}
// Where to send the user after a successful adjustment. Defaults to the
// stock ledger; item_edit.php's "Adjust stock →" links pass return_to=
// item_edit so a quick adjustment doesn't strand the user away from the
// item they were editing. Whitelisted, since this ends up in a redirect.
$return_to = ($_GET['return_to'] ?? $_POST['return_to'] ?? '') === 'item_edit' ? 'item_edit' : 'ledger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $item_id  = (int)($_POST['item_id'] ?? 0);
        $variant_id = (int)($_POST['variant_id'] ?? 0) ?: null;
        $reason   = trim($_POST['reason'] ?? '');

        $stmt = $pdo->prepare('SELECT variant_label, quantity_on_hand FROM items WHERE id = :id');
        $stmt->execute(['id' => $item_id]);
        $item_row = $stmt->fetch();

        // The form now shows/edits the actual on-hand count (auto-filled
        // with what's currently on hand), not a raw +/- delta — so the
        // adjustment itself is worked out here as the difference between
        // the count the person entered and what's on hand right now.
        // Reading "before" fresh from the DB (rather than trusting a
        // hidden field) also means a stale page can't silently apply the
        // wrong delta.
        $before_qty = null;
        if ($item_id > 0) {
            if ($variant_id) {
                $vstmt = $pdo->prepare('SELECT quantity_on_hand FROM item_variants WHERE id = :id AND item_id = :item_id');
                $vstmt->execute(['id' => $variant_id, 'item_id' => $item_id]);
                $vrow = $vstmt->fetch();
                $before_qty = $vrow ? (int)$vrow['quantity_on_hand'] : null;
            } elseif ($item_row) {
                $before_qty = (int)$item_row['quantity_on_hand'];
            }
        }
        $new_count = (int)($_POST['quantity'] ?? 0);
        $signed_qty = $before_qty !== null ? $new_count - $before_qty : 0;
        $qty = abs($signed_qty);

        if ($item_id < 1)   $errors[] = 'Select an item.';
        if ($item_row && $item_row['variant_label'] && !$variant_id) {
            $errors[] = 'Select which ' . $item_row['variant_label'] . ' this adjustment applies to.';
        }
        if ($item_id >= 1 && $before_qty === null) $errors[] = 'Could not find the current stock count for that item.';
        if ($new_count < 0) $errors[] = 'Stock count cannot be negative.';
        if (!$errors && $qty < 1) $errors[] = 'The count matches what\'s already on hand — change it with − / + if a recount found a different number.';
        if ($reason === '') $errors[] = 'A reason is required for every adjustment.';

        if (!$errors && $signed_qty < 0) {
            $safety = verify_stock_reduction_safety($pdo, $item_id, $new_count, $variant_id);
            if (!$safety['safe']) {
                $errors[] = $safety['message'];
            }
        }

        if (!$errors) {
            try {
                $result = record_stock_movement($pdo, $item_id, 'adjustment', $signed_qty, 'manual', null, $user['id'], $reason, $variant_id);
                maybe_alert_stock_threshold(
                    $item_id, $result['before'], $result['after'], $result['name'], $result['unit'],
                    $result['variant_before'] ?? null, $result['variant_after'] ?? null, $result['variant_value'] ?? null
                );
                resolve_stock_alert_notifications($pdo, $item_id);
                log_audit_event($pdo, $user, 'stock_adjustment', 'item', $item_id,
                    $user['full_name'] . ' ' . ($signed_qty > 0 ? 'increased' : 'decreased') . ' stock of "' . $result['name'] . '" by ' . $qty . ' (' . $result['before'] . ' → ' . $result['after'] . '). Reason: ' . $reason);
                $redirect = $return_to === 'item_edit'
                    ? BASE_URL . '/inventory/item_edit.php?id=' . $item_id . '&recorded=1'
                    : BASE_URL . '/inventory/stock_ledger.php?item=' . $item_id . '&recorded=1';
                header('Location: ' . $redirect);
                exit;
            } catch (InsufficientStockException $e) {
                $errors[] = clean_error_message($e);
            } catch (Exception $e) {
                error_log($e->getMessage());
                $errors[] = 'Something went wrong recording this adjustment. Please try again.';
            }
        }
    }
}

$items = $pdo->query("SELECT id, item_code, name, unit, quantity_on_hand, variant_label FROM items WHERE status = 'active' ORDER BY name")->fetchAll();
$variants_by_item = get_item_variants_for($pdo, array_column($items, 'id'));

$committed_by_item = [];
$committed_by_variant = [];
$c_stmt = $pdo->query("SELECT ri.item_id, ri.variant_selected, SUM(ri.quantity_requested) AS reserved_qty
                       FROM requisition_items ri
                       JOIN requisitions r ON r.id = ri.requisition_id
                       WHERE r.status = 'approved'
                       GROUP BY ri.item_id, ri.variant_selected");
foreach ($c_stmt->fetchAll() as $crow) {
    $iid = (int)$crow['item_id'];
    $rqty = (int)$crow['reserved_qty'];
    $vval = $crow['variant_selected'];
    $committed_by_item[$iid] = ($committed_by_item[$iid] ?? 0) + $rqty;
    if ($vval !== null && $vval !== '') {
        $committed_by_variant[$iid][$vval] = $rqty;
    }
}

$page_title = 'Adjust Stock';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Automated stock recording</div>
    <h1>Adjust Stock</h1>
  </div>
  <?php if ($return_to === 'item_edit' && $selected_item_id !== ''): ?>
    <a href="<?= BASE_URL ?>/inventory/item_edit.php?id=<?= (int)$selected_item_id ?>" class="btn btn-outline">&larr; Back to item</a>
  <?php else: ?>
    <a href="<?= BASE_URL ?>/inventory/stock_ledger.php" class="btn btn-outline">View history</a>
  <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card card-form">
  <form method="post" id="adjustForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to) ?>">
    <div class="form-group">
      <label for="item_id">Item</label>
      <select id="item_id" name="item_id" required>
        <option value="">— Select an item —</option>
        <?php foreach ($items as $it):
          $iv = $variants_by_item[$it['id']] ?? [];
          $it_committed = $committed_by_item[$it['id']] ?? 0;
          $variant_data = array_map(function($v) use ($it, $committed_by_variant) {
              $vc = $committed_by_variant[$it['id']][$v['variant_value']] ?? 0;
              return [
                  'id' => $v['id'],
                  'value' => $v['variant_value'],
                  'note' => $v['variant_note'],
                  'qty' => $v['quantity_on_hand'],
                  'committed' => $vc,
              ];
          }, $iv);
        ?>
          <option value="<?= $it['id'] ?>"
            data-variant-label="<?= htmlspecialchars($it['variant_label'] ?? '') ?>"
            data-qty="<?= (int)$it['quantity_on_hand'] ?>"
            data-committed="<?= $it_committed ?>"
            data-variants="<?= htmlspecialchars(json_encode($variant_data)) ?>"
            <?= (string)$selected_item_id === (string)$it['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($it['item_code'] . ' — ' . $it['name']) ?> (<?= $iv ? 'total ' : '' ?><?= (int)$it['quantity_on_hand'] ?> <?= htmlspecialchars((string)($it['unit'] ?? '')) ?><?= $it_committed > 0 ? " · {$it_committed} committed" : '' ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group hidden" id="variantGroup">
      <label for="variant_id" id="variantLabel">Option</label>
      <select id="variant_id" name="variant_id"></select>
    </div>
    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:180px;">
        <label for="quantity">Quantity</label>
        <div class="qty-stepper">
          <button type="button" class="qty-stepper-btn" id="qtyMinus" aria-label="Lower the count">&minus;</button>
          <input type="text" inputmode="numeric" id="quantity" name="quantity" value="0" required>
          <button type="button" class="qty-stepper-btn" id="qtyPlus" aria-label="Raise the count">&plus;</button>
        </div>
        <div id="committedNotice" class="text-muted-normal" style="margin-top:0.35rem; font-size:0.85rem;"></div>
      </div>
      <div class="form-group" style="flex:2; min-width:260px;">
        <label for="reason">Reason <span class="text-muted-normal">(required)</span></label>
        <input type="text" id="reason" name="reason" placeholder="e.g. Damaged in yard" required>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Record adjustment</button>
  </form>
</div>

<script>
  (function () {
    var itemSelect = document.getElementById('item_id');
    var variantGroup = document.getElementById('variantGroup');
    var variantLabel = document.getElementById('variantLabel');
    var variantSelect = document.getElementById('variant_id');
    var qtyInput = document.getElementById('quantity');
    var resultPreview = document.getElementById('resultPreview'); // no longer in the markup, kept optional
    var preselectedVariantId = <?= json_encode($selected_variant_id !== '' ? (int)$selected_variant_id : null) ?>;

    function currentOnHand() {
      var qty;
      if (variantGroup.style.display !== 'none' && variantSelect.options.length) {
        var vOpt = variantSelect.options[variantSelect.selectedIndex];
        qty = vOpt ? vOpt.dataset.qty : null;
      } else {
        var iOpt = itemSelect.options[itemSelect.selectedIndex];
        qty = iOpt ? iOpt.dataset.qty : null;
      }
      return qty !== null && qty !== undefined && qty !== '' ? parseInt(qty, 10) : null;
    }

    // Live "current → resulting" preview so the person can see exactly
    // what the on-hand count will become before they submit. The field
    // holds the actual count (not a raw delta), auto-filled with what's
    // on hand — the preview below shows what adjustment that implies,
    // so it doubles as the "am I adding or removing" indicator that the
    // old Direction toggle used to show.
    function updateResultPreview() {
      if (!resultPreview) return;
      var onHand = currentOnHand();
      var typed = parseInt(qtyInput.value, 10);
      resultPreview.classList.remove('is-negative');

      if (onHand === null || isNaN(typed)) {
        resultPreview.textContent = '';
        return;
      }
      var delta = typed - onHand;
      if (delta === 0) {
        resultPreview.textContent = onHand + ' on hand — no change';
        return;
      }
      var verb = delta > 0 ? 'Adding ' + delta : 'Removing ' + Math.abs(delta);
      resultPreview.textContent = verb + ' — ' + onHand + ' \u2192 ' + typed + ' on hand';
      if (typed < 0) { resultPreview.classList.add('is-negative'); }
    }

    function currentCommitted() {
      var comm = 0;
      if (variantGroup.style.display !== 'none' && variantSelect.options.length) {
        var vOpt = variantSelect.options[variantSelect.selectedIndex];
        comm = vOpt ? parseInt(vOpt.dataset.committed || '0', 10) : 0;
      } else {
        var iOpt = itemSelect.options[itemSelect.selectedIndex];
        comm = iOpt ? parseInt(iOpt.dataset.committed || '0', 10) : 0;
      }
      return isNaN(comm) ? 0 : comm;
    }

    var committedNotice = document.getElementById('committedNotice');
    function updateCommittedNotice() {
      if (!committedNotice) return;
      var comm = currentCommitted();
      var typed = parseInt(qtyInput.value, 10);
      if (comm > 0) {
        var warningText = '\u26A0 ' + comm + ' committed to approved requisition(s). Minimum allowed on-hand is ' + comm + '.';
        if (!isNaN(typed) && typed < comm) {
          committedNotice.innerHTML = '<span style="color:#dc2626; font-weight:600;">' + warningText + '<br>\u2716 New count is lower than committed stock!</span>';
        } else {
          committedNotice.innerHTML = '<span style="color:#d97706; font-weight:500;">' + warningText + '</span>';
        }
      } else {
        committedNotice.textContent = '';
      }
    }

    // Auto-fills the quantity field with whatever's currently on hand
    // for the selected item/option, so staff normally only need to tap
    // − / + a couple times to correct it during a recount instead of
    // typing the full number.
    function syncQtyToOnHand() {
      var onHand = currentOnHand();
      if (onHand !== null) {
        qtyInput.value = onHand;
      }
      updateResultPreview();
      updateCommittedNotice();
    }

    function syncVariants() {
      var opt = itemSelect.options[itemSelect.selectedIndex];
      var variants = opt && opt.dataset.variants ? JSON.parse(opt.dataset.variants) : [];
      if (variants.length) {
        variantSelect.innerHTML = '';
        variants.forEach(function (v) {
          var o = document.createElement('option');
          o.value = v.id;
          o.dataset.qty = v.qty;
          o.dataset.committed = v.committed || 0;
          o.textContent = v.value + ' (' + v.qty + ' on hand' + (v.committed > 0 ? ' · ' + v.committed + ' committed' : '') + ')';
          if (v.note) { o.title = v.note; }
          variantSelect.appendChild(o);
        });
        if (preselectedVariantId) {
          variantSelect.value = preselectedVariantId;
        }
        variantLabel.textContent = opt.dataset.variantLabel || 'Option';
        variantSelect.required = true;
        variantGroup.style.display = '';
      } else {
        variantSelect.innerHTML = '';
        variantSelect.required = false;
        variantGroup.style.display = 'none';
      }
      preselectedVariantId = null; // only honor the preselect once
      syncQtyToOnHand();
    }

    itemSelect.addEventListener('change', syncVariants);
    variantSelect.addEventListener('change', syncQtyToOnHand);
    qtyInput.addEventListener('input', function () {
      updateResultPreview();
      updateCommittedNotice();
    });
    syncVariants();

    // +/- stepper: nudges the count by 1 per click, never below 0 (an
    // on-hand count can't go negative).
    function stepQty(delta) {
      var current = parseInt(qtyInput.value, 10);
      if (isNaN(current)) { current = 0; }
      var next = current + delta;
      if (next < 0) { next = 0; }
      qtyInput.value = next;
      updateResultPreview();
      updateCommittedNotice();
    }
    document.getElementById('qtyMinus').addEventListener('click', function () { stepQty(-1); });
    document.getElementById('qtyPlus').addEventListener('click', function () { stepQty(1); });
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
