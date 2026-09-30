<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_role(['admin', 'inventory_staff']);

$pdo = get_db();

$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['day', 'week', 'month', 'custom'], true)) {
    $range = 'month';
}

if ($range === 'day') {
    $from = date('Y-m-d');
    $to   = date('Y-m-d');
} elseif ($range === 'week') {
    $from = date('Y-m-d', strtotime('-6 days'));
    $to   = date('Y-m-d');
} elseif ($range === 'month') {
    $from = date('Y-m-d', strtotime('-29 days'));
    $to   = date('Y-m-d');
} else {
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = date('Y-m-d', strtotime('-29 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = date('Y-m-d');
    }
}
$from_ts = $from . ' 00:00:00';
$to_ts   = $to . ' 23:59:59';

// ---- KPIs ----
$stmt = $pdo->prepare(
    "SELECT status, COUNT(*) c FROM requisitions WHERE created_at BETWEEN :f AND :t GROUP BY status"
);
$stmt->execute(['f' => $from_ts, 't' => $to_ts]);
$status_counts = array_fill_keys(['pending', 'approved', 'declined', 'cancelled', 'released'], 0);
foreach ($stmt->fetchAll() as $row) {
    $status_counts[$row['status']] = (int)$row['c'];
}
$total_requisitions = array_sum($status_counts);
$decided = $status_counts['approved'] + $status_counts['declined'] + $status_counts['released'];
$approval_rate = $decided > 0
    ? round((($status_counts['approved'] + $status_counts['released']) / $decided) * 100)
    : null;

$stmt = $pdo->prepare(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, decided_at)) / 60.0 AS avg_hrs
     FROM requisitions WHERE decided_at IS NOT NULL AND created_at BETWEEN :f AND :t"
);
$stmt->execute(['f' => $from_ts, 't' => $to_ts]);
$avg_turnaround = $stmt->fetch()['avg_hrs'];

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(quantity_change),0) c FROM stock_movements
     WHERE movement_type = 'stock_in' AND created_at BETWEEN :f AND :t"
);
$stmt->execute(['f' => $from_ts, 't' => $to_ts]);
$stock_in_total = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(-quantity_change),0) c FROM stock_movements
     WHERE movement_type = 'release' AND created_at BETWEEN :f AND :t"
);
$stmt->execute(['f' => $from_ts, 't' => $to_ts]);
$released_total = (int)$stmt->fetch()['c'];

$active_loans = $pdo->query("SELECT COUNT(*) c FROM tool_loans WHERE returned_at IS NULL")->fetch()['c'];
$overdue_loans = count_overdue_loans($pdo);

// ---- Requisitions by status (bar chart) ----
$status_labels = ['pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'released' => 'Released'];
$max_status = max($status_counts) ?: 1;

// ---- Top requested items ----
$stmt = $pdo->prepare(
    "SELECT ri.item_name_snapshot AS name,
            MAX(i.item_code) AS item_code,
            MAX(c.name) AS category_name,
            SUM(ri.quantity_requested) AS total_qty,
            COUNT(DISTINCT ri.requisition_id) AS times_requested
     FROM requisition_items ri
     JOIN requisitions r ON r.id = ri.requisition_id
     LEFT JOIN items i ON i.id = ri.item_id
     LEFT JOIN categories c ON c.id = i.category_id
     WHERE r.created_at BETWEEN :f AND :t
     GROUP BY ri.item_name_snapshot
     ORDER BY total_qty DESC
     LIMIT 5"
);
$stmt->execute(['f' => $from_ts, 't' => $to_ts]);
$top_items = $stmt->fetchAll();
$max_top_item = $top_items ? max(array_column($top_items, 'total_qty')) : 1;

// ---- Most borrowed tools ----
$stmt = $pdo->prepare(
    "SELECT i.name,
            MAX(i.item_code) AS item_code,
            MAX(c.name) AS category_name,
            COUNT(*) AS loan_count,
            SUM(tl.quantity) AS total_qty
     FROM tool_loans tl
     JOIN items i ON i.id = tl.item_id
     LEFT JOIN categories c ON c.id = i.category_id
     WHERE tl.borrowed_at BETWEEN :f AND :t
     GROUP BY i.id, i.name
     ORDER BY loan_count DESC
     LIMIT 5"
);
$stmt->execute(['f' => $from_ts, 't' => $to_ts]);
$top_tools = $stmt->fetchAll();
$max_top_tool = $top_tools ? max(array_column($top_tools, 'loan_count')) : 1;

// ---- Top requesters ----
$stmt = $pdo->prepare(
    "SELECT u.id, u.full_name, u.role, u.position, COUNT(*) AS req_count
     FROM requisitions r
     JOIN users u ON u.id = r.requester_id
     WHERE r.created_at BETWEEN :f AND :t
     GROUP BY u.id, u.full_name, u.role, u.position
     ORDER BY req_count DESC
     LIMIT 5"
);
$stmt->execute(['f' => $from_ts, 't' => $to_ts]);
$top_requesters = $stmt->fetchAll();
$max_requester = $top_requesters ? max(array_column($top_requesters, 'req_count')) : 1;

// ---- Trend over time (for the chart) ----
// Requisitions submitted and items released, bucketed by day. For ranges
// longer than ~45 days (a wide custom range) we roll the buckets up to
// weeks instead, so the chart stays readable.
$span_days = (strtotime($to) - strtotime($from)) / 86400 + 1;
$bucket_by_week = $span_days > 45;

if ($bucket_by_week) {
    $stmt = $pdo->prepare(
        "SELECT YEARWEEK(created_at, 3) yw, MIN(DATE(created_at)) bucket_start, COUNT(*) c
         FROM requisitions WHERE created_at BETWEEN :f AND :t GROUP BY yw"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);
    $req_by_bucket = [];
    foreach ($stmt->fetchAll() as $row) {
        $req_by_bucket[$row['yw']] = (int)$row['c'];
    }

    $stmt = $pdo->prepare(
        "SELECT YEARWEEK(created_at, 3) yw, COALESCE(SUM(-quantity_change),0) c
         FROM stock_movements WHERE movement_type = 'release' AND created_at BETWEEN :f AND :t GROUP BY yw"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);
    $rel_by_bucket = [];
    foreach ($stmt->fetchAll() as $row) {
        $rel_by_bucket[$row['yw']] = (int)$row['c'];
    }

    $trend_labels = [];
    $trend_requisitions = [];
    $trend_released = [];
    $cursor = new DateTime($from);
    $end = new DateTime($to);
    while ($cursor <= $end) {
        $yw = (int)$cursor->format('oW');
        $trend_labels[] = 'Wk of ' . $cursor->format('M j');
        $trend_requisitions[] = $req_by_bucket[$yw] ?? 0;
        $trend_released[] = $rel_by_bucket[$yw] ?? 0;
        $cursor->modify('+1 week');
    }
} else {
    $stmt = $pdo->prepare(
        "SELECT DATE(created_at) d, COUNT(*) c
         FROM requisitions WHERE created_at BETWEEN :f AND :t GROUP BY d"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);
    $req_by_day = array_column($stmt->fetchAll(), 'c', 'd');

    $stmt = $pdo->prepare(
        "SELECT DATE(created_at) d, COALESCE(SUM(-quantity_change),0) c
         FROM stock_movements WHERE movement_type = 'release' AND created_at BETWEEN :f AND :t GROUP BY d"
    );
    $stmt->execute(['f' => $from_ts, 't' => $to_ts]);
    $rel_by_day = array_column($stmt->fetchAll(), 'c', 'd');

    $trend_labels = [];
    $trend_requisitions = [];
    $trend_released = [];
    $cursor = new DateTime($from);
    $end = new DateTime($to);
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $trend_labels[] = $cursor->format('M j');
        $trend_requisitions[] = (int)($req_by_day[$key] ?? 0);
        $trend_released[] = (int)($rel_by_day[$key] ?? 0);
        $cursor->modify('+1 day');
    }
}

$page_title = 'Reports & Analytics';
$current_role = current_user()['role'] ?? '';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow"><?= $current_role === 'admin' ? 'Reports &amp; analytics' : 'Warehouse &amp; Inventory Operations' ?></div>
    <h1><?= $current_role === 'admin' ? 'Overview' : 'Requisitions &amp; Tool Analytics' ?></h1>
  </div>
</div>

<?php if ($current_role === 'admin'): ?>
<div class="report-switch">
  <span class="report-switch-link active"><?= icon_svg('clipboard') ?> Overview</span>
  <a href="<?= BASE_URL ?>/reports/utilization.php" class="report-switch-link"><?= icon_svg('tool') ?> Fleet &amp; Equipment Utilization</a>
</div>
<?php endif; ?>

<div class="range-tabs" role="tablist" aria-label="Report period">
  <a href="?range=day" role="tab" aria-selected="<?= $range === 'day' ? 'true' : 'false' ?>" class="range-tab <?= $range === 'day' ? 'active' : '' ?>">Daily</a>
  <a href="?range=week" role="tab" aria-selected="<?= $range === 'week' ? 'true' : 'false' ?>" class="range-tab <?= $range === 'week' ? 'active' : '' ?>">Weekly</a>
  <a href="?range=month" role="tab" aria-selected="<?= $range === 'month' ? 'true' : 'false' ?>" class="range-tab <?= $range === 'month' ? 'active' : '' ?>">Monthly</a>
  <a href="?range=custom&amp;from=<?= htmlspecialchars($from) ?>&amp;to=<?= htmlspecialchars($to) ?>" role="tab" aria-selected="<?= $range === 'custom' ? 'true' : 'false' ?>" class="range-tab <?= $range === 'custom' ? 'active' : '' ?>">Custom range</a>
</div>

<?php if ($range === 'custom'): ?>
<form method="get" class="filter-bar">
  <input type="hidden" name="range" value="custom">
  <div class="form-group">
    <label for="from">From</label>
    <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="form-group">
    <label for="to">To</label>
    <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
  </div>
</form>
<?php else: ?>
<div class="filter-bar-note">
  Showing <?= $range === 'day' ? 'today' : ($range === 'week' ? 'the last 7 days' : 'the last 30 days') ?>
  <span class="mono">(<?= htmlspecialchars($from) ?> – <?= htmlspecialchars($to) ?>)</span>
</div>
<?php endif; ?>

<div class="stat-grid-v2">
  <div class="stat-card-v2">
    <div class="stat-card-v2-icon"><?= icon_svg('clipboard') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Requisitions submitted</div>
      <div class="stat-card-v2-value"><?= $total_requisitions ?></div>
    </div>
  </div>
  <div class="stat-card-v2 <?= $approval_rate !== null && $approval_rate < 60 ? 'is-warn' : 'is-ok' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('check-circle') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Approval rate</div>
      <div class="stat-card-v2-value"><?= $approval_rate !== null ? $approval_rate . '%' : '—' ?></div>
      <div class="stat-card-v2-sub"><?= $decided ?> decided</div>
    </div>
  </div>
  <div class="stat-card-v2">
    <div class="stat-card-v2-icon"><?= icon_svg('clock') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Avg. time to decide</div>
      <div class="stat-card-v2-value"><?= $avg_turnaround !== null ? round($avg_turnaround, 1) . 'h' : '—' ?></div>
    </div>
  </div>
  <div class="stat-card-v2 is-ok">
    <div class="stat-card-v2-icon"><?= icon_svg('package-check') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Items released</div>
      <div class="stat-card-v2-value"><?= $released_total ?></div>
      <div class="stat-card-v2-sub">units, this period</div>
    </div>
  </div>
  <div class="stat-card-v2">
    <div class="stat-card-v2-icon"><?= icon_svg('box') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Stock received</div>
      <div class="stat-card-v2-value"><?= $stock_in_total ?></div>
      <div class="stat-card-v2-sub">units, this period</div>
    </div>
  </div>
  <div class="stat-card-v2 <?= $overdue_loans > 0 ? 'is-danger' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('tool') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Active tool loans</div>
      <div class="stat-card-v2-value <?= $overdue_loans > 0 ? 'is-danger' : '' ?>"><?= (int)$active_loans ?></div>
      <div class="stat-card-v2-sub"><?= (int)$overdue_loans ?> overdue right now</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-head">
    <div>
      <h2>Requisitions &amp; releases trend</h2>
      <div class="section-sub"><?= $bucket_by_week ? 'Weekly' : 'Daily' ?> · <?= $range === 'day' ? 'today' : ($range === 'week' ? 'last 7 days' : ($range === 'month' ? 'last 30 days' : 'selected range')) ?></div>
    </div>
  </div>
  <?php if (array_sum($trend_requisitions) === 0 && array_sum($trend_released) === 0): ?>
    <div class="empty-state">No activity in this period.</div>
  <?php else: ?>
    <div class="chart-wrap">
      <canvas id="trendChart" height="110" role="img" aria-label="Requisitions submitted and items released over time"></canvas>
    </div>
  <?php endif; ?>
</div>

<style>
.analytics-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.5rem;
  margin-top: 1.5rem;
}
@media (max-width: 960px) {
  .analytics-dashboard-grid {
    grid-template-columns: 1fr;
  }
}
.analytics-card {
  background: var(--card-bg, #FFFFFF);
  border: 1px solid var(--border-color, #E6DACA);
  border-radius: var(--radius-lg, 12px);
  padding: 1.5rem;
  box-shadow: 0 1px 3px rgba(43,32,24,0.04);
  display: flex;
  flex-direction: column;
}
.analytics-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--border-color, #F0E8DD);
}
.analytics-card-title {
  margin: 0;
  font-size: 1.12rem;
  font-weight: 700;
  color: var(--ink-dark, #2B2018);
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.analytics-card-sub {
  font-size: 0.78rem;
  color: var(--ink-soft, #7A6A58);
  margin-top: 2px;
}
.donut-layout {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex: 1;
}
@media (max-width: 540px) {
  .donut-layout {
    flex-direction: column;
  }
}
.donut-chart-container {
  width: 140px;
  height: 140px;
  position: relative;
  flex-shrink: 0;
}
.donut-chart-center {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
  pointer-events: none;
}
.donut-center-val {
  font-size: 1.4rem;
  font-weight: 800;
  line-height: 1;
  color: var(--ink-dark, #2B2018);
  font-family: var(--font-mono, monospace);
}
.donut-center-lbl {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ink-soft, #7A6A58);
  margin-top: 2px;
}
.donut-legend {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}
.donut-legend-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.82rem;
  padding: 0.35rem 0.6rem;
  border-radius: 6px;
  background: var(--surface-soft, #FAF6F0);
  transition: transform 0.15s ease, background 0.15s ease;
}
.donut-legend-item:hover {
  background: #F3ECE2;
  transform: translateX(3px);
}
.donut-legend-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 500;
  color: var(--ink-dark, #2B2018);
}
.donut-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.donut-legend-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-family: var(--font-mono, monospace);
  font-size: 0.8rem;
}
.donut-legend-count {
  font-weight: 700;
  color: var(--ink-dark, #2B2018);
}
.donut-legend-pct {
  color: var(--ink-soft, #7A6A58);
  font-size: 0.74rem;
}

/* Leaderboard List */
.rank-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  flex: 1;
}
.rank-row {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.6rem 0.75rem;
  border-radius: 8px;
  background: var(--surface-soft, #FAF6F0);
  border: 1px solid rgba(230, 218, 202, 0.4);
  transition: all 0.15s ease;
}
.rank-row:hover {
  border-color: var(--border-color, #E6DACA);
  box-shadow: 0 2px 6px rgba(43,32,24,0.04);
  transform: translateY(-1px);
}
.rank-badge {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  font-weight: 800;
  font-family: var(--font-mono, monospace);
  flex-shrink: 0;
}
.rank-badge.rank-1 {
  background: #D97706;
  color: #FFF;
}
.rank-badge.rank-2 {
  background: #64748B;
  color: #FFF;
}
.rank-badge.rank-3 {
  background: #A0522D;
  color: #FFF;
}
.rank-badge.rank-other {
  background: #E8DEC0;
  color: var(--ink-soft, #7A6A58);
}
.rank-content {
  flex: 1;
  min-width: 0;
}
.rank-title {
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--ink-dark, #2B2018);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rank-sub {
  font-size: 0.72rem;
  color: var(--ink-soft, #7A6A58);
  margin-top: 1px;
}
.rank-track {
  height: 5px;
  background: rgba(0,0,0,0.06);
  border-radius: 4px;
  margin-top: 0.4rem;
  overflow: hidden;
}
.rank-fill {
  height: 100%;
  border-radius: 4px;
  background: var(--amber, #8E5225);
  transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.rank-fill.fill-blue {
  background: #2563EB;
}
.rank-fill.fill-green {
  background: #059669;
}
.rank-fill.fill-amber {
  background: #D97706;
}
.rank-pill {
  font-family: var(--font-mono, monospace);
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--ink-dark, #2B2018);
  background: #FFF;
  border: 1px solid var(--border-color, #E6DACA);
  padding: 0.2rem 0.5rem;
  border-radius: 20px;
  flex-shrink: 0;
}
.user-avatar-mini {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #3B2A1F;
  color: #F7F1E7;
  font-size: 0.75rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
</style>

<div class="analytics-dashboard-grid">
  <!-- Card 1: Requisitions by status -->
  <div class="analytics-card">
    <div class="analytics-card-header">
      <div>
        <h2 class="analytics-card-title"><?= icon_svg('clipboard') ?> Requisitions by status</h2>
        <div class="analytics-card-sub"><?= (int)$total_requisitions ?> total in this period</div>
      </div>
      <a href="<?= BASE_URL ?>/reports/export.php?type=requisitions&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-outline btn-sm"><?= icon_svg('arrow-right') ?> Export CSV</a>
    </div>
    <?php if ($total_requisitions === 0): ?>
      <div class="empty-state-mini" style="padding:2.5rem 1rem; text-align:center; color:var(--ink-soft);">
        <?= icon_svg('clipboard') ?>
        <div style="margin-top:0.5rem;">No requisitions in this period.</div>
      </div>
    <?php else: ?>
      <div class="donut-layout">
        <div class="donut-chart-container">
          <canvas id="statusDoughnutChart"></canvas>
          <div class="donut-chart-center">
            <div class="donut-center-val"><?= $total_requisitions ?></div>
            <div class="donut-center-lbl">Total</div>
          </div>
        </div>
        <div class="donut-legend">
          <?php
          $status_meta = [
            'released'  => ['label' => 'Released',  'color' => '#10B981'],
            'approved'  => ['label' => 'Approved',  'color' => '#3B82F6'],
            'pending'   => ['label' => 'Pending',   'color' => '#F59E0B'],
            'cancelled' => ['label' => 'Cancelled', 'color' => '#6B7280'],
            'declined'  => ['label' => 'Declined',  'color' => '#EF4444'],
          ];
          foreach ($status_meta as $key => $meta):
            $cnt = $status_counts[$key] ?? 0;
            $pct = $total_requisitions > 0 ? round(($cnt / $total_requisitions) * 100) : 0;
          ?>
            <div class="donut-legend-item">
              <span class="donut-legend-label">
                <span class="donut-dot" style="background:<?= $meta['color'] ?>;"></span>
                <?= $meta['label'] ?>
              </span>
              <span class="donut-legend-meta">
                <span class="donut-legend-count"><?= $cnt ?></span>
                <span class="donut-legend-pct">(<?= $pct ?>%)</span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Card 2: Top requested items -->
  <div class="analytics-card">
    <div class="analytics-card-header">
      <div>
        <h2 class="analytics-card-title"><?= icon_svg('box') ?> Top requested items</h2>
        <div class="analytics-card-sub">Highest demand parts &amp; consumables</div>
      </div>
      <a href="<?= BASE_URL ?>/reports/export.php?type=movements&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-outline btn-sm"><?= icon_svg('arrow-right') ?> Export CSV</a>
    </div>
    <?php if (!$top_items): ?>
      <div class="empty-state-mini" style="padding:2.5rem 1rem; text-align:center; color:var(--ink-soft);">
        <?= icon_svg('box') ?>
        <div style="margin-top:0.5rem;">No requisitions in this period.</div>
      </div>
    <?php else: ?>
      <div class="rank-list">
        <?php foreach ($top_items as $idx => $it):
          $rank = $idx + 1;
          $rank_class = match($rank) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-other' };
          $pct_bar = bar_pct($it['total_qty'], $max_top_item);
        ?>
          <div class="rank-row">
            <div class="rank-badge <?= $rank_class ?>">#<?= $rank ?></div>
            <div class="rank-content">
              <div style="display:flex; justify-content:space-between; align-items:baseline; gap:0.5rem;">
                <span class="rank-title" title="<?= htmlspecialchars((string)($it['name'] ?? '')) ?>"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></span>
                <span class="rank-pill"><?= (int)$it['total_qty'] ?> units</span>
              </div>
              <div class="rank-sub">
                <?= htmlspecialchars($it['category_name'] ?? 'General item') ?>
                <?php if (!empty($it['item_code'])): ?> &middot; <span class="mono"><?= htmlspecialchars((string)($it['item_code'] ?? '')) ?></span><?php endif; ?>
              </div>
              <div class="rank-track">
                <div class="rank-fill fill-amber" style="width:<?= $pct_bar ?>%;"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Card 3: Most borrowed tools -->
  <div class="analytics-card">
    <div class="analytics-card-header">
      <div>
        <h2 class="analytics-card-title"><?= icon_svg('tool') ?> Most borrowed tools</h2>
        <div class="analytics-card-sub">High-utilization equipment &amp; hand tools</div>
      </div>
      <a href="<?= BASE_URL ?>/reports/export.php?type=loans&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-outline btn-sm"><?= icon_svg('arrow-right') ?> Export CSV</a>
    </div>
    <?php if (!$top_tools): ?>
      <div class="empty-state-mini" style="padding:2.5rem 1rem; text-align:center; color:var(--ink-soft);">
        <?= icon_svg('tool') ?>
        <div style="margin-top:0.5rem;">No tools borrowed in this period.</div>
      </div>
    <?php else: ?>
      <div class="rank-list">
        <?php foreach ($top_tools as $idx => $it):
          $rank = $idx + 1;
          $rank_class = match($rank) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-other' };
          $pct_bar = bar_pct($it['loan_count'], $max_top_tool);
        ?>
          <div class="rank-row">
            <div class="rank-badge <?= $rank_class ?>">#<?= $rank ?></div>
            <div class="rank-content">
              <div style="display:flex; justify-content:space-between; align-items:baseline; gap:0.5rem;">
                <span class="rank-title" title="<?= htmlspecialchars((string)($it['name'] ?? '')) ?>"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></span>
                <span class="rank-pill"><?= (int)$it['loan_count'] ?>&times; loans</span>
              </div>
              <div class="rank-sub">
                <?= htmlspecialchars($it['category_name'] ?? 'Equipment') ?>
                <?php if (!empty($it['item_code'])): ?> &middot; <span class="mono"><?= htmlspecialchars((string)($it['item_code'] ?? '')) ?></span><?php endif; ?>
              </div>
              <div class="rank-track">
                <div class="rank-fill fill-blue" style="width:<?= $pct_bar ?>%;"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Card 4: Top requesters -->
  <div class="analytics-card">
    <div class="analytics-card-header">
      <div>
        <h2 class="analytics-card-title"><?= icon_svg('users') ?> Top requesters</h2>
        <div class="analytics-card-sub">Top requesters by volume</div>
      </div>
      <a href="<?= BASE_URL ?>/reports/requester_history.php" class="btn btn-outline btn-sm">
        <?= icon_svg('users') ?> All Requesters &rarr;
      </a>
    </div>
    <?php if (!$top_requesters): ?>
      <div class="empty-state-mini" style="padding:2.5rem 1rem; text-align:center; color:var(--ink-soft);">
        <?= icon_svg('users') ?>
        <div style="margin-top:0.5rem;">No requisitions in this period.</div>
      </div>
    <?php else: ?>
      <div class="rank-list">
        <?php foreach ($top_requesters as $idx => $r):
          $rank = $idx + 1;
          $rank_class = match($rank) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-other' };
          $pct_bar = bar_pct($r['req_count'], $max_requester);
          $initials = item_initials($r['full_name']);
        ?>
          <div class="rank-row rank-interactive" onclick="openRequesterHistoryModal(<?= (int)$r['id'] ?>)" style="cursor:pointer;" title="Click to view activity history for <?= htmlspecialchars((string)($r['full_name'] ?? '')) ?>">
            <div class="rank-badge <?= $rank_class ?>">#<?= $rank ?></div>
            <div class="user-avatar-mini" title="<?= htmlspecialchars((string)($r['full_name'] ?? '')) ?>"><?= htmlspecialchars($initials) ?></div>
            <div class="rank-content">
              <div style="display:flex; justify-content:space-between; align-items:baseline; gap:0.5rem;">
                <span class="rank-title"><?= htmlspecialchars((string)($r['full_name'] ?? '')) ?></span>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                  <span class="rank-pill"><?= (int)$r['req_count'] ?> requests</span>
                  <span class="btn btn-outline btn-sm" style="height:22px; padding:0 0.4rem; font-size:0.68rem; line-height:20px;">History &rarr;</span>
                </div>
              </div>
              <div class="rank-sub">
                <?= htmlspecialchars(role_display($r['role'], $r['position'] ?? null)) ?>
              </div>
              <div class="rank-track">
                <div class="rank-fill fill-green" style="width:<?= $pct_bar ?>%;"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php 
$has_trend = (array_sum($trend_requisitions) > 0 || array_sum($trend_released) > 0);
$has_status_data = ($total_requisitions > 0);
?>
<?php if ($has_trend || $has_status_data): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  (function () {
    if (typeof Chart === 'undefined') return;

    <?php if ($has_trend): ?>
    var trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
      new Chart(trendCtx, {
        type: 'line',
        data: {
          labels: <?= json_encode($trend_labels) ?>,
          datasets: [
            {
              label: 'Requisitions submitted',
              data: <?= json_encode($trend_requisitions) ?>,
              borderColor: '#B87A33',
              backgroundColor: 'rgba(184, 122, 51, 0.15)',
              tension: 0.3,
              fill: true,
              pointRadius: 4,
              pointHoverRadius: 6,
              pointBackgroundColor: '#B87A33'
            },
            {
              label: 'Items released',
              data: <?= json_encode($trend_released) ?>,
              borderColor: '#3B2A1F',
              backgroundColor: 'rgba(59, 42, 31, 0.08)',
              tension: 0.3,
              fill: true,
              pointRadius: 4,
              pointHoverRadius: 6,
              pointBackgroundColor: '#3B2A1F'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: {
              position: 'bottom',
              labels: { color: '#2B2018', font: { family: "'Inter', sans-serif", size: 12 }, boxWidth: 14, padding: 16 }
            },
            tooltip: {
              backgroundColor: '#3B2A1F',
              titleColor: '#F7F1E7',
              bodyColor: '#F7F1E7',
              padding: 10,
              cornerRadius: 6
            }
          },
          scales: {
            x: {
              grid: { color: '#E6DACA' },
              ticks: { color: '#7A6A58', font: { family: "'IBM Plex Mono', monospace" } }
            },
            y: {
              beginAtZero: true,
              grid: { color: '#E6DACA' },
              ticks: { color: '#7A6A58', precision: 0, font: { family: "'IBM Plex Mono', monospace" } }
            }
          }
        }
      });
    }
    <?php endif; ?>

    <?php if ($has_status_data): ?>
    var statusCtx = document.getElementById('statusDoughnutChart');
    if (statusCtx) {
      new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: ['Released', 'Approved', 'Pending', 'Cancelled', 'Declined'],
          datasets: [{
            data: [
              <?= (int)$status_counts['released'] ?>,
              <?= (int)$status_counts['approved'] ?>,
              <?= (int)$status_counts['pending'] ?>,
              <?= (int)$status_counts['cancelled'] ?>,
              <?= (int)$status_counts['declined'] ?>
            ],
            backgroundColor: ['#10B981', '#3B82F6', '#F59E0B', '#6B7280', '#EF4444'],
            borderWidth: 2,
            borderColor: '#FFFFFF',
            hoverOffset: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '72%',
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: '#3B2A1F',
              titleColor: '#F7F1E7',
              bodyColor: '#F7F1E7',
              padding: 10,
              cornerRadius: 6,
              callbacks: {
                label: function(ctx) {
                  var total = <?= (int)$total_requisitions ?>;
                  var val = ctx.parsed;
                  var pct = total > 0 ? Math.round((val / total) * 100) : 0;
                  return ' ' + ctx.label + ': ' + val + ' (' + pct + '%)';
                }
              }
            }
          }
        }
      });
    }
    <?php endif; ?>
  })();
</script>
<?php endif; ?>

<!-- Requester History Modal Backdrop & Container -->
<div class="item-modal-backdrop" id="reqHistoryBackdrop" onclick="closeRequesterHistoryModal()"></div>
<div class="item-modal" id="reqHistoryModal" role="dialog" aria-modal="true" style="max-width:760px; width:94%; max-height:88vh; display:none; flex-direction:column; padding:1.5rem; overflow:hidden;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem;">
    <div style="display:flex; align-items:center; gap:0.9rem;">
      <div id="mReqAvatar" style="width:46px; height:46px; border-radius:50%; background:#3B2A1F; color:#FFF; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0;"></div>
      <div>
        <h3 id="mReqName" style="margin:0; font-size:1.15rem; font-weight:800; color:var(--ink-dark);">Loading...</h3>
        <div id="mReqRole" style="font-size:0.78rem; color:var(--ink-soft); margin-top:2px;"></div>
      </div>
    </div>
    <div style="display:flex; gap:0.5rem; align-items:center;">
      <a id="mReqFullLink" href="#" target="_blank" class="btn btn-outline btn-sm" style="font-size:0.75rem; height:28px; padding:0 0.6rem; display:inline-flex; align-items:center; gap:0.25rem;">
        Full Profile &rarr;
      </a>
      <button type="button" class="item-modal-close" onclick="closeRequesterHistoryModal()" style="position:static; padding:0; width:28px; height:28px; font-size:1.2rem; cursor:pointer;" aria-label="Close">&times;</button>
    </div>
  </div>

  <!-- KPI Badges Grid -->
  <div id="mReqStats" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:0.6rem; margin-bottom:1rem;">
    <div style="background:var(--surface-soft, #FAF6F0); border:1px solid rgba(230,218,202,0.6); padding:0.5rem 0.6rem; border-radius:8px; text-align:center;">
      <div style="font-size:0.68rem; color:var(--ink-soft); text-transform:uppercase; font-weight:600;">Requisitions</div>
      <div id="mStatReqs" style="font-size:1.15rem; font-weight:800; color:var(--ink-dark); font-family:var(--font-mono, monospace);">0</div>
    </div>
    <div style="background:var(--surface-soft, #FAF6F0); border:1px solid rgba(230,218,202,0.6); padding:0.5rem 0.6rem; border-radius:8px; text-align:center;">
      <div style="font-size:0.68rem; color:var(--ink-soft); text-transform:uppercase; font-weight:600;">Released</div>
      <div id="mStatReleased" style="font-size:1.15rem; font-weight:800; color:#059669; font-family:var(--font-mono, monospace);">0</div>
    </div>
    <div style="background:var(--surface-soft, #FAF6F0); border:1px solid rgba(230,218,202,0.6); padding:0.5rem 0.6rem; border-radius:8px; text-align:center;">
      <div style="font-size:0.68rem; color:var(--ink-soft); text-transform:uppercase; font-weight:600;">Tools Loaned</div>
      <div id="mStatLoans" style="font-size:1.15rem; font-weight:800; color:var(--ink-dark); font-family:var(--font-mono, monospace);">0</div>
    </div>
    <div style="background:var(--surface-soft, #FAF6F0); border:1px solid rgba(230,218,202,0.6); padding:0.5rem 0.6rem; border-radius:8px; text-align:center;">
      <div style="font-size:0.68rem; color:var(--ink-soft); text-transform:uppercase; font-weight:600;">Active / Overdue</div>
      <div id="mStatActiveLoans" style="font-size:1.15rem; font-weight:800; color:#D97706; font-family:var(--font-mono, monospace);">0</div>
    </div>
  </div>

  <!-- Modal Tabs -->
  <div style="display:flex; gap:0.5rem; border-bottom:1px solid var(--border-color); margin-bottom:0.75rem;">
    <button type="button" class="profile-tab active" id="mTabBtnReqs" onclick="switchModalTab('reqs')" style="padding:0.4rem 0.8rem; font-size:0.82rem;">
      <?= icon_svg('clipboard') ?> Requisitions (<span id="mCountReqs">0</span>)
    </button>
    <button type="button" class="profile-tab" id="mTabBtnLoans" onclick="switchModalTab('loans')" style="padding:0.4rem 0.8rem; font-size:0.82rem;">
      <?= icon_svg('tool') ?> Tool Loans (<span id="mCountLoans">0</span>)
    </button>
  </div>

  <!-- Scrollable Panel Body -->
  <div style="flex:1; overflow-y:auto; max-height:48vh; padding-right:4px;">
    <!-- Loading State -->
    <div id="mLoading" style="text-align:center; padding:2rem; color:var(--ink-soft); font-size:0.88rem;">
      Loading requester activity profile...
    </div>

    <!-- Panel 1: Requisitions -->
    <div id="mPanelReqs" style="display:none;">
      <div id="mReqsEmpty" style="display:none; text-align:center; padding:2rem 1rem; color:var(--ink-soft); font-size:0.85rem;">
        No requisitions filed by this personnel.
      </div>
      <table class="data" id="mReqsTable" style="display:none; width:100%; font-size:0.82rem;">
        <thead>
          <tr>
            <th style="width:10%;">#</th>
            <th style="width:18%;">Submitted</th>
            <th style="width:18%;">Truck</th>
            <th style="width:32%;">Items</th>
            <th style="width:12%;">Status</th>
            <th style="width:10%; text-align:right;"></th>
          </tr>
        </thead>
        <tbody id="mReqsTbody"></tbody>
      </table>
    </div>

    <!-- Panel 2: Tool Loans -->
    <div id="mPanelLoans" style="display:none;">
      <div id="mLoansEmpty" style="display:none; text-align:center; padding:2rem 1rem; color:var(--ink-soft); font-size:0.85rem;">
        No tools or equipment borrowed by this personnel.
      </div>
      <table class="data" id="mLoansTable" style="display:none; width:100%; font-size:0.82rem;">
        <thead>
          <tr>
            <th style="width:10%;">#</th>
            <th style="width:34%;">Tool / Equipment</th>
            <th style="width:18%;">Asset Tag</th>
            <th style="width:18%;">Borrowed</th>
            <th style="width:20%;">Status</th>
          </tr>
        </thead>
        <tbody id="mLoansTbody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
function openRequesterHistoryModal(userId) {
  var backdrop = document.getElementById('reqHistoryBackdrop');
  var modal = document.getElementById('reqHistoryModal');
  if (!modal || !backdrop) return;

  // Reset modal state
  document.getElementById('mLoading').style.display = 'block';
  document.getElementById('mPanelReqs').style.display = 'none';
  document.getElementById('mPanelLoans').style.display = 'none';
  document.getElementById('mReqName').textContent = 'Loading...';
  document.getElementById('mReqRole').textContent = '';
  document.getElementById('mReqAvatar').textContent = '..';
  document.getElementById('mReqFullLink').href = '<?= BASE_URL ?>/reports/requester_history.php?id=' + userId;

  backdrop.classList.add('open');
  modal.style.display = 'flex';
  modal.classList.add('open');

  fetch('<?= BASE_URL ?>/reports/api_requester_history.php?id=' + userId)
    .then(function(res) { return res.json(); })
    .then(function(data) {
      document.getElementById('mLoading').style.display = 'none';
      if (!data.success) {
        document.getElementById('mReqName').textContent = 'Error';
        document.getElementById('mReqRole').textContent = data.error || 'Failed to load';
        return;
      }

      var u = data.requester;
      var s = data.stats;
      document.getElementById('mReqName').textContent = u.full_name;
      document.getElementById('mReqRole').textContent = u.role_label + (u.position ? ' (' + u.position + ')' : '') + ' • Emp ID: ' + u.employee_id;
      document.getElementById('mReqAvatar').textContent = u.initials || '??';

      document.getElementById('mStatReqs').textContent = s.total_requisitions;
      document.getElementById('mStatReleased').textContent = s.released_count;
      document.getElementById('mStatLoans').textContent = s.total_loans;
      document.getElementById('mStatActiveLoans').textContent = s.active_loans + (s.overdue_loans > 0 ? ' (' + s.overdue_loans + ' ovd)' : '');

      document.getElementById('mCountReqs').textContent = data.requisitions.length;
      document.getElementById('mCountLoans').textContent = data.loans.length;

      // Populate Requisitions
      var reqsTbody = document.getElementById('mReqsTbody');
      reqsTbody.innerHTML = '';
      if (data.requisitions.length === 0) {
        document.getElementById('mReqsEmpty').style.display = 'block';
        document.getElementById('mReqsTable').style.display = 'none';
      } else {
        document.getElementById('mReqsEmpty').style.display = 'none';
        document.getElementById('mReqsTable').style.display = 'table';
        data.requisitions.forEach(function(r) {
          var itemsSummary = r.items.map(function(it) {
            return it.item_name_snapshot + ' (×' + it.quantity_requested + ')';
          }).join(', ') || 'No items';

          var tr = document.createElement('tr');
          tr.innerHTML = '<td class="mono">#' + r.id + '</td>' +
            '<td class="mono" style="font-size:0.75rem; color:var(--ink-soft);">' + r.formatted_date + '</td>' +
            '<td><span class="mono" style="font-size:0.75rem;">' + r.truck_plate + '</span></td>' +
            '<td style="font-size:0.78rem;" title="' + itemsSummary + '">' + (itemsSummary.length > 35 ? itemsSummary.substring(0, 35) + '...' : itemsSummary) + '</td>' +
            '<td><span class="badge ' + r.status_class + '">' + r.status_label + '</span></td>' +
            '<td style="text-align:right;"><a href="<?= BASE_URL ?>/requisition/view.php?id=' + r.id + '" target="_blank" class="btn btn-outline btn-sm" style="height:22px; padding:0 0.4rem; font-size:0.68rem; line-height:20px;">View</a></td>';
          reqsTbody.appendChild(tr);
        });
      }

      // Populate Loans
      var loansTbody = document.getElementById('mLoansTbody');
      loansTbody.innerHTML = '';
      if (data.loans.length === 0) {
        document.getElementById('mLoansEmpty').style.display = 'block';
        document.getElementById('mLoansTable').style.display = 'none';
      } else {
        document.getElementById('mLoansEmpty').style.display = 'none';
        document.getElementById('mLoansTable').style.display = 'table';
        data.loans.forEach(function(l) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td class="mono">#' + l.id + '</td>' +
            '<td><strong>' + l.item_name + '</strong> (×' + l.quantity + ')</td>' +
            '<td class="mono" style="font-size:0.75rem;">' + (l.asset_tag || 'Untagged') + '</td>' +
            '<td class="mono" style="font-size:0.75rem;">' + l.borrowed_fmt + '</td>' +
            '<td><span class="badge ' + l.status_class + '">' + l.status_label + '</span></td>';
          loansTbody.appendChild(tr);
        });
      }

      switchModalTab('reqs');
    })
    .catch(function(err) {
      document.getElementById('mLoading').style.display = 'none';
      document.getElementById('mReqName').textContent = 'Connection Error';
      document.getElementById('mReqRole').textContent = 'Could not fetch history data.';
    });
}

function closeRequesterHistoryModal() {
  var backdrop = document.getElementById('reqHistoryBackdrop');
  var modal = document.getElementById('reqHistoryModal');
  if (backdrop) backdrop.classList.remove('open');
  if (modal) {
    modal.classList.remove('open');
    modal.style.display = 'none';
  }
}

function switchModalTab(tab) {
  var reqsBtn = document.getElementById('mTabBtnReqs');
  var loansBtn = document.getElementById('mTabBtnLoans');
  var reqsPanel = document.getElementById('mPanelReqs');
  var loansPanel = document.getElementById('mPanelLoans');

  if (tab === 'reqs') {
    if (reqsBtn) reqsBtn.classList.add('active');
    if (loansBtn) loansBtn.classList.remove('active');
    if (reqsPanel) reqsPanel.style.display = 'block';
    if (loansPanel) loansPanel.style.display = 'none';
  } else {
    if (loansBtn) loansBtn.classList.add('active');
    if (reqsBtn) reqsBtn.classList.remove('active');
    if (loansPanel) loansPanel.style.display = 'block';
    if (reqsPanel) reqsPanel.style.display = 'none';
  }
}

// Close on Escape key
window.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeRequesterHistoryModal();
  }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

