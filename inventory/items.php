<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploads.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$is_admin = ($user['role'] ?? '') === 'admin';
$flash_error = null;
$flash_success = null;

// Toggle active/inactive (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    if (!$is_admin) {
        log_audit_event($pdo, $user, 'catalog_deactivate_blocked', 'item', (int)$_POST['toggle_id'],
            $user['full_name'] . ' attempted to deactivate/activate item #' . (int)$_POST['toggle_id'] . ' — blocked (Admin only).');
        $flash_error = 'Permission Denied: Only an Administrator can activate or deactivate catalog items.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $flash_error = 'Your session expired. Please try again.';
    } else {
        $stmt = $pdo->prepare(
            "UPDATE items SET status = IF(status='active','inactive','active') WHERE id = :id"
        );
        $stmt->execute(['id' => (int)$_POST['toggle_id']]);
        log_audit_event($pdo, $user, 'catalog_status_toggled', 'item', (int)$_POST['toggle_id'],
            $user['full_name'] . ' toggled status of item #' . (int)$_POST['toggle_id'] . '.');
        $flash_success = 'Item status updated.';
    }
}

if (isset($_GET['recorded']) && $_GET['recorded'] === '1') {
    $flash_success = 'Stock recorded.';
}

$search   = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';
$low_stock_only = isset($_GET['low_stock']) && $_GET['low_stock'] === '1';

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = " WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (i.name LIKE :q1 
               OR i.item_code LIKE :q2 
               OR i.brand LIKE :q3 
               OR i.description LIKE :q4 
               OR s.stall_number LIKE :q5 
               OR sl.layer_name LIKE :q6)";
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
    $params['q4'] = "%$search%";
    $params['q5'] = "%$search%";
    $params['q6'] = "%$search%";
}
if ($category !== '') {
    $where .= " AND i.category_id = :cat";
    $params['cat'] = $category;
}
if ($low_stock_only) {
    $where .= " AND i.status = 'active' AND (
        i.quantity_on_hand <= COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ")
        OR EXISTS (
            SELECT 1 FROM item_variants iv
            WHERE iv.item_id = i.id
              AND iv.quantity_on_hand <= COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ")
        )
    )";
}

$count_sql = "SELECT COUNT(*) c
              FROM items i
              LEFT JOIN categories c ON c.id = i.category_id
              LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
              LEFT JOIN stalls s ON s.id = sl.stall_id" . $where;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_items = (int)$count_stmt->fetch()['c'];

$total_pages = max(1, (int)ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT i.*, c.name AS category_name, r.room_number, s.stall_number, sl.layer_name,
               COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ") AS low_stock_threshold
        FROM items i
        LEFT JOIN categories c ON c.id = i.category_id
        LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
        LEFT JOIN stalls s ON s.id = sl.stall_id
        LEFT JOIN rooms r ON r.id = s.room_id" . $where . "
        ORDER BY i.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();
$variants_by_item = get_item_variants_for($pdo, array_column($items, 'id'));

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$page_title = 'Catalog Management';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Digital inventory catalog</div>
    <h1>Catalog Management</h1>
  </div>
  <a href="<?= BASE_URL ?>/inventory/item_add.php" class="btn btn-primary">+ Add item</a>
</div>

<?php if ($flash_error): ?><div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>
<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
<?php if ($low_stock_only): ?>
  <div class="alert" style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
    <span>Showing only items low or out of stock.</span>
    <a href="<?= BASE_URL ?>/inventory/items.php" class="btn btn-outline btn-sm">Clear filter</a>
  </div>
<?php endif; ?>

<form method="get" class="filter-bar" id="itemFilterForm">
  <div class="form-group grow">
    <label for="q">Search</label>
    <div class="search-input-wrap">
      <input type="text" id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search items" autocomplete="off">
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
  <?php if ($low_stock_only): ?><input type="hidden" name="low_stock" value="1"><?php endif; ?>
  <?php if ($search !== '' || $category !== '' || $low_stock_only): ?>
    <a href="<?= BASE_URL ?>/inventory/items.php" class="filter-clear-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear filters
    </a>
  <?php endif; ?>
</form>

<div class="card" style="padding-bottom:0; overflow:hidden;">
  <div class="table-responsive">
    <table class="data">
      <thead>
        <tr>
          <th style="width:38%;">Item &amp; Details</th>
          <th style="width:20%;">Category &amp; Location</th>
          <th style="width:16%;">Stock &amp; Qty</th>
          <th style="width:10%;">Status</th>
          <th style="width:16%; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="5" style="text-align:center; padding:2rem 1rem;">No items match your filters.</td></tr>
        <?php else: foreach ($items as $it):
          $st = stock_status((int)$it['quantity_on_hand'], (int)$it['low_stock_threshold']);
          $iv = $variants_by_item[$it['id']] ?? [];
          $has_depleted_variant = false;
          $has_low_variant = false;
          $variant_search_str = '';
          foreach ($iv as $v) {
              $variant_search_str .= ' ' . $v['variant_value'] . ' ' . ($v['variant_note'] ?? '');
              if ((int)$v['quantity_on_hand'] <= 0) {
                  $has_depleted_variant = true;
              } elseif ((int)$v['quantity_on_hand'] <= (int)$it['low_stock_threshold']) {
                  $has_low_variant = true;
              }
          }

          $brand_for_search = $it['brand'] ?? '';
          $stall_for_search = $it['stall_number'] ? 'room ' . $it['room_number'] . ' stall ' . $it['stall_number'] . ' ' . $it['layer_name'] : '';
          $search_blob = strtolower($it['item_code'] . ' ' . $it['name'] . ' ' . $brand_for_search . ' ' . $stall_for_search . ' ' . ($it['description'] ?? '') . $variant_search_str);

          $brand = $it['brand'] ?? null;
          $description = $it['description'] ?? '';
          if (($brand === null || $brand === '') && $description !== '' && preg_match('/Brand\/Supplier:\s*([^;]+)/i', $description, $m)) {
              $brand = trim($m[1]);
              $description = trim(preg_replace('/Brand\/Supplier:\s*[^;]+;?\s*/i', '', $description));
          }

          $detail_bits = [];
          if ($description !== '') $detail_bits[] = $description;
          if (!empty($it['specification'])) $detail_bits[] = $it['specification'];
          $detail_str = $detail_bits ? implode(' — ', $detail_bits) : '';
        ?>
          <tr class="item-row" data-search="<?= htmlspecialchars($search_blob) ?>">
            <td class="item-primary-col" data-label="Item">
              <div class="item-info-wrapper">
                <?php if (!empty($it['image_filename'])): ?>
                  <button type="button" class="item-thumb-trigger" data-image="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($it['image_filename'] ?? '')) ?>" data-name="<?= htmlspecialchars((string)($it['name'] ?? '')) ?>" title="View photo" aria-label="View photo of <?= htmlspecialchars((string)($it['name'] ?? '')) ?>">
                    <img class="item-thumb-lg" src="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($it['image_filename'] ?? '')) ?>" alt="<?= htmlspecialchars((string)($it['name'] ?? '')) ?>">
                  </button>
                <?php else: ?>
                  <span class="item-thumb-lg item-thumb-placeholder" title="No photo uploaded"><?= htmlspecialchars(item_initials($it['name'])) ?></span>
                <?php endif; ?>
                <div class="item-text-stack">
                  <div class="item-title"><span class="searchable-text"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></span></div>
                  <div class="item-meta-sub">
                    <span class="item-code-badge"><span class="searchable-text"><?= htmlspecialchars((string)($it['item_code'] ?? '')) ?></span></span>
                    <?php if ($brand !== null && $brand !== ''): ?>
                      <span class="item-brand-tag"><?= htmlspecialchars($brand) ?></span>
                    <?php endif; ?>
                    <?php if ($detail_str !== ''): ?>
                      <span class="item-spec-tag" title="<?= htmlspecialchars($detail_str) ?>"><?= htmlspecialchars($detail_str) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($it['variant_label']) && count($iv) > 0): 
                      $v_summary = implode(', ', array_map(fn($v) => $v['variant_value'] . ' (' . (int)$v['quantity_on_hand'] . ')', $iv));
                    ?>
                      <span class="item-brand-tag" style="background:var(--accent-tint, rgba(142,75,40,0.1)); color:var(--accent, #8e4b28); border-color:transparent; font-weight:600;" title="<?= htmlspecialchars($v_summary) ?>">
                        <?= htmlspecialchars((string)($it['variant_label'] ?? '')) ?>: <?= count($iv) ?> options
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>

            <td data-label="Category &amp; Location">
              <div class="category-cell-name"><?= htmlspecialchars($it['category_name'] ?? '—') ?></div>
              <?php if ($it['stall_number']): ?>
                <div class="location-subline" title="Stored in Room <?= (int)$it['room_number'] ?>, Stall <?= (int)$it['stall_number'] ?>, <?= htmlspecialchars((string)($it['layer_name'] ?? '')) ?>">
                  <span>Room <?= (int)$it['room_number'] ?> &middot; Stall <?= (int)$it['stall_number'] ?> &middot; <?= htmlspecialchars((string)($it['layer_name'] ?? '')) ?></span>
                </div>
              <?php else: ?>
                <div class="location-subline"><span style="opacity:0.6;">Unassigned location</span></div>
              <?php endif; ?>
            </td>

            <td data-label="Stock">
              <div class="stock-qty-text mono"><?= (int)$it['quantity_on_hand'] ?> <span style="font-size:0.8rem; font-weight:normal; color:var(--ink-soft);"><?= htmlspecialchars((string)($it['unit'] ?? '')) ?></span></div>
              <span class="badge <?= $st['class'] ?>"><?= $st['label'] ?></span>
              <?php if ($st['class'] === 'in-stock'): ?>
                <?php if ($has_depleted_variant): ?>
                  <div style="margin-top:0.25rem;"><span class="badge out-stock" style="font-size:0.68rem; padding:1px 5px;" title="At least one variant option is out of stock">Option out</span></div>
                <?php elseif ($has_low_variant): ?>
                  <div style="margin-top:0.25rem;"><span class="badge low-stock" style="font-size:0.68rem; padding:1px 5px;" title="At least one variant option is running low">Option low</span></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>

            <td data-label="Status">
              <span class="badge <?= $it['status'] ?>"><?= htmlspecialchars(ucfirst($it['status'])) ?></span>
            </td>

            <td class="table-actions-cell" data-label="Actions">
              <div class="table-actions-toolbar">
                <a href="<?= BASE_URL ?>/inventory/stock_in.php?item_id=<?= $it['id'] ?>"
                   class="btn-table-stockin <?= $st['class'] === 'out-stock' ? 'is-urgent' : '' ?> js-open-stockin"
                   data-item-id="<?= $it['id'] ?>"
                   data-item-name="<?= htmlspecialchars($it['item_code'] . ' — ' . $it['name']) ?>"
                   data-unit="<?= htmlspecialchars((string)($it['unit'] ?? '')) ?>"
                   data-variant-label="<?= htmlspecialchars($it['variant_label'] ?? '') ?>"
                   data-variants="<?= htmlspecialchars(json_encode(array_map(fn($v) => ['id' => $v['id'], 'value' => $v['variant_value'], 'note' => $v['variant_note'], 'qty' => $v['quantity_on_hand']], $iv))) ?>"
                   title="Record incoming delivery / stock in">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <span>Stock in</span>
                </a>

                <div class="table-action-pill">
                  <a href="<?= BASE_URL ?>/inventory/item_edit.php?id=<?= $it['id'] ?>" class="table-action-btn js-open-itemedit" data-item-id="<?= $it['id'] ?>" title="Edit catalog item" aria-label="Edit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                  </a>
                  <a href="<?= BASE_URL ?>/inventory/stock_ledger.php?item=<?= $it['id'] ?>" class="table-action-btn" title="View stock movement history" aria-label="Stock Ledger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  </a>
                  <a href="<?= BASE_URL ?>/inventory/assets.php?item=<?= $it['id'] ?>" class="table-action-btn" title="Tracked QR assets" aria-label="Tracked Assets">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="21" y2="21"/><line x1="21" y1="14" x2="14" y2="21"/></svg>
                  </a>
                  <?php if ($is_admin): ?>
                  <form method="post" class="action-form" style="display:contents;" onsubmit="return confirm('Change status for <?= htmlspecialchars(addslashes($it['name'])) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="toggle_id" value="<?= $it['id'] ?>">
                    <button type="submit" class="table-action-btn table-action-toggle <?= $it['status'] === 'active' ? 'is-on' : 'is-off' ?>" title="<?= $it['status'] === 'active' ? 'Active — click to deactivate' : 'Inactive — click to activate' ?>" aria-label="<?= $it['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                      <?php if ($it['status'] === 'active'): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="7" width="22" height="10" rx="5"/><circle cx="16" cy="12" r="3" fill="currentColor" stroke="none"/></svg>
                      <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="7" width="22" height="10" rx="5"/><circle cx="8" cy="12" r="3" fill="currentColor" stroke="none"/></svg>
                      <?php endif; ?>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        <tr id="live-search-empty" class="hidden"><td colspan="5" style="text-align:center; padding:2rem 1rem;">No items match "<span id="live-search-empty-term"></span>".</td></tr>
      </tbody>
    </table>
  </div>
  <?= render_pagination($page, $total_pages, $per_page) ?>
</div>

<!-- Record stock in: one shared modal (same overlay pattern as the
     Adjust stock modal on item_edit.php), populated per-row from the
     trigger button's data-* attributes so staff never leave this page
     to log a delivery. -->
<div class="variant-modal-backdrop" id="stockInBackdrop"></div>
<div class="variant-modal" id="stockInModal" role="dialog" aria-modal="true" aria-label="Record stock in">
  <button type="button" class="variant-modal-close" id="stockInClose" aria-label="Close">&times;</button>
  <div class="variant-modal-body">
    <h3 class="variant-modal-title">Record stock in</h3>
    <p id="stockInItemName" style="margin:-0.6rem 0 1rem; font-weight:600;"></p>
    <div id="stockInErrors"></div>
    <form id="stockInForm">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="item_id" id="stockInItemId">
      <div class="form-group hidden" id="stockInVariantGroup">
        <label for="stockInVariant" id="stockInVariantLabel">Option</label>
        <select id="stockInVariant" name="variant_id"></select>
      </div>
      <div class="form-group">
        <label for="stockInQty">Quantity received</label>
        <input type="text" inputmode="numeric" id="stockInQty" name="quantity" required>
      </div>
      <div class="form-group">
        <label for="stockInNote">Note <span class="text-muted-normal">(supplier, PO)</span></label>
        <input type="text" id="stockInNote" name="note" placeholder="e.g. ABC Hardware, PO-2201">
      </div>
      <button type="submit" class="btn btn-primary" id="stockInSubmit">Record stock in</button>
    </form>
  </div>
</div>

<!-- Edit item: loads the full item_edit.php form into a modal via fetch,
     so Inventory Staff can edit an item (including variants and photo)
     without leaving the catalog list. Falls back to a normal page
     navigation (the Edit link's href) if JavaScript is unavailable. -->
<div class="variant-modal-backdrop" id="itemEditBackdrop"></div>
<div class="variant-modal variant-modal-wide" id="itemEditModal" role="dialog" aria-modal="true" aria-label="Edit item">
  <button type="button" class="variant-modal-close" id="itemEditClose" aria-label="Close">&times;</button>
  <div class="variant-modal-body" id="itemEditModalBody">
    <p class="text-muted">Loading…</p>
  </div>
</div>

<!-- Photo lightbox: one shared overlay, populated on click so staff can
     confirm a lookalike item (e.g. two "Impact Drill" rows) without
     relying on the name/code alone. -->
<div id="photo-lightbox-backdrop" class="photo-lightbox-backdrop">
  <div class="photo-lightbox-box" role="dialog" aria-modal="true" aria-label="Item photo">
    <button type="button" class="photo-lightbox-close" aria-label="Close">&times;</button>
    <img id="photo-lightbox-img" src="" alt="">
    <div id="photo-lightbox-caption" class="photo-lightbox-caption"></div>
  </div>
</div>

<style>
  .item-thumb-trigger { padding: 0; border: none; background: none; cursor: zoom-in; display: block; line-height: 0; }
  .item-thumb-lg {
    width: 56px; height: 56px; object-fit: cover;
    border-radius: var(--radius-sm); border: 1px solid var(--line); flex-shrink: 0;
  }
  .item-thumb-placeholder {
    width: 56px; height: 56px;
    display: flex; align-items: center; justify-content: center;
    background: var(--charcoal-2); color: var(--amber-on-dark);
    font-family: var(--font-mono); font-weight: 600; font-size: 1rem;
  }
  .photo-lightbox-backdrop {
    position: fixed; inset: 0; background: rgba(20, 14, 8, 0.6);
    display: none; align-items: center; justify-content: center;
    z-index: 1200; padding: 1.5rem;
  }
  .photo-lightbox-backdrop.open { display: flex; }
  .photo-lightbox-box {
    position: relative; background: var(--surface); border-radius: var(--radius-md);
    padding: 1rem; max-width: min(90vw, 480px); max-height: 90vh;
    display: flex; flex-direction: column; align-items: center; box-shadow: var(--shadow-card);
  }
  .photo-lightbox-box img { max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: var(--radius-sm); }
  .photo-lightbox-caption { margin-top: 0.6rem; font-size: 0.9rem; color: var(--ink); text-align: center; }
  .photo-lightbox-close {
    position: absolute; top: 0.4rem; right: 0.6rem; background: none; border: none;
    font-size: 1.6rem; line-height: 1; color: var(--ink-soft); cursor: pointer; padding: 0.25rem;
  }
  .photo-lightbox-close:hover { color: var(--ink); }
</style>

<script src="<?= BASE_URL ?>/assets/js/live-table-search.js"></script>
<script src="<?= BASE_URL ?>/assets/js/search-suggest.js"></script>
<script>
  initLiveTableSearch({ inputId: 'q', rowSelector: 'tr.item-row', emptyRowId: 'live-search-empty', emptyTermId: 'live-search-empty-term' });
  initSearchSuggest({ inputId: 'q', type: 'items' });

  (function () {
    var backdrop = document.getElementById('photo-lightbox-backdrop');
    var img = document.getElementById('photo-lightbox-img');
    var caption = document.getElementById('photo-lightbox-caption');

    function openLightbox(src, name) {
      img.src = src;
      img.alt = name;
      caption.textContent = name;
      backdrop.classList.add('open');
    }
    function closeLightbox() {
      backdrop.classList.remove('open');
      img.src = '';
    }

    document.querySelectorAll('.item-thumb-trigger').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openLightbox(btn.dataset.image, btn.dataset.name);
      });
    });
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeLightbox();
    });
    document.querySelector('.photo-lightbox-close').addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeLightbox();
    });
  })();

  (function () {
    var backdrop = document.getElementById('stockInBackdrop');
    var modal = document.getElementById('stockInModal');
    var closeBtn = document.getElementById('stockInClose');
    var form = document.getElementById('stockInForm');
    var itemNameEl = document.getElementById('stockInItemName');
    var itemIdField = document.getElementById('stockInItemId');
    var variantGroup = document.getElementById('stockInVariantGroup');
    var variantLabel = document.getElementById('stockInVariantLabel');
    var variantSelect = document.getElementById('stockInVariant');
    var qtyInput = document.getElementById('stockInQty');
    var noteInput = document.getElementById('stockInNote');
    var errorsEl = document.getElementById('stockInErrors');
    var submitBtn = document.getElementById('stockInSubmit');

    function showErrors(messages) {
      errorsEl.innerHTML = '';
      (messages && messages.length ? messages : ['Something went wrong. Please try again.']).forEach(function (msg) {
        var p = document.createElement('div');
        p.className = 'alert alert-error';
        p.style.marginBottom = '0.6rem';
        p.textContent = msg;
        errorsEl.appendChild(p);
      });
    }

    function openModal(trigger) {
      itemNameEl.textContent = trigger.dataset.itemName || '';
      itemIdField.value = trigger.dataset.itemId || '';
      qtyInput.value = '';
      noteInput.value = '';
      errorsEl.innerHTML = '';

      var variants = [];
      try { variants = trigger.dataset.variants ? JSON.parse(trigger.dataset.variants) : []; } catch (e) { variants = []; }

      if (variants.length) {
        variantSelect.innerHTML = '';
        variants.forEach(function (v) {
          var o = document.createElement('option');
          o.value = v.id;
          o.textContent = v.value + ' (' + v.qty + ' on hand)';
          if (v.note) o.title = v.note;
          variantSelect.appendChild(o);
        });
        variantLabel.textContent = trigger.dataset.variantLabel || 'Option';
        variantSelect.required = true;
        variantGroup.style.display = '';
      } else {
        variantSelect.innerHTML = '';
        variantSelect.required = false;
        variantGroup.style.display = 'none';
      }

      backdrop.classList.add('open');
      modal.classList.add('open');
      document.body.classList.add('variant-modal-open');
      qtyInput.focus();
    }

    function closeModal() {
      backdrop.classList.remove('open');
      modal.classList.remove('open');
      document.body.classList.remove('variant-modal-open');
    }

    document.querySelectorAll('.js-open-stockin').forEach(function (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        openModal(trigger);
      });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      errorsEl.innerHTML = '';
      submitBtn.disabled = true;
      submitBtn.textContent = 'Recording…';

      fetch('<?= BASE_URL ?>/inventory/stock_in.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.success) {
            // Reload so the row's On hand qty, Stock badge, and the
            // button's out-of-stock styling all stay in sync with the
            // server rather than duplicating that logic here in JS.
            var url = new URL(window.location.href);
            url.searchParams.set('recorded', '1');
            window.location.href = url.toString();
          } else {
            showErrors(data.errors);
            submitBtn.disabled = false;
            submitBtn.textContent = 'Record stock in';
          }
        })
        .catch(function () {
          showErrors(['Network error. Please try again.']);
          submitBtn.disabled = false;
          submitBtn.textContent = 'Record stock in';
        });
    });
  })();

  (function () {
    var backdrop = document.getElementById('itemEditBackdrop');
    var modal = document.getElementById('itemEditModal');
    var closeBtn = document.getElementById('itemEditClose');
    var body = document.getElementById('itemEditModalBody');
    var editBaseUrl = '<?= BASE_URL ?>/inventory/item_edit.php';

    // Script tags injected via innerHTML never execute on their own —
    // each one has to be recreated and re-inserted for the browser to
    // actually run it. async=false on external scripts preserves the
    // original document order (item-form.js before the inline scripts
    // that call functions from it) even though they load asynchronously.
    function runScripts(container) {
      var scripts = Array.prototype.slice.call(container.querySelectorAll('script'));
      scripts.forEach(function (old) {
        var s = document.createElement('script');
        Array.prototype.forEach.call(old.attributes, function (attr) { s.setAttribute(attr.name, attr.value); });
        if (old.src) {
          s.async = false;
        } else {
          s.textContent = old.textContent;
        }
        old.parentNode.replaceChild(s, old);
      });
    }

    function openModal() {
      backdrop.classList.add('open');
      modal.classList.add('open');
      document.body.classList.add('variant-modal-open');
    }
    function closeModal() {
      backdrop.classList.remove('open');
      modal.classList.remove('open');
      document.body.classList.remove('variant-modal-open');
      body.innerHTML = '<p class="text-muted">Loading…</p>';
    }

    // Wires the just-injected copy of the form: intercept its submit,
    // send it via fetch (FormData handles the photo/variant-photo file
    // inputs the same as a normal multipart POST would), then either
    // close on success (a JSON reply) or swap in the returned fragment
    // again (an HTML reply — the same form, now showing validation
    // errors), re-running its scripts and re-wiring it the same way.
    function wireForm(itemId) {
      var form = body.querySelector('form');
      if (!form) return;
      form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Mirrors the confirm check the injected inline script normally
        // relies on for a full page submit — that script's own listener
        // still runs too, but since this handler always calls
        // preventDefault() (the fetch below replaces the native submit
        // entirely), a second, independent check here is what actually
        // stops the save if the person cancels.
        var markedCount = form.querySelectorAll('.variant-delete-flag').length
          ? Array.prototype.filter.call(form.querySelectorAll('.variant-delete-flag'), function (el) { return el.value === '1'; }).length
          : 0;
        if (markedCount > 0) {
          var word = markedCount === 1 ? 'option' : 'options';
          if (!confirm('This will permanently remove ' + markedCount + ' ' + word + ' and its stock record. Continue?')) {
            return;
          }
        }

        var submitBtn = form.querySelector('#itemEditSubmit');
        var originalLabel = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving…'; }

        fetch(editBaseUrl + '?id=' + encodeURIComponent(itemId), {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new FormData(form)
        })
          .then(function (r) {
            var isJson = (r.headers.get('Content-Type') || '').indexOf('application/json') !== -1;
            return isJson ? r.json().then(function (d) { return { json: d }; }) : r.text().then(function (t) { return { html: t }; });
          })
          .then(function (result) {
            if (result.json && result.json.success) {
              window.location.reload();
              return;
            }
            body.innerHTML = result.html || '<p>Something went wrong. Please try again.</p>';
            runScripts(body);
            wireForm(itemId);
            body.scrollTop = 0;
          })
          .catch(function () {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalLabel; }
            alert('Network error. Please try again.');
          });
      });
    }

    function openForItem(itemId) {
      body.innerHTML = '<p class="text-muted">Loading…</p>';
      openModal();
      fetch(editBaseUrl + '?id=' + encodeURIComponent(itemId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          body.innerHTML = html;
          runScripts(body);
          wireForm(itemId);
        })
        .catch(function () {
          body.innerHTML = '<p>Could not load this item. Please try again.</p>';
        });
    }

    document.querySelectorAll('.js-open-itemedit').forEach(function (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        openForItem(trigger.dataset.itemId);
      });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
