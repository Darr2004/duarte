<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loans.php';
require_role(['driver_helper', 'field_supervisor']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

// Handle borrower's extension requests (single or trip-wide)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'];
        $days = max(1, min(14, (int)($_POST['extension_days'] ?? 1)));
        $reason = trim($_POST['extension_reason'] ?? '');

        if ($action === 'request_extension') {
            $loan_id = (int)($_POST['loan_id'] ?? 0);
            try {
                $res = request_tool_loan_extension($pdo, $loan_id, (int)$user['id'], $days, $reason);
                $flash_success = "Extension request for +{$days} day(s) submitted to Inventory Staff. Overdue flag is on hold while pending review.";
            } catch (Exception $e) {
                $errors[] = clean_error_message($e);
            }
        } elseif ($action === 'request_trip_extension') {
            $req_id = (int)($_POST['requisition_id'] ?? 0);
            try {
                $res = request_trip_tools_extension($pdo, $req_id, (int)$user['id'], $days, $reason);
                $count = count($res);
                $flash_success = "Trip extension submitted for {$count} tool(s) (+{$days} day(s)). Overdue flags are on hold while pending review by Inventory Staff.";
            } catch (Exception $e) {
                $errors[] = clean_error_message($e);
            }
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT tl.*, i.name AS item_name, i.item_code, i.unit,
            r.purpose AS requisition_purpose
     FROM tool_loans tl
     JOIN items i ON i.id = tl.item_id
     LEFT JOIN requisitions r ON r.id = tl.requisition_id
     WHERE tl.borrower_id = :uid
     ORDER BY (tl.returned_at IS NULL) DESC, tl.due_date ASC"
);
$stmt->execute(['uid' => $user['id']]);
$loans = $stmt->fetchAll();

// Group loans by requisition_id
$groups = [];
foreach ($loans as $l) {
    $rid = $l['requisition_id'];
    $key = $rid !== null ? 'req_' . $rid : 'direct_' . $l['id'];
    $groups[$key]['requisition_id'] = $rid;
    $groups[$key]['purpose'] = $l['requisition_purpose'];
    $groups[$key]['loans'][] = $l;
}

function loan_group_status(array $loans): string
{
    $all_returned = true;
    $any_overdue = false;
    $any_ext_pending = false;
    foreach ($loans as $l) {
        $s = loan_status($l['due_date'], $l['returned_at'], $l['extension_status'] ?? 'none');
        if ($s !== 'returned') $all_returned = false;
        if ($s === 'overdue') $any_overdue = true;
        if ($s === 'extension_pending') $any_ext_pending = true;
    }
    if ($any_overdue) return 'overdue';
    if ($any_ext_pending) return 'extension_pending';
    if ($all_returned) return 'returned';
    return 'borrowed';
}

$page_title = 'My Borrowed Tools';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Tool borrowing &amp; due date monitoring</div>
    <h1>My Borrowed Tools</h1>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>

<?php if (!$groups): ?>
  <div class="empty-state">You haven't borrowed any tools yet.</div>
<?php else: ?>
  <div class="card">
    <div class="table-responsive">
<table class="data">
      <thead><tr><th>Request / Trip</th><th>Items</th><th>Status</th><th>Extension Status</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($groups as $group):
          $summary_status = loan_group_status($group['loans']);
          $unreturned_loans = array_filter($group['loans'], fn($l) => $l['returned_at'] === null);
          $has_unreturned = count($unreturned_loans) > 0;
          $has_pending_ext = false;
          $has_approved_ext = false;
          foreach ($group['loans'] as $l) {
              if ($l['returned_at'] === null) {
                  if (($l['extension_status'] ?? 'none') === 'pending') $has_pending_ext = true;
                  if (($l['extension_status'] ?? 'none') === 'approved' && (int)($l['extension_days'] ?? 0) > 0) $has_approved_ext = true;
              }
          }

          $row_items = array_map(function ($l) {
              $status = loan_status($l['due_date'], $l['returned_at'], $l['extension_status'] ?? 'none');
              return [
                  'id'                    => (int)$l['id'],
                  'name'                  => $l['item_name'],
                  'code'                  => $l['item_code'],
                  'qty'                   => (int)$l['quantity'],
                  'unit'                  => $l['unit'],
                  'borrowedAt'            => $l['borrowed_at'],
                  'dueDate'               => $l['due_date'],
                  'returnedAt'            => $l['returned_at'],
                  'status'                => $status,
                  'statusClass'           => loan_status_class($status),
                  'extensionStatus'       => $l['extension_status'] ?? 'none',
                  'extensionDays'         => (int)($l['extension_days'] ?? 0),
                  'extensionReason'       => $l['extension_reason'] ?? '',
                  'extensionRequestedAt'  => $l['extension_requested_at'] ?? '',
                  'extensionDecisionNote' => $l['extension_decision_note'] ?? '',
              ];
          }, $group['loans']);

          $row_label = $group['requisition_id'] !== null
              ? 'Request #' . (int)$group['requisition_id']
              : 'Direct checkout';
          $row_data = [
              'label'         => $row_label,
              'requisitionId' => $group['requisition_id'],
              'purpose'       => $group['purpose'],
              'items'         => $row_items,
          ];
        ?>
          <tr>
            <td data-label="Request / Trip">
              <strong><?= htmlspecialchars($row_label) ?></strong>
              <?php if ($group['purpose']): ?>
                <div class="text-muted" style="font-size:0.82rem;"><?= htmlspecialchars((string)($group['purpose'] ?? '')) ?></div>
              <?php elseif ($group['requisition_id'] === null): ?>
                <span class="badge role" style="font-size:0.68rem; margin-left:0.3rem;" title="Handed to you directly by Inventory Staff, outside the online request flow">no requisition</span>
              <?php endif; ?>
            </td>
            <td class="td-detail" data-label="Items"><?= count($group['loans']) ?> item<?= count($group['loans']) === 1 ? '' : 's' ?></td>
            <td data-label="Status"><span class="badge <?= loan_status_class($summary_status) ?>"><?= $summary_status ?></span></td>
            <td data-label="Extension Status">
              <?php if ($has_pending_ext): ?>
                <span class="badge" style="background:var(--amber-tint); color:var(--amber-dim); border:1px solid var(--amber-border); font-size:0.75rem;">⏳ Ext. Pending</span>
              <?php elseif ($has_approved_ext): ?>
                <span class="badge" style="background:var(--blue-tint); color:var(--blue-info); border:1px solid var(--blue-border); font-size:0.75rem;">✓ Extended</span>
              <?php elseif ($has_unreturned): ?>
                <span class="text-muted" style="font-size:0.8rem;">Standard due</span>
              <?php else: ?>
                <span class="text-muted" style="font-size:0.8rem;">Returned</span>
              <?php endif; ?>
            </td>
            <td data-label="Actions" style="text-align:right; white-space:nowrap;">
              <button type="button" class="btn btn-outline btn-sm js-view-loan-group"
                data-loan-group="<?= htmlspecialchars(json_encode($row_data), ENT_QUOTES, 'UTF-8') ?>">
                View
              </button>
              <?php if ($has_unreturned && !$has_pending_ext): ?>
                <?php if ($group['requisition_id'] !== null): ?>
                  <button type="button" class="btn btn-primary btn-sm js-trigger-trip-ext"
                    style="margin-left:4px;"
                    data-req-id="<?= (int)$group['requisition_id'] ?>"
                    data-tool-count="<?= count($unreturned_loans) ?>"
                    data-label="<?= htmlspecialchars($row_label) ?>">
                    🚚 Extend Trip
                  </button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
</div>
  </div>
<?php endif; ?>

<!-- Borrowed-tools detail modal -->
<div class="item-modal-backdrop" id="loanModalBackdrop"></div>
<div class="item-modal item-modal-wide" id="loanModal" role="dialog" aria-modal="true" aria-hidden="true" style="max-width:820px;">
  <button type="button" class="item-modal-close" id="loanModalClose" aria-label="Close">&times;</button>
  <div class="item-modal-body">
    <div style="margin-bottom:0.9rem; padding-right:1.6rem;">
      <h2 style="margin:0; font-size:1.1rem;" id="loanModalLabel"></h2>
      <div class="text-muted hidden" id="loanModalPurpose" style="font-size:0.85rem; margin-top:0.2rem;"></div>
    </div>

    <div class="table-responsive">
      <table class="data" style="min-width:0;">
        <thead><tr><th>Item</th><th>Qty</th><th>Borrowed</th><th>Due Date</th><th>Returned</th><th>Status</th><th style="text-align:right;">Trip Extension</th></tr></thead>
        <tbody id="loanModalItems"></tbody>
      </table>
    </div>

    <div id="modalTripExtBanner" class="hidden" style="margin-top:1.2rem; padding:0.85rem 1rem; background:var(--surface-subtle); border:1px solid var(--line); border-radius:6px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
      <div>
        <strong style="color:var(--ink); font-size:0.9rem;">🚚 Trip Tools in Field</strong>
        <p style="margin:0; font-size:0.8rem; color:var(--ink-soft);">Extend due date for all equipment in this trip simultaneously.</p>
      </div>
      <button type="button" class="btn btn-primary btn-sm" id="btnTripExtFromModal">
        Extend Trip
      </button>
    </div>
  </div>
</div>

<!-- Extension Request Action Modal (Single or Trip) -->
<div class="item-modal-backdrop" id="extModalBackdrop" style="z-index: 1050;"></div>
<div class="item-modal" id="extModal" role="dialog" aria-modal="true" aria-hidden="true" style="max-width:480px; z-index: 1051;">
  <button type="button" class="item-modal-close" id="extModalClose" aria-label="Close">&times;</button>
  <div class="item-modal-body">
    <h2 style="margin:0 0 0.4rem; font-size:1.1rem; color:var(--ink);" id="extModalTitle">Request Tool Extension</h2>
    <p style="margin:0 0 1rem; font-size:0.84rem; color:var(--ink-soft);" id="extModalSubtitle">
      Request additional days from Inventory Staff if the delivery run is extended.
    </p>

    <form method="post" id="extForm">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" id="extFormAction" value="request_extension">
      <input type="hidden" name="loan_id" id="extFormLoanId" value="">
      <input type="hidden" name="requisition_id" id="extFormReqId" value="">

      <div class="form-group" style="margin-bottom:0.85rem;">
        <label for="extFormDays" style="font-weight:600; font-size:0.88rem;">Days Needed</label>
        <select name="extension_days" id="extFormDays" class="form-control" style="width:100%; padding:0.45rem 0.6rem; border:1px solid var(--line); border-radius:4px;">
          <option value="1">+1 Day (Minor Delay)</option>
          <option value="2">+2 Days</option>
          <option value="3" selected>+3 Days (Long Haul)</option>
          <option value="5">+5 Days</option>
          <option value="7">+1 Week (Regional Project)</option>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:0.85rem;">
        <label for="extFormReason" style="font-weight:600; font-size:0.88rem;">Reason / Operational Status</label>
        <select aria-label="Common reasons" id="extPresetReasons" class="form-control" style="width:100%; padding:0.45rem 0.6rem; border:1px solid var(--line); border-radius:4px; margin-bottom:0.4rem; font-size:0.82rem;">
          <option value="">-- Select reason or type below --</option>
          <option value="Nasa malayo pang biyahe / na-delay ang biyahe pabalik ng planta">Na-delay ang biyahe pabalik ng planta</option>
          <option value="Masamang panahon / Baha o bagyo sa ruta">Masamang panahon / Baha o bagyo sa ruta</option>
          <option value="Nasiraan ang truck / sumasailalim sa emergency roadside repair">Nasiraan ang truck / emergency roadside repair</option>
          <option value="Hindi pa tapos ang trabaho / unloading sa project site">Hindi pa tapos ang trabaho sa project site</option>
          <option value="__custom__">Iba pa / Custom (Mag-type ng sariling dahilan)...</option>
        </select>
        <textarea name="extension_reason" id="extFormReason" rows="3" class="form-control" style="width:100%; padding:0.45rem 0.6rem; border:1px solid var(--line); border-radius:4px;" placeholder="Pumili sa itaas o mag-type ng dahilan" required></textarea>
      </div>

      <div style="background:var(--surface-subtle); border:1px solid var(--line); border-radius:6px; padding:0.6rem 0.75rem; font-size:0.78rem; color:var(--ink-soft); margin-bottom:1rem;">
        Pending staff review.
      </div>

      <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
        <button type="button" class="btn btn-outline" id="extModalCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Extension</button>
      </div>
    </form>
  </div>
</div>

<script>
  (function () {
    var backdrop  = document.getElementById('loanModalBackdrop');
    var modal     = document.getElementById('loanModal');
    var closeBtn  = document.getElementById('loanModalClose');
    var labelEl   = document.getElementById('loanModalLabel');
    var purposeEl = document.getElementById('loanModalPurpose');
    var itemsBody = document.getElementById('loanModalItems');
    var bannerTrip = document.getElementById('modalTripExtBanner');
    var btnTripExtModal = document.getElementById('btnTripExtFromModal');

    // Extension Action Modal elements
    var extBackdrop = document.getElementById('extModalBackdrop');
    var extModal    = document.getElementById('extModal');
    var extClose    = document.getElementById('extModalClose');
    var extCancel   = document.getElementById('extModalCancel');
    var extTitle    = document.getElementById('extModalTitle');
    var extSubtitle = document.getElementById('extModalSubtitle');
    var extAction   = document.getElementById('extFormAction');
    var extLoanId   = document.getElementById('extFormLoanId');
    var extReqId    = document.getElementById('extFormReqId');
    var extDays     = document.getElementById('extFormDays');
    var extReason   = document.getElementById('extFormReason');
    var extPreset   = document.getElementById('extPresetReasons');

    var currentModalData = null;

    function escapeHtml(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function openModal(data) {
      currentModalData = data;
      labelEl.textContent = data.label;
      if (data.purpose) {
        purposeEl.textContent = data.purpose;
        purposeEl.classList.remove('hidden');
      } else {
        purposeEl.classList.add('hidden');
      }

      var unreturnedCount = 0;
      var hasPending = false;

      itemsBody.innerHTML = data.items.map(function (it) {
        var isReturned = !!it.returnedAt;
        if (!isReturned) unreturnedCount++;
        if (it.extensionStatus === 'pending') hasPending = true;

        var extCell = '';
        if (isReturned) {
          extCell = '<span class="text-muted" style="font-size:0.75rem;">Returned</span>';
        } else if (it.extensionStatus === 'pending') {
          extCell = '<span class="badge" style="background:var(--amber-tint); color:var(--amber-dim); font-size:0.75rem; border:1px solid var(--amber-border);">⏳ Pending (+' + it.extensionDays + 'd)</span>'
                  + '<div style="font-size:0.72rem; color:var(--ink-soft); margin-top:2px;">Under review by Inventory Staff</div>';
        } else if (it.extensionStatus === 'approved' && it.extensionDays > 0) {
          extCell = '<span class="badge" style="background:var(--blue-tint); color:var(--blue-info); font-size:0.75rem; border:1px solid var(--blue-border);">✓ Extended (+' + it.extensionDays + 'd)</span>'
                  + '<div style="margin-top:4px;"><button type="button" class="btn btn-outline btn-xs js-open-single-ext" data-loan-id="' + it.id + '" data-item-name="' + escapeHtml(it.name) + '" style="font-size:0.7rem; padding:1px 5px;">Extend more</button></div>';
        } else {
          var decNote = it.extensionStatus === 'declined' && it.extensionDecisionNote ? '<div style="color:var(--red-danger); font-size:0.72rem;">Declined: ' + escapeHtml(it.extensionDecisionNote) + '</div>' : '';
          extCell = decNote + '<button type="button" class="btn btn-outline btn-sm js-open-single-ext" data-loan-id="' + it.id + '" data-item-name="' + escapeHtml(it.name) + '" style="font-size:0.75rem; padding:2px 8px;">🚚 Request Extension</button>';
        }

        return '<tr>'
          + '<td data-label="Item">' + escapeHtml(it.name) + ' <span class="mono text-muted">(' + escapeHtml(it.code) + ')</span></td>'
          + '<td class="mono" data-label="Qty">' + it.qty + ' ' + escapeHtml(it.unit) + '</td>'
          + '<td class="mono" data-label="Borrowed">' + escapeHtml(it.borrowedAt) + '</td>'
          + '<td class="mono" data-label="Due">' + escapeHtml(it.dueDate) + '</td>'
          + '<td class="mono" data-label="Returned">' + (it.returnedAt ? escapeHtml(it.returnedAt) : '<span class="text-muted">—</span>') + '</td>'
          + '<td data-label="Status"><span class="badge ' + it.statusClass + '">' + escapeHtml(it.status) + '</span></td>'
          + '<td data-label="Trip Extension" style="text-align:right;">' + extCell + '</td>'
          + '</tr>';
      }).join('');

      if (data.requisitionId && unreturnedCount > 1 && !hasPending) {
        bannerTrip.classList.remove('hidden');
        bannerTrip.style.display = 'flex';
      } else {
        bannerTrip.classList.add('hidden');
        bannerTrip.style.display = 'none';
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

    function openExtDialog(config) {
      extTitle.textContent = config.title;
      extSubtitle.textContent = config.subtitle;
      extAction.value = config.action;
      extLoanId.value = config.loanId || '';
      extReqId.value = config.reqId || '';
      extDays.value = 3;
      extReason.value = '';
      if (extPreset) extPreset.value = '';

      // Reset presets UI
      document.querySelectorAll('.js-day-preset').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-days') === '3');
      });

      extModal.classList.add('open');
      extModal.setAttribute('aria-hidden', 'false');
      extBackdrop.classList.add('open');
    }

    function closeExtDialog() {
      extModal.classList.remove('open');
      extModal.setAttribute('aria-hidden', 'true');
      extBackdrop.classList.remove('open');
    }

    document.querySelectorAll('.js-view-loan-group').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var data = JSON.parse(btn.getAttribute('data-loan-group'));
        openModal(data);
      });
    });

    document.querySelectorAll('.js-trigger-trip-ext').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var reqId = btn.getAttribute('data-req-id');
        var label = btn.getAttribute('data-label');
        var count = btn.getAttribute('data-tool-count');
        openExtDialog({
          action: 'request_trip_extension',
          reqId: reqId,
          title: 'Extend Trip: ' + label + ' (' + count + ' Tools)',
          subtitle: 'I-extend ang due date ng lahat ng ' + count + ' na gamit sa biyaheng ito dahil sa delay.'
        });
      });
    });

    if (btnTripExtModal) {
      btnTripExtModal.addEventListener('click', function () {
        if (!currentModalData) return;
        openExtDialog({
          action: 'request_trip_extension',
          reqId: currentModalData.requisitionId,
          title: 'Extend All Tools: ' + currentModalData.label,
          subtitle: 'I-extend ang due date ng lahat ng gamit sa biyaheng ito dahil sa delay.'
        });
      });
    }

    // Delegate inline single extension clicks
    itemsBody.addEventListener('click', function (e) {
      var btn = e.target.closest('.js-open-single-ext');
      if (!btn) return;
      var lid = btn.getAttribute('data-loan-id');
      var name = btn.getAttribute('data-item-name');
      openExtDialog({
        action: 'request_extension',
        loanId: lid,
        title: 'Request Extension: ' + name,
        subtitle: 'Humiling ng dagdag na araw bago i-return ang ' + name + ' dahil sa byahe o delayed na delivery.'
      });
    });

    // Preset days buttons
    document.querySelectorAll('.js-day-preset').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var d = btn.getAttribute('data-days');
        extDays.value = d;
        document.querySelectorAll('.js-day-preset').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
      });
    });

    if (extPreset) {
      extPreset.addEventListener('change', function () {
        if (extPreset.value === '__custom__') {
          extReason.value = '';
          extReason.focus();
        } else if (extPreset.value) {
          extReason.value = extPreset.value;
        }
      });
    }

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    extClose.addEventListener('click', closeExtDialog);
    extCancel.addEventListener('click', closeExtDialog);
    extBackdrop.addEventListener('click', closeExtDialog);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (extModal.classList.contains('open')) {
          closeExtDialog();
        } else if (modal.classList.contains('open')) {
          closeModal();
        }
      }
    });
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
