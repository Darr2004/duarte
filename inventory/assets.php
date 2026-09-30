<?php
/**
 * DuaRTE — QR Code Asset Tracking: Asset list.
 * Lists every registered asset — a per-unit row for borrowable items,
 * or a per-lot row (with a remaining Qty) for consumables — filterable
 * by item and status, with a quick link into each one's detail/scan-
 * landing page (asset_view.php) and into printing its QR label.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();

$item_filter   = $_GET['item'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = " WHERE 1=1";
$params = [];

if ($item_filter !== '') {
    $where .= " AND a.item_id = :item_id";
    $params['item_id'] = $item_filter;
}
if (in_array($status_filter, ['available', 'checked_out', 'under_maintenance', 'missing', 'retired'], true)) {
    $where .= " AND a.status = :status";
    $params['status'] = $status_filter;
} else {
    // Default view hides retired units — they're kept for history but
    // shouldn't clutter the working list unless explicitly asked for.
    $where .= " AND a.status != 'retired'";
}
if ($search !== '') {
    $where .= " AND (a.asset_tag LIKE :q1
                  OR a.serial_number LIKE :q2
                  OR i.name LIKE :q3
                  OR i.item_code LIKE :q4
                  OR i.brand LIKE :q5
                  OR iv.variant_value LIKE :q6
                  OR a.location_note LIKE :q7
                  OR h.full_name LIKE :q8
                  OR h.employee_id LIKE :q9)";
    $params['q1'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
    $params['q3'] = '%' . $search . '%';
    $params['q4'] = '%' . $search . '%';
    $params['q5'] = '%' . $search . '%';
    $params['q6'] = '%' . $search . '%';
    $params['q7'] = '%' . $search . '%';
    $params['q8'] = '%' . $search . '%';
    $params['q9'] = '%' . $search . '%';
}

// Count total matching assets
$count_sql = "SELECT COUNT(*) c
              FROM assets a
              JOIN items i ON i.id = a.item_id
              LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
              LEFT JOIN users h ON h.id = a.current_holder_id" . $where;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_assets = (int)$count_stmt->fetch()['c'];

$total_pages = max(1, (int)ceil($total_assets / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT a.*, i.name AS item_name, i.item_code, i.unit, iv.variant_value,
               h.full_name AS holder_name, h.employee_id AS holder_employee_id
        FROM assets a
        JOIN items i ON i.id = a.item_id
        LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
        LEFT JOIN users h ON h.id = a.current_holder_id" . $where . "
        ORDER BY i.name ASC, a.created_at ASC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$assets = $stmt->fetchAll();

if (!function_exists('asset_page_url')) {
    function asset_page_url(int $p): string
    {
        $params = $_GET;
        $params['page'] = $p;
        return '?' . http_build_query($params);
    }
}

// Any active item can have assets registered under it now — borrowable
// items as per-unit rows, consumables as per-lot rows.
$filterable_items = $pdo->query(
    "SELECT id, item_code, name FROM items WHERE status = 'active' ORDER BY name"
)->fetchAll();

$unsynced_stmt = $pdo->query(
    "SELECT COUNT(*) c FROM items i WHERE i.status = 'active' AND NOT EXISTS (SELECT 1 FROM assets a WHERE a.item_id = i.id)"
);
$unsynced_count = (int)$unsynced_stmt->fetch()['c'];

$has_filters = $item_filter !== '' || $status_filter !== '' || $search !== '';

$page_title = 'Assets';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:1rem;">
  <div>
    <div class="eyebrow">QR code asset tracking</div>
    <h1 style="margin:0.15rem 0 0;">Assets</h1>
  </div>
  <div style="display:flex; gap:0.65rem; align-items:center; flex-wrap:wrap;">
    <?php if ($unsynced_count > 0): ?>
      <form method="post" action="<?= BASE_URL ?>/inventory/asset_sync.php" onsubmit="return confirm('⚡ AUTO-SYNC FROM CATALOG\n\nGenerate QR asset tags for <?= $unsynced_count ?> catalog item(s) that are not yet tracked as assets?\n\nThis will automatically assign their current stalls and layers.');">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <button type="submit" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:600; border-color:var(--amber); color:var(--amber);">
          ⚡ Auto-Sync from Catalog (<?= $unsynced_count ?>)
        </button>
      </form>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/inventory/asset_add.php" class="btn btn-primary">Register asset</a>
  </div>
</div>

<?php if (!empty($_GET['synced'])): ?>
  <div class="alert alert-success">
    ✅ Successfully auto-synced <?= (int)$_GET['synced'] ?> QR asset tag(s) across <?= (int)($_GET['items'] ?? 0) ?> catalog item(s)! All items now have assigned stalls and QR tags.
  </div>
<?php endif; ?>
<?php if (!empty($_GET['registered'])): ?>
  <div class="alert alert-success">Asset(s) registered. Print their QR labels and attach one to each unit.</div>
<?php endif; ?>
<?php if (!empty($_GET['updated'])): ?>
  <div class="alert alert-success">Asset updated.</div>
<?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?>
  <div class="alert alert-success">Asset record permanently deleted.</div>
<?php endif; ?>

<form method="get" class="filter-bar" id="assetFilterForm">
  <div class="form-group grow">
    <label for="q">Search</label>
    <div class="search-input-wrap">
      <input type="text" id="q" name="q" placeholder="Search assets" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
    </div>
  </div>
  <div class="form-group">
    <label for="item">Item</label>
    <select id="item" name="item">
      <option value="">All items</option>
      <?php foreach ($filterable_items as $it): ?>
        <option value="<?= $it['id'] ?>" <?= (string)$item_filter === (string)$it['id'] ? 'selected' : '' ?>><?= htmlspecialchars($it['item_code'] . ' — ' . $it['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All (except retired)</option>
      <?php foreach (['available', 'checked_out', 'under_maintenance', 'missing', 'retired'] as $s): ?>
        <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= asset_status_label($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($has_filters): ?>
    <a href="<?= BASE_URL ?>/inventory/assets.php" class="filter-clear-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear filters
    </a>
  <?php endif; ?>
</form>

<div class="history-summary">
  <span>
    Showing <?= $total_assets > 0 ? $offset + 1 : 0 ?>–<?= min($total_assets, $offset + count($assets)) ?> of <?= $total_assets ?> asset<?= $total_assets === 1 ? '' : 's' ?>
    <?php if ($has_filters): ?> · filters applied<?php endif; ?>
  </span>
  <?php if ($has_filters): ?>
    <a href="<?= BASE_URL ?>/inventory/assets.php">Clear filters</a>
  <?php endif; ?>
  <a href="<?= BASE_URL ?>/inventory/asset_audit.php" style="margin-left:auto;">Start a physical audit &rarr;</a>
</div>

<?php if (!$assets): ?>
  <div class="empty-state">
    <?php if ($has_filters): ?>
      No assets match this filter.
    <?php elseif (!$filterable_items): ?>
      No active items in the catalog yet — add one in Catalog Management before registering assets for it.
    <?php else: ?>
      No assets registered yet. <a href="<?= BASE_URL ?>/inventory/asset_add.php">Register your first one</a>.
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card" style="padding-bottom:0; overflow:hidden;">
    <div class="table-responsive">
      <table class="data">
        <thead>
          <tr>
            <th style="width:14%;">Tag</th>
            <th style="width:30%;">Item</th>
            <th style="width:8%;">Qty</th>
            <th style="width:14%;">Status</th>
            <th style="width:18%;">Holder / Location</th>
            <th style="width:12%;">Last activity</th>
            <th style="width:4%; text-align:right;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($assets as $a): ?>
            <tr>
              <td class="mono" data-label="Tag"><span class="item-code-badge" style="font-size:0.82rem;"><?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?></span></td>
              <td data-label="Item">
                <div style="font-weight:600; color:var(--ink);"><?= htmlspecialchars((string)($a['item_name'] ?? '')) ?><?php if ($a['variant_value']): ?> <span style="font-weight:600; color:var(--amber-dim);">— <?= htmlspecialchars((string)($a['variant_value'] ?? '')) ?></span><?php endif; ?></div>
                <div class="mono" style="font-size:0.75rem; color:var(--ink-soft); margin-top:0.15rem;"><?= htmlspecialchars((string)($a['item_code'] ?? '')) ?></div>
              </td>
              <td data-label="Qty"><?php if ((int)$a['quantity'] > 1): ?><span class="mono" style="font-weight:600;"><?= (int)$a['quantity'] ?></span> <span class="text-muted" style="font-size:0.75rem;">(lot)</span><?php else: ?><span class="mono">1</span><?php endif; ?></td>
              <td data-label="Status"><span class="badge <?= asset_status_class($a['status']) ?>"><?= asset_status_label($a['status']) ?></span></td>
              <td data-label="Holder / Location" class="td-detail">
                <?php if ($a['status'] === 'checked_out' && $a['holder_name']): ?>
                  <div style="font-weight:500;"><?= htmlspecialchars((string)($a['holder_name'] ?? '')) ?></div>
                  <div class="mono" style="font-size:0.75rem; color:var(--ink-soft);"><?= htmlspecialchars((string)($a['holder_employee_id'] ?? '')) ?></div>
                <?php elseif ($a['location_note']): ?>
                  <?= htmlspecialchars((string)($a['location_note'] ?? '')) ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="mono td-detail" data-label="Last activity" style="font-size:0.8rem; color:var(--ink-soft);"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($a['updated_at']))) ?></td>
              <td style="white-space:nowrap; text-align:right;" data-label="">
                <a href="<?= BASE_URL ?>/inventory/asset_view.php?tag=<?= urlencode($a['asset_tag']) ?>" class="btn btn-outline btn-sm" style="height:30px; padding:0 0.65rem;">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?= render_pagination($page, $total_pages, $per_page) ?>
  </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/search-suggest.js"></script>
<script>
  initSearchSuggest({ inputId: 'q', type: 'assets' });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
