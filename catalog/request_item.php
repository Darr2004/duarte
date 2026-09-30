<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/item_requests.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['driver_helper', 'field_supervisor']);

$pdo = get_db();
$user = current_user();
$errors = [];

// Pre-fill from an existing (out-of-stock) catalog item when arriving
// via "Request this item" in the browse modal; otherwise the requester
// types the item name themselves (item not in the catalog at all).
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : (isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0);
$catalog_item = null;
if ($item_id) {
    $stmt = $pdo->prepare("SELECT id, name, unit FROM items WHERE id = :id AND status = 'active'");
    $stmt->execute(['id' => $item_id]);
    $catalog_item = $stmt->fetch();
    if (!$catalog_item) {
        $item_id = 0;
    }
}

$variant_param = trim($_GET['variant'] ?? ($_POST['variant'] ?? ''));
$default_item_name = $catalog_item ? $catalog_item['name'] : '';
$default_reason = '';
if ($catalog_item && $variant_param !== '') {
    $default_item_name = $catalog_item['name'] . ' (' . $variant_param . ')';
    $default_reason = 'Out of stock for option: ' . $variant_param;
}

$unit_options = item_request_unit_options();
$container_units = item_request_container_units();

// When requesting an existing catalog item, the unit is locked to
// whatever that item is already tracked in (e.g. "Brake Pads (set)"
// is always requested in sets) — the requester doesn't choose it.
// For a free-text item (not in the catalog), the requester picks the
// unit themselves so "1" isn't ambiguous between a piece and a set.
$locked_unit = $catalog_item['unit'] ?? null;

$posted_unit = $_POST['unit'] ?? 'pc';
$values = [
    'item_name'       => trim($_POST['item_name'] ?? $default_item_name),
    'quantity'        => max(1, (int)($_POST['quantity'] ?? 1)),
    'unit'            => $locked_unit ?? (array_key_exists($posted_unit, $unit_options) ? $posted_unit : 'pc'),
    'pieces_per_unit' => isset($_POST['pieces_per_unit']) ? max(1, (int)$_POST['pieces_per_unit']) : null,
    'reason'          => trim($_POST['reason'] ?? $default_reason),
];
$is_container_unit = in_array($values['unit'], $container_units, true);
if (!$is_container_unit) {
    $values['pieces_per_unit'] = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        if ($values['item_name'] === '') {
            $errors[] = 'Please enter the item name.';
        }
        if ($values['reason'] === '') {
            $errors[] = 'Please tell Inventory Staff why you need this item.';
        }

        // Free-text requests (no catalog item_id) need the unit tagged
        // onto `reason` since there's no dedicated column for it —
        // catalog-linked requests don't need this, their unit is read
        // straight from items.unit at display time instead.
        $stored_reason = $values['reason'];
        if (!$item_id) {
            $stored_reason = item_request_encode_reason($values['reason'], $values['unit'], $values['pieces_per_unit']);
            if (strlen($stored_reason) > 255) {
                $errors[] = 'Reason is too long — please shorten it a bit.';
            }
        }

        $image_filename = null;
        if (!$errors) {
            try {
                $image_filename = handle_item_image_upload($_FILES['image'] ?? [], 'request_');
            } catch (RuntimeException $e) {
                $errors[] = clean_error_message($e);
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO item_requests (requester_id, item_id, item_name, quantity, reason, image_filename)
                 VALUES (:requester_id, :item_id, :item_name, :quantity, :reason, :image)'
            );
            $stmt->execute([
                'requester_id' => $user['id'],
                'item_id'      => $item_id ?: null,
                'item_name'    => $values['item_name'],
                'quantity'     => $values['quantity'],
                'reason'       => $stored_reason,
                'image'        => $image_filename,
            ]);
            $new_id = (int)$pdo->lastInsertId();

            notify_role('inventory_staff',
                $user['full_name'] . ' requested "' . $values['item_name'] . '".',
                BASE_URL . '/inventory/item_requests.php');

            log_audit_event($pdo, $user, 'item_request_create', 'item_request', $new_id,
                $user['full_name'] . ' requested "' . $values['item_name'] . '".');

            header('Location: ' . BASE_URL . '/catalog/my_item_requests.php?submitted=1');
            exit;
        }
    }
}

$page_title = 'Request to Purchase (PO)';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Procurement &amp; Purchasing</div>
    <h1>Request to Purchase (PO)</h1>
  </div>
</div>

<div class="card" style="max-width:640px;">
  <div class="section-sub" style="margin-bottom:1.1rem; line-height:1.45;">
    <strong>Wala sa warehouse?</strong> Mag-request dito.
  </div>

  <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

  <form method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <?php if ($item_id): ?><input type="hidden" name="item_id" value="<?= $item_id ?>"><?php endif; ?>

    <div class="form-group">
      <label for="item_name">Item name</label>
      <input type="text" id="item_name" name="item_name" value="<?= htmlspecialchars((string)($values['item_name'] ?? '')) ?>"
        placeholder="e.g. Generator 5kW" <?= $catalog_item ? 'readonly' : '' ?> required>
      <?php if ($catalog_item): ?>
        <div class="section-sub" style="margin-top:0.3rem;">Out-of-stock catalog item.</div>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label for="quantity">Quantity needed</label>
      <div style="display:flex; gap:0.6rem; align-items:flex-start; flex-wrap:wrap;">
        <input type="number" id="quantity" name="quantity" min="1" value="<?= (int)$values['quantity'] ?>" style="max-width:140px;" required>

        <?php if ($locked_unit): ?>
          <input type="hidden" name="unit" value="<?= htmlspecialchars($locked_unit) ?>">
          <div style="padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; background:var(--bg-soft, #f5f5f5); min-width:120px;">
            <?= htmlspecialchars(item_request_unit_label_plural($locked_unit)) ?>
          </div>
        <?php else: ?>
          <select aria-label="Unit" id="unit" name="unit" style="max-width:160px;" required>
            <?php foreach ($unit_options as $val => $label): ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= $values['unit'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <?php if ($locked_unit): ?>
        <div class="section-sub" style="margin-top:0.3rem;">Tracked in <?= htmlspecialchars(strtolower(item_request_unit_label_plural($locked_unit))) ?>.</div>
      <?php endif; ?>
    </div>

    <div class="form-group" id="piecesPerUnitGroup" style="<?= $is_container_unit ? '' : 'display:none;' ?>">
      <label for="pieces_per_unit" id="piecesPerUnitLabel">How many pieces per <?= htmlspecialchars(strtolower(item_request_unit_label($values['unit']))) ?>?</label>
      <input type="number" id="pieces_per_unit" name="pieces_per_unit" min="1"
        value="<?= $values['pieces_per_unit'] ? (int)$values['pieces_per_unit'] : '' ?>" style="max-width:140px;">
      <div class="section-sub" style="margin-top:0.3rem;">
        e.g. 4 for a set.
      </div>
    </div>

    <div class="form-group" style="margin-bottom:1.1rem;">
      <label for="reason" style="font-weight:600; display:block; margin-bottom:0.35rem;">Reason for Request</label>
      <select id="reason_preset" class="form-control" style="width:100%; padding:0.5rem 0.65rem; border:1px solid var(--line); border-radius:6px; margin-bottom:0.45rem; font-size:0.85rem; background:var(--surface);">
        <option value="">-- Pumili ng Dahilan (Preset) o mag-type sa ibaba --</option>
        <option value="Wala sa bodega / Out of stock sa warehouse">Wala sa bodega / Out of stock sa warehouse</option>
        <option value="Emergency replacement para sa sirang pyesa ng truck">Emergency replacement para sa sirang pyesa ng truck</option>
        <option value="Kailangang espesyal na kagamitan / Specific tool para sa biyahe">Kailangang espesyal na kagamitan / Specific tool para sa biyahe</option>
        <option value="Nauubos nang consumable supplies (Replenishment)">Nauubos nang consumable supplies (Replenishment)</option>
        <option value="Para sa bagong project site / client cargo requirement">Para sa bagong project site / client cargo requirement</option>
        <option value="__custom__">Iba pa / Custom (Mag-type ng sariling dahilan)...</option>
      </select>
      <textarea id="reason" name="reason" rows="3" required
        placeholder="Pumili sa itaas o mag-type ng dahilan ng request"
        style="width:100%; padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; font-family:var(--font-body);"><?= htmlspecialchars((string)($values['reason'] ?? '')) ?></textarea>
    </div>

    <div class="form-group">
      <label for="image">Photo (optional)</label>
      <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
      <div class="section-sub" style="margin-top:0.3rem;">JPG/PNG/WEBP, max 3MB.</div>
    </div>

    <button type="submit" class="btn btn-primary">Submit PO Request</button>
    <a href="<?= BASE_URL ?>/catalog/browse.php" class="btn btn-outline">Cancel</a>
  </form>
</div>

<script>
  // Preset reason selector autofill
  (function () {
    var preset = document.getElementById('reason_preset');
    var input = document.getElementById('reason');
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
</script>

<?php if (!$locked_unit): ?>
<script>
  (function () {
    var unitSelect = document.getElementById('unit');
    var group = document.getElementById('piecesPerUnitGroup');
    var label = document.getElementById('piecesPerUnitLabel');
    var containerUnits = <?= json_encode($container_units) ?>;
    var unitLabels = <?= json_encode($unit_options) ?>;

    function sync() {
      var unit = unitSelect.value;
      if (containerUnits.indexOf(unit) !== -1) {
        group.style.display = '';
        label.textContent = 'How many pieces per ' + unitLabels[unit].toLowerCase() + '?';
      } else {
        group.style.display = 'none';
      }
    }

    unitSelect.addEventListener('change', sync);
    sync();
  })();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
