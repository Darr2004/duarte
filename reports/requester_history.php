<?php
/**
 * DuaRTE — Requester History & Activity Profile.
 * Provides inventory staff and administrators with complete historical visibility
 * into a requester's requisitions, consumables withdrawn, and borrowed tool loans.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_login();

$user = current_user();
$is_elevated = in_array($user['role'], ['admin', 'inventory_staff'], true);
if (!$is_elevated) {
    header('Location: ' . BASE_URL . '/requisition/my_history.php');
    exit;
}

$pdo = get_db();
$selected_id = (int)($_GET['id'] ?? 0);

// Fetch all potential requesters for the dropdown selector
$requesters_stmt = $pdo->query(
    "SELECT u.id, u.full_name, u.role, u.position, u.employee_id,
            (SELECT COUNT(*) FROM requisitions r WHERE r.requester_id = u.id) AS req_count,
            (SELECT COUNT(*) FROM tool_loans tl WHERE tl.borrower_id = u.id) AS loan_count
     FROM users u
     WHERE u.role IN ('driver_helper', 'field_supervisor', 'inventory_staff', 'admin')
     ORDER BY (req_count + loan_count) DESC, u.full_name ASC"
);
$all_requesters = $requesters_stmt->fetchAll(PDO::FETCH_ASSOC);

// If no user selected, default to the top requester or first user with requests
if ($selected_id <= 0 && !empty($all_requesters)) {
    $selected_id = (int)$all_requesters[0]['id'];
}

$requester = null;
$requisitions = [];
$loans = [];
$stats = [
    'total_requisitions' => 0,
    'released_count'     => 0,
    'approved_count'     => 0,
    'pending_count'      => 0,
    'declined_count'     => 0,
    'cancelled_count'    => 0,
    'total_items'        => 0,
    'total_loans'        => 0,
    'active_loans'       => 0,
    'overdue_loans'      => 0,
];

if ($selected_id > 0) {
    // 1. Fetch user info
    $stmt = $pdo->prepare(
        "SELECT id, employee_id, full_name, email, role, position, contact_number, status, created_at
         FROM users WHERE id = :id"
    );
    $stmt->execute(['id' => $selected_id]);
    $requester = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($requester) {
        // 2. Fetch requisitions
        $stmt = $pdo->prepare(
            "SELECT r.id, r.truck_plate_snapshot, r.purpose, r.status, r.created_at, r.decided_at, r.released_at,
                    d.full_name AS decided_by_name
             FROM requisitions r
             LEFT JOIN users d ON d.id = r.decided_by
             WHERE r.requester_id = :id
             ORDER BY r.created_at DESC"
        );
        $stmt->execute(['id' => $selected_id]);
        $requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch item details
        $req_ids = array_column($requisitions, 'id');
        $items_by_req = [];
        if (!empty($req_ids)) {
            $placeholders = implode(',', array_fill(0, count($req_ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT ri.requisition_id, ri.item_name_snapshot, ri.quantity_requested, ri.quantity_released,
                        i.item_code, i.unit
                 FROM requisition_items ri
                 LEFT JOIN items i ON i.id = ri.item_id
                 WHERE ri.requisition_id IN ($placeholders)
                 ORDER BY ri.id ASC"
            );
            $stmt->execute($req_ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items_by_req[$row['requisition_id']][] = $row;
            }
        }

        foreach ($requisitions as &$r) {
            $r['items'] = $items_by_req[$r['id']] ?? [];
            if (isset($stats[$r['status'] . '_count'])) {
                $stats[$r['status'] . '_count']++;
            }
            foreach ($r['items'] as $it) {
                $stats['total_items'] += (int)$it['quantity_requested'];
            }
        }
        unset($r);
        $stats['total_requisitions'] = count($requisitions);

        // 3. Fetch tool loans
        $stmt = $pdo->prepare(
            "SELECT tl.id, tl.requisition_id, tl.quantity, tl.borrowed_at, tl.due_date, tl.returned_at, tl.initial_condition,
                    i.name AS item_name, i.item_code, a.asset_tag,
                    ret.full_name AS returned_by_name
             FROM tool_loans tl
             JOIN items i ON i.id = tl.item_id
             LEFT JOIN assets a ON a.id = tl.asset_id
             LEFT JOIN users ret ON ret.id = tl.returned_by
             WHERE tl.borrower_id = :id
             ORDER BY tl.borrowed_at DESC"
        );
        $stmt->execute(['id' => $selected_id]);
        $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['total_loans'] = count($loans);

        $now = time();
        foreach ($loans as $l) {
            if (empty($l['returned_at'])) {
                $stats['active_loans']++;
                if (!empty($l['due_date']) && strtotime($l['due_date']) < $now) {
                    $stats['overdue_loans']++;
                }
            }
        }
    }
}

$page_title = 'Requester History';
$current_role = current_user()['role'] ?? '';
require __DIR__ . '/../includes/header.php';
?>
<style>
.profile-card-hero {
  background: var(--surface, #FFFFFF);
  border: 1px solid var(--border-color, #E6DACA);
  border-radius: var(--radius-lg, 12px);
  padding: 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.5rem;
  margin-bottom: 1.5rem;
  box-shadow: 0 1px 3px rgba(43,32,24,0.04);
}
@media (max-width: 768px) {
  .profile-card-hero {
    flex-direction: column;
    align-items: flex-start;
  }
}
.profile-hero-left {
  display: flex;
  align-items: center;
  gap: 1.25rem;
}
.profile-hero-avatar {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: #3B2A1F;
  color: #F7F1E7;
  font-size: 1.5rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(43,32,24,0.12);
}
.profile-hero-title {
  margin: 0 0 0.25rem 0;
  font-size: 1.35rem;
  font-weight: 800;
  color: var(--ink-dark, #2B2018);
}
.profile-hero-tags {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.8rem;
}
.profile-hero-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-top: 0.5rem;
  font-size: 0.82rem;
  color: var(--ink-soft, #7A6A58);
  flex-wrap: wrap;
}
.profile-hero-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-shrink: 0;
}
.profile-tabs {
  display: flex;
  gap: 0.5rem;
  border-bottom: 1px solid var(--border-color, #E6DACA);
  margin-bottom: 1.25rem;
}
.profile-tab {
  padding: 0.65rem 1.15rem;
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--ink-soft, #7A6A58);
  border: none;
  background: transparent;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  display: flex;
  align-items: center;
  gap: 0.45rem;
  transition: all 0.15s ease;
}
.profile-tab:hover {
  color: var(--ink-dark, #2B2018);
}
.profile-tab.active {
  color: var(--amber, #8E5225);
  border-bottom-color: var(--amber, #8E5225);
}
.badge-count {
  background: var(--surface-soft, #FAF6F0);
  border: 1px solid var(--border-color, #E6DACA);
  padding: 0.1rem 0.45rem;
  border-radius: 12px;
  font-size: 0.72rem;
  font-family: var(--font-mono, monospace);
  font-weight: 700;
}
.item-chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-top: 0.25rem;
}
.item-chip {
  background: var(--surface-soft, #FAF6F0);
  border: 1px solid rgba(230,218,202,0.6);
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
  font-size: 0.75rem;
  color: var(--ink-dark, #2B2018);
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.item-chip strong {
  color: var(--amber, #8E5225);
  font-family: var(--font-mono, monospace);
}
@media print {
  body { background: #fff !important; }
  .sidebar, .top-nav, .report-switch, .profile-hero-actions, .filter-bar, nav, header { display: none !important; }
  .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
  .profile-card-hero { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>

<div class="page-header">
  <div>
    <div class="eyebrow">Personnel &amp; Operational Audits</div>
    <h1>Requester History</h1>
  </div>
  <div style="display:flex; gap:0.5rem; align-items:center;">
    <a href="<?= BASE_URL ?>/reports/index.php" class="btn btn-outline btn-sm">
      <?= icon_svg('arrow-left') ?> Back to Reports
    </a>
    <button type="button" onclick="window.print()" class="btn btn-outline btn-sm">
      <?= icon_svg('clipboard') ?> Print Profile Record
    </button>
  </div>
</div>

<!-- Requester Selection Bar -->
<div class="card" style="margin-bottom:1.5rem; padding:1rem 1.25rem;">
  <form method="get" id="requesterSelectForm" style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
    <label for="requesterSelect" style="font-weight:700; color:var(--ink-dark); font-size:0.9rem; margin:0; display:flex; align-items:center; gap:0.4rem;">
      <?= icon_svg('users') ?> Select Requester / Personnel:
    </label>
    <select name="id" id="requesterSelect" onchange="this.form.submit()" style="flex:1; min-width:260px; max-width:460px; padding:0.5rem 0.75rem; border-radius:8px; border:1px solid var(--border-color); font-weight:500;">
      <?php foreach ($all_requesters as $u): ?>
        <option value="<?= $u['id'] ?>" <?= (int)$u['id'] === $selected_id ? 'selected' : '' ?>>
          <?= htmlspecialchars($u['full_name']) ?> — <?= htmlspecialchars(role_label($u['role'])) ?><?= !empty($u['position']) ? ' (' . htmlspecialchars(position_label($u['position'])) . ')' : '' ?> [<?= (int)$u['req_count'] ?> requests, <?= (int)$u['loan_count'] ?> loans]
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn-primary btn-sm">Load</button></noscript>
  </form>
</div>

<?php if (!$requester): ?>
  <div class="card" style="text-align:center; padding:3rem 1rem; color:var(--ink-soft);">
    <?= icon_svg('users') ?>
    <h3 style="margin-top:0.75rem;">No Requester Selected</h3>
    <p>Please select a driver, helper, or supervisor from the list above to view their activity history.</p>
  </div>
<?php else: ?>

  <!-- Requester Hero Profile -->
  <div class="profile-card-hero">
    <div class="profile-hero-left">
      <div class="profile-hero-avatar">
        <?= htmlspecialchars(item_initials($requester['full_name'])) ?>
      </div>
      <div>
        <h2 class="profile-hero-title"><?= htmlspecialchars($requester['full_name']) ?></h2>
        <div class="profile-hero-tags">
          <span class="badge" style="background:#3B2A1F; color:#FFF; font-weight:600;"><?= htmlspecialchars(role_label($requester['role'])) ?></span>
          <?php if (!empty($requester['position'])): ?>
            <span class="badge" style="background:var(--surface-soft); color:var(--ink); border:1px solid var(--border-color);"><?= htmlspecialchars(position_label($requester['position'])) ?></span>
          <?php endif; ?>
          <span class="badge <?= $requester['status'] === 'active' ? 'badge-ok' : 'badge-danger' ?>">
            <?= ucfirst(htmlspecialchars($requester['status'])) ?>
          </span>
        </div>
        <div class="profile-hero-meta">
          <span><strong>Emp ID:</strong> <span class="mono"><?= htmlspecialchars($requester['employee_id'] ?: 'None') ?></span></span>
          <?php if (!empty($requester['contact_number'])): ?>
            <span>&bull;</span>
            <span><strong>Contact:</strong> <?= htmlspecialchars($requester['contact_number']) ?></span>
          <?php endif; ?>
          <?php if (!empty($requester['email'])): ?>
            <span>&bull;</span>
            <span><strong>Email:</strong> <?= htmlspecialchars($requester['email']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Metric Counters -->
  <div class="stat-grid-v2" style="margin-bottom:1.5rem;">
    <div class="stat-card-v2">
      <div class="stat-card-v2-icon"><?= icon_svg('clipboard') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Requisitions Submitted</div>
        <div class="stat-card-v2-value"><?= (int)$stats['total_requisitions'] ?></div>
        <div class="stat-card-v2-sub"><?= (int)$stats['released_count'] ?> released, <?= (int)$stats['pending_count'] ?> pending</div>
      </div>
    </div>
    <div class="stat-card-v2 is-ok">
      <div class="stat-card-v2-icon"><?= icon_svg('package-check') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Total Units Requested</div>
        <div class="stat-card-v2-value"><?= (int)$stats['total_items'] ?></div>
        <div class="stat-card-v2-sub">consumables &amp; supplies</div>
      </div>
    </div>
    <div class="stat-card-v2">
      <div class="stat-card-v2-icon"><?= icon_svg('tool') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Tools Borrowed</div>
        <div class="stat-card-v2-value"><?= (int)$stats['total_loans'] ?></div>
        <div class="stat-card-v2-sub">lifetime equipment loans</div>
      </div>
    </div>
    <div class="stat-card-v2 <?= $stats['overdue_loans'] > 0 ? 'is-danger' : ($stats['active_loans'] > 0 ? 'is-warn' : 'is-ok') ?>">
      <div class="stat-card-v2-icon"><?= icon_svg('clock') ?></div>
      <div class="stat-card-v2-body">
        <div class="stat-card-v2-label">Active Loans / Overdue</div>
        <div class="stat-card-v2-value"><?= (int)$stats['active_loans'] ?></div>
        <div class="stat-card-v2-sub <?= $stats['overdue_loans'] > 0 ? 'is-danger' : '' ?>">
          <?= (int)$stats['overdue_loans'] ?> overdue right now
        </div>
      </div>
    </div>
  </div>

  <!-- Tabbed Activity Sections -->
  <div class="profile-tabs" role="tablist">
    <button type="button" class="profile-tab active" id="tabBtnReqs" onclick="switchProfileTab('reqs')">
      <?= icon_svg('clipboard') ?> Requisitions History
      <span class="badge-count"><?= count($requisitions) ?></span>
    </button>
    <button type="button" class="profile-tab" id="tabBtnLoans" onclick="switchProfileTab('loans')">
      <?= icon_svg('tool') ?> Tool Loans &amp; Equipment
      <span class="badge-count"><?= count($loans) ?></span>
    </button>
  </div>

  <!-- Tab Panel 1: Requisitions -->
  <div id="panelReqs" class="card" style="padding-bottom:0; overflow:hidden;">
    <div class="table-responsive">
      <table class="data">
        <thead>
          <tr>
            <th style="width:8%;">#</th>
            <th style="width:16%;">Submitted</th>
            <th style="width:14%;">Truck Plate</th>
            <th style="width:20%;">Purpose</th>
            <th style="width:24%;">Items Requested</th>
            <th style="width:10%;">Status</th>
            <th style="width:8%; text-align:right;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($requisitions)): ?>
            <tr><td colspan="7" style="text-align:center; padding:2rem 1rem; color:var(--ink-soft);">No requisitions filed by this personnel yet.</td></tr>
          <?php else: foreach ($requisitions as $r): ?>
            <tr>
              <td class="mono" data-label="#">#<?= $r['id'] ?></td>
              <td class="mono td-detail" data-label="Submitted" style="font-size:0.8rem; color:var(--ink-soft);">
                <?= date('M j, Y g:ia', strtotime($r['created_at'])) ?>
              </td>
              <td data-label="Truck">
                <?= truck_plate_badge($r['truck_plate_snapshot']) ?>
              </td>
              <td data-label="Purpose" style="font-weight:500;">
                <?= htmlspecialchars((string)($r['purpose'] ?? 'General Requisition')) ?>
              </td>
              <td data-label="Items">
                <?php if (empty($r['items'])): ?>
                  <span class="text-muted" style="font-size:0.8rem;">No items itemized</span>
                <?php else: ?>
                  <div class="item-chip-list">
                    <?php foreach ($r['items'] as $it): ?>
                      <span class="item-chip" title="<?= htmlspecialchars($it['item_name_snapshot']) ?>">
                        <?= htmlspecialchars($it['item_name_snapshot']) ?>
                        <strong>&times;<?= (int)$it['quantity_requested'] ?></strong>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Status">
                <span class="badge <?= requisition_status_class($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span>
                <?php if (!empty($r['decided_by_name'])): ?>
                  <div style="font-size:0.72rem; color:var(--ink-soft); margin-top:0.15rem;">by <?= htmlspecialchars($r['decided_by_name']) ?></div>
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap; text-align:right;" data-label="">
                <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $r['id'] ?>" class="btn btn-outline btn-sm" style="height:28px; padding:0 0.6rem; font-size:0.75rem;">
                  View
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tab Panel 2: Tool Loans -->
  <div id="panelLoans" class="card" style="padding-bottom:0; overflow:hidden; display:none;">
    <div class="table-responsive">
      <table class="data">
        <thead>
          <tr>
            <th style="width:8%;">Loan #</th>
            <th style="width:26%;">Tool &amp; Equipment</th>
            <th style="width:14%;">Asset Tag</th>
            <th style="width:8%;">Qty</th>
            <th style="width:14%;">Borrowed Date</th>
            <th style="width:14%;">Due Date</th>
            <th style="width:16%;">Return Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($loans)): ?>
            <tr><td colspan="7" style="text-align:center; padding:2rem 1rem; color:var(--ink-soft);">No tools or equipment borrowed by this personnel.</td></tr>
          <?php else: foreach ($loans as $l): 
            $is_ret = !empty($l['returned_at']);
            $is_ovd = !$is_ret && !empty($l['due_date']) && strtotime($l['due_date']) < time();
          ?>
            <tr>
              <td class="mono" data-label="Loan #">#<?= $l['id'] ?></td>
              <td data-label="Tool">
                <div style="font-weight:600; color:var(--ink-dark);"><?= htmlspecialchars($l['item_name']) ?></div>
                <?php if (!empty($l['item_code'])): ?>
                  <span class="mono" style="font-size:0.75rem; color:var(--ink-soft);"><?= htmlspecialchars($l['item_code']) ?></span>
                <?php endif; ?>
              </td>
              <td class="mono" data-label="Asset Tag">
                <?= htmlspecialchars($l['asset_tag'] ?: 'Untagged') ?>
              </td>
              <td class="mono" data-label="Qty"><?= (int)$l['quantity'] ?></td>
              <td class="mono td-detail" data-label="Borrowed" style="font-size:0.8rem; color:var(--ink-soft);">
                <?= date('M j, Y', strtotime($l['borrowed_at'])) ?>
              </td>
              <td class="mono td-detail" data-label="Due" style="font-size:0.8rem;">
                <?= !empty($l['due_date']) ? date('M j, Y', strtotime($l['due_date'])) : '—' ?>
              </td>
              <td data-label="Status">
                <?php if ($is_ret): ?>
                  <span class="badge badge-ok">Returned</span>
                  <div style="font-size:0.72rem; color:var(--ink-soft); margin-top:0.15rem;">
                    <?= date('M j, Y', strtotime($l['returned_at'])) ?>
                    <?php if (!empty($l['returned_by_name'])): ?> by <?= htmlspecialchars($l['returned_by_name']) ?><?php endif; ?>
                  </div>
                <?php elseif ($is_ovd): ?>
                  <span class="badge badge-danger">Overdue</span>
                  <div style="font-size:0.72rem; color:var(--danger, #DC2626); margin-top:0.15rem;">Action required</div>
                <?php else: ?>
                  <span class="badge badge-warn">Active Loan</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<script>
function switchProfileTab(tab) {
  const reqsBtn = document.getElementById('tabBtnReqs');
  const loansBtn = document.getElementById('tabBtnLoans');
  const reqsPanel = document.getElementById('panelReqs');
  const loansPanel = document.getElementById('panelLoans');

  if (tab === 'reqs') {
    reqsBtn.classList.add('active');
    loansBtn.classList.remove('active');
    reqsPanel.style.display = 'block';
    loansPanel.style.display = 'none';
  } else {
    loansBtn.classList.add('active');
    reqsBtn.classList.remove('active');
    loansPanel.style.display = 'block';
    reqsPanel.style.display = 'none';
  }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
