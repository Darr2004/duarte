<?php
/**
 * DuaRTE — QR Code Asset Tracking: Physical audit.
 * Lists every asset expected to be on-site right now (available or
 * already under maintenance — NOT checked out, since those are
 * expected to be elsewhere) and lets Inventory Staff check off each
 * one as physically confirmed while walking the tool room. Anything
 * left unconfirmed when the audit is submitted gets flagged 'missing'
 * — the one thing quantity-only tracking could never catch, since
 * nothing distinguished individual units before.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_audit'])) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $expected_ids  = array_values(array_filter(array_map('intval', explode(',', $_POST['expected_ids'] ?? ''))));
        $confirmed_ids = array_map('intval', $_POST['confirmed'] ?? []);
        $damaged_ids   = array_map('intval', $_POST['damaged'] ?? []);
        $damage_notes  = $_POST['damage_note'] ?? [];
        $missing_ids   = array_diff($expected_ids, $confirmed_ids);

        try {
            $pdo->beginTransaction();

            $damaged_count = 0;
            foreach ($confirmed_ids as $aid) {
                if (!in_array($aid, $expected_ids, true)) {
                    continue;
                }
                record_asset_event($pdo, $aid, 'audit_confirmed', $user, 'Confirmed present during audit.');

                // Present, but not in good shape — flag it right here instead
                // of making staff finish the walk, then hunt the unit down
                // again on its own page just to report what they just saw.
                if (in_array($aid, $damaged_ids, true)) {
                    $note = trim($damage_notes[$aid] ?? '') ?: 'Flagged as damaged during physical audit.';
                    $pdo->prepare(
                        "UPDATE assets SET status = 'under_maintenance', condition_note = :note
                         WHERE id = :id AND status != 'retired'"
                    )->execute(['note' => $note, 'id' => $aid]);
                    record_asset_event($pdo, $aid, 'damage_reported', $user, $note);
                    $damaged_count++;
                }
            }
            foreach ($missing_ids as $aid) {
                $pdo->prepare("UPDATE assets SET status = 'missing' WHERE id = :id AND status != 'retired'")
                    ->execute(['id' => $aid]);
                record_asset_event($pdo, $aid, 'audit_missing', $user, 'Expected on-site but not confirmed during audit.');
            }

            log_audit_event(
                $pdo, $user, 'asset_audit', 'audit', null,
                $user['full_name'] . ' completed an asset audit — ' . count($confirmed_ids) . ' confirmed, '
                    . $damaged_count . ' flagged damaged, ' . count($missing_ids) . ' flagged missing.'
            );

            $pdo->commit();
            $flash_success = 'Audit recorded — ' . count($confirmed_ids) . ' confirmed present'
                . ($damaged_count > 0 ? ' (' . $damaged_count . ' flagged damaged)' : '')
                . (count($missing_ids) > 0 ? ', ' . count($missing_ids) . ' flagged missing.' : '.');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $errors[] = 'Something went wrong recording the audit. Please try again.';
        }
    }
}

// Expected on-site = available or already under maintenance. Checked-out
// units are expected to be elsewhere (with their borrower), so they're
// not part of "is it physically here" — that's covered by the normal
// tool-loan overdue tracking instead.
$expected = $pdo->query(
    "SELECT a.*, i.name AS item_name, i.item_code, i.unit
     FROM assets a JOIN items i ON i.id = a.item_id
     WHERE a.status IN ('available', 'under_maintenance')
     ORDER BY i.name ASC, a.asset_tag ASC"
)->fetchAll();

$page_title = 'Asset Audit';
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= BASE_URL ?>/inventory/assets.php" class="back-link">&larr; Back to Assets</a>
<div class="page-header">
  <div>
    <div class="eyebrow">QR code asset tracking</div>
    <h1>Physical Audit</h1>
  </div>
</div>

<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if (!$expected): ?>
  <div class="empty-state">Nothing expected on-site right now — every tracked asset is either checked out or retired.</div>
<?php else: ?>
  <p style="color:var(--ink-soft); font-size:0.88rem;">
    Check found assets. Unchecked = <span class="badge inactive">Missing</span>.
  </p>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="expected_ids" value="<?= htmlspecialchars(implode(',', array_column($expected, 'id'))) ?>">

    <div class="card" id="audit-scan-card">
      <h2 class="card-heading">Scan to confirm</h2>
      <p style="color:var(--ink-soft); font-size:0.85rem; margin:0 0 0.75rem;">Scan each QR tag. Rows auto-check.</p>
      <button type="button" id="audit-scan-toggle" class="btn btn-outline btn-sm">Start camera</button>
      <span id="audit-scan-count" class="badge role" style="margin-left:0.5rem;" role="status" aria-live="polite"><?= count($expected) ?> of <?= count($expected) ?> confirmed</span>
      <p id="audit-scan-hint" style="font-size:0.78rem; color:var(--ink-soft); margin-top:0.5rem;">Camera resets checks. Manual entry available.</p>
      <div id="qr-reader" style="width:100%; max-width:360px; min-height:0; background:var(--paper-soft, #f4ede2); border:1px dashed var(--line); border-radius:8px; margin-top:0.75rem; display:none;"></div>
      <p id="audit-scan-status" role="status" aria-live="polite" style="font-size:0.85rem; margin-top:0.6rem; display:none;"></p>
      <p class="qr-focus-hint-short" style="font-size:0.78rem; color:var(--ink-soft); margin-top:0.35rem;"></p>
    </div>

    <div class="card">
      <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-bottom:0.75rem;">
        <button type="button" class="btn btn-outline btn-sm" onclick="document.querySelectorAll('.audit-check').forEach(c=>c.checked=true)">Check all</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="document.querySelectorAll('.audit-check').forEach(c=>c.checked=false)">Uncheck all</button>
      </div>
      <div class="table-responsive">
<table class="data">
        <thead><tr><th style="width:2rem;">Found</th><th>Tag</th><th>Item</th><th>Qty</th><th>Current status</th><th>Location</th><th>Broken?</th></tr></thead>
        <tbody>
          <?php foreach ($expected as $a): ?>
            <tr data-tag="<?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?>" data-name="<?= htmlspecialchars($a['item_name'] . ' (' . $a['asset_tag'] . ')') ?>">
              <td><input type="checkbox" class="audit-check" name="confirmed[]" value="<?= $a['id'] ?>"
                         aria-label="Found: <?= htmlspecialchars($a['item_name'] . ' (' . $a['asset_tag'] . ')') ?>" checked></td>
              <td class="mono" data-label="Tag"><?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?></td>
              <td data-label="Item"><?= htmlspecialchars((string)($a['item_name'] ?? '')) ?> <span class="mono" class="text-muted">(<?= htmlspecialchars((string)($a['item_code'] ?? '')) ?>)</span></td>
              <td data-label="Qty"><?php if ((int)$a['quantity'] > 1): ?><?= (int)$a['quantity'] ?> <?= htmlspecialchars((string)($a['unit'] ?? '')) ?><?php else: ?><span class="text-muted">1</span><?php endif; ?></td>
              <td data-label="Current status"><span class="badge <?= asset_status_class($a['status']) ?>"><?= asset_status_label($a['status']) ?></span></td>
              <td data-label="Location" class="td-detail"><?= $a['location_note'] ? htmlspecialchars((string)($a['location_note'] ?? '')) : '—' ?></td>
              <td data-label="Broken?" class="audit-damage-cell">
                <label style="display:flex; align-items:center; gap:0.4rem; font-weight:400;">
                  <input type="checkbox" class="audit-damage-check" name="damaged[]" value="<?= $a['id'] ?>">
                  <span style="font-size:0.8rem; color:var(--ink-soft);">Broken</span>
                </label>
                <input aria-label="What is wrong with <?= htmlspecialchars((string)($a['asset_tag'] ?? '')) ?>" type="text" name="damage_note[<?= $a['id'] ?>]" placeholder="What's wrong? (optional)"
                       class="audit-damage-note" style="display:none; margin-top:0.35rem; font-size:0.82rem; padding:0.3rem 0.5rem; width:100%; min-width:160px;">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
</div>
    </div>

    <button type="submit" name="submit_audit" value="1" class="btn btn-primary" style="margin-top:1rem;"
            onclick="return confirm('Submit this audit? Anything left unchecked will be flagged Missing.');">
      Submit audit
    </button>
  </form>

  <script>
    // A unit can't be simultaneously "not here" and "here but broken" —
    // checking Broken implies Found, so keep the row's Found box in sync
    // instead of letting the two contradict each other on submit.
    document.querySelectorAll('.audit-damage-check').forEach(function (dmg) {
      var row = dmg.closest('tr');
      var foundBox = row.querySelector('.audit-check');
      var noteInput = row.querySelector('.audit-damage-note');
      dmg.addEventListener('change', function () {
        if (dmg.checked) { foundBox.checked = true; }
        noteInput.style.display = dmg.checked ? 'block' : 'none';
      });
      foundBox.addEventListener('change', function () {
        if (!foundBox.checked && dmg.checked) {
          dmg.checked = false;
          noteInput.style.display = 'none';
        }
      });
    });

    function auditSyncCount() {
      var countEl = document.getElementById('audit-scan-count');
      if (!countEl) return;
      var total = document.querySelectorAll('.audit-check').length;
      var found = document.querySelectorAll('.audit-check:checked').length;
      countEl.textContent = found + ' of ' + total + ' confirmed';
      countEl.classList.toggle('active', total > 0 && found === total);
    }
    document.querySelectorAll('.audit-check').forEach(function (c) {
      c.addEventListener('change', auditSyncCount);
    });
    auditSyncCount();
  </script>

  <script src="<?= BASE_URL ?>/assets/js/html5-qrcode.min.js"></script>
  <script>
    if (typeof Html5Qrcode === 'undefined') {
      document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"><\/script>');
    }
  </script>
  <script src="<?= BASE_URL ?>/assets/js/qr-scan-shared.js"></script>
  <script>
    DuarteQR.injectFocusHints();

    (function () {
      var toggleBtn = document.getElementById('audit-scan-toggle');
      var readerEl  = document.getElementById('qr-reader');
      var statusEl  = document.getElementById('audit-scan-status');
      if (!toggleBtn || !readerEl) return;

      function showStatus(msg, color) {
        statusEl.textContent = msg;
        statusEl.style.color = color || 'var(--ink-soft)';
        statusEl.style.display = 'block';
      }

      if (!DuarteQR.checkPrereqs(function (msg) {
        toggleBtn.disabled = true;
        showStatus(msg.replace('Use manual entry below instead.', 'Check assets off by hand below instead.'), 'var(--red-danger)');
      })) {
        return;
      }

      var qr = null;
      var running = false;
      var lastTag = null;
      var lastTime = 0;

      function onScan(decodedText) {
        var tag = DuarteQR.extractParam(decodedText, 'tag');
        var now = Date.now();
        // Same tag scanned again within 2s is the camera re-reading the
        // same still-in-frame label, not a second physical unit — ignore
        // the repeat instead of re-flashing the same confirmation.
        if (tag === lastTag && now - lastTime < 2000) return;
        lastTag = tag;
        lastTime = now;

        var row = document.querySelector('tr[data-tag="' + CSS.escape(tag) + '"]');
        if (!row) {
          DuarteQR.playErrorFeedback();
          showStatus('Not on the expected list: ' + tag + ' — already checked out, retired, or not a tracked asset.', 'var(--red-danger)');
          return;
        }
        DuarteQR.playSuccessFeedback();
        var box = row.querySelector('.audit-check');
        var already = box.checked;
        box.checked = true;
        auditSyncCount();
        row.style.transition = 'background-color 0.2s';
        row.style.backgroundColor = 'var(--amber-tint, #F3E3CC)';
        setTimeout(function () { row.style.backgroundColor = ''; }, 700);
        showStatus((already ? 'Already confirmed — ' : '\u2713 Confirmed: ') + row.dataset.name, 'var(--green-ok)');

        // Only nudge the scroll if the row is genuinely off-screen — a
        // forced scrollIntoView after every single scan pulls the page
        // away from the camera preview itself, which defeats the point
        // of "just keep scanning without touching anything."
        var rect = row.getBoundingClientRect();
        var visible = rect.top >= 0 && rect.bottom <= (window.innerHeight || document.documentElement.clientHeight);
        if (!visible) {
          row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }

      var scanConfig = DuarteQR.buildScanConfig(220);

      function beginScanMode() {
        // Switch the row from "assume present, uncheck exceptions" to
        // "assume absent, scan proves presence" — otherwise every row is
        // pre-checked already and scanning confirms nothing. Manual
        // checkboxes stay available right after for whatever the camera
        // physically can't reach.
        document.querySelectorAll('.audit-check').forEach(function (c) { c.checked = false; });
        auditSyncCount();
        var hint = document.getElementById('audit-scan-hint');
        if (hint) hint.textContent = 'Scan mode active. Check manually if needed.';
      }

      function startScanner() {
        // Not unchecked yet here on purpose — only once the camera has
        // actually started (below). If getUserMedia fails, the audit
        // must be left exactly as it was, not stripped of its defaults
        // with no working camera to rebuild them.
        qr = new Html5Qrcode('qr-reader');
        readerEl.style.display = 'block';
        DuarteQR.startWithFallback(qr, scanConfig, onScan, {
          onStarted: function () {
            running = true;
            toggleBtn.textContent = 'Stop camera';
            showStatus('Camera running — scan each asset as you find it.');
            beginScanMode();
          },
          onNoCamera: function () {
            showStatus('No camera was found on this device. Check assets off by hand below.', 'var(--red-danger)');
            readerEl.style.display = 'none';
          },
          onError: function (err2) {
            showStatus('Could not start the camera (' + (err2 && err2.name ? err2.name : 'unknown error') +
              '). It may be in use by another app or tab, or permission was denied.', 'var(--red-danger)');
            readerEl.style.display = 'none';
          },
          onDenied: function () {
            showStatus('Camera permission was denied or is unavailable. Allow camera access in your browser — if it\'s already allowed there, check your OS camera privacy setting too.', 'var(--red-danger)');
            readerEl.style.display = 'none';
          }
        });
      }

      function stopScanner() {
        if (!qr || !running) { running = false; readerEl.style.display = 'none'; toggleBtn.textContent = 'Start camera'; return; }
        qr.stop().then(function () {
          qr.clear();
          running = false;
          readerEl.style.display = 'none';
          toggleBtn.textContent = 'Start camera';
        }).catch(function () {
          running = false;
          readerEl.style.display = 'none';
          toggleBtn.textContent = 'Start camera';
        });
      }

      toggleBtn.addEventListener('click', function () {
        if (running) { stopScanner(); } else { startScanner(); }
      });

      // Stop the camera on submit AND on navigating away (e.g. tapping
      // "Back to Assets" mid-walk) — leaving the stream open past either
      // one keeps the device "in use" for other apps/tabs for no reason.
      var form = toggleBtn.closest('form') || document.querySelector('form');
      if (form) {
        form.addEventListener('submit', function () { if (running) stopScanner(); });
      }
      window.addEventListener('pagehide', function () { if (running) stopScanner(); });
    })();
  </script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
