<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin']);

$pdo = get_db();
$user = current_user();
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$total_users    = $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
$active_users   = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE status = 'active'")->fetch()['c'];
$by_role = $pdo->query("SELECT role, COUNT(*) c FROM users WHERE status = 'active' GROUP BY role")->fetchAll();

// Today only, resets at midnight — the full history lives on the
// Audit Logs page. Capped at 50 so a very busy day doesn't blow up
// the dashboard.
$recent_logins  = $pdo->query(
    "SELECT username, ip_address, success, attempted_at FROM login_attempts
     WHERE DATE(attempted_at) = CURDATE()
     ORDER BY attempted_at DESC LIMIT 50"
)->fetchAll();

// Suspicious = 3+ failed attempts for the same username within the last 15
// minutes. Flags the admin dashboard so brute-force attempts don't sit
// unnoticed inside a long, un-highlighted list.
$suspicious = $pdo->query(
    "SELECT username, ip_address, COUNT(*) AS fails, MAX(attempted_at) AS last_attempt
     FROM login_attempts
     WHERE success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)
     GROUP BY username, ip_address
     HAVING fails >= 3
     ORDER BY fails DESC"
)->fetchAll();

$failed_logins_today = (int)$pdo->query(
    "SELECT COUNT(*) c FROM login_attempts WHERE success = 0 AND DATE(attempted_at) = CURDATE()"
)->fetch()['c'];

$success_logins_today = (int)$pdo->query(
    "SELECT COUNT(*) c FROM login_attempts WHERE success = 1 AND DATE(attempted_at) = CURDATE()"
)->fetch()['c'];

// Everything below this line is admin's actual scope — accounts, not
// operational data (stock/requisitions have their own dashboards for
// the roles that own that work).

// Active accounts sitting unused since they were created — often
// forgotten test/demo accounts or onboarding that never finished.
$never_logged_in = $pdo->query(
    "SELECT id, full_name, username, role, position, created_at FROM users
     WHERE status = 'active' AND last_login_at IS NULL
     ORDER BY created_at ASC"
)->fetchAll();

$inactive_accounts_count = (int)$total_users - (int)$active_users;

// Account-related audit events in the last 7 days — a quick pulse
// check so an unusual spike (mass deactivations, a burst of new
// accounts) is visible without digging through the full log.
$account_changes_week = (int)$pdo->query(
    "SELECT COUNT(*) c FROM audit_logs
     WHERE entity_type = 'user'
       AND action IN ('user_create','user_update','user_activate','user_deactivate')
       AND created_at >= (NOW() - INTERVAL 7 DAY)"
)->fetch()['c'];

$recent_account_changes = $pdo->query(
    "SELECT * FROM audit_logs
     WHERE entity_type = 'user'
       AND action IN ('user_create','user_update','user_activate','user_deactivate')
     ORDER BY created_at DESC LIMIT 6"
)->fetchAll();

// Auto-approved requisitions (office staff / a Field Supervisor's own
// request — see requisition_needs_supervisor_approval()) skip the
// pending queue entirely, so priority scoring never sees them and
// Field Supervisor's own requisition pages never surface them either.
// That leaves nobody with a review step on these — this is the one
// piece of requisition data that belongs on an otherwise accounts-only
// admin dashboard, specifically because it's an approval-bypass
// oversight concern rather than day-to-day requisition management.
$auto_approved_week_count = (int)$pdo->query(
    "SELECT COUNT(*) c FROM requisitions
     WHERE (decision_note LIKE 'Approved without review%' OR decision_note LIKE 'Auto-approved%')
       AND decided_at >= (NOW() - INTERVAL 7 DAY)"
)->fetch()['c'];

$recent_auto_approved = $pdo->query(
    "SELECT r.id, r.decision_note, r.decided_at, u.full_name AS requester_name,
            u.role AS requester_role, u.position AS requester_position
     FROM requisitions r
     JOIN users u ON u.id = r.requester_id
     WHERE (r.decision_note LIKE 'Approved without review%' OR r.decision_note LIKE 'Auto-approved%')
     ORDER BY r.decided_at DESC LIMIT 6"
)->fetchAll();

// Color swatches for the role-split legend — reuses the app's existing
// palette so it stays consistent with the badges used everywhere else.
$role_colors = [
    'admin'             => 'var(--charcoal)',
    'inventory_staff'   => 'var(--amber)',
    'field_supervisor'  => 'var(--green-ok)',
    'driver_helper'     => 'var(--ink-soft)',
];
$role_split_max = 0;
foreach ($by_role as $r) { $role_split_max = max($role_split_max, (int)$r['c']); }
$role_split_max = max($role_split_max, 1);

// Telemetry & Architecture Health
$db_version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
$db_is_mariadb = stripos($db_version, 'mariadb') !== false;
$db_display = ($db_is_mariadb ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*$/', '', $db_version);

require_once __DIR__ . '/../config/sms.php';
$sms_enabled = defined('SMS_ENABLED') && SMS_ENABLED;
$sms_recent = $pdo->query("SELECT status FROM sms_logs ORDER BY created_at DESC LIMIT 1")->fetch();
$sms_status = $sms_enabled ? ($sms_recent && $sms_recent['status'] === 'failed' ? 'Degraded' : 'Active') : 'Disabled';

$uploads_dir = __DIR__ . '/../uploads';
$uploads_writable = is_dir($uploads_dir) && is_writable($uploads_dir);
$disk_free = @disk_free_space(__DIR__);
$disk_free_gb = $disk_free ? round($disk_free / (1024 * 1024 * 1024), 1) . ' GB free' : 'Available';

if ($suspicious) {
    $threat_level = 'Elevated';
    $threat_class = 'is-danger';
} elseif ($failed_logins_today > 5) {
    $threat_level = 'Guarded';
    $threat_class = 'is-warn';
} else {
    $threat_level = 'Nominal';
    $threat_class = 'is-ok';
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
</div>

<div class="telemetry-strip">
  <div class="telemetry-strip-group">
    <div class="telemetry-item" title="Database Engine & Connectivity">
      <span class="telemetry-dot is-ok"></span>
      <span class="telemetry-label">Database:</span>
      <span class="telemetry-val"><?= htmlspecialchars($db_display) ?></span>
    </div>
    <div class="telemetry-item" title="PhilSMS Gateway v3 Dispatch Status">
      <span class="telemetry-dot <?= $sms_status === 'Active' ? 'is-ok' : ($sms_status === 'Degraded' ? 'is-warn' : '') ?>"></span>
      <span class="telemetry-label">SMS Gateway:</span>
      <span class="telemetry-val"><?= htmlspecialchars($sms_status) ?></span>
    </div>
    <div class="telemetry-item" title="Local Storage & Uploads Subsystem">
      <span class="telemetry-dot <?= $uploads_writable ? 'is-ok' : 'is-warn' ?>"></span>
      <span class="telemetry-label">Storage:</span>
      <span class="telemetry-val"><?= htmlspecialchars($disk_free_gb) ?></span>
    </div>
  </div>
  <div class="telemetry-item" title="Authentication & Brute-Force Activity">
    <span class="telemetry-dot <?= $threat_class ?>"></span>
    <span class="telemetry-label">Threat Index:</span>
    <span class="telemetry-val" style="color:<?= $threat_class === 'is-danger' ? 'var(--red-danger)' : ($threat_class === 'is-warn' ? 'var(--amber)' : 'inherit') ?>;"><?= htmlspecialchars($threat_level) ?></span>
  </div>
</div>

<?php if ($suspicious): ?>
  <div class="alert alert-warning">
    <strong><?= icon_svg('shield-alert') ?> Possible brute-force attempt.</strong><br>
    <?php foreach ($suspicious as $s): ?>
      <?= htmlspecialchars((string)($s['username'] ?? '')) ?>
      (<?= htmlspecialchars($s['ip_address'] ?? 'unknown IP') ?>)
      — <?= (int)$s['fails'] ?> failed logins in the last 15 minutes, most recent at
      <?= htmlspecialchars((string)($s['last_attempt'] ?? '')) ?>.<br>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="stat-grid-v2">
  <a href="<?= BASE_URL ?>/admin/users.php" class="stat-card-v2">
    <div class="stat-card-v2-icon"><?= icon_svg('users') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Total accounts</div>
      <div class="stat-card-v2-value"><?= (int)$total_users ?></div>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/admin/users.php" class="stat-card-v2 is-ok">
    <div class="stat-card-v2-icon"><?= icon_svg('user-check') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Active accounts</div>
      <div class="stat-card-v2-value"><?= (int)$active_users ?></div>
      <?php if ($inactive_accounts_count > 0): ?>
        <div class="stat-card-v2-sub"><?= $inactive_accounts_count ?> inactive</div>
      <?php endif; ?>
    </div>
  </a>
  <div class="stat-card-v2<?= $failed_logins_today > 0 ? ' is-warn' : ' is-ok' ?>" style="cursor:default;">
    <div class="stat-card-v2-icon"><?= icon_svg('log-in') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Logins today</div>
      <div class="stat-card-v2-value"><?= $success_logins_today ?></div>
      <?php if ($failed_logins_today > 0): ?>
        <div class="stat-card-v2-sub" style="color:var(--amber-dim); font-weight:600;"><?= $failed_logins_today ?> failed</div>
      <?php endif; ?>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/admin/audit_logs.php" class="stat-card-v2">
    <div class="stat-card-v2-icon"><?= icon_svg('shield') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Account changes (7 days)</div>
      <div class="stat-card-v2-value"><?= $account_changes_week ?></div>
    </div>
  </a>
</div>

<div class="account-insights-grid">
  <div class="card">
    <h2 class="card-heading">Accounts by role</h2>
    <div class="role-split">
      <?php foreach ($by_role as $r): ?>
        <div class="role-split-row">
          <span class="role-split-swatch" style="background:<?= $role_colors[$r['role']] ?? 'var(--ink-soft)' ?>;"></span>
          <span class="role-split-label"><?= htmlspecialchars(role_label($r['role'])) ?></span>
          <span class="role-split-value"><?= (int)$r['c'] ?></span>
        </div>
        <div class="bar-track" style="height:6px;">
          <div class="bar-fill" style="width: <?= bar_pct((int)$r['c'], $role_split_max) ?>%; background:<?= $role_colors[$r['role']] ?? 'var(--ink-soft)' ?>;"></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2 class="card-heading">Accounts needing attention</h2>
    <?php if (!$never_logged_in): ?>
      <div class="empty-state-mini">
        <?= icon_svg('check-circle') ?>
        <div>Every active account has logged in at least once.</div>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach (array_slice($never_logged_in, 0, 5) as $u): ?>
          <div class="timeline-row">
            <div class="timeline-dot is-danger"><?= icon_svg('alert-triangle') ?></div>
            <div class="timeline-body">
              <div class="timeline-title"><a href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= (int)$u['id'] ?>"><?= htmlspecialchars((string)($u['full_name'] ?? '')) ?></a></div>
              <div class="timeline-meta"><?= htmlspecialchars(role_display($u['role'], $u['position'] ?? null)) ?> &middot; never signed in</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($never_logged_in) > 5): ?>
        <p style="font-size:0.8rem; color:var(--ink-soft); margin:0.75rem 0 0;">+<?= count($never_logged_in) - 5 ?> more — see <a href="<?= BASE_URL ?>/admin/users.php">User Accounts</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card-heading">Recent account changes</h2>
    <?php if (!$recent_account_changes): ?>
      <div class="empty-state-mini">
        <?= icon_svg('shield') ?>
        <div>No account changes recorded yet.</div>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($recent_account_changes as $c): ?>
          <div class="timeline-row">
            <div class="timeline-dot"><?= icon_svg('shield') ?></div>
            <div class="timeline-body">
              <div class="timeline-title"><span class="badge <?= audit_action_class($c['action']) ?>"><?= htmlspecialchars(audit_action_label($c['action'])) ?></span></div>
              <div class="timeline-meta" title="<?= htmlspecialchars((string)($c['description'] ?? '')) ?>"><?= htmlspecialchars(audit_short_account_change((string)($c['description'] ?? ''), (string)($c['actor_name_snapshot'] ?? ''))) ?> &middot; by <?= htmlspecialchars((string)($c['actor_name_snapshot'] ?? '')) ?></div>
            </div>
            <div class="timeline-time"><?= time_ago($c['created_at']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-0">
  <div class="section-head">
    <div>
      <h2>Approvals without review</h2>
      <div class="section-sub"><?= $auto_approved_week_count ?> this week</div>
    </div>
  </div>
  <?php if (!$recent_auto_approved): ?>
    <div class="empty-state-mini">
      <?= icon_svg('check-circle') ?>
      <div>No unreviewed approvals yet.</div>
    </div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="data">
    <thead>
      <tr><th>Requester</th><th>Reason</th><th>Decided</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($recent_auto_approved as $ra): ?>
        <tr>
          <td data-label="Requester"><?= htmlspecialchars((string)($ra['requester_name'] ?? '')) ?>
            <span class="td-detail"><?= htmlspecialchars(role_display($ra['requester_role'], $ra['requester_position'] ?? null)) ?></span>
          </td>
          <td class="td-detail" data-label="Reason"><?= htmlspecialchars(requisition_no_review_reason($ra['decision_note'] ?? null)) ?></td>
          <td class="mono td-detail nowrap" data-label="Decided"><?= htmlspecialchars((string)($ra['decided_at'] ?? '')) ?></td>
          <td data-label=""><a href="<?= BASE_URL ?>/requisition/view.php?id=<?= (int)$ra['id'] ?>" class="btn btn-outline btn-sm">View →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="card mt-0">
  <div class="section-head">
    <div>
      <h2>Recent login activity</h2>
      <div class="section-sub">Today</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/audit_logs.php" class="btn btn-outline btn-sm">View full log →</a>
  </div>
  <div class="table-responsive">
<table class="data">
    <thead>
      <tr><th>Username</th><th>Result</th><th>IP address</th><th>Time</th></tr>
    </thead>
    <tbody>
      <?php if (!$recent_logins): ?>
        <tr><td colspan="4">No login activity today.</td></tr>
      <?php else: foreach ($recent_logins as $row): ?>
        <tr>
          <td class="mono" data-label="Username"><?= htmlspecialchars((string)($row['username'] ?? '')) ?></td>
          <td data-label="Result"><span class="badge <?= $row['success'] ? 'active' : 'inactive' ?>"><?= $row['success'] ? 'Success' : 'Failed' ?></span></td>
          <td class="mono td-detail" data-label="IP address"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
          <td class="mono td-detail" data-label="Time"><?= htmlspecialchars((string)($row['attempted_at'] ?? '')) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
