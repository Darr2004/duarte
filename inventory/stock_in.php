<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/assets.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$selected_item_id = $_GET['item_id'] ?? $_POST['item_id'] ?? '';
$selected_variant_id = $_GET['variant_id'] ?? $_POST['variant_id'] ?? '';

// Catalog Management opens this in a modal via fetch() rather than a full
// navigation. It sends X-Requested-With so this endpoint can answer with
// JSON instead of a redirect/HTML page. The plain-page path below still
// works unchanged for a direct/no-JS visit to the "Record stock in" link.
$is_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

// Stock in is only ever meant to be started from a specific item's
// "Record stock in" button in Catalog Management — landing here with
// no item picked yet (e.g. a bookmarked/typed URL) would otherwise let
// staff record stock through a page Catalog Management isn't managing.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $selected_item_id === '') {
    header('Location: ' . BASE_URL . '/inventory/items.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $variant_id = (int)($_POST['variant_id'] ?? 0) ?: null;
        $qty     = (int)($_POST['quantity'] ?? 0);
        $note    = trim($_POST['note'] ?? '');
        $generate_qr = !empty($_POST['generate_qr']);

        $stmt = $pdo->prepare(
            'SELECT i.variant_label, i.name, i.borrow_mode, r.room_number, s.stall_number, sl.layer_name
             FROM items i
             LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
             LEFT JOIN stalls s ON s.id = sl.stall_id
             LEFT JOIN rooms r ON r.id = s.room_id
             WHERE i.id = :id'
        );
        $stmt->execute(['id' => $item_id]);
        $item_row = $stmt->fetch();

        $item_location = null;
        if (!empty($item_row['stall_number']) && !empty($item_row['layer_name'])) {
            $item_location = 'Room ' . $item_row['room_number'] . ' · Stall ' . $item_row['stall_number'] . ' — ' . $item_row['layer_name'];
        }

        $variant_row = null;
        if ($variant_id) {
            $vstmt = $pdo->prepare('SELECT id, variant_value FROM item_variants WHERE id = :id AND item_id = :item_id');
            $vstmt->execute(['id' => $variant_id, 'item_id' => $item_id]);
            $variant_row = $vstmt->fetch();
        }

        if ($item_id < 1) $errors[] = 'Select an item.';
        if ($item_row && $item_row['variant_label'] && !$variant_id) {
            $errors[] = 'Select which ' . $item_row['variant_label'] . ' this delivery is for.';
        }
        if ($qty < 1)     $errors[] = 'Enter a quantity of at least 1.';

        if (!$errors) {
            try {
                $result = record_stock_movement($pdo, $item_id, 'stock_in', $qty, 'manual', null, $user['id'], $note ?: null, $variant_id);
                maybe_alert_stock_threshold(
                    $item_id, $result['before'], $result['after'], $result['name'], $result['unit'],
                    $result['variant_before'] ?? null, $result['variant_after'] ?? null, $result['variant_value'] ?? null
                );
                resolve_stock_alert_notifications($pdo, $item_id);
                log_audit_event($pdo, $user, 'stock_in', 'item', $item_id,
                    $user['full_name'] . ' recorded stock in of ' . $qty . ' ' . $result['unit'] . ' for "' . $result['name'] . '" (now ' . $result['after'] . ').');

                // Optional: tag this specific delivery with its own QR
                // right away — a new physical lot/batch of units just
                // arrived, so it gets its own tag(s) rather than being
                // silently merged into an existing one on the shelf.
                // Failure here never rolls back the stock-in itself,
                // same as the identical block in item_add.php.
                $created_tags = [];
                if ($generate_qr && $qty > 0) {
                    try {
                        $pdo->beginTransaction();
                        $label_bit = $variant_row ? ' (' . $variant_row['variant_value'] . ')' : '';
                        if (borrow_mode_is_borrowable($item_row['borrow_mode'])) {
                            for ($i = 0; $i < $qty; $i++) {
                                $tag = generate_unique_asset_tag($pdo);
                                $ins = $pdo->prepare(
                                    'INSERT INTO assets (item_id, item_variant_id, asset_tag, serial_number, quantity, condition_note, location_note, acquired_at, registered_by)
                                     VALUES (:item_id, :variant_id, :tag, NULL, 1, NULL, :loc, CURDATE(), :by)'
                                );
                                $ins->execute(['item_id' => $item_id, 'variant_id' => $variant_id, 'tag' => $tag, 'loc' => $item_location, 'by' => $user['id']]);
                                $asset_id = (int)$pdo->lastInsertId();
                                record_asset_event(
                                    $pdo, $asset_id, 'registered', $user,
                                    'Registered as a tracked unit of ' . $item_row['name'] . $label_bit . ' (generated at stock in).'
                                );
                                $created_tags[] = $tag;
                            }
                        } else {
                            $tag = generate_unique_asset_tag($pdo);
                            $ins = $pdo->prepare(
                                'INSERT INTO assets (item_id, item_variant_id, asset_tag, serial_number, quantity, condition_note, location_note, acquired_at, registered_by)
                                 VALUES (:item_id, :variant_id, :tag, NULL, :qty, NULL, :loc, CURDATE(), :by)'
                            );
                            $ins->execute(['item_id' => $item_id, 'variant_id' => $variant_id, 'tag' => $tag, 'qty' => $qty, 'loc' => $item_location, 'by' => $user['id']]);
                            $asset_id = (int)$pdo->lastInsertId();
                            record_asset_event(
                                $pdo, $asset_id, 'registered', $user,
                                'Registered as a lot of ' . $qty . ' ' . $item_row['name'] . $label_bit . ' (generated at stock in).'
                            );
                            $created_tags[] = $tag;
                        }
                        log_audit_event($pdo, $user, 'asset_register', 'item', $item_id,
                            $user['full_name'] . ' generated ' . count($created_tags) . ' QR tag(s) for "' . $item_row['name'] . '" at stock in.');
                        $pdo->commit();
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log($e->getMessage());
                        $created_tags = [];
                    }
                }

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'item_id' => $item_id,
                        'quantity_on_hand' => $result['after'],
                        'unit' => $result['unit'],
                        'name' => $result['name'],
                        'created_tags' => $created_tags,
                    ]);
                    exit;
                }

                if ($created_tags) {
                    header('Location: ' . BASE_URL . '/inventory/asset_add.php?print_tags=' . urlencode(implode(',', $created_tags)));
                } else {
                    header('Location: ' . BASE_URL . '/inventory/stock_ledger.php?item=' . $item_id . '&recorded=1');
                }
                exit;
            } catch (Exception $e) {
                error_log($e->getMessage());
                $errors[] = 'Something went wrong recording this stock. Please try again.';
            }
        }
    }

    // Covers a failed CSRF check above, validation errors, or the caught
    // exception — anything that leaves $errors populated for an AJAX call.
    if ($is_ajax && $errors) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}

$items = $pdo->query("SELECT id, item_code, name, unit, quantity_on_hand, variant_label FROM items WHERE status = 'active' ORDER BY name")->fetchAll();
$variants_by_item = get_item_variants_for($pdo, array_column($items, 'id'));

$page_title = 'Record Stock In';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Automated stock recording</div>
    <h1>Record Stock In</h1>
  </div>
  <a href="<?= BASE_URL ?>/inventory/stock_ledger.php" class="btn btn-outline">View history</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card card-form">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label for="item_id">Item</label>
      <select id="item_id" name="item_id" required>
        <option value="">— Select an item —</option>
        <?php foreach ($items as $it):
          $iv = $variants_by_item[$it['id']] ?? []; ?>
          <option value="<?= $it['id'] ?>"
            data-variant-label="<?= htmlspecialchars($it['variant_label'] ?? '') ?>"
            data-variants="<?= htmlspecialchars(json_encode(array_map(fn($v) => ['id' => $v['id'], 'value' => $v['variant_value'], 'note' => $v['variant_note'], 'qty' => $v['quantity_on_hand']], $iv))) ?>"
            <?= (string)$selected_item_id === (string)$it['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($it['item_code'] . ' — ' . $it['name']) ?> (<?= $iv ? 'total ' : '' ?><?= (int)$it['quantity_on_hand'] ?> <?= htmlspecialchars((string)($it['unit'] ?? '')) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group hidden" id="variantGroup">
      <label for="variant_id" id="variantLabel">Option</label>
      <select id="variant_id" name="variant_id"></select>
    </div>
    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:160px;">
        <label for="quantity">Quantity received</label>
        <input type="text" inputmode="numeric" id="quantity" name="quantity" required>
      </div>
      <div class="form-group" style="flex:2; min-width:260px;">
        <label for="note">Note <span class="text-muted-normal">(supplier, PO)</span></label>
        <input type="text" id="note" name="note" placeholder="e.g. ABC Hardware, PO-2201">
      </div>
    </div>
    <div class="form-group" id="generateQrGroup">
      <label style="display:flex; align-items:center; gap:0.5rem; font-weight:400;">
        <input type="checkbox" id="generate_qr" name="generate_qr" value="1" checked>
        Generate QR tracking tag(s) for this delivery now
      </label>
      <span style="font-size:0.8rem; color:var(--ink-soft);">One tag per unit for tools/equipment, or one tag for this whole lot for consumables. If this item has options, the tag(s) are scoped to the specific option you pick above.</span>
    </div>
    <button type="submit" class="btn btn-primary">Record stock in</button>
  </form>
</div>

<script>
  (function () {
    var itemSelect = document.getElementById('item_id');
    var variantGroup = document.getElementById('variantGroup');
    var variantLabel = document.getElementById('variantLabel');
    var variantSelect = document.getElementById('variant_id');
    var preselectedVariantId = <?= json_encode($selected_variant_id !== '' ? (int)$selected_variant_id : null) ?>;

    function syncVariants() {
      var opt = itemSelect.options[itemSelect.selectedIndex];
      var variants = opt && opt.dataset.variants ? JSON.parse(opt.dataset.variants) : [];
      if (variants.length) {
        variantSelect.innerHTML = '';
        variants.forEach(function (v) {
          var o = document.createElement('option');
          o.value = v.id;
          o.textContent = v.value + ' (' + v.qty + ' on hand)';
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
      preselectedVariantId = null;
    }

    itemSelect.addEventListener('change', syncVariants);
    syncVariants();
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
