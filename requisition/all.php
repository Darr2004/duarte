<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'field_supervisor']);

$pdo = get_db();
$status_filter = $_GET['status'] ?? '';
$search_plate = trim($_GET['q'] ?? '');

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = " WHERE 1=1";
$params = [];
if (in_array($status_filter, ['pending', 'approved', 'declined', 'cancelled', 'released'], true)) {
    $where .= " AND r.status = :status";
    $params['status'] = $status_filter;
}
if ($search_plate !== '') {
    $where .= " AND (r.truck_plate_snapshot LIKE :q1 
               OR r.purpose LIKE :q2 
               OR u.full_name LIKE :q3 
               OR u.employee_id LIKE :q4 
               OR r.id LIKE :q5)";
    $params['q1'] = '%' . $search_plate . '%';
    $params['q2'] = '%' . $search_plate . '%';
    $params['q3'] = '%' . $search_plate . '%';
    $params['q4'] = '%' . $search_plate . '%';
    $params['q5'] = '%' . $search_plate . '%';
}

$count_sql = "SELECT COUNT(*) c FROM requisitions r JOIN users u ON u.id = r.requester_id" . $where;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_requests = (int)$count_stmt->fetch()['c'];

$total_pages = max(1, (int)ceil($total_requests / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT r.*, u.full_name AS requester_name, u.employee_id, u.position AS requester_position,
            d.full_name AS decided_by_name,
            (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
        FROM requisitions r
        JOIN users u ON u.id = r.requester_id
        LEFT JOIN users d ON d.id = r.decided_by" . $where . "
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll();

$page_title = 'All Requests';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Online requisition &amp; approval</div>
    <h1>All Requests</h1>
  </div>
</div>

<form method="get" class="filter-bar" style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
  <div class="form-group" style="margin-bottom:0;">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All statuses</option>
      <?php foreach (['pending','approved','declined','cancelled','released'] as $s): ?>
        <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="margin-bottom:0;">
    <label for="q">Search Truck Plate / Requester / Keyword</label>
    <div class="search-input-wrap">
      <input type="text" id="q" name="q" value="<?= htmlspecialchars($search_plate) ?>" placeholder="Search requisitions" autocomplete="off">
    </div>
  </div>
  <?php if ($search_plate !== '' || $status_filter !== ''): ?>
    <a href="<?= BASE_URL ?>/requisition/all.php" class="filter-clear-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear filters
    </a>
  <?php endif; ?>
</form>

<div class="card" style="padding-bottom:0;">
  <div class="table-responsive">
    <table class="data">
      <thead>
        <tr>
          <th style="min-width:60px;">#</th>
          <th style="min-width:200px;">Requester</th>
          <th style="min-width:140px;">Truck Plate</th>
          <th style="min-width:160px;">Submitted</th>
          <th style="min-width:90px;">Items</th>
          <th style="min-width:130px;">Status</th>
          <th style="min-width:60px; text-align:right;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$requests): ?>
          <tr><td colspan="7" style="text-align:center; padding:2rem 1rem;">No requisitions match this filter.</td></tr>
        <?php else: foreach ($requests as $r): ?>
          <tr>
            <td class="mono" data-label="#">#<?= $r['id'] ?></td>
            <td data-label="Requester">
              <div style="font-weight:600; color:var(--ink);"><?= htmlspecialchars((string)($r['requester_name'] ?? '')) ?></div>
              <?php $req_pos = position_label($r['requester_position']); if ($req_pos !== ''): ?>
                <div style="font-size:0.75rem; color:var(--ink-soft); margin-top:0.15rem;"><?= htmlspecialchars($req_pos) ?></div>
              <?php endif; ?>
            </td>
            <td data-label="Truck">
              <?= truck_plate_badge($r['truck_plate_snapshot']) ?>
              <?php if (!empty($r['is_maintenance_request'])): ?>
                <div style="margin-top:0.25rem;">
                  <span class="badge" style="background:var(--blue-tint); color:var(--blue-info); border:1px solid var(--blue-border); font-size:0.72rem; font-weight:600;" title="Requisition para sa pyesa o pagkukumpuni ng sasakyan">🔧 Pyesa / Repair</span>
                </div>
              <?php elseif (!empty($r['truck_plate_snapshot'])): ?>
                <div style="margin-top:0.25rem;">
                  <span class="badge" style="background:var(--surface-subtle); color:var(--ink-soft); border:1px solid var(--line); font-size:0.72rem; font-weight:500;" title="Requisition para sa mga gamit ng crew sa biyahe">🚛 Gamit sa Byahe</span>
                </div>
              <?php endif; ?>
            </td>
            <td class="mono td-detail" data-label="Submitted" style="font-size:0.8rem; color:var(--ink-soft);">
              <?= date('M j, Y g:ia', strtotime($r['created_at'])) ?>
            </td>
            <td data-label="Items" class="td-detail mono"><?= (int)$r['item_count'] ?> item(s)</td>
            <td data-label="Status">
              <span class="badge <?= requisition_status_class($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span>
              <?php if (!empty($r['decided_by_name'])): ?>
                <div style="font-size:0.72rem; color:var(--ink-soft); margin-top:0.15rem;">by <?= htmlspecialchars((string)($r['decided_by_name'] ?? '')) ?></div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap; text-align:right;" data-label="">
              <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $r['id'] ?>" class="table-action-btn btn-action-view" title="View Requisition Details" aria-label="View">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= render_pagination($page, $total_pages, $per_page) ?>
</div>

<script src="<?= BASE_URL ?>/assets/js/search-suggest.js"></script>
<script>
  initSearchSuggest({ inputId: 'q', minChars: 999 }); // activates clear button and input wrap
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
