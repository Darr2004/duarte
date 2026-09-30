<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/cart.php';
require_role(['admin', 'inventory_staff', 'driver_helper', 'field_supervisor']);

$pdo = get_db();
$user = current_user();
$flash_success = null;
$flash_error = null;

// Add to cart (Personnel only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item_id'])) {
    if (!in_array($user['role'], ['driver_helper', 'field_supervisor'], true)) {
        $flash_error = 'Only Personnel accounts can build a requisition.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $flash_error = 'Your session expired. Please try again.';
    } else {
        $item_id = (int)$_POST['add_item_id'];
        $qty     = max(1, (int)($_POST['add_qty'] ?? 1));
        $days    = isset($_POST['add_days']) ? min(30, max(1, (int)$_POST['add_days'])) : null;
        $variant = trim($_POST['add_variant'] ?? '') ?: null;
        $requested_mode = $_POST['add_mode'] ?? null;

        $stmt = $pdo->prepare("SELECT i.*, c.name AS category_name, c.code_prefix FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = :id AND i.status = 'active'");
        $stmt->execute(['id' => $item_id]);
        $item = $stmt->fetch();

        $is_office_staff_user = ($user['position'] ?? null) === 'office_staff';

        if (!$item) {
            $flash_error = 'That item is no longer available.';
        } elseif ($is_office_staff_user && $item['category_name'] !== 'Office Supplies' && $item['code_prefix'] !== 'OS') {
            $flash_error = 'Office Staff accounts are restricted to requesting Office Supplies only.';
        } elseif ($item['variant_label'] && !$variant) {
            $flash_error = 'Please choose a ' . $item['variant_label'] . ' for ' . $item['name'] . '.';
        } else {
            // Checks what's actually left after other pending/approved
            // requisitions' reservations — not just the raw stock count
            // — so a fully-reserved item correctly shows "out of stock"
            // right here, instead of only failing later at Submit.
            $stock = available_stock_for($pdo, $item, $variant);

            if (!$stock['ok']) {
                $flash_error = 'That ' . $item['variant_label'] . ' option isn\'t available for ' . $item['name'] . '.';
            } elseif ($stock['available'] < 1) {
                $flash_error = $stock['label'] . ' is currently out of stock.';
            } else {
                $already = cart_get()[$item_id]['qty'] ?? 0;
                if ($already + $qty > $stock['available']) {
                    $flash_error = 'Only ' . $stock['available'] . ' ' . $item['unit']
                        . ' of ' . $stock['label'] . ' available — adjust the quantity in your cart.';
                } else {
                    $mode = resolve_line_borrow_mode($item['borrow_mode'], $requested_mode);
                    cart_add($item_id, $qty, $mode, $mode === 'borrow' ? $days : null, $variant);
                    $flash_success = $item['name'] . ' added to your cart.';
                }
            }
        }
    }
}

$search   = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';

// Stall/Layer location is operational data for Inventory Staff only —
// Personnel and Field Supervisor accounts never see it, so it's
// only joined in when the viewer is staff.
$show_location = $user['role'] === 'inventory_staff';
$location_select = $show_location ? ', r.room_number, s.stall_number, sl.layer_name' : '';
$location_join = $show_location
    ? ' LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id LEFT JOIN stalls s ON s.id = sl.stall_id LEFT JOIN rooms r ON r.id = s.room_id'
    : '';
$is_office_staff = ($user['position'] ?? null) === 'office_staff';

$sql = "SELECT i.*, c.name AS category_name,
               COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ") AS low_stock_threshold
               {$location_select}
        FROM items i
        LEFT JOIN categories c ON c.id = i.category_id{$location_join}
        WHERE i.status = 'active'";
$params = [];
if ($is_office_staff) {
    $sql .= " AND (c.name = 'Office Supplies' OR c.code_prefix = 'OS')";
}
if ($search !== '') {
    $sql .= " AND (i.name LIKE :q1 OR i.item_code LIKE :q2 OR i.brand LIKE :q3 OR i.description LIKE :q4)";
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
    $params['q4'] = "%$search%";
}
if ($category !== '') {
    $sql .= " AND i.category_id = :cat";
    $params['cat'] = $category;
}
$sql .= " ORDER BY i.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$variants_by_item = get_item_variants_for($pdo, array_column($items, 'id'));

$active_loans_by_item = [];
if (!empty($items)) {
    $item_ids_list = array_column($items, 'id');
    $ph = implode(',', array_fill(0, count($item_ids_list), '?'));
    $loans_stmt = $pdo->prepare("SELECT item_id, COUNT(*) c, MIN(due_date) earliest_due FROM tool_loans WHERE returned_at IS NULL AND item_id IN ($ph) GROUP BY item_id");
    $loans_stmt->execute($item_ids_list);
    foreach ($loans_stmt->fetchAll() as $lr) {
        $active_loans_by_item[(int)$lr['item_id']] = [
            'count'        => (int)$lr['c'],
            'earliest_due' => $lr['earliest_due'],
        ];
    }
}

// Group sibling rows that share the same name + brand + category and
// don't already have their own DB-level variant_label into a single
// card with a "Specification" dropdown — e.g. the 4 separate "Pylox
// Spray Paint / Nippon Paint" rows become one card with a color
// picker. This is purely a display/add-to-cart convenience: the rows
// stay exactly as they are in the items table, each keeping its own
// item_code and quantity_on_hand. Picking an option just changes
// which underlying item_id gets added to the cart.
$solo_items = [];
$buckets = [];
foreach ($items as $it) {
    if (!empty($it['variant_label'])) {
        $solo_items[] = $it;
        continue;
    }
    $key = mb_strtolower(trim($it['name'])) . '|' . mb_strtolower(trim($it['brand'] ?? '')) . '|' . $it['category_id'];
    $buckets[$key][] = $it;
}
$cards = [];
foreach ($buckets as $group) {
    if (count($group) === 1) {
        $solo_items[] = $group[0];
    } else {
        $cards[] = $group;
    }
}
foreach ($solo_items as $it) {
    $cards[] = [$it];
}
usort($cards, fn($a, $b) => strcasecmp($a[0]['name'], $b[0]['name']));

if ($is_office_staff) {
    $categories = $pdo->query("SELECT id, name FROM categories WHERE name = 'Office Supplies' OR code_prefix = 'OS' ORDER BY name")->fetchAll();
} else {
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
}

$page_title = 'Browse Catalog';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Digital inventory catalog</div>
    <h1>Browse Catalog</h1>
    <?php if ($is_office_staff): ?>
      <div style="display:inline-block; margin-top:0.35rem; padding:0.25rem 0.6rem; border-radius:4px; font-size:0.8rem; background:var(--amber-tint, #fef3c7); color:var(--amber-dim, #92400e); font-weight:600;">
        Office Staff: Office Supplies only
      </div>
    <?php endif; ?>
  </div>
  <?php if (in_array($user['role'], ['driver_helper', 'field_supervisor'], true)): ?>
    <a href="<?= BASE_URL ?>/catalog/request_item.php" class="btn btn-outline btn-sm" style="margin-right:0.5rem;">Can't find it? Request to Purchase (PO)</a>
    <a href="<?= BASE_URL ?>/requisition/cart.php" id="cartNavLink" class="cart-icon-btn" aria-label="Open cart">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="9" cy="21" r="1"></circle>
        <circle cx="20" cy="21" r="1"></circle>
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
      </svg>
      <span id="cartBadge" class="cart-icon-badge" style="<?= $cart_count > 0 ? '' : 'display:none;' ?>"><?= $cart_count ?></span>
    </a>
  <?php endif; ?>
</div>

<?php if ($flash_error): ?><div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>
<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>

<form method="get" class="filter-bar" id="catalogFilterForm">
  <div class="form-group grow">
    <label for="q">Search</label>
    <div class="search-input-wrap">
      <input type="text" id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search catalog" autocomplete="off">
    </div>
  </div>
  <div class="form-group">
    <label for="category">Category</label>
    <select id="category" name="category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $category == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($c['name'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($search !== '' || $category !== ''): ?>
    <a href="<?= BASE_URL ?>/catalog/browse.php" class="filter-clear-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear filters
    </a>
  <?php endif; ?>
</form>

<?php if (!$items): ?>
  <div class="empty-state">No items match your search. Try a different keyword or category.</div>
<?php else: ?>
  <div class="empty-state hidden" id="live-search-empty">No items match "<span id="live-search-empty-term"></span>". Try a different keyword or category.</div>
  <div class="catalog-grid">
    <?php foreach ($cards as $group):
      $is_group = count($group) > 1;

      // Representative row for display fields that must be a single
      // value (thumb, code shown before a choice is made, etc). Prefer
      // whichever sibling has a photo.
      $it = $group[0];
      foreach ($group as $gi) {
          if (!empty($gi['image_filename'])) { $it = $gi; break; }
      }

      $total_qty = array_sum(array_column($group, 'quantity_on_hand'));
      // Siblings in a group always share the same category (grouping key
      // includes category_id), so any member's threshold applies to all.
      $st = stock_status($total_qty, (int)$it['low_stock_threshold']);

      if ($is_group) {
          $variant_label   = 'Specification';
          $variant_options = array_map(fn($gi) => [
              'value'       => $gi['specification'] ?: $gi['item_code'],
              'note'        => null,
              'qty'         => (int)$gi['quantity_on_hand'],
              'image'       => $gi['image_filename'] ? ITEM_UPLOAD_URL . $gi['image_filename'] : null,
              'itemId'      => (int)$gi['id'],
              'code'        => $gi['item_code'],
              'description' => $gi['description'] ?? '',
          ], $group);
          $search_terms = strtolower($it['name'] . ' ' . ($it['brand'] ?? ''));
          foreach ($group as $gi) {
              $search_terms .= ' ' . strtolower($gi['item_code'] . ' ' . ($gi['specification'] ?? ''));
          }
      } else {
          $variant_label   = $it['variant_label'] ?? '';
          $db_variants     = $variants_by_item[$it['id']] ?? [];
          $variant_options = array_map(fn($v) => [
              'value'       => $v['variant_value'],
              'note'        => $v['variant_note'],
              'qty'         => $v['quantity_on_hand'],
              'image'       => $v['image_filename'] ? ITEM_UPLOAD_URL . $v['image_filename'] : null,
              'itemId'      => null,
              'code'        => null,
              'description' => null,
          ], $db_variants);
          $search_terms = strtolower($it['item_code'] . ' ' . $it['name'] . ' ' . ($it['brand'] ?? ''));
          $loan_info = $active_loans_by_item[$it['id']] ?? null;
      }
      ?>
      <button
        type="button"
        class="item-card item-card-trigger"
        data-id="<?= $it['id'] ?>"
        data-code="<?= htmlspecialchars((string)($it['item_code'] ?? '')) ?>"
        data-name="<?= htmlspecialchars((string)($it['name'] ?? '')) ?>"
        data-brand="<?= htmlspecialchars($it['brand'] ?? '') ?>"
        data-category="<?= htmlspecialchars($it['category_name'] ?? 'Uncategorized') ?>"
        data-description="<?= htmlspecialchars($it['description'] ?? '') ?>"
        data-spec="<?= $is_group ? '' : htmlspecialchars($it['specification'] ?? '') ?>"
        data-unit="<?= htmlspecialchars((string)($it['unit'] ?? '')) ?>"
        data-qty="<?= (int)$total_qty ?>"
        data-threshold="<?= (int)$it['low_stock_threshold'] ?>"
        data-borrowable="<?= $it['is_borrowable'] ? '1' : '0' ?>"
        data-borrow-mode="<?= htmlspecialchars((string)($it['borrow_mode'] ?? '')) ?>"
        data-loan-count="<?= $loan_info ? $loan_info['count'] : 0 ?>"
        data-loan-due="<?= $loan_info ? htmlspecialchars((string)($loan_info['earliest_due'] ?? '')) : '' ?>"
        data-variant-label="<?= htmlspecialchars($variant_label) ?>"
        data-grouped="<?= $is_group ? '1' : '0' ?>"
        data-variants="<?= htmlspecialchars(json_encode($variant_options)) ?>"
        data-image="<?= $it['image_filename'] ? ITEM_UPLOAD_URL . htmlspecialchars((string)($it['image_filename'] ?? '')) : '' ?>"
        data-initials="<?= htmlspecialchars(item_initials($it['name'])) ?>"
        <?php if ($show_location): ?>
        data-location="<?= htmlspecialchars($it['stall_number'] ? 'Room ' . (int)$it['room_number'] . ' · Stall ' . (int)$it['stall_number'] . ' — ' . $it['layer_name'] : '') ?>"
        <?php endif; ?>
        data-search="<?= htmlspecialchars($search_terms) ?>"
      >
        <div class="thumb">
          <?php if ($it['image_filename']): ?>
            <img src="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($it['image_filename'] ?? '')) ?>" alt="<?= htmlspecialchars((string)($it['name'] ?? '')) ?>">
          <?php else: ?>
            <span class="placeholder"><?= htmlspecialchars(item_initials($it['name'])) ?></span>
          <?php endif; ?>
        </div>
        <div class="body">
          <div class="code"><span class="searchable-text"><?= htmlspecialchars($is_group ? $it['item_code'] . ' + ' . (count($group) - 1) : $it['item_code']) ?></span></div>
          <div class="name"><span class="searchable-text"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></span><?php if (!empty($it['brand'])): ?> <span class="brand-tag"><?= htmlspecialchars((string)($it['brand'] ?? '')) ?></span><?php endif; ?></div>
          <?php if (!empty($it['description'])): ?>
            <div class="desc" style="font-size:0.82rem; color:var(--ink-soft); margin:0.15rem 0 0.35rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;"><?= htmlspecialchars((string)($it['description'] ?? '')) ?></div>
          <?php endif; ?>
          <div class="cat"><?= htmlspecialchars($it['category_name'] ?? 'Uncategorized') ?>
            <?php if ($it['borrow_mode'] === 'borrow'): ?>
              <span class="badge role ml-sm">Tool — must return</span>
            <?php elseif ($it['borrow_mode'] === 'choice'): ?>
              <span class="badge role ml-sm">Consume or borrow</span>
            <?php endif; ?>
            <?php if (!empty($variant_label)): ?>
              <span class="badge role ml-sm"><?= htmlspecialchars($variant_label) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($show_location): ?>
            <div class="desc" style="font-size:0.78rem; color:var(--ink-soft); margin-top:0.1rem;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px; margin-right:0.2rem;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <?= $it['stall_number'] ? 'Room ' . (int)$it['room_number'] . ' · Stall ' . (int)$it['stall_number'] . ' — ' . htmlspecialchars((string)($it['layer_name'] ?? '')) : 'Location not set' ?>
            </div>
          <?php endif; ?>
          <div class="stock-row">
            <span class="badge qty-badge <?= $st['class'] ?>"><?= (int)$total_qty ?> <?= htmlspecialchars((string)($it['unit'] ?? '')) ?></span>
          </div>
        </div>
      </button>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/live-table-search.js"></script>
<script src="<?= BASE_URL ?>/assets/js/search-suggest.js"></script>
<script>
  initLiveTableSearch({ inputId: 'q', rowSelector: '.item-card-trigger', emptyRowId: 'live-search-empty', emptyTermId: 'live-search-empty-term' });
  initSearchSuggest({ inputId: 'q', type: 'catalog' });
</script>

<?php if (in_array($user['role'], ['driver_helper', 'field_supervisor'], true)): ?>
<div class="item-modal-backdrop" id="itemModalBackdrop"></div>
<div class="item-modal" id="itemModal" role="dialog" aria-modal="true" aria-hidden="true">
  <button type="button" class="item-modal-close" id="itemModalClose" aria-label="Close">&times;</button>
  <div class="item-modal-thumb" id="itemModalThumb"></div>
  <div class="item-modal-body">
    <div class="item-detail-grid">
      <div class="detail-field detail-field-full">
        <span class="detail-label">Item Code</span>
        <span class="detail-value" id="itemModalCode"></span>
      </div>
      <div class="detail-row-split">
        <div class="detail-field">
          <span class="detail-label">Brand Name</span>
          <span class="detail-value" id="itemModalBrand"></span>
        </div>
        <div class="detail-field" id="itemModalMeasurementField">
          <span class="detail-label">Unit</span>
          <span class="detail-value" id="itemModalMeasurement"></span>
        </div>
      </div>
      <div class="detail-row-split">
        <div class="detail-field">
          <span class="detail-label">Item Name</span>
          <span class="detail-value" id="itemModalName"></span>
        </div>
        <div class="detail-field" id="itemModalSpecField">
          <span class="detail-label">Specification</span>
          <span class="detail-value" id="itemModalSpec"></span>
        </div>
      </div>
      <div class="detail-field detail-field-full">
        <span class="detail-label">Description / Purpose</span>
        <span class="detail-value" id="itemModalDescription"></span>
      </div>
    </div>

    <div class="cat" id="itemModalCategory" style="margin:0.7rem 0 0;"></div>

    <div class="stock-row" style="margin:0.7rem 0;">
      <span class="badge qty-badge" id="itemModalStockQty"></span>
    </div>

    <div id="itemModalOutOfStock" class="alert alert-error" style="display:none; margin:0.8rem 0;">
      <div style="font-weight:600; margin-bottom:0.25rem;">This item is currently out of stock.</div>
      <div id="itemModalLoanNotice" style="font-size:0.85rem; margin-bottom:0.5rem; display:none; color:var(--ink-soft); line-height:1.4;"></div>
      <div style="display:flex; flex-direction:column; gap:0.45rem; margin-top:0.6rem;">
        <button type="button" id="itemModalNotifyBtn" class="btn btn-primary btn-sm" style="display:flex; align-items:center; justify-content:center; gap:0.45rem; font-weight:600; padding:0.6rem 1rem;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
          <span id="itemModalNotifyText">Notify Me When Available</span>
        </button>
        <div id="itemModalNotifyFeedback" style="font-size:0.8rem; color:var(--ink); display:none; text-align:center; padding:0.4rem 0.6rem; border-radius:var(--radius-sm); background:rgba(255,255,255,0.85); font-weight:500;"></div>
        <a href="#" id="itemModalRequestLink" class="btn btn-outline btn-sm" style="display:block; text-align:center;">Request Purchase (PO) if needed</a>
      </div>
    </div>

    <form method="post" class="js-add-to-cart" id="itemModalForm" style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.5rem;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="add_item_id" id="itemModalItemId" value="">
      <div class="form-group" id="itemModalVariantGroup" style="flex:1 1 100%; display:none;">
        <label for="itemModalVariant" id="itemModalVariantLabel">Size</label>
        <select id="itemModalVariant" name="add_variant" style="width:100%; padding:0.5rem;"></select>
      </div>
      <div id="itemModalOrderFields" style="display:flex; flex-wrap:wrap; gap:0.5rem; width:100%;">
        <div class="form-group" style="flex:1; min-width:9rem;">
          <label for="itemModalQty">Quantity</label>
          <div class="qty-stepper">
            <button type="button" class="qty-step-btn" id="itemModalQtyMinus" aria-label="Decrease quantity">&minus;</button>
            <input type="text" inputmode="numeric" id="itemModalQty" name="add_qty" value="1">
            <button type="button" class="qty-step-btn" id="itemModalQtyPlus" aria-label="Increase quantity">+</button>
          </div>
        </div>
        <div class="form-group" id="itemModalModeGroup" style="flex:1 1 100%; display:none;">
          <label style="display:block; margin-bottom:0.4rem;">How do you need this?</label>
          <div class="mode-toggle">
            <label class="mode-toggle-option">
              <input type="radio" name="add_mode" value="consume" id="itemModalModeConsume" checked>
              <span>Consume<small>Keep it</small></span>
            </label>
            <label class="mode-toggle-option">
              <input type="radio" name="add_mode" value="borrow" id="itemModalModeBorrow">
              <span>Borrow<small>Return it</small></span>
            </label>
          </div>
        </div>
        <div class="form-group" id="itemModalDaysGroup" style="flex:1; min-width:8rem; display:none;">
          <label for="itemModalDays">Borrow for (business days)</label>
          <input type="number" inputmode="numeric" id="itemModalDays" name="add_days" min="1" max="30" step="1" value="3" style="width:100%; padding:0.5rem;">
          <p style="margin:0.3rem 0 0; font-size:0.76rem; color:var(--ink-soft);">Sat/Sun hindi bilang — 1–30 days lang.</p>
        </div>
        <button type="submit" class="btn btn-primary" id="itemModalSubmit" style="flex:1 1 100%;">Add to cart</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (in_array($user['role'], ['driver_helper', 'field_supervisor'], true)): ?>
<script>
  (function () {
    var DEFAULT_STOCK_ALERT_THRESHOLD = <?= (int)DEFAULT_STOCK_ALERT_THRESHOLD ?>;
    var currentThreshold = DEFAULT_STOCK_ALERT_THRESHOLD;
    var backdrop  = document.getElementById('itemModalBackdrop');
    var modal     = document.getElementById('itemModal');
    var closeBtn  = document.getElementById('itemModalClose');
    if (!modal) return;

    var thumb     = document.getElementById('itemModalThumb');
    var codeEl    = document.getElementById('itemModalCode');
    var nameEl    = document.getElementById('itemModalName');
    var brandEl   = document.getElementById('itemModalBrand');
    var measurementField = document.getElementById('itemModalMeasurementField');
    var measurementEl = document.getElementById('itemModalMeasurement');
    var specField = document.getElementById('itemModalSpecField');
    var specEl    = document.getElementById('itemModalSpec');
    var catEl     = document.getElementById('itemModalCategory');
    var descEl    = document.getElementById('itemModalDescription');
    var stockQty  = document.getElementById('itemModalStockQty');
    var outOfStock = document.getElementById('itemModalOutOfStock');
    var requestLink = document.getElementById('itemModalRequestLink');
    var notifyBtn = document.getElementById('itemModalNotifyBtn');
    var notifyText = document.getElementById('itemModalNotifyText');
    var notifyFeedback = document.getElementById('itemModalNotifyFeedback');
    var itemIdInput = document.getElementById('itemModalItemId');
    var qtyInput  = document.getElementById('itemModalQty');
    var qtyMinus  = document.getElementById('itemModalQtyMinus');
    var qtyPlus   = document.getElementById('itemModalQtyPlus');
    var daysGroup = document.getElementById('itemModalDaysGroup');
    var daysSelect = document.getElementById('itemModalDays');
    var modeGroup = document.getElementById('itemModalModeGroup');
    var modeConsumeRadio = document.getElementById('itemModalModeConsume');
    var modeBorrowRadio = document.getElementById('itemModalModeBorrow');
    var orderFields = document.getElementById('itemModalOrderFields');
    var loanNotice  = document.getElementById('itemModalLoanNotice');

    function checkSubscriptionStatus() {
      if (!notifyBtn) return;
      var itemId = itemIdInput.value;
      var variant = variantSelect && variantSelect.value ? variantSelect.value : '';
      fetch('<?= BASE_URL ?>/catalog/subscribe_api.php?item_id=' + encodeURIComponent(itemId) + '&variant=' + encodeURIComponent(variant))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.subscribed) {
            setNotifyBtnSubscribed(true);
          } else {
            setNotifyBtnSubscribed(false);
          }
        })
        .catch(function () {});
    }

    function setNotifyBtnSubscribed(isSubbed) {
      if (!notifyBtn) return;
      if (isSubbed) {
        notifyBtn.classList.remove('btn-primary');
        notifyBtn.classList.add('btn-secondary');
        notifyText.textContent = 'Subscribed (We\'ll notify you)';
        notifyBtn.dataset.subscribed = '1';
      } else {
        notifyBtn.classList.remove('btn-secondary');
        notifyBtn.classList.add('btn-primary');
        notifyText.textContent = 'Notify Me When Available';
        notifyBtn.dataset.subscribed = '0';
      }
    }

    if (notifyBtn) {
      notifyBtn.addEventListener('click', function () {
        var itemId = itemIdInput.value;
        var variant = variantSelect && variantSelect.value ? variantSelect.value : '';
        var csrf = document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '';
        notifyBtn.disabled = true;
        fetch('<?= BASE_URL ?>/catalog/subscribe_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ item_id: itemId, variant: variant, action: 'toggle', csrf_token: csrf })
        })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            notifyBtn.disabled = false;
            if (data.success) {
              setNotifyBtnSubscribed(data.subscribed);
              if (notifyFeedback) {
                notifyFeedback.textContent = data.message;
                notifyFeedback.style.display = 'block';
                setTimeout(function () { notifyFeedback.style.display = 'none'; }, 4000);
              }
            } else if (data.error) {
              alert(data.error);
            }
          })
          .catch(function () {
            notifyBtn.disabled = false;
          });
      });
    }

    function refreshDaysVisibility() {
      var borrowMode = modal.dataset.currentBorrowMode || 'consume';
      var isChoice = borrowMode === 'choice';
      var wantsBorrow = borrowMode === 'borrow' || (isChoice && modeBorrowRadio.checked);
      if (isChoice) {
        // Reserve the field's space at all times on choice items so
        // toggling Consume/Borrow only fades it, instead of resizing
        // (and re-centering) the whole modal.
        daysGroup.style.display = '';
        daysGroup.classList.toggle('is-hidden', !wantsBorrow);
        daysSelect.disabled = !wantsBorrow;
      } else {
        daysGroup.classList.remove('is-hidden');
        daysGroup.style.display = wantsBorrow ? '' : 'none';
      }
    }
    if (modeConsumeRadio && modeBorrowRadio) {
      modeConsumeRadio.addEventListener('change', refreshDaysVisibility);
      modeBorrowRadio.addEventListener('change', refreshDaysVisibility);
    }
    var variantGroup = document.getElementById('itemModalVariantGroup');
    var variantLabel = document.getElementById('itemModalVariantLabel');
    var variantSelect = document.getElementById('itemModalVariant');
    var submitBtn = document.getElementById('itemModalSubmit');

    var currentUnit = '';
    var currentVariants = [];
    var currentItemImage = '';
    var currentItemName = '';
    var currentInitials = '';
    var isGrouped = false;

    // Shows the selected option's own photo if it has one (e.g. a
    // close-up of that specific Part #), otherwise falls back to the
    // item's own photo, then to the initials placeholder.
    function refreshThumb() {
      var chosen = currentVariants.length
        ? currentVariants.filter(function (v) { return v.value === variantSelect.value; })[0]
        : null;
      var imgUrl = (chosen && chosen.image) ? chosen.image : currentItemImage;
      thumb.innerHTML = '';
      if (imgUrl) {
        var thumbImg = document.createElement('img');
        thumbImg.src = imgUrl;
        thumbImg.alt = currentItemName;
        thumb.appendChild(thumbImg);
      } else {
        var placeholderSpan = document.createElement('span');
        placeholderSpan.className = 'placeholder';
        placeholderSpan.textContent = currentInitials;
        thumb.appendChild(placeholderSpan);
      }
    }

    // Reflects stock for whatever's currently selected: the specific
    // variant if this item has variant options, otherwise the item's
    // own (single, shared) count.
    function refreshStockDisplay() {
      var qty, label;

      if (currentVariants.length) {
        var chosen = currentVariants.filter(function (v) { return v.value === variantSelect.value; })[0];
        qty = chosen ? chosen.qty : 0;
        label = variantSelect.value ? (variantSelect.value + ': ') : '';
      } else {
        qty = parseInt(stockQty.dataset.totalQty || '0', 10);
        label = '';
      }

      var cls = qty === 0
        ? 'out-stock'
        : (qty <= currentThreshold ? 'low-stock' : 'in-stock');

      stockQty.textContent = label + qty + ' ' + currentUnit;
      stockQty.className = 'badge qty-badge ' + cls;

      // Grouped cards (siblings with the same name/brand merged into
      // one card) don't share one underlying item — each dropdown
      // option IS a different item row. Switch the id (and the
      // per-row fields) submitted on "Add" to match.
      if (isGrouped && currentVariants.length) {
        var picked = currentVariants.filter(function (v) { return v.value === variantSelect.value; })[0];
        if (picked) {
          itemIdInput.value = picked.itemId;
          if (picked.code) { codeEl.textContent = picked.code; }
          descEl.textContent = picked.description || 'No description provided.';
        }
      }

      var inStock = qty > 0;
      outOfStock.style.display = inStock ? 'none' : 'block';
      if (orderFields) {
        orderFields.style.display = inStock ? 'flex' : 'none';
      }
      qtyInput.max = qty;
      if (!inStock) {
        var vVal = (variantSelect && variantSelect.value) ? '&variant=' + encodeURIComponent(variantSelect.value) : '';
        requestLink.href = '<?= BASE_URL ?>/catalog/request_item.php?item_id=' + encodeURIComponent(itemIdInput.value) + vVal;
        if (loanNotice) {
          var loanCount = parseInt(modal.dataset.currentLoanCount || '0', 10);
          var loanDue = modal.dataset.currentLoanDue || '';
          if (loanCount > 0 && (modal.dataset.currentBorrowMode === 'borrow' || modal.dataset.currentBorrowMode === 'choice')) {
            loanNotice.textContent = loanCount + ' unit(s) currently borrowed / on loan. Earliest expected return: ' + loanDue + '.';
            loanNotice.style.display = 'block';
          } else {
            loanNotice.style.display = 'none';
          }
        }
        checkSubscriptionStatus();
      }
    }

    function openModal(card) {
      var d = card.dataset;
      isGrouped = d.grouped === '1';
      modal.dataset.currentLoanCount = d.loanCount || '0';
      modal.dataset.currentLoanDue = d.loanDue || '';
      codeEl.textContent = d.code;
      nameEl.textContent = d.name;
      brandEl.textContent = d.brand || '—';

      // Measurement (unit of stock, e.g. pc / box / set) is always present
      // on an item, so this field always shows.
      measurementEl.textContent = d.unit || '—';
      measurementField.style.display = '';

      // Specification is optional per item — hide the field entirely when
      // there isn't one, so Item Name expands to fill the row.
      if (d.spec) {
        specEl.textContent = d.spec;
        specField.style.display = '';
      } else {
        specField.style.display = 'none';
      }

      var modeLabel = { borrow: ' — Tool, must return', choice: ' — Consume or borrow, your choice', consume: '' };
      catEl.textContent  = d.category + (modeLabel[d.borrowMode] || '');
      descEl.textContent = d.description || 'No description provided.';

      currentItemImage = d.image || '';
      currentItemName = d.name || '';
      currentInitials = d.initials || '';

      currentUnit = d.unit;
      stockQty.dataset.totalQty = d.qty;
      currentThreshold = d.threshold ? parseInt(d.threshold, 10) : DEFAULT_STOCK_ALERT_THRESHOLD;

      itemIdInput.value = d.id;
      qtyInput.value = 1;
      daysSelect.value = 3;
      modal.dataset.currentBorrowMode = d.borrowMode || 'consume';
      modeConsumeRadio.checked = true;
      modeGroup.style.display = d.borrowMode === 'choice' ? '' : 'none';
      refreshDaysVisibility();

      currentVariants = d.variants ? JSON.parse(d.variants) : [];

      if (currentVariants.length) {
        variantSelect.innerHTML = '';
        currentVariants.forEach(function (v) {
          var o = document.createElement('option');
          o.value = v.value;
          o.textContent = v.value + ' — ' + v.qty + ' ' + d.unit + (v.qty === 0 ? ' (out of stock)' : '');
          if (v.note) { o.title = v.note; }
          o.disabled = v.qty === 0;
          variantSelect.appendChild(o);
        });
        // Default to the first option that actually has stock, if any.
        var firstInStock = currentVariants.filter(function (v) { return v.qty > 0; })[0];
        variantSelect.value = firstInStock ? firstInStock.value : currentVariants[0].value;
        variantLabel.textContent = 'Select ' + (d.variantLabel || 'an option');
        variantSelect.required = true;
        variantGroup.style.display = '';
      } else {
        variantSelect.innerHTML = '';
        variantSelect.required = false;
        variantGroup.style.display = 'none';
      }

      refreshThumb();
      refreshStockDisplay();

      modal.classList.add('open');
      backdrop.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      // On mobile the modal is a bottom sheet that already covers most of
      // the viewport; the fixed bottom-nav sitting on top of it (higher
      // z-index) was clipping the sheet's own footer content. Tuck the
      // nav away while the sheet is open so the sheet gets that space back.
      document.body.classList.add('item-modal-open');
    }

    function closeModal() {
      modal.classList.remove('open');
      backdrop.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('item-modal-open');
    }

    document.querySelectorAll('.item-card-trigger').forEach(function (card) {
      card.addEventListener('click', function () { openModal(card); });
    });

    variantSelect.addEventListener('change', function () {
      refreshThumb();
      refreshStockDisplay();
      qtyInput.value = 1;
    });

    function currentMax() {
      var max = parseInt(qtyInput.max, 10);
      return isNaN(max) ? Infinity : max;
    }

    qtyMinus.addEventListener('click', function () {
      var val = parseInt(qtyInput.value, 10) || 1;
      qtyInput.value = Math.max(1, val - 1);
    });

    qtyPlus.addEventListener('click', function () {
      var val = parseInt(qtyInput.value, 10) || 1;
      qtyInput.value = Math.min(currentMax(), val + 1);
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();
    });
  })();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>


