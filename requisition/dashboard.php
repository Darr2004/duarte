<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority.php';
require_role(['field_supervisor']);

$pdo = get_db();

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$pending_count = count_pending_requisitions($pdo);

$by_status = $pdo->query(
    "SELECT status, COUNT(*) c FROM requisitions
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY status"
)->fetchAll();
$week_counts = ['pending' => 0, 'approved' => 0, 'declined' => 0, 'released' => 0, 'cancelled' => 0];
foreach ($by_status as $row) {
    $week_counts[$row['status']] = (int)$row['c'];
}

// Daily submission volume over the last 7 days, for the trend sparkline
// on the "Awaiting your decision" card — a quiet read on whether the
// queue is likely to grow before it shrinks.
$daily_rows = $pdo->query(
    "SELECT DATE(created_at) d, COUNT(*) c FROM requisitions
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at)"
)->fetchAll();
$daily_by_date = [];
foreach ($daily_rows as $row) {
    $daily_by_date[$row['d']] = (int)$row['c'];
}
$submitted_trend = fill_daily_series($daily_by_date, 7);

$decided_week = $week_counts['approved'] + $week_counts['declined'] + $week_counts['released'];
$approval_rate_week = $decided_week > 0
    ? round((($week_counts['approved'] + $week_counts['released']) / $decided_week) * 100)
    : null;

// Scored the same way as requisition/pending.php so "who's up next"
// Scored the same way as requisition/pending.php so "who's up next"
// reads the same on the dashboard as it does on the full queue —
// oldest-first here would silently disagree with the priority order
// shown one click away.
$pending_list_all = $pdo->query(
    "SELECT r.*, u.full_name AS requester_name, u.position AS requester_position,
        (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
     FROM requisitions r
     JOIN users u ON u.id = r.requester_id
     WHERE r.status = 'pending'
     ORDER BY r.created_at ASC"
)->fetchAll();
$scored_pending_all = score_pending_requisitions($pdo, $pending_list_all);
$pending_list = array_slice($scored_pending_all, 0, 6);

// Flag high-priority / urgent items (MCDA >= 70 or manual urgent)
$urgent_pending_items = array_filter($scored_pending_all, function($r) {
    return !empty($r['manual_urgent']) || ($r['priority_score'] ?? 0) >= 70;
});
$urgent_count = count($urgent_pending_items);

// Decision Velocity: Turnaround time across last 7 days
$turnaround_stmt = $pdo->query(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, decided_at)) AS avg_mins
     FROM requisitions
     WHERE decided_at IS NOT NULL
       AND decided_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
       AND status IN ('approved', 'declined', 'released')"
);
$avg_decision_mins = $turnaround_stmt->fetch()['avg_mins'];
$turnaround_label = '—';
if ($avg_decision_mins !== null && (float)$avg_decision_mins > 0) {
    $m = (float)$avg_decision_mins;
    $turnaround_label = $m < 60 ? round($m) . 'm' : round($m / 60, 1) . 'h';
}

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
  <div class="welcome-hero-actions">
    <a href="<?= BASE_URL ?>/requisition/pending.php" class="btn btn-primary">
      <?= icon_svg('clipboard') ?> Pending Approvals<?php if ($pending_count > 0): ?> <span class="badge inactive" style="background:#ffffff; color:var(--amber); margin-left:0.35rem; font-weight:700;"><?= (int)$pending_count ?></span><?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/requisition/all.php" class="btn btn-ghost" style="border:1px solid rgba(255,255,255,0.25); color:#ffffff;">All Requests</a>
  </div>
</div>

<?php if ($urgent_count > 0): ?>
  <div class="urgent-action-banner">
    <div class="urgent-action-banner-body">
      <div class="urgent-action-banner-icon">
        <?= icon_svg('alert-triangle') ?>
      </div>
      <div>
        <div class="urgent-action-title">
          High-Priority Action Required (<?= (int)$urgent_count ?>)
        </div>
        <div class="urgent-action-desc">
          <?= (int)$urgent_count ?> over critical threshold.
        </div>
      </div>
    </div>
    <div>
      <a href="<?= BASE_URL ?>/requisition/pending.php?sort=priority" class="btn btn-primary btn-sm">Review Priority Queue &rarr;</a>
    </div>
  </div>
<?php elseif ($pending_count > 0): ?>
  <div class="alert alert-warning"><?= icon_svg('alert-triangle') ?> <?= (int)$pending_count ?> requisition(s) need your review</div>
<?php endif; ?>

<div class="stat-grid-v2">
  <a href="<?= BASE_URL ?>/requisition/pending.php" class="stat-card-v2<?= $pending_count > 0 ? ' is-warn' : ' is-ok' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('clock') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Awaiting Decision</div>
      <div class="stat-card-v2-value<?= $pending_count > 0 ? ' is-warn' : '' ?>"><?= (int)$pending_count ?></div>
      <?php if ($submitted_trend): ?>
        <div class="stat-card-v2-sparkline"><?= sparkline_svg($submitted_trend) ?></div>
        <div class="stat-card-v2-sub">Submissions, last 7 days</div>
      <?php endif; ?>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/requisition/all.php" class="stat-card-v2 is-ok">
    <div class="stat-card-v2-icon"><?= icon_svg('check-circle') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Approved (7 Days)</div>
      <div class="stat-card-v2-value"><?= $week_counts['approved'] + $week_counts['released'] ?></div>
      <div class="stat-card-v2-sub"><?= $week_counts['released'] ?> already released</div>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/requisition/all.php?status=declined" class="stat-card-v2<?= $week_counts['declined'] > 0 ? ' is-danger' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('x-circle') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Declined (7 Days)</div>
      <div class="stat-card-v2-value"><?= $week_counts['declined'] ?></div>
      <div class="stat-card-v2-sub"><?= $decided_week > 0 ? round(($week_counts['declined'] / $decided_week) * 100) . '% rate' : 'No declines' ?></div>
    </div>
  </a>
  <div class="stat-card-v2" style="cursor:default;">
    <div class="stat-card-v2-icon"><?= icon_svg('user-check') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Approval Rate</div>
      <div class="stat-card-v2-value"><?= $approval_rate_week !== null ? $approval_rate_week . '%' : '—' ?></div>
      <div class="stat-card-v2-sub"><?= $decided_week ?> decided &middot; <?= $turnaround_label !== '—' ? 'avg ' . $turnaround_label : 'steady pace' ?></div>
    </div>
  </div>
</div>

<div class="dashboard-columns" style="display:grid; grid-template-columns: 1.6fr 1fr; gap: 1.5rem; align-items:start;">
  <!-- Left Column: Decision Queue -->
  <div class="card m-0">
    <div class="section-head">
      <div>
        <h2>Waiting on you</h2>
        <div class="section-sub">Highest priority first</div>
      </div>
      <?php if ($pending_count > 0): ?>
        <a href="<?= BASE_URL ?>/requisition/pending.php?sort=priority" class="btn btn-outline btn-sm">Full queue (<?= (int)$pending_count ?>) &rarr;</a>
      <?php endif; ?>
    </div>
    <?php if (!$pending_list): ?>
      <div class="empty-state-mini" style="padding: 2.25rem 1.5rem; text-align:center;">
        <div style="width:42px; height:42px; border-radius:50%; background:var(--green-tint, rgba(16,185,129,0.12)); color:var(--green-ok); display:inline-flex; align-items:center; justify-content:center; margin-bottom:0.75rem;">
          <?= icon_svg('check-circle') ?>
        </div>
        <div style="font-weight:600; font-size:0.95rem; color:var(--ink); margin-bottom:0.25rem;">Approval Queue Clear</div>
        <div style="font-size:0.82rem; color:var(--ink-soft); max-width:38ch; margin:0 auto 1rem; line-height:1.45;">Nothing pending review.</div>
        <a href="<?= BASE_URL ?>/requisition/all.php" class="btn btn-outline btn-sm">Browse historical requests &rarr;</a>
      </div>
    <?php else: ?>
      <div class="priority-list">
        <?php foreach ($pending_list as $r): ?>
          <div class="priority-item">
            <div class="priority-item-avatar"><?= htmlspecialchars(item_initials($r['requester_name'])) ?></div>
            <div class="priority-item-body">
              <div class="priority-item-title">
                #<?= $r['id'] ?> &middot; <?= htmlspecialchars((string)($r['requester_name'] ?? '')) ?>
                <?php $req_pos = position_label($r['requester_position']); if ($req_pos !== ''): ?>
                  <span class="text-muted-normal">(<?= htmlspecialchars($req_pos) ?>)</span>
                <?php endif; ?>
                <?php if (!empty($r['manual_urgent'])): ?>
                  <span class="badge inactive" title="<?= htmlspecialchars($r['manual_urgent_reason'] ?? '') ?>">Urgent</span>
                <?php endif; ?>
              </div>
              <div class="priority-item-meta">
                <?php if (!empty($r['truck_plate_snapshot'])): ?>
                  <span style="display:inline-block; margin-right:0.35rem; vertical-align:middle;"><?= truck_plate_badge($r['truck_plate_snapshot']) ?></span>
                <?php endif; ?>
                <?= (int)$r['item_count'] ?> item(s) &middot; submitted <?= time_ago($r['created_at']) ?>
              </div>
            </div>
            <div class="priority-item-cta">
              <span class="badge <?= priority_score_class($r['priority_score']) ?>" title="Stock <?= $r['priority_stock'] ?? $r['priority_scarcity'] ?>% · Demand <?= $r['priority_demand'] ?? $r['priority_contention'] ?>% · Trust <?= $r['priority_trust'] ?? $r['priority_reliability'] ?>%"><?= $r['priority_score'] ?></span>
              <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $r['id'] ?>" class="btn btn-primary btn-sm">Review</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($pending_list) === 6 && $pending_count > 6): ?>
        <p style="font-size:0.82rem; color:var(--ink-soft); margin:0.85rem 0 0; text-align:right;">
          <a href="<?= BASE_URL ?>/requisition/pending.php">See all <?= (int)$pending_count ?> pending requisitions &rarr;</a>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Right Column: Queue Telemetry & Quick Access -->
  <div style="display:flex; flex-direction:column; gap:1.25rem;">
    <div class="card m-0">
      <div class="section-head">
        <div>
          <h2>Decision Velocity</h2>
          <div class="section-sub">Past 7 days performance pulse</div>
        </div>
      </div>
      <div style="display:flex; flex-direction:column; gap:0.85rem;">
        <div>
          <div style="display:flex; justify-content:space-between; font-size:0.82rem; margin-bottom:0.35rem;">
            <span style="color:var(--ink-soft);">Approval Rate:</span>
            <strong style="color:var(--ink);"><?= $approval_rate_week !== null ? $approval_rate_week . '%' : '—' ?></strong>
          </div>
          <div class="bar-track" style="height:6px; background:var(--line); border-radius:3px; overflow:hidden;">
            <div class="bar-fill" style="width: <?= $approval_rate_week ?? 0 ?>%; height:100%; background:var(--green-ok);"></div>
          </div>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.82rem; padding:0.4rem 0; border-top:1px solid var(--line);">
          <span style="color:var(--ink-soft);">Average Resolution:</span>
          <strong class="mono" style="color:var(--ink);"><?= $turnaround_label ?></strong>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.82rem; padding:0.4rem 0; border-top:1px solid var(--line);">
          <span style="color:var(--ink-soft);">Total Decided (7d):</span>
          <strong style="color:var(--ink);"><?= $decided_week ?> request(s)</strong>
        </div>
      </div>
    </div>

    <div class="card m-0">
      <div class="section-head">
        <div>
          <h2>Supervisor Shortcuts</h2>
          <div class="section-sub">Direct access to operational tools</div>
        </div>
      </div>
      <div style="display:flex; flex-direction:column; gap:0.5rem;">
        <a href="<?= BASE_URL ?>/requisition/all.php" class="btn btn-outline" style="justify-content:flex-start; font-size:0.84rem;">
          <?= icon_svg('clipboard') ?> All Requests Log &rarr;
        </a>
        <a href="<?= BASE_URL ?>/reports/utilization.php" class="btn btn-outline" style="justify-content:flex-start; font-size:0.84rem;">
          <?= icon_svg('truck') ?> Fleet Trucks &amp; High Consumption &rarr;
        </a>
        <a href="<?= BASE_URL ?>/requisition/catalog.php" class="btn btn-outline" style="justify-content:flex-start; font-size:0.84rem;">
          <?= icon_svg('box') ?> Browse Equipment Catalog &rarr;
        </a>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

