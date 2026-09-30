<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();

$item_filter = $_GET['item'] ?? '';
$type_filter = $_GET['type'] ?? '';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$where = " WHERE 1=1";
$params = [];
if ($item_filter !== '') {
    $where .= " AND m.item_id = :item_id";
    $params['item_id'] = $item_filter;
}
if (in_array($type_filter, ['stock_in', 'release', 'adjustment', 'return'], true)) {
    $where .= " AND m.movement_type = :type";
    $params['type'] = $type_filter;
}
if ($from !== '') {
    $where .= " AND m.created_at >= :from";
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= " AND m.created_at <= :to";
    $params['to'] = $to . ' 23:59:59';
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements m" . $where);
$count_stmt->execute($params);
$total_movements = (int)$count_stmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$total_pages = max(1, (int)ceil($total_movements / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT m.*, i.name AS item_name, i.item_code, i.unit, u.full_name AS recorded_by_name,
        iv.variant_value
        FROM stock_movements m
        JOIN items i ON i.id = m.item_id
        JOIN users u ON u.id = m.recorded_by
        LEFT JOIN item_variants iv ON iv.id = m.item_variant_id"
        . $where .
        " ORDER BY m.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$movements = $stmt->fetchAll();

$items = $pdo->query("SELECT id, item_code, name FROM items ORDER BY name")->fetchAll();

$type_labels = ['stock_in' => 'Stock in', 'release' => 'Released', 'adjustment' => 'Adjustment', 'return' => 'Tool returned'];

// Group movements by calendar day so the feed reads like an activity
// log instead of one long flat table — a lot fewer columns to scan per
// row, with the date said once per group instead of on every line.
$grouped = [];
foreach ($movements as $m) {
    $day = date('Y-m-d', strtotime($m['created_at']));
    $grouped[$day][] = $m;
}

$page_title = 'Stock Ledger';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Automated stock recording</div>
    <h1>Stock Ledger</h1>
  </div>
</div>

<?php if (!empty($_GET['recorded'])): ?>
  <div class="alert alert-success">Movement recorded.</div>
<?php endif; ?>

<form method="get" class="filter-bar">
  <div class="form-group">
    <label for="item">Item</label>
    <select id="item" name="item">
      <option value="">All items</option>
      <?php foreach ($items as $it): ?>
        <option value="<?= $it['id'] ?>" <?= (string)$item_filter === (string)$it['id'] ? 'selected' : '' ?>><?= htmlspecialchars($it['item_code'] . ' — ' . $it['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="type">Type</label>
    <select id="type" name="type">
      <option value="">All types</option>
      <?php foreach ($type_labels as $val => $label): ?>
        <option value="<?= $val ?>" <?= $type_filter === $val ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="from">From</label>
    <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="form-group">
    <label for="to">To</label>
    <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
  <?php if ($item_filter !== '' || $type_filter !== '' || $from !== '' || $to !== ''): ?>
    <a href="<?= BASE_URL ?>/inventory/stock_ledger.php" class="filter-clear-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear filters
    </a>
  <?php endif; ?>
</form>

<?php $has_filters = $item_filter !== '' || $type_filter !== '' || $from !== '' || $to !== ''; ?>
<div class="history-summary">
  <span>
    Showing <?= $total_movements > 0 ? $offset + 1 : 0 ?>–<?= min($total_movements, $offset + count($movements)) ?> of <?= $total_movements ?> movement<?= $total_movements === 1 ? '' : 's' ?>
    <?php if ($has_filters): ?> · filters applied<?php endif; ?>
  </span>
  <?php if ($has_filters): ?>
    <a href="<?= BASE_URL ?>/inventory/stock_ledger.php">Clear filters</a>
  <?php endif; ?>
</div>

<?php if (!$movements): ?>
  <div class="card history-empty">
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M9 15l2 2 4-4"/></svg>
    <strong>No stock movements found</strong>
    <span>Nothing matches this filter yet. Try widening the date range or clearing a filter.</span>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $day => $day_movements): ?>
    <div class="history-day-label"><?= htmlspecialchars(date('F j, Y', strtotime($day))) ?></div>
    <div class="card history-group">
      <?php foreach ($day_movements as $m):
        $is_positive = $m['quantity_change'] > 0;
        $dir = $is_positive ? 'is-in' : 'is-out'; ?>
        <div class="history-row <?= $dir ?>">
          <span class="history-change-pill <?= $dir ?>">
            <?= $is_positive ? '+' : '' ?><?= (int)$m['quantity_change'] ?> <?= htmlspecialchars((string)($m['unit'] ?? '')) ?>
          </span>
          <div class="history-row-body">
            <div class="history-row-main">
              <span class="badge-movement <?= $dir ?>"><?= $type_labels[$m['movement_type']] ?? htmlspecialchars((string)($m['movement_type'] ?? '')) ?></span>
              <span class="history-item-name"><?= htmlspecialchars((string)($m['item_name'] ?? '')) ?></span>
              <span class="mono history-item-code">(<?= htmlspecialchars((string)($m['item_code'] ?? '')) ?>)</span>
              <?php if ($m['variant_value']): ?>
                <span class="badge role"><?= htmlspecialchars((string)($m['variant_value'] ?? '')) ?></span>
              <?php endif; ?>
            </div>
            <div class="history-row-detail">
              <span class="history-before-after"><?= (int)$m['quantity_before'] ?> → <?= (int)$m['quantity_after'] ?> <?= htmlspecialchars((string)($m['unit'] ?? '')) ?></span>
              <?php if ($m['note']): ?>
                <span class="history-note">
                  — <?= htmlspecialchars((string)($m['note'] ?? '')) ?>
                  <?php if ($m['reference_type'] === 'requisition' && $m['reference_id']): ?>
                    <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= (int)$m['reference_id'] ?>">req #<?= (int)$m['reference_id'] ?></a>
                  <?php elseif ($m['reference_type'] === 'direct_checkout' && $m['reference_id']): ?>
                    <a href="<?= BASE_URL ?>/inventory/asset_view.php?id=<?= (int)$m['reference_id'] ?>">view asset</a>
                  <?php endif; ?>
                </span>
              <?php endif; ?>
            </div>
            <div class="history-row-meta">
              <?= htmlspecialchars((string)($m['recorded_by_name'] ?? '')) ?> · <?= htmlspecialchars(date('g:i A', strtotime($m['created_at']))) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?= render_pagination($page, $total_pages, $per_page) ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
