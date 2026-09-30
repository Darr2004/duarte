<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['driver_helper', 'field_supervisor']);

$pdo = get_db();
$user = current_user();

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$count_stmt = $pdo->prepare("SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid");
$count_stmt->execute(['uid' => $user['id']]);
$total_requests = (int)$count_stmt->fetch()['c'];

$total_pages = max(1, (int)ceil($total_requests / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare(
    "SELECT r.*,
        (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count,
        d.full_name AS decided_by_name, rel.full_name AS released_by_name
     FROM requisitions r
     LEFT JOIN users d ON d.id = r.decided_by
     LEFT JOIN users rel ON rel.id = r.released_by
     WHERE r.requester_id = :uid
     ORDER BY r.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':uid', (int)$user['id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll();

// Pull every line item for these requisitions in a single query, then
// group by requisition_id, so the modal can be populated purely from
// data already on the page instead of navigating to view.php.
$items_by_req = [];
if ($requests) {
    $ids = array_column($requests, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $istmt = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id IN ($placeholders) ORDER BY id");
    $istmt->execute($ids);
    foreach ($istmt->fetchAll() as $it) {
        $items_by_req[$it['requisition_id']][] = $it;
    }
}

$po_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM item_requests WHERE requester_id = :uid AND status = 'pending'");
$po_count_stmt->execute(['uid' => $user['id']]);
$my_pending_po_count = (int)$po_count_stmt->fetchColumn();

$page_title = 'My Requests';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Online requisition &amp; approval</div>
    <h1>My Requests</h1>
  </div>
  <a href="<?= BASE_URL ?>/catalog/browse.php" class="btn btn-primary">+ New request</a>
</div>

<div class="range-tabs" role="tablist" style="margin-bottom:1.25rem;">
  <a href="<?= BASE_URL ?>/requisition/my_requests.php" class="range-tab active">
    Warehouse Requisitions (<?= $total_requests ?>)
  </a>
  <a href="<?= BASE_URL ?>/catalog/my_item_requests.php" class="range-tab">
    Purchase Requests / PO<?= $my_pending_po_count > 0 ? ' (' . $my_pending_po_count . ' pending)' : '' ?>
  </a>
</div>

<div class="card">
  <div class="table-responsive">
<table class="data">
    <thead><tr><th>#</th><th>Submitted</th><th>Truck Plate</th><th>Items</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="6">You haven't submitted any requisitions yet.</td></tr>
      <?php else: foreach ($requests as $r):
        $is_auto_expired = $r['status'] === 'cancelled'
            && $r['decision_note']
            && str_starts_with($r['decision_note'], 'Auto-expired');

        // Mirrors requisition/view.php's $is_auto_approved: an office
        // staff requisition that the system approved on submission
        // because there's no Field Supervisor over that position.
        $is_auto_approved = $r['status'] === 'approved'
            && !$r['decided_by_name']
            && requisition_is_no_review_note($r['decision_note']);

        $row_items = [];
        foreach ($items_by_req[$r['id']] ?? [] as $it) {
            $row_items[] = [
                'name'          => $it['item_name_snapshot'],
                'variant'       => $it['variant_selected'],
                'qty'           => (int)$it['quantity_requested'],
                'unit'          => $it['unit_snapshot'],
                'isBorrowable'  => (bool)$it['is_borrowable'],
                'requestedDays' => (int)($it['requested_days'] ?? 3),
            ];
        }

        $row_data = [
          'id'             => (int)$r['id'],
          'submittedAt'    => $r['created_at'],
          'status'         => $r['status'],
          'statusLabel'    => $r['status'],
          'statusClass'    => requisition_status_class($r['status']),
          'purpose'        => $r['purpose'],
          'truckPlate'     => $r['truck_plate_snapshot'],
          'decisionNote'   => $r['decision_note'],
          'isAutoExpired'  => $is_auto_expired,
          'isAutoApproved' => $is_auto_approved,
          'decidedByName'  => $r['decided_by_name'],
          'decidedAt'      => $r['decided_at'],
          'releasedByName' => $r['released_by_name'],
          'releasedAt'     => $r['released_at'],
          'qrToken'        => $r['qr_token'],
          'items'          => $row_items,
        ];
      ?>
        <tr>
          <td class="mono" data-label="#">#<?= $r['id'] ?></td>
          <td class="mono td-detail" data-label="Submitted"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td>
          <td data-label="Truck">
            <?= truck_plate_badge($r['truck_plate_snapshot']) ?>
          </td>
          <td data-label="Items" class="td-detail"><?= (int)$r['item_count'] ?> item(s)</td>
          <td data-label="Status"><span class="badge <?= requisition_status_class($r['status']) ?>"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></span></td>
          <td data-label="" class="td-detail">
            <button type="button" class="btn btn-outline btn-sm js-view-requisition"
              data-requisition="<?= htmlspecialchars(json_encode($row_data), ENT_QUOTES, 'UTF-8') ?>">
              View
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?= render_pagination($page, $total_pages, $per_page) ?>
</div>

<!-- Requisition detail modal -->
<div class="item-modal-backdrop" id="reqModalBackdrop"></div>
<div class="item-modal" id="reqModal" role="dialog" aria-modal="true" aria-hidden="true">
  <button type="button" class="item-modal-close" id="reqModalClose" aria-label="Close">&times;</button>
  <div class="item-modal-body">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; margin-bottom:0.9rem; padding-right:1.6rem;">
      <h2 style="margin:0; font-size:1.05rem;">Request <span id="reqModalId"></span></h2>
      <span class="badge" id="reqModalStatusBadge" style="font-size:0.8rem; flex-shrink:0;"></span>
    </div>

    <div class="item-detail-grid">
      <div class="detail-row-split">
        <div class="detail-field">
          <span class="detail-label">Submitted</span>
          <span class="detail-value mono" id="reqModalSubmitted"></span>
        </div>
        <div class="detail-field hidden" id="reqModalDecidedField">
          <span class="detail-label" id="reqModalDecidedLabel">Decided by</span>
          <span class="detail-value" id="reqModalDecided"></span>
        </div>
      </div>
      <div class="detail-field detail-field-full hidden" id="reqModalReleasedField">
        <span class="detail-label">Given out by</span>
        <span class="detail-value" id="reqModalReleased"></span>
      </div>
      <div class="detail-field detail-field-full hidden" id="reqModalTruckField">
        <span class="detail-label">Assigned Truck</span>
        <span class="detail-value mono" id="reqModalTruck" style="font-weight:700;"></span>
      </div>
      <div class="detail-field detail-field-full hidden" id="reqModalPurposeField">
        <span class="detail-label">Purpose</span>
        <span class="detail-value" id="reqModalPurpose"></span>
      </div>
      <div class="detail-field detail-field-full hidden" id="reqModalNoteField">
        <span class="detail-label">Note from approver</span>
        <span class="detail-value" id="reqModalNote"></span>
      </div>
    </div>

    <div class="mt-1">
      <div class="eyebrow" style="font-family:var(--font-mono); font-size:0.7rem; color:var(--ink-soft); margin-bottom:0.4rem;">Requested items</div>
      <div class="table-responsive">
        <table class="data" style="min-width:0;">
          <thead><tr><th>Item</th><th>Quantity</th><th>Type</th></tr></thead>
          <tbody id="reqModalItems"></tbody>
        </table>
      </div>
    </div>

    <div id="reqModalQrCard" style="display:none; text-align:center; margin-top:1rem; padding-top:1rem; border-top:1px solid var(--line);">
      <div class="eyebrow" style="font-family:var(--font-mono); font-size:0.7rem; color:var(--ink-soft); margin-bottom:0.5rem;">Pickup QR code</div>
      <p id="reqModalQrReleased" style="display:none; color:var(--green-ok); font-weight:600;">Already released.</p>
      <div id="reqModalQrWrap">
        <div id="reqModalQrCanvas" style="display:flex; justify-content:center; margin-bottom:0.6rem;"></div>
        <p style="font-size:0.82rem; color:var(--ink-soft);">Show at tool room.</p>
      </div>
    </div>

    <form method="post" action="<?= BASE_URL ?>/requisition/view.php" id="reqModalCancelForm" style="margin-top:1rem; display:none;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" id="reqModalCancelId" value="">
      <button type="submit" name="cancel" value="1" class="btn btn-outline">Cancel request</button>
    </form>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
  if (typeof QRCode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
  }
</script>
<script>
  (function () {
    var backdrop   = document.getElementById('reqModalBackdrop');
    var modal      = document.getElementById('reqModal');
    var closeBtn   = document.getElementById('reqModalClose');
    var idEl       = document.getElementById('reqModalId');
    var statusBadge = document.getElementById('reqModalStatusBadge');
    var submittedEl = document.getElementById('reqModalSubmitted');
    var decidedField = document.getElementById('reqModalDecidedField');
    var decidedLabelEl = document.getElementById('reqModalDecidedLabel');
    var decidedEl  = document.getElementById('reqModalDecided');
    var releasedField = document.getElementById('reqModalReleasedField');
    var releasedEl = document.getElementById('reqModalReleased');
    var truckField = document.getElementById('reqModalTruckField');
    var truckEl    = document.getElementById('reqModalTruck');
    var purposeField = document.getElementById('reqModalPurposeField');
    var purposeEl  = document.getElementById('reqModalPurpose');
    var noteField  = document.getElementById('reqModalNoteField');
    var noteEl     = document.getElementById('reqModalNote');
    var itemsBody  = document.getElementById('reqModalItems');
    var qrCard     = document.getElementById('reqModalQrCard');
    var qrReleasedMsg = document.getElementById('reqModalQrReleased');
    var qrWrap     = document.getElementById('reqModalQrWrap');
    var qrCanvas   = document.getElementById('reqModalQrCanvas');
    var cancelForm = document.getElementById('reqModalCancelForm');
    var cancelIdInput = document.getElementById('reqModalCancelId');

    function escapeHtml(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function openModal(data) {
      idEl.textContent = '#' + data.id;
      statusBadge.textContent = data.statusLabel;
      statusBadge.className = 'badge ' + data.statusClass;
      submittedEl.textContent = data.submittedAt;

      if (data.isAutoExpired) {
        decidedField.classList.remove('hidden');
        decidedLabelEl.textContent = 'Decided by';
        decidedEl.textContent = 'Auto-cancelled by system — ' + data.decidedAt;
      } else if (data.isAutoApproved) {
        decidedField.classList.remove('hidden');
        decidedLabelEl.textContent = 'Approved by';
        decidedEl.textContent = 'Approved without review — ' + data.decidedAt;
      } else if (data.decidedByName) {
        decidedField.classList.remove('hidden');
        decidedLabelEl.textContent = data.status === 'declined' ? 'Declined by' : 'Approved by';
        decidedEl.textContent = data.decidedByName + ' — ' + data.decidedAt;
      } else {
        decidedField.classList.add('hidden');
      }

      if (data.releasedByName) {
        releasedField.classList.remove('hidden');
        releasedEl.textContent = data.releasedByName + ' — ' + data.releasedAt;
      } else {
        releasedField.classList.add('hidden');
      }

      if (data.truckPlate) {
        truckField.classList.remove('hidden');
        truckEl.textContent = '🚚 ' + data.truckPlate;
      } else {
        truckField.classList.add('hidden');
      }

      if (data.purpose) {
        purposeField.classList.remove('hidden');
        purposeEl.textContent = data.purpose;
      } else {
        purposeField.classList.add('hidden');
      }

      if (data.decisionNote) {
        noteField.classList.remove('hidden');
        noteEl.textContent = data.decisionNote;
      } else {
        noteField.classList.add('hidden');
      }

      itemsBody.innerHTML = data.items.map(function (it) {
        var typeHtml = it.isBorrowable
          ? '<span class="badge role">Tool — borrow ' + it.requestedDays + ' day(s)</span>'
          : '<span style="color:var(--ink-soft); font-size:0.85rem;">Consumable</span>';
        var variantHtml = it.variant
          ? ' <span class="mono text-muted">(' + escapeHtml(it.variant) + ')</span>'
          : '';
        return '<tr>'
          + '<td data-label="Item">' + escapeHtml(it.name) + variantHtml + '</td>'
          + '<td class="mono" data-label="Quantity">' + it.qty + ' ' + escapeHtml(it.unit) + '</td>'
          + '<td data-label="Type">' + typeHtml + '</td>'
          + '</tr>';
      }).join('');

      qrCanvas.innerHTML = '';
      if ((data.status === 'approved' || data.status === 'released') && data.qrToken) {
        qrCard.style.display = '';
        if (data.status === 'released') {
          qrReleasedMsg.style.display = '';
          qrWrap.style.display = 'none';
        } else {
          qrReleasedMsg.style.display = 'none';
          qrWrap.style.display = '';
          new QRCode(qrCanvas, {
            text: <?= json_encode(BASE_URL . '/inventory/verify.php?token=') ?> + data.qrToken,
            width: 180,
            height: 180,
            colorDark: '#1F2430',
            colorLight: '#ffffff'
          });
        }
      } else {
        qrCard.style.display = 'none';
      }

      if (data.status === 'pending' || data.isAutoApproved) {
        cancelForm.style.display = '';
        cancelIdInput.value = data.id;
      } else {
        cancelForm.style.display = 'none';
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

    document.querySelectorAll('.js-view-requisition').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var data = JSON.parse(btn.getAttribute('data-requisition'));
        openModal(data);
      });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    cancelForm.addEventListener('submit', function (e) {
      if (!confirm('Cancel this request?')) e.preventDefault();
    });
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
