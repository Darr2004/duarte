<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

// Opportunistic overdue check — fires any pending overdue alerts on page load.
// A real deployment should also wire cron/check_overdue.php to an actual
// cron job so alerts go out even when nobody's viewing this page.
check_overdue_loans($pdo);

/**
 * Thin wrapper around the shared return_tool_loan(): closes the loan
 * (restoring stock either way — from a requisition release or a
 * direct checkout, see return_tool_loan()), then checks the tied
 * physical asset back in with a clean/default condition —
 * this page's per-row "Mark returned" doesn't ask a damaged? question,
 * so default to a clean return here.
 * @throws Exception on failure (caller rolls back)
 */
function mark_loan_returned(PDO $pdo, array $loan, array $user): array
{
    $result = return_tool_loan($pdo, $loan, $user);

    if (!empty($loan['asset_id'])) {
        checkin_asset(
            $pdo, (int)$loan['asset_id'], $user, false, null,
            'tool_loan', (int)$loan['id']
        );
    }

    return $result;
}

// Mark loan(s) returned — either a single row (individual "Mark returned"
// button / QR scan) or every still-out row from the same borrow
// transaction at once ("Mark all returned" on the transaction header),
// since both post to the same handler.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['return_loan_id']) || isset($_POST['return_loan_ids']))) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $loan_ids = isset($_POST['return_loan_ids'])
            ? array_map('intval', (array)$_POST['return_loan_ids'])
            : [(int)$_POST['return_loan_id']];

        $returned_results = [];
        try {
            $pdo->beginTransaction();

            foreach ($loan_ids as $loan_id) {
                $stmt = $pdo->prepare('SELECT * FROM tool_loans WHERE id = :id FOR UPDATE');
                $stmt->execute(['id' => $loan_id]);
                $loan = $stmt->fetch();

                if (!$loan) {
                    $errors[] = "Loan #$loan_id not found.";
                    continue;
                }
                if ($loan['returned_at'] !== null) {
                    // Already returned (e.g. double-submit) — skip quietly
                    // rather than failing the whole batch over it.
                    continue;
                }

                $returned_results[] = mark_loan_returned($pdo, $loan, $user);
            }

            $pdo->commit();

            foreach ($returned_results as $result) {
                if ($result['stock_restored']) {
                    maybe_alert_stock_threshold(
                        $result['item_id'], $result['before'], $result['after'],
                        $result['name'], $result['unit'],
                        $result['variant_before'] ?? null,
                        $result['variant_after'] ?? null,
                        $result['variant_value'] ?? null
                    );
                    resolve_stock_alert_notifications($pdo, $result['item_id']);
                }

                notify_user(
                    $result['borrower_id'],
                    'Your returned ' . $result['name'] . ' has been checked in. Thanks!',
                    BASE_URL . '/requisition/my_loans.php'
                );
            }

            if ($returned_results) {
                $flash_success = count($returned_results) > 1
                    ? count($returned_results) . ' items marked returned and stock restored.'
                    : 'Marked returned and stock restored.';
            } elseif (!$errors) {
                $errors[] = 'Nothing to return — those loans were already checked in.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $errors[] = 'Something went wrong recording the return. Please try again.';
        }
    }
}

// Handle extension decisions by Inventory Staff / Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extension_action'])) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $ext_action = trim($_POST['extension_action']);
        $is_approve = ($ext_action === 'approve');
        $decision_note = !empty($_POST['decision_note']) ? trim($_POST['decision_note']) : null;
        $loan_ids = isset($_POST['extension_loan_ids'])
            ? array_map('intval', (array)$_POST['extension_loan_ids'])
            : [isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0];

        $ext_success_count = 0;
        foreach ($loan_ids as $lid) {
            if ($lid <= 0) continue;
            try {
                $res = decide_tool_loan_extension($pdo, $lid, $user, $is_approve, $decision_note);
                $ext_success_count++;
            } catch (Exception $e) {
                $errors[] = "Loan #$lid: " . $e->getMessage();
            }
        }
        if ($ext_success_count > 0) {
            $flash_success = $is_approve
                ? ($ext_success_count > 1 ? "$ext_success_count loan extensions approved." : 'Loan extension approved successfully.')
                : ($ext_success_count > 1 ? "$ext_success_count loan extension requests declined." : 'Loan extension request declined.');
        }
    }
}

// Query pending extension requests for immediate review by Inventory Staff
$stmt_ext = $pdo->query(
    "SELECT tl.*, i.name AS item_name, i.item_code, i.unit,
            u.full_name AS borrower_name, u.employee_id, u.contact_number,
            a.asset_tag, r.purpose AS requisition_purpose,
            t.plate_number AS truck_plate, r.truck_plate_snapshot
     FROM tool_loans tl
     JOIN items i ON i.id = tl.item_id
     JOIN users u ON u.id = tl.borrower_id
     LEFT JOIN requisitions r ON r.id = tl.requisition_id
     LEFT JOIN trucks t ON t.id = r.truck_id
     LEFT JOIN assets a ON a.id = tl.asset_id
     WHERE tl.extension_status = 'pending' AND tl.returned_at IS NULL
     ORDER BY tl.extension_requested_at ASC"
);
$pending_extensions = $stmt_ext->fetchAll();

$stmt = $pdo->query(
    "SELECT tl.*, i.name AS item_name, i.item_code, i.unit, u.full_name AS borrower_name, u.employee_id,
            a.asset_tag, r.purpose AS requisition_purpose
     FROM tool_loans tl
     JOIN items i ON i.id = tl.item_id
     JOIN users u ON u.id = tl.borrower_id
     LEFT JOIN requisitions r ON r.id = tl.requisition_id
     LEFT JOIN assets a ON a.id = tl.asset_id
     WHERE tl.returned_at IS NULL
     ORDER BY tl.due_date ASC"
);
$loans = $stmt->fetchAll();
$late_return_counts = get_late_return_counts($pdo);

// One row per borrowable line item means one requisition released with
// several borrowed items produces several rows here. Group them back by
// requisition_id so staff can see — and act on — everything that went
// out together as a single borrow transaction, instead of a flat list
// where they look like unrelated loans. A direct/manual checkout has no
// requisition_id at all, so each one stands as its own single-item
// group instead of being lumped in with unrelated direct checkouts.
// Group order follows the ORDER BY above (soonest due date first).
$loan_groups = [];
foreach ($loans as $l) {
    $rid = $l['requisition_id'];
    $key = $rid !== null ? 'req_' . $rid : 'direct_' . $l['id'];
    $loan_groups[$key]['requisition_id'] = $rid;
    $loan_groups[$key]['purpose'] = $l['requisition_purpose'];
    $loan_groups[$key]['borrower_name'] = $l['borrower_name'];
    $loan_groups[$key]['employee_id'] = $l['employee_id'];
    $loan_groups[$key]['borrower_id'] = $l['borrower_id'];
    $loan_groups[$key]['loans'][] = $l;
}

$page_title = 'Tool Loans';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Tool borrowing &amp; due date monitoring</div>
    <h1>Active Tool Loans</h1>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>

<?php if (!empty($pending_extensions)): ?>
  <div class="card" style="border-left: 4px solid var(--amber); background: var(--surface); margin-bottom: 1.5rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--line); padding-bottom:0.6rem; margin-bottom:0.75rem;">
      <div>
        <h2 class="card-heading" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0;">
          <span>Pending Loan Extensions</span>
          <span class="badge" style="background:var(--amber-tint); color:var(--amber-dim); border:1px solid var(--amber-border); font-size:0.78rem;"><?= count($pending_extensions) ?> pending</span>
        </h2>
      </div>
    </div>
    <div class="table-responsive">
      <table class="data">
        <thead>
          <tr>
            <th style="min-width:180px;">Borrower / Trip</th>
            <th style="min-width:180px;">Tool / Asset</th>
            <th style="min-width:120px;">Current Due</th>
            <th style="min-width:150px;">Requested Extension</th>
            <th style="min-width:180px;">Driver's Reason</th>
            <th style="min-width:100px; text-align:right;">Staff Decision</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_extensions as $pe): 
            $tentative_due = date('Y-m-d', strtotime($pe['due_date'] . ' +' . (int)$pe['extension_days'] . ' days'));
          ?>
            <tr>
              <td data-label="Borrower / Trip">
                <strong><?= htmlspecialchars((string)($pe['borrower_name'] ?? '')) ?></strong> <span class="mono text-muted">(<?= htmlspecialchars((string)($pe['employee_id'] ?? '')) ?>)</span>
                <?php if (!empty($pe['contact_number'])): ?>
                  <div style="font-size:0.8rem; color:var(--ink-soft);"><?= htmlspecialchars((string)($pe['contact_number'] ?? '')) ?></div>
                <?php endif; ?>
                <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:2px;">
                  <?php if ($pe['requisition_id']): ?>
                    <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= (int)$pe['requisition_id'] ?>">Request #<?= (int)$pe['requisition_id'] ?></a>
                  <?php else: ?>
                    Direct Checkout
                  <?php endif; ?>
                  <?php $plate = $pe['truck_plate'] ?: $pe['truck_plate_snapshot']; if ($plate): ?>
                    &bull; <span class="badge role" style="font-size:0.72rem;">🚚 <?= htmlspecialchars($plate) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td data-label="Tool / Asset">
                <?= htmlspecialchars((string)($pe['item_name'] ?? '')) ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($pe['item_code'] ?? '')) ?>)</span>
                <?php if ($pe['asset_tag']): ?>
                  <span class="mono text-muted" style="font-size:0.72rem;">[<?= htmlspecialchars((string)($pe['asset_tag'] ?? '')) ?>]</span>
                <?php endif; ?>
                <div><span class="mono td-detail"><?= (int)$pe['quantity'] ?> <?= htmlspecialchars((string)($pe['unit'] ?? '')) ?></span></div>
              </td>
              <td class="mono td-detail" data-label="Current Due">
                <?= htmlspecialchars((string)($pe['due_date'] ?? '')) ?>
              </td>
              <td data-label="Requested Extension">
                <span class="badge" style="background:var(--amber-tint); color:var(--amber-dim); border:1px solid var(--amber-border); font-weight:bold; font-size:0.85rem;">+<?= (int)$pe['extension_days'] ?> <?= (int)$pe['extension_days'] === 1 ? 'day' : 'days' ?></span>
                <div style="font-size:0.78rem; color:var(--ink-soft); margin-top:2px;">New Due: <strong class="mono" style="color:var(--ink);"><?= $tentative_due ?></strong></div>
              </td>
              <td data-label="Driver's Reason" style="max-width:260px;">
                <em style="color:var(--ink); font-size:0.88rem;">&ldquo;<?= htmlspecialchars($pe['extension_reason'] ?? 'No reason provided') ?>&rdquo;</em>
                <div style="font-size:0.75rem; color:var(--ink-soft); margin-top:2px;">Requested: <?= htmlspecialchars((string)($pe['extension_requested_at'] ?? '')) ?></div>
              </td>
              <td data-label="Staff Decision" style="text-align:right; white-space:nowrap;">
                <div class="table-action-pill" style="justify-content:flex-end;">
                  <form method="post" style="display:contents;" onsubmit="return confirm('Approve +<?= (int)$pe['extension_days'] ?> days extension for <?= htmlspecialchars(addslashes($pe['item_name'])) ?>? New due date will be <?= $tentative_due ?>.');">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="extension_action" value="approve">
                    <input type="hidden" name="loan_id" value="<?= (int)$pe['id'] ?>">
                    <button type="submit" class="table-action-btn btn-action-stockin" title="Approve Extension (Aprubahan)" aria-label="Approve">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                  </form>
                  <form method="post" style="display:contents;" onsubmit="var note = prompt('Optional reason for declining:'); if (note === null) return false; this.decision_note.value = note; return true;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="extension_action" value="decline">
                    <input type="hidden" name="loan_id" value="<?= (int)$pe['id'] ?>">
                    <input type="hidden" name="decision_note" value="">
                    <button type="submit" class="table-action-btn btn-action-danger" title="Decline Extension (Tanggihan)" aria-label="Decline">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (!$loan_groups): ?>
  <div class="empty-state">No tools are currently out on loan.</div>
<?php else: ?>
  <div class="card" style="max-width:420px;">
    <h2 class="card-heading">Scan to return</h2>
    <div id="return-qr-reader" style="width:100%; max-width:360px; min-height:220px; background:var(--paper-soft, #f4ede2); border:1px dashed var(--line); border-radius:8px;"></div>
    <p id="return-qr-status" style="display:none; font-size:0.82rem; color:var(--red-danger); margin-top:0.5rem;"></p>
    <p style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.5rem;">Scan unit QR tag.</p>
    <p style="font-size:0.78rem; color:var(--ink-soft); margin-top:0.35rem;">Tip: ilayo ang QR 20&ndash;30cm.</p>
    <div style="display:flex; gap:0.5rem; margin-top:1rem;">
      <input aria-label="Asset tag" type="text" id="return-tag-input" placeholder="Enter asset tag" class="flex-1">
      <button type="button" id="return-tag-lookup" class="btn btn-outline">Look up</button>
    </div>
  </div>

  <?php foreach ($loan_groups as $group): ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:baseline; gap:1rem; border-bottom:1px solid var(--line); padding-bottom:0.6rem; margin-bottom:0.75rem;">
      <div>
        <h2 class="card-heading">
          <?php if ($group['requisition_id'] !== null): ?>
            <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= (int)$group['requisition_id'] ?>">Request #<?= (int)$group['requisition_id'] ?></a>
          <?php else: $asset_tag = $group['loans'][0]['asset_tag'] ?? null; ?>
            <?php if ($asset_tag): ?>
              <a href="<?= BASE_URL ?>/inventory/asset_view.php?tag=<?= urlencode($asset_tag) ?>">Direct checkout</a>
            <?php else: ?>
              Direct checkout
            <?php endif; ?>
            <span class="badge role" style="font-size:0.68rem;" title="Handed out directly from the asset page, outside the requisition flow">no requisition</span>
          <?php endif; ?>
          &mdash; <?= htmlspecialchars((string)($group['borrower_name'] ?? '')) ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($group['employee_id'] ?? '')) ?>)</span>
          <?php $late_ct = $late_return_counts[(int)$group['borrower_id']] ?? 0; if ($late_ct > 0): ?>
            <span class="badge role ml-sm" title="Past loans returned after their due date">⚠ <?= $late_ct ?> late before</span>
          <?php endif; ?>
        </h2>
        <?php if ($group['purpose']): ?><p class="text-muted" style="margin:0;font-size:0.85rem;"><?= htmlspecialchars((string)($group['purpose'] ?? '')) ?></p><?php endif; ?>
      </div>
      <?php if (count($group['loans']) > 1): ?>
        <form method="post" onsubmit="return confirm('Mark all <?= count($group['loans']) ?> items in this transaction as returned?');">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <?php foreach ($group['loans'] as $l): ?>
            <input type="hidden" name="return_loan_ids[]" value="<?= (int)$l['id'] ?>">
          <?php endforeach; ?>
          <button type="submit" class="btn btn-outline btn-sm">Mark all returned</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
<table class="data">
      <thead><tr><th>Item</th><th>Qty</th><th>Borrowed</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($group['loans'] as $l):
          $status = loan_status($l['due_date'], $l['returned_at'], $l['extension_status'] ?? 'none'); ?>
          <tr data-asset-tag="<?= htmlspecialchars($l['asset_tag'] ?? '') ?>" data-loan-id="<?= $l['id'] ?>">
            <td data-label="Item"><?= htmlspecialchars((string)($l['item_name'] ?? '')) ?> <span class="mono text-muted">(<?= htmlspecialchars((string)($l['item_code'] ?? '')) ?>)</span><?php if ($l['asset_tag']): ?> <span class="mono text-muted" style="font-size:0.72rem;">[<?= htmlspecialchars((string)($l['asset_tag'] ?? '')) ?>]</span><?php endif; ?></td>
            <td class="mono td-detail" data-label="Qty"><?= (int)$l['quantity'] ?> <?= htmlspecialchars((string)($l['unit'] ?? '')) ?></td>
            <td class="mono td-detail" data-label="Borrowed"><?= htmlspecialchars((string)($l['borrowed_at'] ?? '')) ?></td>
            <td class="mono td-detail" data-label="Due"><?= htmlspecialchars((string)($l['due_date'] ?? '')) ?></td>
            <td data-label="Status">
              <span class="badge <?= loan_status_class($status) ?>"><?= $status ?></span>
              <?php if (($l['extension_status'] ?? 'none') === 'pending'): ?>
                <div style="margin-top:3px;"><span class="badge" style="background:var(--amber-tint); color:var(--amber); font-size:0.7rem; border:1px solid var(--amber-border);">+<?= (int)$l['extension_days'] ?>d ext. pending</span></div>
              <?php elseif (($l['extension_status'] ?? 'none') === 'approved' && (int)$l['extension_days'] > 0): ?>
                <div style="margin-top:3px;"><span class="badge" style="background:var(--blue-tint); color:var(--blue-info); font-size:0.7rem; border:1px solid var(--blue-border);">Extended (+<?= (int)$l['extension_days'] ?>d)</span></div>
              <?php endif; ?>
            </td>
            <td data-label="" class="td-detail">
              <form method="post" class="return-loan-form" onsubmit="return confirm('Mark this loan as returned?');">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="return_loan_id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">Mark returned</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
</div>
  </div>
  <?php endforeach; ?>

  <style>
    tr.return-scan-highlight { outline: 2px solid var(--gold, #b8860b); outline-offset: -2px; background: var(--paper-soft, #f4ede2); }
  </style>

  <script src="<?= BASE_URL ?>/assets/js/html5-qrcode.min.js"></script>
  <script>
    if (typeof Html5Qrcode === 'undefined') {
      document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"><\/script>');
    }
  </script>
  <script>
    (function () {
      var readerEl = document.getElementById('return-qr-reader');
      var statusEl = document.getElementById('return-qr-status');
      var tagInput = document.getElementById('return-tag-input');
      var lookupBtn = document.getElementById('return-tag-lookup');
      if (!readerEl) return;

      function showStatus(msg, isError) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.color = isError ? 'var(--red-danger)' : 'var(--ink-soft)';
        statusEl.style.display = msg ? 'block' : 'none';
      }

      function extractTag(decodedText) {
        try {
          var url = new URL(decodedText, window.location.origin);
          var t = url.searchParams.get('tag');
          if (t) return t;
        } catch (e) { /* not a URL, fall through */ }
        return decodedText;
      }

      function handleTag(tag) {
        tag = (tag || '').trim();
        if (!tag) return;
        document.querySelectorAll('tr.return-scan-highlight').forEach(function (r) {
          r.classList.remove('return-scan-highlight');
        });
        var row = document.querySelector('tr[data-asset-tag="' + CSS.escape(tag) + '"]');
        if (!row || !row.dataset.assetTag) {
          showStatus('No active loan found for tag "' + tag + '" — it may already be returned, or wasn\'t a QR-tracked unit.', true);
          return;
        }
        showStatus('');
        row.classList.add('return-scan-highlight');
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var btn = row.querySelector('.return-loan-form button[type="submit"]');
        if (btn) btn.click();
      }

      if (tagInput && lookupBtn) {
        lookupBtn.addEventListener('click', function () { handleTag(tagInput.value); });
        tagInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); handleTag(tagInput.value); }
        });
      }

      if (typeof Html5Qrcode === 'undefined') {
        showStatus('Could not load the scanner. Check your connection and refresh, or look up the tag manually above.', true);
        return;
      }

      var isSecure = window.isSecureContext !== undefined
        ? window.isSecureContext
        : (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1');
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !isSecure) {
        showStatus('Camera access requires a secure connection or device permission. Enter the tag manually above.', true);
        return;
      }

      var qr = new Html5Qrcode('return-qr-reader');
      var videoConstraints = {
        facingMode: 'environment',
        width: { ideal: 1280 },
        height: { ideal: 720 }
      };
      var scanConfig = {
        fps: 20,
        qrbox: 220,
        videoConstraints: videoConstraints,
        useBarCodeDetectorIfSupported: false,
        experimentalFeatures: { useBarCodeDetectorIfSupported: false }
      };

      function onScan(decodedText) {
        handleTag(extractTag(decodedText));
      }

      qr.start({ facingMode: 'environment' }, scanConfig, onScan).catch(function () {
        Html5Qrcode.getCameras().then(function (cameras) {
          if (!cameras || !cameras.length) {
            showStatus('No camera was found on this device. Look up the tag manually above.', true);
            return;
          }
          var back = cameras.find(function (c) { return /back|rear|environment/i.test(c.label || ''); });
          qr.start((back || cameras[0]).id, scanConfig, onScan).catch(function (err2) {
            showStatus('Could not start the camera (' + (err2 && err2.name ? err2.name : 'unknown error') +
              '). Look up the tag manually above instead.', true);
          });
        }).catch(function () {
          showStatus('Camera permission was denied or is unavailable. Check your browser and OS camera privacy settings, or look up the tag manually above instead.', true);
        });
      });
    })();
  </script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
