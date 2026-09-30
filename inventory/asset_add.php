<?php
/**
 * DuaRTE — QR Code Asset Tracking: Register asset(s).
 *
 * Two registration shapes, chosen automatically from the selected
 * item's borrow_mode (see includes/assets.php for the full reasoning):
 *   - borrow_mode 'borrow'/'choice' (tools/equipment): creates one row
 *     PER PHYSICAL UNIT, each its own permanent QR tag — "5 hammers"
 *     creates 5 separate assets/5 separate tags, never one shared tag.
 *   - borrow_mode 'consume' (bolts, gasoline, etc.): creates ONE row
 *     for the whole lot, with quantity set to how many units are in
 *     it — one tag on the box, not one per bolt.
 *
 * If the chosen item has catalog options (item_variants — e.g. several
 * Part # choices under one "Oil filter" listing), a specific option
 * MUST be picked first: a C-110 filter and a D-306 filter are
 * different physical parts, so a tag/lot always belongs to exactly one
 * option, never the item's blended aggregate stock.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];

$values = [
    'item_id'       => $_GET['item_id'] ?? '',
    'variant_id'    => $_GET['variant_id'] ?? '',
    'quantity'      => 1,
    'serial_prefix' => '',
    'acquired_at'   => date('Y-m-d'),
    'location_note' => '',
];

$created_tags = [];
$from_item_creation = false;
$is_reprint = isset($_GET['reprint']);

// Arrived here straight from Add Item, which already created the asset
// row(s) itself — just show the same print-labels view, no re-submit.
if (isset($_GET['print_tags']) && trim($_GET['print_tags']) !== '') {
    $requested_tags = array_values(array_filter(array_map('trim', explode(',', $_GET['print_tags']))));
    if ($requested_tags) {
        // Not restricted to the original registrant — any inventory_staff/admin
        // (already enforced by require_role above) can reprint a lost/damaged
        // label for an asset someone else registered.
        $placeholders = implode(',', array_fill(0, count($requested_tags), '?'));
        $check = $pdo->prepare("SELECT asset_tag FROM assets WHERE asset_tag IN ($placeholders)");
        $check->execute($requested_tags);
        $created_tags = $check->fetchAll(PDO::FETCH_COLUMN);
        $from_item_creation = (bool)$created_tags;
    }
}

if (!$from_item_creation && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $values['item_id']       = $_POST['item_id'] ?? '';
        $values['variant_id']    = $_POST['variant_id'] ?? '';
        $values['quantity']      = max(1, (int)($_POST['quantity'] ?? 1));
        $values['serial_prefix'] = trim($_POST['serial_prefix'] ?? '');
        $values['acquired_at']   = trim($_POST['acquired_at'] ?? '') ?: date('Y-m-d');
        $values['location_note'] = trim($_POST['location_note'] ?? '');

        if ($values['item_id'] === '') {
            $errors[] = 'Choose which catalog item these units belong to.';
        }
        if ($values['quantity'] > 100) {
            $errors[] = 'Register at most 100 units at a time.';
        }

        $item = null;
        $item_borrow_mode = null;
        $variant = null;
        if (!$errors) {
            $stmt = $pdo->prepare("SELECT * FROM items WHERE id = :id AND status = 'active'");
            $stmt->execute(['id' => $values['item_id']]);
            $item = $stmt->fetch();
            if (!$item) {
                $errors[] = 'That item was not found.';
            } else {
                $item_borrow_mode = $item['borrow_mode'];

                // Items with catalog options can't be registered at the
                // item level — the label on the box has to say exactly
                // which Part #/option it is.
                if ($item['variant_label']) {
                    if ($values['variant_id'] === '') {
                        $errors[] = 'Select which ' . $item['variant_label'] . ' this is for — this item has multiple options and they are not interchangeable.';
                    } else {
                        $vstmt = $pdo->prepare('SELECT * FROM item_variants WHERE id = :id AND item_id = :item_id');
                        $vstmt->execute(['id' => $values['variant_id'], 'item_id' => $item['id']]);
                        $variant = $vstmt->fetch();
                        if (!$variant) {
                            $errors[] = 'That option was not found for this item.';
                        }
                    }
                }
            }
        }

        if (!$errors && $item) {
            try {
                $pdo->beginTransaction();

                $variant_id = $variant ? (int)$variant['id'] : null;
                $label_bit = $variant ? ' (' . $variant['variant_value'] . ')' : '';

                if (borrow_mode_is_borrowable($item_borrow_mode)) {
                    // Per-unit: one row, one tag, per physical piece.
                    for ($i = 0; $i < $values['quantity']; $i++) {
                        $tag = generate_unique_asset_tag($pdo);
                        $serial = $values['serial_prefix'] !== ''
                            ? $values['serial_prefix'] . '-' . ($i + 1)
                            : null;

                        $ins = $pdo->prepare(
                            'INSERT INTO assets (item_id, item_variant_id, asset_tag, serial_number, quantity, condition_note, location_note, acquired_at, registered_by)
                             VALUES (:item_id, :variant_id, :tag, :serial, 1, NULL, :location, :acquired_at, :by)'
                        );
                        $ins->execute([
                            'item_id'      => $item['id'],
                            'variant_id'   => $variant_id,
                            'tag'          => $tag,
                            'serial'       => $serial,
                            'location'     => $values['location_note'] ?: null,
                            'acquired_at'  => $values['acquired_at'],
                            'by'           => $user['id'],
                        ]);
                        $asset_id = (int)$pdo->lastInsertId();

                        record_asset_event(
                            $pdo, $asset_id, 'registered', $user,
                            'Registered as a tracked unit of ' . $item['name'] . $label_bit . '.'
                        );

                        $created_tags[] = $tag;
                    }
                } else {
                    // Consumable: ONE row for the whole lot, tag covers the batch.
                    $tag = generate_unique_asset_tag($pdo);
                    $serial = $values['serial_prefix'] !== '' ? $values['serial_prefix'] : null;

                    $ins = $pdo->prepare(
                        'INSERT INTO assets (item_id, item_variant_id, asset_tag, serial_number, quantity, condition_note, location_note, acquired_at, registered_by)
                         VALUES (:item_id, :variant_id, :tag, :serial, :qty, NULL, :location, :acquired_at, :by)'
                    );
                    $ins->execute([
                        'item_id'      => $item['id'],
                        'variant_id'   => $variant_id,
                        'tag'          => $tag,
                        'serial'       => $serial,
                        'qty'          => $values['quantity'],
                        'location'     => $values['location_note'] ?: null,
                        'acquired_at'  => $values['acquired_at'],
                        'by'           => $user['id'],
                    ]);
                    $asset_id = (int)$pdo->lastInsertId();

                    record_asset_event(
                        $pdo, $asset_id, 'registered', $user,
                        'Registered as a lot of ' . $values['quantity'] . ' ' . $item['name'] . $label_bit . '.'
                    );

                    $created_tags[] = $tag;
                }

                $audit_desc = borrow_mode_is_borrowable($item_borrow_mode)
                    ? $user['full_name'] . ' registered ' . $values['quantity'] . ' tracked unit(s) of "' . $item['name'] . $label_bit . '".'
                    : $user['full_name'] . ' registered a lot of ' . $values['quantity'] . ' "' . $item['name'] . $label_bit . '".';
                log_audit_event($pdo, $user, 'asset_register', 'item', (int)$item['id'], $audit_desc);

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $errors[] = 'Something went wrong registering these assets. Please try again.';
                $created_tags = [];
            }
        }
    }
}

$catalog_items = $pdo->query(
    "SELECT i.id, i.item_code, i.name, i.borrow_mode, i.quantity_on_hand, i.unit, i.variant_label,
            r.room_number, s.stall_number, sl.layer_name
     FROM items i
     LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
     LEFT JOIN stalls s ON s.id = sl.stall_id
     LEFT JOIN rooms r ON r.id = s.room_id
     WHERE i.status = 'active' ORDER BY i.name"
)->fetchAll();
$variants_by_item = get_item_variants_for($pdo, array_column($catalog_items, 'id'));
$proper_locations = $pdo->query(
    "SELECT s.stall_number, sl.layer_name, CONCAT('Room ', r.room_number, ' · Stall ', s.stall_number, ' — ', sl.layer_name) AS label
     FROM stalls s
     JOIN rooms r ON r.id = s.room_id
     JOIN stall_layers sl ON sl.stall_id = s.id
     ORDER BY r.room_number, s.stall_number, sl.layer_number"
)->fetchAll();

// After a successful registration, show the printable labels instead of
// the form again — the whole point of registering is to walk away with
// tags to print and stick on the actual tools.
$show_labels = !empty($created_tags);
$label_assets = [];
if ($show_labels) {
    $placeholders = implode(',', array_fill(0, count($created_tags), '?'));
    $stmt = $pdo->prepare(
        "SELECT a.*, i.name AS item_name, i.item_code, i.borrow_mode, iv.variant_value
         FROM assets a
         JOIN items i ON i.id = a.item_id
         LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
         WHERE a.asset_tag IN ($placeholders)"
    );
    $stmt->execute($created_tags);
    $label_assets = $stmt->fetchAll();
}

$page_title = 'Register Asset';
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= BASE_URL ?>/inventory/assets.php" class="back-link">&larr; Back to Assets</a>
<div class="page-header">
  <div>
    <div class="eyebrow">QR code asset tracking</div>
    <h1><?= $show_labels ? 'Print QR Labels' : 'Register Asset' ?></h1>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if ($show_labels): ?>
  <?php $is_lot_batch = $label_assets && !borrow_mode_is_borrowable($label_assets[0]['borrow_mode']); ?>
  <div class="alert alert-success">
    <?php if ($from_item_creation && !$is_reprint): ?>
      Item added to the catalog<?php if ($is_lot_batch): ?> and its lot QR tag was generated<?php else: ?> and its QR tag(s) were generated<?php endif; ?>.
    <?php endif; ?>
    <?php if ($is_reprint): ?>
      Reprinting the existing label<?= count($label_assets) > 1 ? 's' : '' ?> below — the tag itself is unchanged, so this is safe to print as many times as you need.
    <?php elseif ($is_lot_batch): ?>
      Print this label and attach it to the box/container — scanning it shows what's left in this lot.
    <?php else: ?>
      <?= count($label_assets) ?> asset(s) registered. Print these labels and attach one to each physical unit — each tag is unique and permanent for that unit's life.
    <?php endif; ?>
  </div>

  <div class="card" style="max-width:640px;">
    <div style="display:flex; gap:1rem; flex-wrap:wrap; justify-content:center;">
      <?php foreach ($label_assets as $a): ?>
        <div class="asset-label" style="border:1px dashed var(--line); border-radius:8px; padding:0.75rem; width:170px; text-align:center;">
          <div class="asset-label-qr" data-tag="<?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?>" style="display:flex; justify-content:center; margin-bottom:0.4rem;"></div>
          <div style="font-size:0.75rem; font-weight:600;"><?= htmlspecialchars((string)($a['item_name'] ?? '')) ?></div>
          <?php if ($a['variant_value']): ?><div style="font-size:0.72rem; color:var(--ink-soft); font-weight:600;"><?= htmlspecialchars((string)($a['variant_value'] ?? '')) ?></div><?php endif; ?>
          <?php if ($is_lot_batch): ?><div style="font-size:0.7rem; color:var(--ink-soft);">Lot of <?= (int)$a['quantity'] ?></div><?php endif; ?>
          <?php if ($a['serial_number']): ?><div style="font-size:0.7rem; color:var(--ink-soft);"><?= htmlspecialchars((string)($a['serial_number'] ?? '')) ?></div><?php endif; ?>
          <div class="mono" style="font-size:0.68rem; color:var(--ink-soft); word-break:break-all; margin-top:0.2rem;"><?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex; gap:0.75rem; margin-top:1.25rem; justify-content:center; align-items:center;">
      <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ Print labels</button>
      <?php if ($is_reprint && count($label_assets) === 1): ?>
        <a href="<?= BASE_URL ?>/inventory/asset_view.php?tag=<?= urlencode($label_assets[0]['asset_tag']) ?>" class="btn btn-outline">← Back to Asset</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/inventory/assets.php" class="btn btn-outline">Done — go to Assets</a>
      <?php endif; ?>
    </div>
  </div>

  <script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
  <script>
    if (typeof QRCode === 'undefined') {
      document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
    }
  </script>
  <script>
    document.querySelectorAll('.asset-label-qr').forEach(function (el) {
      new QRCode(el, {
        text: <?= json_encode(BASE_URL) ?> + '/inventory/asset_view.php?tag=' + el.dataset.tag,
        width: 120,
        height: 120,
        colorDark: '#1F2430',
        colorLight: '#ffffff'
      });
    });
  </script>
  <style>
    @media print {
      .sidebar, .main-topbar, .page-header, .back-link, .alert, form { display: none !important; }
    }
  </style>
<?php else: ?>
  <div class="card" style="max-width:480px;">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="form-group">
        <label for="item_id">Item <span style="color:var(--red-danger);">*</span></label>
        <select id="item_id" name="item_id" required>
          <option value="">Select an item…</option>
          <?php foreach ($catalog_items as $it):
            $iv = $variants_by_item[$it['id']] ?? [];
            $item_location = $it['stall_number'] ? 'Room ' . (int)$it['room_number'] . ' · Stall ' . (int)$it['stall_number'] . ' — ' . $it['layer_name'] : ''; ?>
            <option value="<?= $it['id'] ?>"
              data-mode="<?= htmlspecialchars((string)($it['borrow_mode'] ?? '')) ?>"
              data-qty="<?= (int)$it['quantity_on_hand'] ?>"
              data-unit="<?= htmlspecialchars((string)($it['unit'] ?? '')) ?>"
              data-variant-label="<?= htmlspecialchars($it['variant_label'] ?? '') ?>"
              data-variants="<?= htmlspecialchars(json_encode(array_map(fn($v) => ['id' => $v['id'], 'value' => $v['variant_value'], 'note' => $v['variant_note'], 'qty' => $v['quantity_on_hand']], $iv))) ?>"
              data-location="<?= htmlspecialchars($item_location) ?>"
              <?= (string)$values['item_id'] === (string)$it['id'] ? 'selected' : '' ?>><?= htmlspecialchars($it['item_code'] . ' — ' . $it['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$catalog_items): ?>
          <span style="font-size:0.8rem; color:var(--ink-soft);">No active items yet — add one in Catalog Management first.</span>
        <?php endif; ?>
        <span style="font-size:0.8rem; color:var(--ink-soft);">Borrowable items (tools/equipment) get one QR tag per physical unit. Consumable items get one QR tag for the whole lot.</span>
      </div>
      <div class="form-group hidden" id="variantGroup">
        <label for="variant_id" id="variantLabel">Option <span style="color:var(--red-danger);">*</span></label>
        <select id="variant_id" name="variant_id"></select>
        <span style="font-size:0.8rem; color:var(--ink-soft);">Select specific variant.</span>
      </div>
      <div class="form-group">
        <label for="quantity" id="quantity_label">Units to register</label>
        <input type="number" id="quantity" name="quantity" min="1" max="100" value="<?= (int)$values['quantity'] ?>" required>
        <span id="quantity_source" style="font-size:0.78rem; color:var(--ink-soft); display:none;">Based on current stock (<span id="quantity_source_val"></span>).</span>
        <span id="quantity_hint" style="font-size:0.8rem; color:var(--ink-soft);">One QR tag per unit.</span>
      </div>
      <div class="form-group" id="serial_prefix_group">
        <label for="serial_prefix">Serial number prefix <span style="font-weight:400; color:var(--ink-soft);">(optional)</span></label>
        <input type="text" id="serial_prefix" name="serial_prefix" value="<?= htmlspecialchars((string)($values['serial_prefix'] ?? '')) ?>" placeholder="e.g. HMR">
        <span style="font-size:0.8rem; color:var(--ink-soft);">Units auto-numbered (e.g. HMR-1).</span>
      </div>
      <div class="form-group">
        <label for="acquired_at">Date acquired</label>
        <input type="date" id="acquired_at" name="acquired_at" value="<?= htmlspecialchars((string)($values['acquired_at'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="location_note">Starting location <span style="font-weight:400; color:var(--ink-soft);">(optional)</span></label>
        <input type="text" id="location_note" name="location_note" list="proper_locations_list" value="<?= htmlspecialchars((string)($values['location_note'] ?? '')) ?>" placeholder="e.g. Stall 2, Tool room">
        <datalist id="proper_locations_list">
          <?php foreach ($proper_locations as $loc): ?>
            <option value="<?= htmlspecialchars((string)($loc['label'] ?? '')) ?>">
          <?php endforeach; ?>
        </datalist>
        <span id="location_source" style="font-size:0.78rem; color:var(--ink-soft); display:none;">Pulled from this item's shelf location in Catalog Management — change it if these units are starting somewhere else.</span>
      </div>
      <button type="submit" class="btn btn-primary">Register &amp; generate QR tags</button>
      <script>
        (function () {
          var select = document.getElementById('item_id');
          var qty = document.getElementById('quantity');
          var qtyLabel = document.getElementById('quantity_label');
          var qtyHint = document.getElementById('quantity_hint');
          var qtySource = document.getElementById('quantity_source');
          var qtySourceVal = document.getElementById('quantity_source_val');
          var serialGroup = document.getElementById('serial_prefix_group');
          var variantGroup = document.getElementById('variantGroup');
          var variantLabel = document.getElementById('variantLabel');
          var variantSelect = document.getElementById('variant_id');
          var locationNote = document.getElementById('location_note');
          var locationSource = document.getElementById('location_source');
          var lastAutoLocation = '';
          var preselectedVariantId = <?= json_encode($values['variant_id'] !== '' ? (int)$values['variant_id'] : null) ?>;

          // Fills the quantity field from whichever source currently
          // applies (a picked option's own stock, or the item's
          // aggregate when it has no options) — still editable in case
          // the physical count differs from what's recorded.
          function fillQtyFrom(stockQty, unit) {
            stockQty = parseInt(stockQty, 10) || 0;
            qty.value = stockQty > 0 ? stockQty : 1;
            qtySourceVal.textContent = stockQty + ' ' + (unit || 'pc');
            qtySource.style.display = '';
          }

          // Pulls the item's own shelf location (set in Catalog
          // Management) into the starting-location field — only when
          // the field still holds what WE last auto-filled, so it never
          // clobbers something staff typed in themselves.
          function fillLocationFrom(opt) {
            var loc = opt ? opt.dataset.location : '';
            if (locationNote.value !== '' && locationNote.value !== lastAutoLocation) {
              return; // staff already typed their own value — leave it alone
            }
            if (loc) {
              locationNote.value = loc;
              lastAutoLocation = loc;
              locationSource.style.display = '';
            } else {
              if (locationNote.value === lastAutoLocation) {
                locationNote.value = '';
              }
              lastAutoLocation = '';
              locationSource.style.display = 'none';
            }
          }

          function syncVariants() {
            var opt = select.options[select.selectedIndex];
            var variants = opt && opt.dataset.variants ? JSON.parse(opt.dataset.variants) : [];
            var unit = opt ? opt.dataset.unit : 'pc';

            if (variants.length) {
              variantSelect.innerHTML = '<option value="">Select…</option>';
              variants.forEach(function (v) {
                var o = document.createElement('option');
                o.value = v.id;
                o.dataset.qty = v.qty;
                o.textContent = v.value + ' (' + v.qty + ' ' + unit + ' on hand)';
                if (v.note) { o.title = v.note; }
                variantSelect.appendChild(o);
              });
              if (preselectedVariantId) {
                variantSelect.value = preselectedVariantId;
              }
              variantLabel.childNodes[0].textContent = (opt.dataset.variantLabel || 'Option') + ' ';
              variantSelect.required = true;
              variantGroup.classList.remove('hidden');
              // Quantity comes from the SPECIFIC option once picked —
              // the item's blended total would mix different parts.
              qtySource.style.display = 'none';
              qty.value = 1;
            } else {
              variantSelect.innerHTML = '';
              variantSelect.required = false;
              variantGroup.classList.add('hidden');
              if (opt && opt.value !== '') {
                fillQtyFrom(opt.dataset.qty, unit);
              } else {
                qtySource.style.display = 'none';
              }
            }
            preselectedVariantId = null;
          }

          function syncModeLabels() {
            var opt = select.options[select.selectedIndex];
            var mode = opt ? opt.dataset.mode : null;
            var isLot = mode === 'consume';
            qtyLabel.childNodes[0].textContent = isLot ? 'Units in this lot ' : 'Units to register ';
            qtyHint.textContent = isLot
              ? 'One QR tag per lot.'
              : 'One QR tag per unit.';
            serialGroup.style.display = isLot ? 'none' : '';
          }

          function sync() {
            syncModeLabels();
            syncVariants();
            fillLocationFrom(select.options[select.selectedIndex]);
          }

          variantSelect.addEventListener('change', function () {
            var vopt = variantSelect.options[variantSelect.selectedIndex];
            var itemOpt = select.options[select.selectedIndex];
            if (vopt && vopt.value !== '') {
              fillQtyFrom(vopt.dataset.qty, itemOpt ? itemOpt.dataset.unit : 'pc');
            } else {
              qtySource.style.display = 'none';
            }
          });

          select.addEventListener('change', sync);
          sync();
        })();
      </script>
    </form>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
