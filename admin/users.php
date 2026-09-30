<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin']);

$pdo = get_db();
$me  = current_user();

// Handle status toggle (activate/deactivate) — never allow disabling yourself.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $flash_error = 'Your session expired. Please try again.';
    } else {
        $toggle_id = (int)$_POST['toggle_id'];
        if ($toggle_id === (int)$me['id']) {
            $flash_error = 'You cannot deactivate your own account.';
        } else {
            $target_stmt = $pdo->prepare('SELECT username, status FROM users WHERE id = :id');
            $target_stmt->execute(['id' => $toggle_id]);
            $target_user = $target_stmt->fetch();

            $stmt = $pdo->prepare(
                "UPDATE users SET status = IF(status = 'active','inactive','active') WHERE id = :id"
            );
            $stmt->execute(['id' => $toggle_id]);

            if ($target_user) {
                $going_active = $target_user['status'] !== 'active';
                log_audit_event($pdo, $me, $going_active ? 'user_activate' : 'user_deactivate', 'user', $toggle_id,
                    $me['full_name'] . ' ' . ($going_active ? 'activated' : 'deactivated') . ' account "' . $target_user['username'] . '".');
            }
            $flash_success = 'Account status updated.';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$role_filter = $_GET['role'] ?? '';
$position_filter = $_GET['position'] ?? '';
$status_filter = $_GET['status'] ?? '';

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = " WHERE 1=1";
$params = [];

if ($search !== '') {
    // Each LIKE needs its own placeholder — PDO throws "Invalid parameter
    // number" if the same named placeholder appears more than once.
    $where .= " AND (full_name LIKE :q1 OR username LIKE :q2 OR employee_id LIKE :q3 OR email LIKE :q4 OR contact_number LIKE :q5)";
    $params['q1'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
    $params['q3'] = '%' . $search . '%';
    $params['q4'] = '%' . $search . '%';
    $params['q5'] = '%' . $search . '%';
}
if (in_array($role_filter, ['admin', 'inventory_staff', 'driver_helper', 'field_supervisor'], true)) {
    $where .= " AND role = :role";
    $params['role'] = $role_filter;
}
if (in_array($position_filter, ['driver', 'helper', 'mechanic', 'electrician', 'office_staff'], true)) {
    $where .= " AND position = :position";
    $params['position'] = $position_filter;
}
if (in_array($status_filter, ['active', 'inactive'], true)) {
    $where .= " AND status = :status";
    $params['status'] = $status_filter;
}

$count_sql = "SELECT COUNT(*) c FROM users" . $where;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_users = (int)$count_stmt->fetch()['c'];

$total_pages = max(1, (int)ceil($total_users / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT id, employee_id, full_name, username, email, contact_number, role, position, status, last_login_at
        FROM users" . $where . "
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$page_title = 'User Accounts';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Foundation module</div>
    <h1>User Accounts</h1>
  </div>
  <a href="<?= BASE_URL ?>/admin/user_add.php" class="btn btn-primary">+ Add account</a>
</div>

<?php if (!empty($flash_error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if (!empty($flash_success)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<form method="get" class="filter-bar" id="userFilterForm">
  <div class="form-group grow">
    <label for="q">Search</label>
    <div class="search-input-wrap">
      <input type="text" id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search users" autocomplete="off">
    </div>
  </div>
  <div class="form-group">
    <label for="role">Role</label>
    <select id="role" name="role">
      <option value="">All roles</option>
      <?php foreach (['admin', 'inventory_staff', 'field_supervisor', 'driver_helper'] as $r): ?>
        <option value="<?= $r ?>" <?= $role_filter === $r ? 'selected' : '' ?>><?= htmlspecialchars(role_label($r)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="position">Position</label>
    <select id="position" name="position">
      <option value="">All positions</option>
      <?php foreach (['driver', 'helper', 'mechanic', 'electrician', 'office_staff'] as $p): ?>
        <option value="<?= $p ?>" <?= $position_filter === $p ? 'selected' : '' ?>><?= htmlspecialchars(position_label($p)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All statuses</option>
      <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <?php if ($search !== '' || $role_filter !== '' || $position_filter !== '' || $status_filter !== ''): ?>
    <a href="<?= BASE_URL ?>/admin/users.php" class="filter-clear-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear filters
    </a>
  <?php endif; ?>
</form>


<?php if ($search !== '' || $role_filter !== '' || $position_filter !== '' || $status_filter !== ''): ?>
<div class="filter-bar-note">Showing <?= count($users) ?> matching account<?= count($users) === 1 ? '' : 's' ?></div>
<?php endif; ?>

<div class="card" style="padding-bottom:0; overflow:hidden;">
  <div class="table-responsive">
    <table class="data">
      <thead>
        <tr>
          <th style="width:34%;">User Account</th>
          <th style="width:20%;">Role &amp; Position</th>
          <th style="width:18%;">Contact (SMS)</th>
          <th style="width:16%;">Status &amp; Activity</th>
          <th style="width:12%; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="5" style="text-align:center; padding:2rem 1rem;">No accounts match this filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u):
          $position_display = $u['role'] === 'driver_helper' ? position_label($u['position']) : '';
          $user_search_blob = strtolower($u['employee_id'] . ' ' . $u['full_name'] . ' ' . $u['username'] . ' ' . $u['email'] . ' ' . ($u['contact_number'] ?? '') . ' ' . $position_display);
        ?>
          <tr class="user-row" data-search="<?= htmlspecialchars($user_search_blob) ?>">
            <td data-label="User">
              <div style="font-weight:600; color:var(--ink); font-size:0.95rem;">
                <span class="searchable-text"><?= htmlspecialchars((string)($u['full_name'] ?? '')) ?></span>
              </div>
              <div style="display:flex; align-items:center; gap:0.4rem; margin-top:0.2rem; font-size:0.8rem; color:var(--ink-soft);">
                <span class="item-code-badge"><span class="searchable-text"><?= htmlspecialchars((string)($u['employee_id'] ?? '')) ?></span></span>
                <span class="mono" style="opacity:0.8;">@<span class="searchable-text"><?= htmlspecialchars((string)($u['username'] ?? '')) ?></span></span>
              </div>
            </td>
            <td data-label="Role &amp; Position">
              <div><span class="badge role"><?= htmlspecialchars(role_label($u['role'])) ?></span></div>
              <?php if ($position_display !== ''): ?>
                <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.25rem;"><?= htmlspecialchars($position_display) ?></div>
              <?php endif; ?>
            </td>
            <td class="mono td-detail" data-label="Contact (SMS)">
              <span class="searchable-text"><?= !empty($u['contact_number']) ? htmlspecialchars((string)($u['contact_number'] ?? '')) : '<span class="text-muted-normal">—</span>' ?></span>
            </td>
            <td data-label="Status">
              <div><span class="badge <?= $u['status'] ?>"><?= htmlspecialchars(ucfirst($u['status'])) ?></span></div>
              <div class="mono" style="font-size:0.75rem; color:var(--ink-soft); margin-top:0.25rem;">
                <?= $u['last_login_at'] ? date('M j, Y g:ia', strtotime($u['last_login_at'])) : 'Never logged in' ?>
              </div>
            </td>
            <td class="table-actions-cell" data-label="Actions">
              <div class="table-actions-toolbar" style="justify-content:flex-end;">
                <a href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $u['id'] ?>" class="btn btn-outline btn-sm" style="height:30px; padding:0 0.65rem;">Edit</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Change status for <?= htmlspecialchars(addslashes($u['full_name'])) ?>?');">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="toggle_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-danger' : 'btn-outline' ?>" style="height:30px; padding:0 0.65rem;">
                    <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr id="live-search-empty" class="hidden"><td colspan="5" style="text-align:center; padding:2rem 1rem;">No accounts match "<span id="live-search-empty-term"></span>".</td></tr>
      </tbody>
    </table>
  </div>
  <?= render_pagination($page, $total_pages, $per_page) ?>
</div>

<script src="<?= BASE_URL ?>/assets/js/live-table-search.js"></script>
<script src="<?= BASE_URL ?>/assets/js/search-suggest.js"></script>
<script>
  initLiveTableSearch({ inputId: 'q', rowSelector: 'tr.user-row', emptyRowId: 'live-search-empty', emptyTermId: 'live-search-empty-term' });
  initSearchSuggest({ inputId: 'q', minChars: 999 });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
