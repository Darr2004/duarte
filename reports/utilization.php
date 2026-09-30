<?php
/**
 * DuaRTE — Fleet & Equipment Utilization report.
 *
 * Answers "are we getting value out of what we own, or is it sitting on
 * the shelf?" for borrowable items: for each item, what share of its
 * available stock-days in the period was actually out on loan, how many
 * times it went out, and whether it's coming back late. Built on top of
 * tool_loans + items.quantity_on_hand — no schema change needed.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_role(['admin']);

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

// Whole days in the window (inclusive), used as the denominator for
// "available equipment-days" per item.
$period_days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);

// ---- Per-item utilization ----
// For every loan that overlaps the window at all, clip its borrowed/returned
// span to the window edges, then sum (days-out × quantity) per item. Still-
// out loans count up to $to (or today, whichever is earlier) so a loan that
// hasn't been returned yet doesn't get ignored.
$window_end_for_open = min($to, date('Y-m-d'));
$stmt = $pdo->prepare(
    "SELECT
        i.id, i.item_code, i.name, i.quantity_on_hand,
        tl.quantity,
        GREATEST(DATE(tl.borrowed_at), :f_date) AS span_start,
        LEAST(COALESCE(DATE(tl.returned_at), :open_end), :t_date) AS span_end,
        tl.due_date, tl.returned_at
     FROM tool_loans tl
     JOIN items i ON i.id = tl.item_id
     WHERE i.is_borrowable = 1
       AND tl.borrowed_at <= :t_ts
       AND (tl.returned_at IS NULL OR tl.returned_at >= :f_ts)"
);
$stmt->execute([
    'f_date'  => $from,
    't_date'  => $to,
    'open_end' => $window_end_for_open,
    't_ts'    => $to_ts,
    'f_ts'    => $from_ts,
]);
$loan_spans = $stmt->fetchAll();

// All borrowable items, so ones with zero loans in the window still show
// up at 0% utilization instead of silently disappearing from the report.
// Use true fleet capacity (on hand + active loans + registered assets),
// because released loans reduce quantity_on_hand.
$all_borrowable = $pdo->query(
    "SELECT i.id, i.item_code, i.name, i.quantity_on_hand,
            GREATEST(
                i.quantity_on_hand + COALESCE((SELECT SUM(tl.quantity) FROM tool_loans tl WHERE tl.item_id = i.id AND tl.returned_at IS NULL), 0),
                COALESCE((SELECT COUNT(*) FROM assets a WHERE a.item_id = i.id AND a.status != 'retired'), 0),
                1
            ) AS total_fleet_qty
     FROM items i
     WHERE i.is_borrowable = 1
     ORDER BY i.name"
)->fetchAll();

$usage = []; // item_id => ['days_out' => float, 'loan_count' => int, 'late_count' => int, 'qty' => int, 'name' => ..., 'code' => ...]
foreach ($all_borrowable as $it) {
    $usage[$it['id']] = [
        'name' => $it['name'],
        'code' => $it['item_code'],
        'qty'  => (int)$it['total_fleet_qty'],
        'days_out' => 0.0,
        'loan_count' => 0,
        'late_count' => 0,
    ];
}
foreach ($loan_spans as $row) {
    $id = $row['id'];
    if (!isset($usage[$id])) {
        continue;
    }
    $span_seconds = strtotime($row['span_end']) - strtotime($row['span_start']);
    if ($span_seconds < 0) {
        continue;
    }
    $span_days = ($span_seconds / 86400) + 1;
    $usage[$id]['days_out'] += $span_days * (int)$row['quantity'];
    $usage[$id]['loan_count']++;
    $is_late = $row['returned_at'] !== null
        ? (date('Y-m-d', strtotime($row['returned_at'])) > $row['due_date'])
        : ($row['due_date'] < date('Y-m-d'));
    if ($is_late) {
        $usage[$id]['late_count']++;
    }
}

foreach ($usage as $id => &$u) {
    $available_days = $u['qty'] * $period_days;
    $u['utilization_pct'] = $available_days > 0
        ? round(min(100, ($u['days_out'] / $available_days) * 100), 1)
        : 0.0;
}
unset($u);

// Rank by utilization for the "most used" / "sitting idle" views.
$ranked = $usage;
uasort($ranked, fn($a, $b) => $b['utilization_pct'] <=> $a['utilization_pct']);

$most_utilized = array_slice($ranked, 0, 5, true);
$idle_items = array_filter($ranked, fn($u) => $u['loan_count'] === 0);
$least_utilized = array_slice(
    array_filter($ranked, fn($u) => $u['loan_count'] > 0),
    -5, 5, true
);
$least_utilized = array_reverse($least_utilized, true);

// ---- Fleet-wide KPIs ----
$fleet_total_days_out = array_sum(array_column($usage, 'days_out'));
$fleet_total_available = array_sum(array_map(fn($u) => $u['qty'] * $period_days, $usage));
$fleet_utilization_pct = $fleet_total_available > 0
    ? round(($fleet_total_days_out / $fleet_total_available) * 100, 1)
    : 0.0;
$total_loans_in_period = array_sum(array_column($usage, 'loan_count'));
$total_late_in_period = array_sum(array_column($usage, 'late_count'));
$late_rate_pct = $total_loans_in_period > 0
    ? round(($total_late_in_period / $total_loans_in_period) * 100, 1)
    : null;
$idle_count = count($idle_items);
$borrowable_count = count($all_borrowable);

$max_util_bar = $most_utilized ? max(array_column($most_utilized, 'utilization_pct')) : 1;

// ---- Fleet Vehicles (Trucks) Operational Utilization & Maintenance ----
$truck_counts = [
    'total'             => 0,
    'available'         => 0,
    'on_trip'           => 0,
    'under_maintenance' => 0,
];
$trk_count_rows = $pdo->query(
    'SELECT status, COUNT(*) c FROM trucks GROUP BY status'
)->fetchAll();
foreach ($trk_count_rows as $row) {
    if (isset($truck_counts[$row['status']])) {
        $truck_counts[$row['status']] = (int)$row['c'];
    }
    $truck_counts['total'] += (int)$row['c'];
}

$truck_active_rate = $truck_counts['total'] > 0
    ? round(($truck_counts['on_trip'] / $truck_counts['total']) * 100, 1)
    : 0.0;
$truck_avail_rate = $truck_counts['total'] > 0
    ? round(($truck_counts['available'] / $truck_counts['total']) * 100, 1)
    : 0.0;
$truck_maint_rate = $truck_counts['total'] > 0
    ? round(($truck_counts['under_maintenance'] / $truck_counts['total']) * 100, 1)
    : 0.0;

// Requisition demand by truck in the selected period ($from_ts to $to_ts)
$truck_activity_stmt = $pdo->prepare(
    "SELECT t.id, t.plate_number, t.model, t.status,
            COUNT(DISTINCT r.id) AS req_count,
            COALESCE(SUM(ri.quantity_requested), 0) AS total_parts_qty,
            MAX(r.created_at) AS last_requisition_at
     FROM trucks t
     LEFT JOIN requisitions r
            ON r.truck_id = t.id
           AND r.created_at >= :f_ts
           AND r.created_at <= :t_ts
     LEFT JOIN requisition_items ri
            ON ri.requisition_id = r.id
     GROUP BY t.id, t.plate_number, t.model, t.status
     ORDER BY req_count DESC, t.plate_number ASC"
);
$truck_activity_stmt->execute(['f_ts' => $from_ts, 't_ts' => $to_ts]);
$truck_activity = $truck_activity_stmt->fetchAll();
$total_truck_requisitions_in_period = array_sum(array_column($truck_activity, 'req_count'));
$total_truck_parts_qty_in_period = array_sum(array_column($truck_activity, 'total_parts_qty'));

// Fleet High-Consumption Watchlist: flag trucks with repeated requisitions or maintenance downtime
$watchlist_trucks = [];
foreach ($truck_activity as $trk) {
    $is_high_parts   = (int)$trk['total_parts_qty'] >= 3;
    $is_high_freq    = (int)$trk['req_count'] >= 2;
    $is_maint_active = $trk['status'] === 'under_maintenance' && (int)$trk['req_count'] > 0;
    if ($is_high_parts || $is_high_freq || $is_maint_active) {
        $watchlist_trucks[] = $trk;
    }
}

$page_title = 'Fleet & Equipment Utilization';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Reports &amp; analytics</div>
    <h1>Fleet &amp; Equipment Utilization</h1>
  </div>
</div>

<div class="report-switch">
  <a href="<?= BASE_URL ?>/reports/index.php" class="report-switch-link"><?= icon_svg('clipboard') ?> Overview</a>
  <span class="report-switch-link active"><?= icon_svg('tool') ?> Fleet &amp; Equipment Utilization</span>
</div>

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
  padding: 0.4rem 0.65rem;
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
  padding: 0.65rem 0.8rem;
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
  background: var(--amber);
  color: #FFF;
}
.rank-badge.rank-2 {
  background: var(--charcoal-2);
  color: #FFF;
}
.rank-badge.rank-3 {
  background: var(--ink-soft);
  color: #FFF;
}
.rank-badge.rank-other {
  background: var(--surface-subtle);
  color: var(--ink-soft);
}
.rank-content {
  flex: 1;
  min-width: 0;
}
.rank-title {
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rank-sub {
  font-size: 0.74rem;
  color: var(--ink-soft);
  margin-top: 1px;
}
.rank-track {
  height: 5px;
  background: var(--surface-subtle);
  border-radius: 4px;
  margin-top: 0.4rem;
  overflow: hidden;
}
.rank-fill {
  height: 100%;
  border-radius: 4px;
  background: var(--amber);
  transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.rank-fill.fill-blue {
  background: var(--blue-info);
}
.rank-fill.fill-green {
  background: var(--green-ok);
}
.rank-fill.fill-amber {
  background: var(--amber);
}
.rank-pill {
  font-family: var(--font-mono, monospace);
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--ink);
  background: var(--surface);
  border: 1px solid var(--line);
  padding: 0.2rem 0.5rem;
  border-radius: 20px;
  flex-shrink: 0;
}
</style>

<!-- Quick Section Anchors -->
<div style="display:flex; gap:0.6rem; margin-top:1.2rem; margin-bottom:1.8rem; flex-wrap:wrap;">
  <a href="#fleet-section" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:0.4rem;">
    <?= icon_svg('truck') ?> <span>Fleet Vehicles (<?= $truck_counts['total'] ?> Trucks)</span>
  </a>
  <a href="#equipment-section" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:0.4rem;">
    <?= icon_svg('tool') ?> <span>Equipment &amp; Tools (<?= $borrowable_count ?> Items)</span>
  </a>
</div>

<!-- ======================================================== -->
<!-- SECTION 1: FLEET VEHICLES UTILIZATION & MAINTENANCE     -->
<!-- ======================================================== -->
<div class="card" id="fleet-section" style="margin-bottom:2.2rem; padding:1.5rem;">
  <div class="section-head" style="margin-bottom:1.2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
    <div>
      <div class="eyebrow meta-mono" style="color:var(--ink-soft);">Vehicle Dispatch &amp; Road Readiness</div>
      <h2 style="margin:0; font-size:1.35rem;">Fleet Vehicles Operational Utilization</h2>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
      <a href="<?= BASE_URL ?>/inventory/trucks.php" class="btn btn-outline btn-sm">
        <?= icon_svg('truck') ?> Manage Fleet
      </a>
      <a href="<?= BASE_URL ?>/reports/export.php?type=fleet&amp;from=<?= $from ?>&amp;to=<?= $to ?>" class="btn btn-outline btn-sm">
        <?= icon_svg('arrow-right') ?> Export fleet CSV
      </a>
    </div>
  </div>

  <div class="stat-grid-v2" style="margin-bottom:1.5rem;">
    <div class="stat-card-v2 <?= $truck_active_rate >= 50 ? 'is-ok' : ($truck_active_rate >= 20 ? 'is-warn' : 'is-danger') ?>">
      <div class="stat-card-v2-icon"><?= icon_svg('truck') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Active Haul Rate</div>
        <div class="stat-card-v2-value"><?= $truck_active_rate ?>%</div>
        <div class="stat-card-v2-sub"><?= $truck_counts['on_trip'] ?> of <?= $truck_counts['total'] ?> trucks on road trip</div>
      </div>
    </div>
    <div class="stat-card-v2 is-ok">
      <div class="stat-card-v2-icon"><?= icon_svg('check-circle') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Available for Dispatch</div>
        <div class="stat-card-v2-value is-ok"><?= $truck_counts['available'] ?></div>
        <div class="stat-card-v2-sub"><?= $truck_avail_rate ?>% ready in yard</div>
      </div>
    </div>
    <div class="stat-card-v2 <?= $truck_counts['under_maintenance'] > 0 ? 'is-danger' : 'is-ok' ?>">
      <div class="stat-card-v2-icon"><?= icon_svg('alert-triangle') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Under Maintenance</div>
        <div class="stat-card-v2-value <?= $truck_counts['under_maintenance'] > 0 ? 'is-danger' : '' ?>"><?= $truck_counts['under_maintenance'] ?></div>
        <div class="stat-card-v2-sub"><?= $truck_maint_rate ?>% maintenance downtime</div>
      </div>
    </div>
    <div class="stat-card-v2">
      <div class="stat-card-v2-icon"><?= icon_svg('box') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Parts Requisitions</div>
        <div class="stat-card-v2-value"><?= $total_truck_requisitions_in_period ?></div>
        <div class="stat-card-v2-sub"><?= $total_truck_parts_qty_in_period ?> parts/units in period</div>
      </div>
    </div>
  </div>

  <!-- Fleet Readiness Doughnut Breakdown -->
  <div class="donut-layout" style="margin-bottom:1.75rem; background:var(--surface-soft, #FAF6F0); padding:1.25rem 1.5rem; border-radius:12px; border:1px solid var(--border-color, #E6DACA);">
    <div class="donut-chart-container">
      <canvas id="truckStatusDoughnut"></canvas>
      <div class="donut-chart-center">
        <div class="donut-center-val"><?= $truck_counts['total'] ?></div>
        <div class="donut-center-lbl">Trucks</div>
      </div>
    </div>
    <div class="donut-legend">
      <div class="donut-legend-item">
        <span class="donut-legend-label">
          <span class="donut-dot" style="background:var(--green-ok);"></span>
          Available for Dispatch
        </span>
        <span class="donut-legend-meta">
          <span class="donut-legend-count"><?= $truck_counts['available'] ?></span>
          <span class="donut-legend-pct">(<?= $truck_avail_rate ?>%)</span>
        </span>
      </div>
      <div class="donut-legend-item">
        <span class="donut-legend-label">
          <span class="donut-dot" style="background:var(--blue-info);"></span>
          On Active Road Trip
        </span>
        <span class="donut-legend-meta">
          <span class="donut-legend-count"><?= $truck_counts['on_trip'] ?></span>
          <span class="donut-legend-pct">(<?= $truck_active_rate ?>%)</span>
        </span>
      </div>
      <div class="donut-legend-item">
        <span class="donut-legend-label">
          <span class="donut-dot" style="background:var(--red-danger);"></span>
          Under Maintenance
        </span>
        <span class="donut-legend-meta">
          <span class="donut-legend-count"><?= $truck_counts['under_maintenance'] ?></span>
          <span class="donut-legend-pct">(<?= $truck_maint_rate ?>%)</span>
        </span>
      </div>
    </div>
  </div>

  <h3 style="font-size:1.05rem; margin-top:1.5rem; margin-bottom:0.25rem;">Vehicle Maintenance &amp; Parts Activity</h3>
  <div class="section-sub" style="margin-bottom:0.9rem; font-size:0.85rem; color:var(--ink-soft);">
    Trucks ranked by parts requested.
  </div>

  <?php if (!empty($watchlist_trucks)): ?>
    <!-- Fleet High-Consumption Watchlist Alert -->
    <div style="background:var(--amber-tint); border:1px solid var(--amber-border); border-left:4px solid var(--amber); border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.25rem;">
      <div style="display:flex; align-items:center; gap:0.5rem; font-weight:700; color:var(--ink); font-size:0.95rem;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:var(--amber);"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>Fleet Maintenance Watchlist (<?= count($watchlist_trucks) ?>)</span>
      </div>
      <p style="font-size:0.8rem; color:var(--ink-soft); margin:0.3rem 0 0.85rem;">
        Preventive check recommended.
      </p>
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:0.75rem;">
        <?php foreach ($watchlist_trucks as $wtrk): ?>
          <div style="background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:0.75rem 0.95rem; display:flex; justify-content:space-between; align-items:center;">
            <div>
              <div style="margin-bottom:0.25rem;"><?= truck_plate_badge($wtrk['plate_number']) ?></div>
              <div style="font-size:0.76rem; color:var(--ink-soft);"><?= htmlspecialchars((string)($wtrk['model'] ?? '')) ?></div>
              <div style="font-size:0.75rem; color:var(--amber); font-weight:600; margin-top:0.25rem;">
                <?= (int)$wtrk['req_count'] ?> request(s) &bull; <?= (int)$wtrk['total_parts_qty'] ?> parts/units
                <?php if ($wtrk['status'] === 'under_maintenance'): ?>
                  <span class="badge inactive" style="font-size:0.68rem; padding:0.1rem 0.35rem; margin-left:0.25rem;">Under Repair</span>
                <?php endif; ?>
              </div>
            </div>
            <a href="<?= BASE_URL ?>/requisition/all.php?q=<?= urlencode($wtrk['plate_number']) ?>" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:0.35rem 0.7rem; white-space:nowrap;">
              Audit
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <!-- Normal Fleet Operation Confirmation -->
    <div style="background:var(--green-tint); border:1px solid var(--green-border); border-left:4px solid var(--green-ok); border-radius:10px; padding:0.85rem 1.1rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:0.6rem;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:var(--green-ok);"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <div style="font-size:0.84rem; color:var(--green-ok);">
        <strong>Fleet Status Nominal:</strong> All vehicle parts consumption remains within normal operating limits.
      </div>
    </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="data">
      <thead>
        <tr>
          <th>Plate Number</th>
          <th>Model / Vehicle Type</th>
          <th>Current Status</th>
          <th>Requisitions (This Period)</th>
          <th>Parts Units Requested</th>
          <th>Last Requisition Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$truck_activity): ?>
          <tr><td colspan="7">No registered trucks found in the fleet.</td></tr>
        <?php else: foreach ($truck_activity as $trk):
          $st_info = truck_status_info($trk['status']);
        ?>
          <tr>
            <td data-label="Plate Number">
              <?= truck_plate_badge($trk['plate_number']) ?>
            </td>
            <td data-label="Model">
              <div style="font-weight:600;"><?= htmlspecialchars((string)($trk['model'] ?? '')) ?></div>
            </td>
            <td data-label="Status">
              <span class="badge <?= $st_info['class'] ?>" style="font-size:0.8rem; font-weight:600;">
                <?= htmlspecialchars((string)($st_info['label'] ?? '')) ?>
              </span>
            </td>
            <td data-label="Requisitions">
              <?php if ((int)$trk['req_count'] > 0): ?>
                <span class="badge role" style="font-weight:600;">
                  <?= (int)$trk['req_count'] ?> requisition<?= (int)$trk['req_count'] === 1 ? '' : 's' ?>
                </span>
              <?php else: ?>
                <span class="text-muted">None</span>
              <?php endif; ?>
            </td>
            <td data-label="Parts Qty" class="mono">
              <?= (int)$trk['total_parts_qty'] > 0 ? (int)$trk['total_parts_qty'] . ' unit(s)' : '<span class="text-muted">—</span>' ?>
            </td>
            <td data-label="Last Requisition" class="mono" style="font-size:0.85rem;">
              <?= $trk['last_requisition_at'] ? htmlspecialchars((string)($trk['last_requisition_at'] ?? '')) : '<span class="text-muted">—</span>' ?>
            </td>
            <td data-label="Action">
              <a href="<?= BASE_URL ?>/requisition/all.php?q=<?= urlencode($trk['plate_number']) ?>" class="btn btn-outline btn-sm">
                View Requests
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ======================================================== -->
<!-- SECTION 2: EQUIPMENT & TOOL LOANS UTILIZATION           -->
<!-- ======================================================== -->
<div class="section-head" id="equipment-section" style="margin-top:2rem; margin-bottom:1rem;">
  <div>
    <div class="eyebrow meta-mono" style="color:var(--ink-soft);">Warehouse Tools &amp; Equipment</div>
    <h2 style="margin:0; font-size:1.35rem;">Equipment &amp; Tool Loans Utilization</h2>
  </div>
</div>

<div class="card gauge-hero">
  <?= gauge_svg($fleet_utilization_pct, 'Utilized') ?>
  <div class="gauge-hero-body">
    <h2 class="gauge-hero-title">Fleet is running at <?= $fleet_utilization_pct ?>% utilization</h2>
    <p class="gauge-hero-desc">
      <?= $fleet_utilization_pct ?>% of capacity on loan.
      <?php if ($idle_count > 0): ?>
        <?= $idle_count ?> never used.
      <?php endif; ?>
    </p>
    <div class="gauge-hero-stats">
      <div>
        <div class="gauge-hero-stat-value"><?= $borrowable_count ?></div>
        <div class="gauge-hero-stat-label">Borrowable items</div>
      </div>
      <div>
        <div class="gauge-hero-stat-value"><?= $total_loans_in_period ?></div>
        <div class="gauge-hero-stat-label">Loans this period</div>
      </div>
      <div>
        <div class="gauge-hero-stat-value" style="<?= $idle_count > 0 ? 'color:var(--red-danger);' : '' ?>"><?= $idle_count ?></div>
        <div class="gauge-hero-stat-label">Idle this period</div>
      </div>
      <div>
        <div class="gauge-hero-stat-value" style="<?= $late_rate_pct !== null && $late_rate_pct > 20 ? 'color:var(--red-danger);' : '' ?>"><?= $late_rate_pct !== null ? $late_rate_pct . '%' : '—' ?></div>
        <div class="gauge-hero-stat-label">Late return rate</div>
      </div>
    </div>
  </div>
</div>

<div class="analytics-dashboard-grid" style="margin-top:1.5rem; margin-bottom:2rem;">
  <!-- Card 1: Most Utilized Equipment -->
  <div class="analytics-card">
    <div class="analytics-card-header">
      <div>
        <h2 class="analytics-card-title"><?= icon_svg('tool') ?> Most utilized equipment</h2>
        <div class="analytics-card-sub">Ranked by days on loan</div>
      </div>
      <a href="<?= BASE_URL ?>/reports/export.php?type=loans&amp;from=<?= $from ?>&amp;to=<?= $to ?>" class="btn btn-outline btn-sm"><?= icon_svg('arrow-right') ?> Export CSV</a>
    </div>
    <?php if (!$most_utilized || $max_util_bar <= 0): ?>
      <div class="empty-state-mini" style="padding:2.5rem 1rem; text-align:center; color:var(--ink-soft);">
        <?= icon_svg('tool') ?>
        <div style="margin-top:0.5rem;">No loan activity in this period.</div>
      </div>
    <?php else: ?>
      <div class="rank-list">
        <?php foreach ($most_utilized as $idx => $u):
          $rank = $idx + 1;
          $rank_class = match($rank) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-other' };
          $tier = $u['utilization_pct'] >= 60 ? 'fill-green' : ($u['utilization_pct'] >= 30 ? 'fill-blue' : 'fill-amber');
          $pct_bar = bar_pct($u['utilization_pct'], $max_util_bar);
        ?>
          <div class="rank-row">
            <div class="rank-badge <?= $rank_class ?>">#<?= $rank ?></div>
            <div class="rank-content">
              <div style="display:flex; justify-content:space-between; align-items:baseline; gap:0.5rem;">
                <span class="rank-title" title="<?= htmlspecialchars((string)($u['name'] ?? '')) ?>"><?= htmlspecialchars((string)($u['name'] ?? '')) ?></span>
                <span class="rank-pill"><?= $u['utilization_pct'] ?>% used</span>
              </div>
              <div class="rank-sub">
                <span class="mono"><?= htmlspecialchars((string)($u['code'] ?? '')) ?></span> &middot; <?= $u['loan_count'] ?> loan<?= $u['loan_count'] === 1 ? '' : 's' ?> in period
                (<?= round($u['days_out'], 1) ?> equip-days)
              </div>
              <div class="rank-track">
                <div class="rank-fill <?= $tier ?>" style="width:<?= $pct_bar ?>%;"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Card 2: Low Turnover & Idle Equipment -->
  <div class="analytics-card">
    <div class="analytics-card-header">
      <div>
        <h2 class="analytics-card-title"><?= icon_svg('alert-triangle') ?> Low turnover &amp; idle equipment</h2>
        <div class="analytics-card-sub">Reallocation candidates</div>
      </div>
    </div>
    <?php 
    $low_and_idle = array_merge(
        array_slice($idle_items, 0, 4, true),
        array_slice($least_utilized, 0, 3, true)
    );
    ?>
    <?php if (!$low_and_idle): ?>
      <div class="empty-state-mini" style="padding:2.5rem 1rem; text-align:center; color:var(--ink-soft);">
        <?= icon_svg('check-circle') ?>
        <div style="margin-top:0.5rem;">Every borrowable equipment item moved at least once.</div>
      </div>
    <?php else: ?>
      <div class="rank-list">
        <?php foreach ($low_and_idle as $u):
          $is_idle = ($u['loan_count'] === 0);
        ?>
          <div class="rank-row">
            <div class="rank-badge <?= $is_idle ? 'rank-other' : 'rank-other' ?>" style="<?= $is_idle ? 'background:#FDE8E8; color:#9B1C1C;' : 'background:#FEF08A; color:#854D0E;' ?>">
              <?= $is_idle ? '0' : '!' ?>
            </div>
            <div class="rank-content">
              <div style="display:flex; justify-content:space-between; align-items:baseline; gap:0.5rem;">
                <span class="rank-title" title="<?= htmlspecialchars((string)($u['name'] ?? '')) ?>"><?= htmlspecialchars((string)($u['name'] ?? '')) ?></span>
                <?php if ($is_idle): ?>
                  <span class="badge inactive" style="font-size:0.75rem;">Idle &middot; 0 loans</span>
                <?php else: ?>
                  <span class="badge low-stock" style="font-size:0.75rem;"><?= $u['utilization_pct'] ?>% use</span>
                <?php endif; ?>
              </div>
              <div class="rank-sub">
                <span class="mono"><?= htmlspecialchars((string)($u['code'] ?? '')) ?></span> &middot; <?= $u['qty'] ?> unit(s) in fleet
                <?php if ($u['late_count'] > 0): ?>
                  &middot; <span style="color:var(--red-danger);"><?= $u['late_count'] ?> late return<?= $u['late_count'] === 1 ? '' : 's' ?></span>
                <?php endif; ?>
              </div>
              <div class="rank-track">
                <div class="rank-fill" style="width:<?= $is_idle ? 0 : max(5, bar_pct($u['utilization_pct'], $max_util_bar)) ?>%; background:<?= $is_idle ? 'transparent' : '#EAB308' ?>;"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  (function () {
    if (typeof Chart === 'undefined') return;

    var truckCtx = document.getElementById('truckStatusDoughnut');
    if (truckCtx) {
      new Chart(truckCtx, {
        type: 'doughnut',
        data: {
          labels: ['Available in Yard', 'On Road Trip', 'Under Maintenance'],
          datasets: [{
            data: [
              <?= (int)$truck_counts['available'] ?>,
              <?= (int)$truck_counts['on_trip'] ?>,
              <?= (int)$truck_counts['under_maintenance'] ?>
            ],
            backgroundColor: ['#10B981', '#3B82F6', '#EF4444'],
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
                  var total = <?= (int)$truck_counts['total'] ?>;
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
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
