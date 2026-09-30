<?php
/**
 * DuaRTE — Universal scanner.
 *
 * One camera, works for both QR types Inventory Staff deal with:
 *   - a requisition pickup code  (verify.php?token=...)
 *   - an asset tag               (asset_view.php?tag=...)
 *
 * Both are already full URLs pointing at the right page, so this page
 * mostly just decodes, reads which param the URL carries, and forwards
 * the browser there. Anything that doesn't match one of the two shapes
 * this app actually generates — a URL with our token/tag param, a bare
 * 32-char hex token (generate_unique_qr_token()), or a bare AST-xxxxxxxxxxxx
 * tag (generate_unique_asset_tag()) — is reported as an unrecognized
 * code rather than guessed at, since a QR from outside the system could
 * be any shape at all.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$manual_error = null;

// Manual entry submits here as a GET so the target URL stays shareable/back-button-safe.
if (isset($_GET['code']) && trim($_GET['code']) !== '') {
    $code = trim($_GET['code']);

    // Someone pasted the full scanned URL instead of just the code —
    // pull the token/tag/item code back out rather than treating the whole
    // pasted URL as a bare code.
    if (preg_match('#^https?://#i', $code) || strpos($code, '/') !== false) {
        if (preg_match('/[?&]token=([^&]+)/', $code, $m)) {
            header('Location: ' . BASE_URL . '/inventory/verify.php?token=' . urlencode(urldecode($m[1])));
            exit;
        }
        if (preg_match('/[?&]tag=([^&]+)/', $code, $m)) {
            header('Location: ' . BASE_URL . '/inventory/asset_view.php?tag=' . urlencode(urldecode($m[1])));
            exit;
        }
        if (preg_match('/[?&](?:item_code|code|q)=([^&]+)/', $code, $m)) {
            header('Location: ' . BASE_URL . '/inventory/items.php?q=' . urlencode(urldecode($m[1])));
            exit;
        }
        $manual_error = "That doesn't look like a DuaRTE QR code or link — nothing to match it against.";
    } elseif (preg_match('/^AST-[0-9a-f]{12}$/i', $code)) {
        // Matches generate_unique_asset_tag() in includes/assets.php.
        header('Location: ' . BASE_URL . '/inventory/asset_view.php?tag=' . urlencode(strtoupper($code)));
        exit;
    } elseif (preg_match('/^[0-9a-f]{32}$/i', $code)) {
        // Matches generate_unique_qr_token() in includes/functions.php.
        header('Location: ' . BASE_URL . '/inventory/verify.php?token=' . urlencode(strtolower($code)));
        exit;
    } else {
        // Check if it matches a catalog item code
        $item_stmt = $pdo->prepare('SELECT id, item_code FROM items WHERE item_code = :c LIMIT 1');
        $item_stmt->execute(['c' => $code]);
        $item_row = $item_stmt->fetch();
        if ($item_row) {
            header('Location: ' . BASE_URL . '/inventory/items.php?q=' . urlencode($item_row['item_code']));
            exit;
        }

        $manual_error = "That doesn't look like a DuaRTE QR code — it doesn't match a requisition code, an asset tag, or an item code.";
    }
}

$page_title = 'Scan';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">QR code scanning</div>
    <h1>Scan</h1>
  </div>
</div>

<div class="card" style="max-width:420px;">
  <h2 class="card-heading">Point the camera at any QR code</h2>
  <p style="font-size:0.85rem; color:var(--ink-soft); margin:-0.25rem 0 0.75rem;">
    Works for both a requester's pickup code and an item's asset tag — you'll land on the right page automatically.
  </p>

  <button type="button" id="scan-toggle" class="btn btn-outline btn-sm">Start camera</button>
  <div id="qr-reader" style="width:100%; max-width:360px; min-height:0; background:var(--paper-soft, #f4ede2); border:1px dashed var(--line); border-radius:8px; margin-top:0.75rem; display:none;"></div>
  <p id="scan-status" role="status" aria-live="polite" style="font-size:0.85rem; margin-top:0.6rem; display:none;"></p>
  <p class="qr-focus-hint-long" style="font-size:0.78rem; color:var(--ink-soft); margin-top:0.35rem;"></p>

  <?php if ($manual_error): ?>
    <p style="font-size:0.85rem; color:var(--red-danger); margin-top:0.6rem;"><?= htmlspecialchars($manual_error) ?></p>
  <?php endif; ?>

  <form method="get" style="display:flex; gap:0.5rem; margin-top:1rem;">
    <input aria-label="QR code" type="text" name="code" placeholder="Paste code here" class="flex-1" value="<?= isset($_GET['code']) ? htmlspecialchars((string)($_GET['code'] ?? '')) : '' ?>">
    <button type="submit" class="btn btn-outline">Go</button>
  </form>
</div>

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
    var toggleBtn = document.getElementById('scan-toggle');
    var readerEl  = document.getElementById('qr-reader');
    var statusEl  = document.getElementById('scan-status');
    if (!toggleBtn || !readerEl) return;

    function showStatus(msg, color) {
      statusEl.textContent = msg;
      statusEl.style.color = color || 'var(--ink-soft)';
      statusEl.style.display = 'block';
    }

    if (!DuarteQR.checkPrereqs(showStatus)) {
      toggleBtn.disabled = true;
      return;
    }

    var BASE = <?= json_encode(BASE_URL) ?>;
    var qr = null;
    var running = false;
    var routed = false; // guards against firing twice on the same frame

    // Both QR types the app prints are already full URLs to the right
    // page (verify.php?token=... or asset_view.php?tag=...). Anything
    // that doesn't decode to one of our two recognized shapes — a URL
    // carrying our token/tag param, a bare 32-char hex token, or a bare
    // AST-xxxxxxxxxxxx tag — is treated as NOT ours, so a stray QR code
    // (a poster, a product label, someone else's app) gets a clear
    // "not a DuaRTE code" message instead of being guessed at and
    // silently forwarded to verify.php for a generic "not found" later.
    function routeScan(decodedText) {
      if (routed) return;
      var text = (decodedText || '').trim();
      if (!text) return;

      var classified = DuarteQR.classifyCode(text);
      if (!classified) {
        // Not routed=true here — let scanning continue, since this is
        // most likely a stray/unrelated code, not the one the staff
        // member meant to point the camera at.
        DuarteQR.playErrorFeedback();
        showStatus('That doesn\'t look like a DuaRTE QR code — keep scanning, or use manual entry below.', 'var(--red-danger)');
        return;
      }

      routed = true;
      DuarteQR.playSuccessFeedback();
      if (classified.type === 'tag') {
        showStatus('Asset tag found — opening asset details…', 'var(--green-ok, #2e7d32)');
        window.location.href = BASE + '/inventory/asset_view.php?tag=' + encodeURIComponent(classified.value);
      } else {
        showStatus('Requisition code found — opening Verify & Release…', 'var(--green-ok, #2e7d32)');
        window.location.href = BASE + '/inventory/verify.php?token=' + encodeURIComponent(classified.value);
      }
    }

    toggleBtn.addEventListener('click', function () {
      if (running) {
        if (qr) { qr.stop().then(function () { qr.clear(); }).catch(function () {}); }
        running = false;
        readerEl.style.display = 'none';
        toggleBtn.textContent = 'Start camera';
        return;
      }

      routed = false;
      readerEl.style.display = 'block';
      toggleBtn.textContent = 'Stop camera';
      statusEl.style.display = 'none';

      qr = new Html5Qrcode('qr-reader');
      var scanConfig = DuarteQR.buildScanConfig(240);

      DuarteQR.startWithFallback(qr, scanConfig, routeScan, {
        onStarted: function () { running = true; },
        onNoCamera: function () {
          showStatus('No camera found on this device. Use manual entry below.', 'var(--red-danger)');
          readerEl.style.display = 'none';
          toggleBtn.textContent = 'Start camera';
        },
        onDenied: function () {
          showStatus('Camera access denied. Allow it in your browser settings — if it\'s already allowed there, your OS camera privacy setting may be blocking it too. Or use manual entry below.', 'var(--red-danger)');
          readerEl.style.display = 'none';
          toggleBtn.textContent = 'Start camera';
        },
        onError: function () {
          showStatus('Could not start the camera. Use manual entry below.', 'var(--red-danger)');
          readerEl.style.display = 'none';
          toggleBtn.textContent = 'Start camera';
        }
      });
    });
  })();
</script>
