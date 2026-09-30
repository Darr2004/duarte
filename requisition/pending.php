<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority.php';
require_role(['field_supervisor', 'admin']);

$pdo = get_db();

$stmt = $pdo->query(
    "SELECT r.*, u.full_name AS requester_name, u.employee_id, u.position AS requester_position,
        (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
     FROM requisitions r
     JOIN users u ON u.id = r.requester_id
     WHERE r.status = 'pending'
     ORDER BY r.created_at ASC"
);
$requests = $stmt->fetchAll();

// Sort by priority default; a plain "oldest first" queue is still one
// click away for anyone who prefers it via ?sort=oldest.
$sort = ($_GET['sort'] ?? 'priority') === 'oldest' ? 'oldest' : 'priority';
if ($sort === 'priority') {
    $requests = score_pending_requisitions($pdo, $requests);
}

$page_title = 'Pending Approvals';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Online requisition &amp; approval</div>
    <h1>Pending Approvals</h1>
  </div>
</div>

<div class="range-tabs" role="tablist" aria-label="Sort order">
  <a href="?sort=priority" role="tab" aria-selected="<?= $sort === 'priority' ? 'true' : 'false' ?>" class="range-tab <?= $sort === 'priority' ? 'active' : '' ?>">Priority</a>
  <a href="?sort=oldest" role="tab" aria-selected="<?= $sort === 'oldest' ? 'true' : 'false' ?>" class="range-tab <?= $sort === 'oldest' ? 'active' : '' ?>">Oldest first</a>
</div>
<?php if ($sort === 'priority'): ?>
<div class="filter-bar-note">Highest urgency first.</div>
<div class="priority-legend">
  <span class="priority-legend-item"><span class="priority-legend-dot seg-scarcity"></span>Stock (Low)</span>
  <span class="priority-legend-item"><span class="priority-legend-dot seg-contention"></span>Demand (Others want it)</span>
  <span class="priority-legend-item"><span class="priority-legend-dot seg-reliability"></span>Trust (Return record)</span>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
<table class="data">
    <thead><tr><th style="min-width:60px;">#</th><th style="min-width:200px;">Requester</th><?php if ($sort === 'priority'): ?><th style="min-width:140px;">Priority</th><?php endif; ?><th style="min-width:160px;">Submitted</th><th style="min-width:90px;">Items</th><th style="min-width:200px;">Purpose</th><th style="min-width:60px; text-align:right;"></th></tr></thead>
    <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="7">Nothing waiting on you right now.</td></tr>
      <?php else: foreach ($requests as $r): ?>
        <tr>
          <td class="mono td-detail" data-label="#">#<?= $r['id'] ?></td>
          <td data-label="Requester">
            <?= htmlspecialchars((string)($r['requester_name'] ?? '')) ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($r['employee_id'] ?? '')) ?>)</span>
            <?php if (!empty($r['manual_urgent'])): ?>
              <span class="badge inactive" title="<?= htmlspecialchars($r['manual_urgent_reason'] ?? '') ?>">Urgent</span>
            <?php endif; ?>
            <?php $req_pos = position_label($r['requester_position']); if ($req_pos !== ''): ?>
              <div style="font-size:0.75rem; color:var(--ink-soft);"><?= htmlspecialchars($req_pos) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($sort === 'priority'): ?>
            <td data-label="Priority">
              <div class="priority-cell">
                <button type="button" class="badge <?= priority_score_class($r['priority_score']) ?>" 
                        style="cursor:pointer; border:none; display:inline-flex; align-items:center; gap:0.25rem;"
                        title="Click to view full mathematical score breakdown"
                        onclick='openPriorityModal(<?= htmlspecialchars(json_encode([
                            "id" => $r["id"],
                            "requester" => $r["requester_name"],
                            "score" => $r["priority_score"],
                            "stock" => $r["priority_stock"] ?? $r["priority_scarcity"],
                            "demand" => $r["priority_demand"] ?? $r["priority_contention"],
                            "trust" => $r["priority_trust"] ?? $r["priority_reliability"],
                            "manual_urgent" => !empty($r["manual_urgent"]),
                            "manual_urgent_reason" => $r["manual_urgent_reason"] ?? "",
                            "score_class" => priority_score_class($r["priority_score"]),
                        ]), ENT_QUOTES, "UTF-8") ?>)'>
                  <span><?= $r['priority_score'] ?></span>
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                </button>
                <div class="priority-breakdown" role="img" aria-label="Stock <?= $r['priority_stock'] ?? $r['priority_scarcity'] ?>%, Demand <?= $r['priority_demand'] ?? $r['priority_contention'] ?>%, Trust <?= $r['priority_trust'] ?? $r['priority_reliability'] ?>%">
                  <span class="priority-breakdown-seg seg-scarcity" style="flex:<?= max($r['priority_stock'] ?? $r['priority_scarcity'], 1) ?>"></span>
                  <span class="priority-breakdown-seg seg-contention" style="flex:<?= max($r['priority_demand'] ?? $r['priority_contention'], 1) ?>"></span>
                  <span class="priority-breakdown-seg seg-reliability" style="flex:<?= max($r['priority_trust'] ?? $r['priority_reliability'], 1) ?>"></span>
                </div>
              </div>
            </td>
          <?php endif; ?>
          <td class="mono td-detail" data-label="Submitted"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td>
          <td data-label="Items" class="td-detail"><?= (int)$r['item_count'] ?> item(s)</td>
          <td data-label="Purpose">
            <?php if (!empty($r['truck_plate_snapshot'])): ?>
              <span style="margin-right:0.4rem;"><?= truck_plate_badge($r['truck_plate_snapshot']) ?></span>
            <?php endif; ?>
            <?= htmlspecialchars($r['purpose'] ?? '—') ?>
          </td>
          <td data-label="" class="td-detail" style="text-align:right;"><a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $r['id'] ?>" class="table-action-btn btn-action-view" title="Review Requisition" aria-label="Review" style="background:var(--amber); color:#fff; border-color:var(--amber);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></a></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</div>

<!-- Priority Score Breakdown Modal (Defense & MCDA Transparency) -->
<div id="priorityModal" style="display:none; position:fixed; inset:0; background:rgba(20,15,10,0.65); z-index:9999; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(3px);">
  <div style="background:var(--surface, #FFFFFF); border:1px solid var(--border-color, #E6DACA); border-radius:14px; max-width:540px; width:100%; box-shadow:0 12px 36px rgba(0,0,0,0.22); overflow:hidden; animation:modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);">
    
    <div style="background:var(--panel-soft, #FAF6F0); padding:1.25rem 1.5rem; border-bottom:1px solid var(--border-color, #E6DACA); display:flex; align-items:center; justify-content:space-between;">
      <div style="display:flex; align-items:center; gap:0.6rem;">
        <div style="width:34px; height:34px; border-radius:8px; background:var(--accent-tint, #FDF6EC); color:var(--accent, #9E5B10); display:flex; align-items:center; justify-content:center;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>
        </div>
        <div>
          <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--ink-dark, #2B2018);" id="pmTitle">Priority Score Breakdown</h3>
          <div style="font-size:0.78rem; color:var(--ink-soft, #7A6A58);" id="pmSubtitle">Multi-Criteria Resource Allocation</div>
        </div>
      </div>
      <button type="button" onclick="closePriorityModal()" style="background:none; border:none; font-size:1.3rem; line-height:1; cursor:pointer; color:var(--ink-soft, #7A6A58); padding:0.2rem 0.5rem; border-radius:6px;">&times;</button>
    </div>

    <div style="padding:1.5rem;">
      <!-- Total Score Banner -->
      <div style="display:flex; align-items:center; justify-content:space-between; padding:0.85rem 1.1rem; background:var(--surface-soft, #F7F2EA); border-radius:10px; margin-bottom:1.25rem; border:1px solid var(--border-color, #E8DFD3);">
        <div>
          <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--ink-soft, #7A6A58); font-weight:600;">Composite MCDA Score</div>
          <div style="font-size:1.45rem; font-weight:800; color:var(--ink-dark, #2B2018); font-family:var(--font-mono, monospace);" id="pmScoreVal">0.0 <span style="font-size:0.85rem; font-weight:500; color:var(--ink-soft);">/ 100 pts</span></div>
        </div>
        <div id="pmUrgentBadge"></div>
      </div>

      <div style="display:flex; flex-direction:column; gap:1.1rem; margin-bottom:1.25rem;">
        <div>
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.3rem;">
            <span style="font-weight:700; font-size:0.86rem; color:var(--ink); display:flex; align-items:center; gap:0.4rem;">
              <span style="width:8px; height:8px; border-radius:50%; background:var(--red-danger); display:inline-block;"></span>
              1. Stock Scarcity (45%)
            </span>
            <span class="mono" style="font-weight:700; font-size:0.86rem; color:var(--ink);" id="pmStockVal">0%</span>
          </div>
          <div style="height:6px; background:var(--surface-subtle); border-radius:4px; overflow:hidden; margin-bottom:0.25rem; border:1px solid var(--line);">
            <div id="pmStockBar" style="height:100%; background:var(--red-danger); width:0%; transition:width 0.4s ease;"></div>
          </div>
          <div style="font-size:0.75rem; color:var(--ink-soft);">
            Stock vs buffer level.
          </div>
        </div>

        <div>
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.3rem;">
            <span style="font-weight:700; font-size:0.86rem; color:var(--ink); display:flex; align-items:center; gap:0.4rem;">
              <span style="width:8px; height:8px; border-radius:50%; background:var(--amber); display:inline-block;"></span>
              2. Demand Contention (35%)
            </span>
            <span class="mono" style="font-weight:700; font-size:0.86rem; color:var(--ink);" id="pmDemandVal">0%</span>
          </div>
          <div style="height:6px; background:var(--surface-subtle); border-radius:4px; overflow:hidden; margin-bottom:0.25rem; border:1px solid var(--line);">
            <div id="pmDemandBar" style="height:100%; background:var(--amber); width:0%; transition:width 0.4s ease;"></div>
          </div>
          <div style="font-size:0.75rem; color:var(--ink-soft);">
            Competing requests, same item.
          </div>
        </div>

        <div>
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.3rem;">
            <span style="font-weight:700; font-size:0.86rem; color:var(--ink); display:flex; align-items:center; gap:0.4rem;">
              <span style="width:8px; height:8px; border-radius:50%; background:var(--green-ok); display:inline-block;"></span>
              3. Borrower Reliability (20%)
            </span>
            <span class="mono" style="font-weight:700; font-size:0.86rem; color:var(--ink);" id="pmTrustVal">0%</span>
          </div>
          <div style="height:6px; background:var(--surface-subtle); border-radius:4px; overflow:hidden; margin-bottom:0.25rem; border:1px solid var(--line);">
            <div id="pmTrustBar" style="height:100%; background:var(--green-ok); width:0%; transition:width 0.4s ease;"></div>
          </div>
          <div style="font-size:0.75rem; color:var(--ink-soft);">
            Past return record.
          </div>
        </div>
      </div>

      <div style="padding:0.75rem 0.9rem; background:var(--surface-subtle); border-radius:8px; border:1px solid var(--line); font-size:0.76rem; color:var(--ink-soft);">
        <strong style="color:var(--ink);">Model:</strong> MCDA scoring.
      </div>
    </div>

    <div style="background:var(--panel-soft, #FAF6F0); padding:0.85rem 1.5rem; border-top:1px solid var(--border-color, #E6DACA); text-align:right;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closePriorityModal()">Close</button>
    </div>
  </div>
</div>

<style>
@keyframes modalPop {
  0% { opacity:0; transform:scale(0.95) translateY(8px); }
  100% { opacity:1; transform:scale(1) translateY(0); }
}
</style>

<script>
function openPriorityModal(r) {
  document.getElementById('pmTitle').textContent = 'Priority Breakdown: Request #' + r.id;
  document.getElementById('pmSubtitle').textContent = 'Requester: ' + r.requester;
  document.getElementById('pmScoreVal').innerHTML = r.score + ' <span style="font-size:0.85rem; font-weight:500; color:var(--ink-soft);">/ 100 pts</span>';
  
  var urgentDiv = document.getElementById('pmUrgentBadge');
  if (r.manual_urgent) {
    urgentDiv.innerHTML = '<span class="badge inactive" style="font-weight:700; font-size:0.82rem;">⚡ Supervisor Override (Urgent)</span>' +
      (r.manual_urgent_reason ? '<div style="font-size:0.72rem; color:var(--danger,#c0392b); margin-top:2px;">' + r.manual_urgent_reason + '</div>' : '');
  } else {
    urgentDiv.innerHTML = '<span class="badge ' + r.score_class + '" style="font-weight:600; font-size:0.8rem;">Priority Rank Verified</span>';
  }

  document.getElementById('pmStockVal').textContent = r.stock + '%';
  document.getElementById('pmStockBar').style.width = Math.min(100, Math.max(2, r.stock)) + '%';

  document.getElementById('pmDemandVal').textContent = r.demand + '%';
  document.getElementById('pmDemandBar').style.width = Math.min(100, Math.max(2, r.demand)) + '%';

  document.getElementById('pmTrustVal').textContent = r.trust + '%';
  document.getElementById('pmTrustBar').style.width = Math.min(100, Math.max(2, r.trust)) + '%';

  var modal = document.getElementById('priorityModal');
  modal.style.display = 'flex';
}

function closePriorityModal() {
  document.getElementById('priorityModal').style.display = 'none';
}

document.getElementById('priorityModal').addEventListener('click', function(e) {
  if (e.target === this) closePriorityModal();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
