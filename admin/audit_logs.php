<?php
/**
 * DuaRTE — System Audit Log (admin-only).
 *
 * Read-only view over audit_logs, written by log_audit_event()
 * (includes/audit.php) at every security-relevant or state-changing
 * event across the system. This page never writes to that table —
 * it only filters and paginates what's already there.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin']);

$pdo = get_db();

$search       = trim($_GET['q'] ?? '');
$action_filter = $_GET['action'] ?? '';
$entity_filter = $_GET['entity'] ?? '';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

// Actions/entity types shown in the filter dropdowns are pulled from
// what's actually in the table, so the lists never drift out of sync
// with whatever events the app is currently logging.
$known_actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$known_entities = $pdo->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);

$where = ' WHERE 1=1';
$params = [];

if ($search !== '') {
    // MySQL's native prepared statements don't allow the same named
    // placeholder to appear more than once in a query, so each LIKE
    // gets its own placeholder bound to the same value.
    $where .= ' AND (actor_name_snapshot LIKE :q1 OR description LIKE :q2 OR ip_address LIKE :q3)';
    $params['q1'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
    $params['q3'] = '%' . $search . '%';
}
if ($action_filter !== '' && in_array($action_filter, $known_actions, true)) {
    $where .= ' AND action = :action';
    $params['action'] = $action_filter;
}
if ($entity_filter !== '' && in_array($entity_filter, $known_entities, true)) {
    $where .= ' AND entity_type = :entity';
    $params['entity'] = $entity_filter;
}
if ($from !== '') {
    $where .= ' AND created_at >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND created_at <= :to';
    $params['to'] = $to . ' 23:59:59';
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) c FROM audit_logs" . $where);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetch()['c'];
$total_pages = max(1, (int)ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT * FROM audit_logs" . $where . " ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Preserves the current filters when building page links / the CSV
// export link, so paging or exporting never silently drops them.
function audit_query_string(array $overrides = []): string
{
    $current = [
        'q'      => $_GET['q']      ?? '',
        'action' => $_GET['action'] ?? '',
        'entity' => $_GET['entity'] ?? '',
        'from'   => $_GET['from']   ?? '',
        'to'     => $_GET['to']     ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $merged = array_filter(array_merge($current, $overrides), fn($v) => $v !== '');
    return http_build_query($merged);
}

$has_filters = $search !== '' || $action_filter !== '' || $entity_filter !== '' || $from !== '' || $to !== '';

$page_title = 'Audit Logs';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">System security</div>
    <h1>Audit Logs</h1>
  </div>
  <a href="<?= BASE_URL ?>/admin/audit_logs_export.php?<?= audit_query_string(['page' => '']) ?>" class="btn btn-outline">Export CSV</a>
</div>

<p style="color:var(--ink-soft); font-size:0.85rem; margin-top:-0.5rem; margin-bottom:1rem;">
  Read-only activity log.
</p>

<form method="get" class="filter-bar" id="auditFilterForm">
  <div class="form-group grow">
    <label for="q">Search</label>
    <div class="search-input-wrap">
      <input type="text" id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Person, description, or IP address" autocomplete="off">
    </div>
  </div>
  <div class="form-group">
    <label for="action">Action</label>
    <select id="action" name="action">
      <option value="">All actions</option>
      <?php foreach ($known_actions as $a): ?>
        <option value="<?= htmlspecialchars($a) ?>" <?= $action_filter === $a ? 'selected' : '' ?>><?= htmlspecialchars(audit_action_label($a)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="entity">Entity</label>
    <select id="entity" name="entity">
      <option value="">All entities</option>
      <?php foreach ($known_entities as $e): ?>
        <option value="<?= htmlspecialchars($e) ?>" <?= $entity_filter === $e ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $e))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="from">Date range</label>
    <div class="filter-date-range">
      <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>" aria-label="From date">
      <span class="filter-date-sep">–</span>
      <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>" aria-label="To date">
    </div>
  </div>
  <?php if ($has_filters): ?>
    <a href="<?= BASE_URL ?>/admin/audit_logs.php" class="filter-clear-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear filters
    </a>
  <?php endif; ?>
</form>

<div class="filter-bar-note">
  <?= $total ?> event<?= $total === 1 ? '' : 's' ?><?= $has_filters ? ' match this filter' : ' recorded' ?>.
</div>

<div class="card" style="padding-bottom:0;">
  <div class="table-responsive">
    <table class="data">
      <thead>
        <tr>
          <th style="min-width:150px;">Date &amp; time</th>
          <th style="min-width:140px;">Action</th>
          <th style="min-width:180px;">Who &amp; IP</th>
          <th style="min-width:320px;">Description</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="4" style="text-align:center; padding:2rem 1rem;">No audit events match this filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log):
          $log_search_blob = strtolower($log['actor_name_snapshot'] . ' ' . $log['description'] . ' ' . ($log['ip_address'] ?? ''));
        ?>
          <tr class="audit-row" data-search="<?= htmlspecialchars($log_search_blob) ?>">
            <td class="mono" style="font-size:0.8rem; color:var(--ink-soft);" data-label="Date &amp; time">
              <?= date('M j, Y g:ia', strtotime($log['created_at'])) ?>
            </td>
            <td data-label="Action">
              <span class="badge <?= audit_action_class($log['action']) ?>"><?= htmlspecialchars(audit_action_label($log['action'])) ?></span>
            </td>
            <td data-label="Who">
              <div style="font-weight:600; color:var(--ink);"><span class="searchable-text"><?= htmlspecialchars((string)($log['actor_name_snapshot'] ?? '')) ?></span></div>
              <div class="mono" style="font-size:0.75rem; color:var(--ink-soft); margin-top:0.15rem;">
                <?= htmlspecialchars(role_label($log['actor_role_snapshot'] ?? '')) ?> &middot; <span class="searchable-text"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></span>
              </div>
            </td>
            <td data-label="Description" class="td-detail" style="font-size:0.88rem; line-height:1.45; word-break:break-word;">
              <span class="searchable-text"><?= htmlspecialchars((string)($log['description'] ?? '')) ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr id="live-search-empty" class="hidden"><td colspan="4" style="text-align:center; padding:2rem 1rem;">No events on this page match "<span id="live-search-empty-term"></span>" — still searching the rest…</td></tr>
      </tbody>
    </table>
  </div>
  <?= render_pagination($page, $total_pages, $per_page) ?>
</div>

<script src="<?= BASE_URL ?>/assets/js/live-table-search.js"></script>
<script src="<?= BASE_URL ?>/assets/js/search-suggest.js"></script>
<script>
  initLiveTableSearch({ inputId: 'q', rowSelector: 'tr.audit-row', emptyRowId: 'live-search-empty', emptyTermId: 'live-search-empty-term' });
  initSearchSuggest({ inputId: 'q', minChars: 999 });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
