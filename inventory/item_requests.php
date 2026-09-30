<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/item_requests.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decision'], $_POST['request_id'])) {
    $request_id = (int)$_POST['request_id'];
    $decision = $_POST['decision'] === 'fulfilled' ? 'fulfilled' : 'rejected';
    $note = trim($_POST['decision_note'] ?? '');
    $purchased_qty = max(1, (int)($_POST['purchased_qty'] ?? 1));
    $auto_stock_in = !empty($_POST['auto_stock_in']);

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM item_requests WHERE id = :id AND status = 'pending'");
        $stmt->execute(['id' => $request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $errors[] = 'That request has already been handled.';
        } else {
            $stock_added_msg = '';
            if ($decision === 'fulfilled') {
                if ($auto_stock_in && !empty($req['item_id'])) {
                    require_once __DIR__ . '/../includes/stock.php';
                    $variant_id = null;
                    $c_stmt = $pdo->prepare("SELECT id, name, variant_label FROM items WHERE id = :id");
                    $c_stmt->execute(['id' => (int)$req['item_id']]);
                    $c_item = $c_stmt->fetch();
                    if ($c_item && !empty($c_item['variant_label'])) {
                        if (preg_match('/\(([^)]+)\)$/', $req['item_name'], $vm)) {
                            $v_val = trim($vm[1]);
                            $v_row = find_item_variant($pdo, (int)$req['item_id'], $v_val);
                            if ($v_row) {
                                $variant_id = (int)$v_row['id'];
                            }
                        }
                    }

                    $reserve_note = $purchased_qty > (int)$req['quantity'] ? ' (Requested: ' . (int)$req['quantity'] . ', extra ' . ($purchased_qty - (int)$req['quantity']) . ' for reserve)' : '';
                    $stock_note = 'PO #' . $request_id . ' delivery' . $reserve_note . ($note !== '' ? ' — ' . $note : '');
                    record_stock_movement($pdo, (int)$req['item_id'], 'stock_in', $purchased_qty, 'item_request', $request_id, $user['id'], $stock_note, $variant_id);
                    resolve_stock_alert_notifications($pdo, (int)$req['item_id']);
                    $stock_added_msg = ' ' . $purchased_qty . ' units successfully stocked into inventory.';
                } elseif (empty($req['item_id'])) {
                    $stock_added_msg = ' (Note: Item was requested outside the catalog — add it in Catalog Management if you want to track physical shelf stock.)';
                }
            }

            decide_item_request($pdo, $request_id, $decision, $user, $note);

            $action_log = $decision === 'fulfilled'
                ? $user['full_name'] . ' marked PO #' . $request_id . ' ("' . $req['item_name'] . '") as fulfilled (purchased ' . $purchased_qty . ' units).'
                : $user['full_name'] . ' closed PO #' . $request_id . ' ("' . $req['item_name'] . '").';

            log_audit_event($pdo, $user, $decision === 'fulfilled' ? 'item_request_fulfill' : 'item_request_reject',
                'item_request', $request_id, $action_log . ($note !== '' ? ' Note: ' . $note : ''));

            $flash_success = $decision === 'fulfilled'
                ? 'PO #' . $request_id . ' marked as fulfilled.' . $stock_added_msg . ' The requester has been notified.'
                : 'PO #' . $request_id . ' was closed / cancelled.';
        }
    }
}

$tab_param = $_GET['tab'] ?? 'pending';
$tab = in_array($tab_param, ['pending', 'fulfilled', 'rejected'], true) ? $tab_param : 'pending';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM item_requests WHERE status = :status");
$count_stmt->execute(['status' => $tab]);
$total_requests = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_requests / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare(
    "SELECT ir.*, u.full_name AS requester_name, u.employee_id, u.position AS requester_position,
        d.full_name AS decided_by_name, i.unit AS catalog_unit
     FROM item_requests ir
     JOIN users u ON u.id = ir.requester_id
     LEFT JOIN users d ON d.id = ir.decided_by
     LEFT JOIN items i ON i.id = ir.item_id
     WHERE ir.status = :status
     ORDER BY ir.created_at " . ($tab === 'pending' ? 'ASC' : 'DESC') . "
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':status', $tab);
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll();

$pending_count = count_pending_item_requests($pdo);

$page_title = 'Purchase Requests (PO)';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Procurement &amp; Purchasing</div>
    <h1>Purchase Requests (PO)</h1>
  </div>
  <?php if ($tab === 'pending' && $pending_count > 0): ?>
    <a href="<?= BASE_URL ?>/inventory/item_requests_print_all.php" target="_blank" class="btn btn-outline btn-sm">
      Print Consolidated Shopping List (All Pending POs)
    </a>
  <?php endif; ?>
</div>

<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="range-tabs" role="tablist" style="margin-bottom:1.25rem;">
  <a href="?tab=pending" class="range-tab <?= $tab === 'pending' ? 'active' : '' ?>">
    Pending POs<?= $pending_count > 0 ? ' (' . $pending_count . ')' : '' ?>
  </a>
  <a href="?tab=fulfilled" class="range-tab <?= $tab === 'fulfilled' ? 'active' : '' ?>">Fulfilled &amp; Purchased</a>
  <a href="?tab=rejected" class="range-tab <?= $tab === 'rejected' ? 'active' : '' ?>">Closed / Cancelled</a>
</div>

<?php if (!$requests): ?>
  <div class="empty-state">Nothing here right now.</div>
<?php else: ?>
  <div style="display:flex; flex-direction:column; gap:0.6rem;">
    <?php foreach ($requests as $r):
      $pos = position_label($r['requester_position']);
      $resolved = item_request_resolve($r, $r['catalog_unit']);
      $row_data = [
        'id'            => (int)$r['id'],
        'itemName'      => $r['item_name'],
        'qty'           => item_request_qty_display((int)$r['quantity'], $resolved),
        'rawQty'        => (int)$r['quantity'],
        'unit'          => $resolved['unit'] ?? ($r['catalog_unit'] ?? 'pcs'),
        'itemId'        => (int)($r['item_id'] ?? 0),
        'status'        => $r['status'],
        'statusLabel'   => ucfirst($r['status']),
        'statusClass'   => item_request_status_class($r['status']),
        'inCatalog'     => (bool)$r['item_id'],
        'image'         => $r['image_filename'] ? ITEM_UPLOAD_URL . $r['image_filename'] : null,
        'requesterName' => $r['requester_name'],
        'employeeId'    => $r['employee_id'],
        'position'      => $pos,
        'timeAgo'       => time_ago($r['created_at']),
        'reason'        => $resolved['reason'],
        'decidedByName' => $r['decided_by_name'],
        'decisionNote'  => $r['decision_note'],
        'printUrl'      => BASE_URL . '/inventory/item_request_print.php?id=' . (int)$r['id'],
      ];
    ?>
      <div class="card" style="margin:0; display:flex; gap:0.9rem; align-items:center; flex-wrap:wrap;">
        <?php if ($r['image_filename']): ?>
          <img src="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($r['image_filename'] ?? '')) ?>" alt="<?= htmlspecialchars((string)($r['item_name'] ?? '')) ?>"
            style="width:48px; height:48px; object-fit:cover; border-radius:8px; border:1px solid var(--line); flex-shrink:0;">
        <?php endif; ?>

        <div style="flex:1 1 220px; min-width:0;">
          <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            <strong><?= htmlspecialchars((string)($r['item_name'] ?? '')) ?></strong>
            <span class="mono text-muted">Qty: <?= htmlspecialchars(item_request_qty_display((int)$r['quantity'], $resolved)) ?></span>
          </div>
          <div class="section-sub" style="margin-top:0.2rem;">
            <?= htmlspecialchars((string)($r['requester_name'] ?? '')) ?> &middot; <?= time_ago($r['created_at']) ?>
          </div>
        </div>

        <span class="badge <?= item_request_status_class($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span>

        <button type="button" class="btn btn-outline btn-sm js-view-request"
          data-request="<?= htmlspecialchars(json_encode($row_data), ENT_QUOTES, 'UTF-8') ?>">
          View status
        </button>
      </div>
    <?php endforeach; ?>
  </div>
  <?= render_pagination($page, $total_pages, $per_page) ?>
<?php endif; ?>

<!-- Shared request detail / status modal -->
<div class="item-modal-backdrop" id="requestModalBackdrop"></div>
<div class="item-modal" id="requestModal" role="dialog" aria-modal="true" aria-hidden="true">
  <button type="button" class="item-modal-close" id="requestModalClose" aria-label="Close">&times;</button>
  <div class="item-modal-thumb hidden" id="requestModalThumb"></div>
  <div class="item-modal-body">
    <div class="item-detail-grid">
      <div class="detail-row-split">
        <div class="detail-field detail-field-full">
          <span class="detail-label">Item</span>
          <span class="detail-value" id="requestModalName"></span>
        </div>
      </div>
      <div class="detail-row-split">
        <div class="detail-field">
          <span class="detail-label">Quantity</span>
          <span class="detail-value" id="requestModalQty"></span>
        </div>
        <div class="detail-field">
          <span class="detail-label">Status</span>
          <span class="detail-value"><span class="badge" id="requestModalStatusBadge"></span></span>
        </div>
      </div>
      <div class="detail-field detail-field-full">
        <span class="detail-label">In catalog</span>
        <span class="detail-value" id="requestModalCatalog"></span>
      </div>
      <div class="detail-field detail-field-full">
        <span class="detail-label">Requested by</span>
        <span class="detail-value" id="requestModalRequester"></span>
      </div>
      <div class="detail-field detail-field-full">
        <span class="detail-label">Reason</span>
        <span class="detail-value" id="requestModalReason"></span>
      </div>
      <div class="detail-field detail-field-full hidden" id="requestModalDecidedField">
        <span class="detail-label">Decision</span>
        <span class="detail-value" id="requestModalDecided"></span>
      </div>
    </div>

    <div style="margin-top:0.9rem;">
      <a href="#" id="requestModalPrint" target="_blank" class="btn btn-outline btn-sm">Print Individual PO Requisition Slip</a>
    </div>

    <form method="post" id="requestModalActions" style="margin-top:0.9rem; display:none;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="request_id" id="requestModalRequestId" value="">
      <input type="hidden" name="decision" id="requestModalDecision" value="">

      <div class="form-group" id="requestModalPurchasedGroup" style="margin-bottom:0.75rem;">
        <label for="requestModalPurchasedQty" style="font-weight:600; display:block; margin-bottom:0.25rem;">
          Actual Quantity Purchased (with Reserve / Buffer)
        </label>
        <div style="display:flex; align-items:center; gap:0.5rem;">
          <input type="number" name="purchased_qty" id="requestModalPurchasedQty" min="1" value="1"
            style="width:110px; padding:0.45rem 0.6rem; border:1px solid var(--line); border-radius:6px; font-weight:600;">
          <span id="requestModalPurchasedUnit" class="text-muted" style="font-size:0.9rem;">pcs</span>
        </div>
        <div class="text-muted" id="requestModalPurchasedHint" style="font-size:0.8rem; margin-top:0.25rem;">
          Requested: <strong id="requestModalRequestedQtyTxt">1</strong>. Add reserve stock.
        </div>
      </div>

      <div class="form-group" id="requestModalStockInGroup" style="margin-bottom:0.75rem; background:var(--surface); padding:0.6rem 0.75rem; border:1px solid var(--line); border-radius:6px;">
        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; margin:0; font-size:0.9rem; font-weight:500;">
          <input type="checkbox" name="auto_stock_in" id="requestModalAutoStockIn" value="1" checked>
          <span>Automatically add purchased units to warehouse inventory (Stock-In)</span>
        </label>
      </div>

      <div id="requestModalNonCatalogNotice" style="display:none; margin-bottom:0.75rem; background:var(--paper); padding:0.6rem 0.75rem; border:1px solid var(--line); border-radius:6px; font-size:0.82rem; color:var(--ink-soft);">
        <strong style="color:var(--ink);">ℹ️ Non-catalog item.</strong> Add after purchase.
      </div>

      <div class="form-group" style="margin-bottom:0.75rem;">
        <label for="requestModalNote" style="font-weight:600; display:block; margin-bottom:0.25rem;">PO / Delivery Note (optional)</label>
        <input type="text" name="decision_note" id="requestModalNote" placeholder="e.g. Supplier, invoice"
          style="width:100%; padding:0.5rem; border:1px solid var(--line); border-radius:6px;">
      </div>
      <div style="display:flex; gap:0.5rem; margin-top:0.6rem;">
        <button type="button" class="btn btn-primary btn-sm flex-1" id="requestModalFulfill">Mark Purchased &amp; Fulfilled</button>
        <button type="button" class="btn btn-outline btn-sm" id="requestModalReject" style="flex:0 0 auto;">Close / Cancel PO</button>
      </div>
    </form>
  </div>
</div>

<script>
  (function () {
    var backdrop  = document.getElementById('requestModalBackdrop');
    var modal     = document.getElementById('requestModal');
    var closeBtn  = document.getElementById('requestModalClose');
    var thumb     = document.getElementById('requestModalThumb');
    var nameEl    = document.getElementById('requestModalName');
    var qtyEl     = document.getElementById('requestModalQty');
    var statusBadge = document.getElementById('requestModalStatusBadge');
    var catalogEl = document.getElementById('requestModalCatalog');
    var requesterEl = document.getElementById('requestModalRequester');
    var reasonEl  = document.getElementById('requestModalReason');
    var decidedField = document.getElementById('requestModalDecidedField');
    var decidedEl = document.getElementById('requestModalDecided');
    var printLink = document.getElementById('requestModalPrint');
    var actionsForm = document.getElementById('requestModalActions');
    var requestIdInput = document.getElementById('requestModalRequestId');
    var decisionInput  = document.getElementById('requestModalDecision');
    var noteInput = document.getElementById('requestModalNote');
    var purchasedQtyInput = document.getElementById('requestModalPurchasedQty');
    var purchasedUnitTxt = document.getElementById('requestModalPurchasedUnit');
    var requestedQtyTxt = document.getElementById('requestModalRequestedQtyTxt');
    var stockInGroup = document.getElementById('requestModalStockInGroup');
    var autoStockInCheck = document.getElementById('requestModalAutoStockIn');
    var fulfillBtn = document.getElementById('requestModalFulfill');
    var rejectBtn  = document.getElementById('requestModalReject');
    var nonCatalogNotice = document.getElementById('requestModalNonCatalogNotice');

    function openModal(data) {
      nameEl.textContent = data.itemName;
      qtyEl.textContent = data.qty;
      statusBadge.textContent = data.statusLabel;
      statusBadge.className = 'badge ' + data.statusClass;
      catalogEl.textContent = data.inCatalog ? 'In catalog' : 'Not in catalog';
      requesterEl.textContent = data.requesterName + ' (' + data.employeeId + ')'
        + (data.position ? ' — ' + data.position : '') + ' · ' + data.timeAgo;
      reasonEl.textContent = data.reason || '—';
      printLink.href = data.printUrl;

      if (data.image) {
        thumb.style.display = '';
        thumb.innerHTML = '';
        var thumbImg = document.createElement('img');
        thumbImg.src = data.image;
        thumbImg.alt = data.itemName;
        thumbImg.style.width = '100%';
        thumbImg.style.height = '100%';
        thumbImg.style.objectFit = 'contain';
        thumb.appendChild(thumbImg);
      } else {
        thumb.style.display = 'none';
        thumb.innerHTML = '';
      }

      if (data.status !== 'pending') {
        decidedField.style.display = '';
        decidedEl.textContent = data.statusLabel + ' by ' + (data.decidedByName || '—')
          + (data.decisionNote ? ' — ' + data.decisionNote : '');
        actionsForm.style.display = 'none';
        if (nonCatalogNotice) nonCatalogNotice.style.display = 'none';
      } else {
        decidedField.style.display = 'none';
        actionsForm.style.display = '';
        requestIdInput.value = data.id;
        noteInput.value = '';

        var rawQty = data.rawQty || 1;
        var unit = data.unit || 'pcs';
        purchasedQtyInput.value = rawQty;
        purchasedUnitTxt.textContent = unit;
        requestedQtyTxt.textContent = rawQty + ' ' + unit;

        if (data.inCatalog) {
          stockInGroup.style.display = '';
          autoStockInCheck.checked = true;
          if (nonCatalogNotice) nonCatalogNotice.style.display = 'none';
        } else {
          stockInGroup.style.display = 'none';
          autoStockInCheck.checked = false;
          if (nonCatalogNotice) nonCatalogNotice.style.display = '';
        }
      }

      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      backdrop.classList.add('open');
      document.body.classList.add('item-modal-open');
    }

    function closeModal() {
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      backdrop.classList.remove('open');
      document.body.classList.remove('item-modal-open');
    }

    document.querySelectorAll('.js-view-request').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var data = JSON.parse(btn.getAttribute('data-request'));
        openModal(data);
      });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    fulfillBtn.addEventListener('click', function () {
      if (!confirm('Mark this Purchase Request as fulfilled / purchased? The requester will be notified.')) return;
      decisionInput.value = 'fulfilled';
      actionsForm.submit();
    });
    rejectBtn.addEventListener('click', function () {
      if (!confirm('Close / cancel this Purchase Request without purchasing?')) return;
      decisionInput.value = 'rejected';
      actionsForm.submit();
    });
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
