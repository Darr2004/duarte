<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/item_requests.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$active_items    = $pdo->query("SELECT COUNT(*) c FROM items WHERE status = 'active'")->fetch()['c'];
$stock_alerts    = count_stock_alerts($pdo);
$overdue_loans   = count_overdue_loans($pdo);
$awaiting_release = count_awaiting_release($pdo);
$pending_item_requests = count_pending_item_requests($pdo);

// Dual Dispatch Staging: Requisitions approved and waiting for pickup / QR verification
$staging_releases = $pdo->query(
    "SELECT r.id, r.purpose, r.truck_plate_snapshot, r.decided_at,
            u.full_name AS requester_name,
            (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
     FROM requisitions r
     JOIN users u ON u.id = r.requester_id
     WHERE r.status = 'approved'
     ORDER BY r.decided_at ASC
     LIMIT 4"
)->fetchAll();

// Active Tool Loans: Items due soon or overdue
$active_loans_due = $pdo->query(
    "SELECT tl.id, tl.due_date, tl.requisition_id,
            i.name AS item_name, a.asset_tag,
            u.full_name AS borrower_name
     FROM tool_loans tl
     JOIN items i ON i.id = tl.item_id
     JOIN users u ON u.id = tl.borrower_id
     LEFT JOIN assets a ON a.id = tl.asset_id
     WHERE tl.returned_at IS NULL
     ORDER BY tl.due_date ASC
     LIMIT 4"
)->fetchAll();

// Critical Low-Stock Items
$critical_stock_items = [];
if ($stock_alerts > 0) {
    $critical_stock_items = $pdo->query(
        "SELECT i.id, i.item_code, i.name, i.unit, i.quantity_on_hand,
                c.name AS category_name,
                COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ") AS threshold
         FROM items i
         LEFT JOIN categories c ON c.id = i.category_id
         WHERE i.status = 'active'
           AND (
               i.quantity_on_hand <= COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ")
               OR EXISTS (
                   SELECT 1 FROM item_variants iv
                   WHERE iv.item_id = i.id
                     AND iv.quantity_on_hand <= COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ")
               )
           )
         ORDER BY i.quantity_on_hand ASC, i.name ASC
         LIMIT 4"
    )->fetchAll();
}

// Daily stock-movement volume over the last 7 days, for the "activity at
// a glance" sparkline — inventory staff live in this data all day, so a
// trend line is more useful to them here than it would be elsewhere.
$daily_rows = $pdo->query(
    "SELECT DATE(created_at) d, COUNT(*) c FROM stock_movements
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at)"
)->fetchAll();
$daily_by_date = [];
foreach ($daily_rows as $row) {
    $daily_by_date[$row['d']] = (int)$row['c'];
}
$movement_trend = fill_daily_series($daily_by_date, 7);

// Movement-type mix for the same 7-day window, feeding the small bar
// breakdown next to the activity feed.
$type_rows = $pdo->query(
    "SELECT movement_type, COUNT(*) c FROM stock_movements
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY movement_type"
)->fetchAll();
$type_week_counts = ['stock_in' => 0, 'release' => 0, 'adjustment' => 0, 'return' => 0];
foreach ($type_rows as $row) {
    $type_week_counts[$row['movement_type']] = (int)$row['c'];
}
$type_week_max = max(1, ...array_values($type_week_counts));

$recent_movements = $pdo->query(
    "SELECT sm.*, i.name AS item_name, u.full_name AS recorded_by_name, iv.variant_value
     FROM stock_movements sm
     JOIN items i ON i.id = sm.item_id
     JOIN users u ON u.id = sm.recorded_by
     LEFT JOIN item_variants iv ON iv.id = sm.item_variant_id
     ORDER BY sm.created_at DESC LIMIT 8"
)->fetchAll();

$movement_meta = [
    'stock_in'   => ['label' => 'Stock in',      'icon' => 'plus-circle',   'tone' => 'is-ok'],
    'release'    => ['label' => 'Released',       'icon' => 'package-check', 'tone' => ''],
    'adjustment' => ['label' => 'Adjustment',     'icon' => 'refresh',       'tone' => 'is-warn'],
    'return'     => ['label' => 'Tool returned',  'icon' => 'tool',          'tone' => 'is-ok'],
];

$page_title = 'Dashboard';
$page_has_hero = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="welcome-hero">
  <div class="welcome-hero-main">
    <?= avatar_html($user, 'welcome-hero-avatar mono') ?>
    <div class="welcome-hero-text">
      <div class="welcome-hero-eyebrow"><?= htmlspecialchars($greeting) ?>, welcome</div>
      <h1 class="welcome-hero-name"><?= htmlspecialchars((string)($user['full_name'] ?? '')) ?></h1>
      <div class="welcome-hero-role"><?= htmlspecialchars(role_display($user['role'], $user['position'] ?? null)) ?></div>
    </div>
  </div>
</div>

<div class="quick-actions-row">
  <a href="<?= BASE_URL ?>/inventory/verify.php" class="btn btn-primary"><?= icon_svg('package-check') ?> Verify &amp; Release</a>
  <a href="<?= BASE_URL ?>/inventory/stock_in.php" class="btn btn-outline">Record Stock In</a>
</div>

<?php if ($critical_stock_items): ?>
  <div class="replenish-callout-banner">
    <div>
      <div style="font-weight:600; font-size:0.9rem; color:var(--ink); display:flex; align-items:center; gap:0.4rem;">
        <?= icon_svg('alert-triangle') ?> Critical Stock Replenishment Needed
      </div>
      <div class="replenish-callout-chips">
        <?php foreach ($critical_stock_items as $csi): ?>
          <span class="replenish-chip" title="Alert threshold: <?= (int)$csi['threshold'] ?>">
            <strong><?= htmlspecialchars((string)($csi['name'] ?? '')) ?></strong>
            <span class="replenish-chip-stock"><?= (int)$csi['quantity_on_hand'] ?> <?= htmlspecialchars((string)($csi['unit'] ?? '')) ?></span>
            <span class="text-muted" style="display:none;">(Min: <?= (int)$csi['threshold'] ?>)</span>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <a href="<?= BASE_URL ?>/inventory/stock_in.php" class="btn btn-primary btn-sm"><?= icon_svg('plus-circle') ?> Restock via Stock-In</a>
    </div>
  </div>
<?php elseif ($stock_alerts > 0 || $overdue_loans > 0 || $pending_item_requests > 0): ?>
  <div class="alert alert-warning"><?= icon_svg('alert-triangle') ?>
    <?php
    $flags = [];
    if ($stock_alerts > 0)  { $flags[] = "$stock_alerts item(s) low or out of stock"; }
    if ($overdue_loans > 0) { $flags[] = "$overdue_loans tool(s) overdue"; }
    if ($pending_item_requests > 0) { $flags[] = "$pending_item_requests item request(s) waiting"; }
    echo htmlspecialchars(implode(' · ', $flags));
    ?>
  </div>
<?php endif; ?>

<div class="stat-grid-v2">
  <a href="<?= BASE_URL ?>/inventory/items.php" class="stat-card-v2">
    <div class="stat-card-v2-icon"><?= icon_svg('box') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Active items</div>
      <div class="stat-card-v2-value"><?= (int)$active_items ?></div>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/inventory/items.php?low_stock=1" class="stat-card-v2<?= $stock_alerts > 0 ? ' is-danger' : ' is-ok' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('alert-triangle') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Stock alerts</div>
      <div class="stat-card-v2-value<?= $stock_alerts > 0 ? ' is-danger' : '' ?>"><?= (int)$stock_alerts ?></div>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/inventory/verify.php" class="stat-card-v2<?= $awaiting_release > 0 ? ' is-warn' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('clock') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Awaiting release</div>
      <div class="stat-card-v2-value<?= $awaiting_release > 0 ? ' is-warn' : '' ?>"><?= (int)$awaiting_release ?></div>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/inventory/loans.php" class="stat-card-v2<?= $overdue_loans > 0 ? ' is-danger' : ' is-ok' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('tool') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Overdue tool loans</div>
      <div class="stat-card-v2-value<?= $overdue_loans > 0 ? ' is-danger' : '' ?>"><?= (int)$overdue_loans ?></div>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/inventory/item_requests.php" class="stat-card-v2<?= $pending_item_requests > 0 ? ' is-warn' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('alert-triangle') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Purchase Requests (PO)</div>
      <div class="stat-card-v2-value<?= $pending_item_requests > 0 ? ' is-warn' : '' ?>"><?= (int)$pending_item_requests ?></div>
    </div>
  </a>
</div>

<div class="dispatch-staging-grid">
  <div class="card m-0">
    <div class="section-head">
      <div>
        <h2>Ready for Dispense &amp; Release</h2>
        <div class="section-sub">Awaiting QR pickup</div>
      </div>
      <a href="<?= BASE_URL ?>/inventory/verify.php" class="btn btn-outline btn-sm">Scanner desk &rarr;</a>
    </div>
    <?php if (!$staging_releases): ?>
      <div class="empty-state-mini">
        <?= icon_svg('check-circle') ?>
        <div>No requisitions waiting for pickup right now.</div>
      </div>
    <?php else: ?>
      <div class="staging-list">
        <?php foreach ($staging_releases as $sr): ?>
          <div class="staging-row">
            <div class="staging-main">
              <div class="staging-title">
                <span>#<?= (int)$sr['id'] ?> &middot; <?= htmlspecialchars((string)($sr['requester_name'] ?? '')) ?></span>
                <?php if (!empty($sr['truck_plate_snapshot'])): ?>
                  <?= truck_plate_badge($sr['truck_plate_snapshot']) ?>
                <?php endif; ?>
              </div>
              <div class="staging-meta">
                <?= (int)$sr['item_count'] ?> item(s) &middot; <?= htmlspecialchars($sr['purpose'] ?? 'General Requisition') ?> &middot; approved <?= !empty($sr['decided_at']) ? time_ago($sr['decided_at']) : 'recently' ?>
              </div>
            </div>
            <div>
              <a href="<?= BASE_URL ?>/inventory/verify.php?req_id=<?= (int)$sr['id'] ?>" class="btn btn-primary btn-sm">Release &rarr;</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card m-0">
    <div class="section-head">
      <div>
        <h2>Active Tool Loans Desk</h2>
        <div class="section-sub">Equipment out in field</div>
      </div>
      <a href="<?= BASE_URL ?>/inventory/loans.php" class="btn btn-outline btn-sm">All loans &rarr;</a>
    </div>
    <?php if (!$active_loans_due): ?>
      <div class="empty-state-mini">
        <?= icon_svg('check-circle') ?>
        <div>No active tool loans outstanding.</div>
      </div>
    <?php else: ?>
      <div class="staging-list">
        <?php foreach ($active_loans_due as $ald): 
          $is_overdue = $ald['due_date'] < date('Y-m-d');
        ?>
          <div class="staging-row">
            <div class="staging-main">
              <div class="staging-title">
                <span><?= htmlspecialchars((string)($ald['item_name'] ?? '')) ?></span>
                <?php if (!empty($ald['asset_tag'])): ?>
                  <span class="mono badge inactive" style="font-size:0.75rem;"><?= htmlspecialchars((string)($ald['asset_tag'] ?? '')) ?></span>
                <?php endif; ?>
                <span class="badge <?= $is_overdue ? 'inactive' : 'active' ?>" style="font-size:0.75rem;">
                  <?= $is_overdue ? 'Overdue' : 'Due ' . htmlspecialchars((string)($ald['due_date'] ?? '')) ?>
                </span>
              </div>
              <div class="staging-meta">
                Borrower: <?= htmlspecialchars((string)($ald['borrower_name'] ?? '')) ?>
              </div>
            </div>
            <div>
              <a href="<?= BASE_URL ?>/inventory/loans.php" class="btn btn-outline btn-sm">Return</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap:1.75rem; align-items:start;">
  <div class="card m-0">
    <div class="section-head">
      <div>
        <h2>Recent stock activity</h2>
        <div class="section-sub">Latest 8 movements</div>
      </div>
      <a href="<?= BASE_URL ?>/inventory/stock_ledger.php" class="btn btn-outline btn-sm">Full history →</a>
    </div>
    <?php if (!$recent_movements): ?>
      <div class="empty-state-mini">
        <?= icon_svg('box') ?>
        <div>No stock activity recorded yet.</div>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($recent_movements as $m): $meta = $movement_meta[$m['movement_type']] ?? ['label' => ucfirst($m['movement_type']), 'icon' => 'box', 'tone' => '']; ?>
          <div class="timeline-row">
            <div class="timeline-dot <?= $meta['tone'] ?>"><?= icon_svg($meta['icon']) ?></div>
            <div class="timeline-body">
              <div class="timeline-title"><?= htmlspecialchars((string)($m['item_name'] ?? '')) ?><?= !empty($m['variant_value']) ? ' (' . htmlspecialchars((string)($m['variant_value'] ?? '')) . ')' : '' ?> &middot; <?= htmlspecialchars((string)($meta['label'] ?? '')) ?>
                <span class="mono" style="color:<?= $m['quantity_change'] > 0 ? 'var(--green-ok)' : 'var(--red-danger)' ?>; font-weight:600; font-size:0.82rem;">
                  <?= $m['quantity_change'] > 0 ? '+' : '' ?><?= (int)$m['quantity_change'] ?>
                </span>
              </div>
              <div class="timeline-meta">by <?= htmlspecialchars((string)($m['recorded_by_name'] ?? '')) ?></div>
            </div>
            <div class="timeline-time"><?= time_ago($m['created_at']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card m-0">
    <div class="section-head">
      <div>
        <h2>Activity, last 7 days</h2>
        <div class="section-sub"><?= array_sum($movement_trend) ?> movement(s) total</div>
      </div>
    </div>
    <div style="margin-bottom:1.25rem;">
      <?= sparkline_svg($movement_trend, 260, 56) ?>
    </div>
    <div class="bar-chart">
      <?php foreach ($movement_meta as $key => $meta): ?>
        <div class="bar-row">
          <div class="bar-label"><?= htmlspecialchars((string)($meta['label'] ?? '')) ?></div>
          <div class="bar-track"><div class="bar-fill" style="width: <?= bar_pct($type_week_counts[$key], $type_week_max) ?>%"></div></div>
          <div class="bar-value"><?= $type_week_counts[$key] ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
