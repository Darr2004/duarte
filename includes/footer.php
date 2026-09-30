  </main>
</div>

<?php if ($user): ?>
<?php
// Bottom tab bar (mobile only, see CSS) — the same idea as Shopee/TikTok's
// icon-only bottom nav: a handful of one-tap destinations instead of
// having to open the hamburger drawer for every page change. Each role
// gets its 3-4 most-used links as dedicated tabs; everything else in that
// role's menu (plus notifications, profile, and log out) stays reachable
// through the last "More" tab, which just opens the existing off-canvas
// drawer — no separate menu to maintain.
$bn_items = [];
if ($user['role'] === 'admin') {
    $bn_items = [
        ['href' => '/admin/dashboard.php', 'match' => ['dashboard.php'], 'label' => 'Dashboard',
         'icon' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/>'],
        ['href' => '/admin/users.php', 'match' => ['users.php','user_add.php','user_edit.php'], 'label' => 'Users',
         'icon' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 8a3 3 0 1 1 3 3"/><path d="M15 14.5c2.6.6 4.5 2.7 4.5 5.5"/>'],
        ['href' => '/admin/audit_logs.php', 'match' => ['audit_logs.php','audit_logs_export.php'], 'label' => 'Audit Logs',
         'icon' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>'],
        ['href' => '/reports/index.php', 'match' => null, 'prefix' => '/reports/', 'label' => 'Reports',
         'icon' => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M3 20h18"/>'],
    ];
} elseif ($user['role'] === 'inventory_staff') {
    $bn_items = [
        ['href' => '/inventory/dashboard.php', 'match' => ['dashboard.php'], 'label' => 'Dashboard',
         'icon' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/>'],
        ['href' => '/inventory/items.php', 'match' => ['items.php','item_add.php','item_edit.php'], 'label' => 'Catalog',
         'icon' => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>'],
        ['href' => '/inventory/loans.php', 'match' => ['loans.php'], 'label' => 'Loans', 'count' => $overdue_loan_count,
         'icon' => '<path d="m14.7 6.3-1.4-1.4a2 2 0 0 0-2.8 0L3.9 11.5a2 2 0 0 0 0 2.8l1.4 1.4a2 2 0 0 0 2.8 0l6.6-6.6a2 2 0 0 0 0-2.8Z"/><path d="m9 9 6 6"/><path d="m17.5 3.5 3 3"/>'],
    ];
} elseif (in_array($user['role'], ['driver_helper', 'field_supervisor'], true)) {
    $bn_items = [
        ['href' => $user['role'] === 'driver_helper' ? '/requisition/home.php' : '/requisition/dashboard.php',
         'match' => ['home.php','dashboard.php'], 'label' => 'Dashboard',
         'icon' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/>'],
        ['href' => '/catalog/browse.php', 'match' => ['browse.php'], 'label' => 'Catalog',
         'icon' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'],
    ];
    if ($user['role'] === 'driver_helper') {
        $bn_items[] = ['href' => '/requisition/my_requests.php', 'match' => ['my_requests.php'], 'label' => 'Requests',
            'icon' => '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M9 3v2h6V3"/><path d="M9 11h6"/><path d="M9 15h4"/>'];
        $bn_items[] = ['href' => '/requisition/my_loans.php', 'match' => ['my_loans.php'], 'label' => 'My Tools',
            'icon' => '<path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 8V5a2 2 0 0 1 2-2h3"/><path d="M21 16v3a2 2 0 0 1-2 2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><rect x="7" y="7" width="10" height="10" rx="1"/>'];
    } else {
        $bn_items[] = ['href' => '/requisition/pending.php', 'match' => ['pending.php'], 'label' => 'Approvals', 'count' => $pending_approval_count,
            'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>'];
        $bn_items[] = ['href' => '/requisition/all.php', 'match' => ['all.php'], 'label' => 'All Requests',
            'icon' => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>'];
    }
}
?>
<?php if ($bn_items): ?>
<nav class="bottom-nav" aria-label="Primary">
  <?php foreach ($bn_items as $item):
    $is_active = $item['match'] ? in_array($nav_script, $item['match'], true)
               : (isset($item['prefix']) && str_starts_with($_SERVER['SCRIPT_NAME'], BASE_URL . $item['prefix']));
    $count = $item['count'] ?? 0;
  ?>
    <a href="<?= BASE_URL . $item['href'] ?>" class="bottom-nav-item<?= $is_active ? ' active' : '' ?>">
      <span class="bottom-nav-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $item['icon'] ?></svg>
        <?php if ($count > 0): ?><span class="badge inactive bottom-nav-badge"><?= $count > 9 ? '9+' : $count ?></span><?php endif; ?>
      </span>
      <span class="bottom-nav-label"><?= htmlspecialchars((string)($item['label'] ?? '')) ?></span>
    </a>
  <?php endforeach; ?>
  <button type="button" class="bottom-nav-item" id="bottomNavMore">
    <span class="bottom-nav-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
      <?php $bn_more_count = $unread_count + $cart_count; ?>
      <?php if ($bn_more_count > 0): ?><span class="badge inactive bottom-nav-badge"><?= $bn_more_count > 9 ? '9+' : $bn_more_count ?></span><?php endif; ?>
    </span>
    <span class="bottom-nav-label">More</span>
  </button>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php if ($user): ?>
<div class="notif-modal-backdrop" id="notifModalBackdrop"></div>
<div class="notif-modal" id="notifModal" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Notifications">
  <div class="notif-modal-header">
    <h2>Notifications</h2>
    <button type="button" class="item-modal-close notif-modal-close" id="notifModalClose" aria-label="Close">&times;</button>
  </div>
  <div class="notif-modal-body" id="notifModalBody">
    <div class="empty-state">Loading…</div>
  </div>
</div>
<?php endif; ?>

<?php if ($user && in_array($user['role'], ['inventory_staff', 'admin'], true)): ?>
<div class="variant-modal-backdrop" id="scanModalBackdrop"></div>
<div class="variant-modal variant-modal-medium" id="scanModal" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Scan QR code">
  <button type="button" class="variant-modal-close" id="scanModalClose" aria-label="Close">&times;</button>
  <div class="variant-modal-body">
    <h2 class="variant-modal-title">Scan QR code</h2>
    <p class="scan-modal-subtitle">Pickup code or asset tag.</p>
    <div class="scan-reader-wrap">
      <div id="scanModalReader"></div>
      <div class="scan-guide" aria-hidden="true">
        <span class="scan-guide-corner scan-guide-tl"></span>
        <span class="scan-guide-corner scan-guide-tr"></span>
        <span class="scan-guide-corner scan-guide-bl"></span>
        <span class="scan-guide-corner scan-guide-br"></span>
      </div>
    </div>
    <p id="scanModalStatus" class="scan-modal-status" role="status" aria-live="polite"></p>
    <p class="scan-guide-resize-hint">
      <span class="qr-focus-hint-short"></span>
      <button type="button" class="scan-guide-reset" id="scanGuideReset">Reset size</button>
    </p>
    <form method="get" action="<?= BASE_URL ?>/inventory/scan.php" class="scan-modal-form">
      <input aria-label="QR code" type="text" name="code" placeholder="Paste code here" class="flex-1">
      <button type="submit" class="btn btn-outline">Go</button>
    </form>
  </div>
</div>
<?php endif; ?>


<?php if ($user && in_array($user['role'], ['driver_helper', 'field_supervisor'], true)):
  require_once __DIR__ . '/functions.php';
  $drawer_trucks = function_exists('get_all_trucks') ? get_all_trucks(get_db()) : [];
?>
<div class="cart-backdrop" id="cartBackdrop"></div>
<div class="cart-drawer" id="cartDrawer" aria-hidden="true">
  <div class="cart-drawer-header">
    <h2>Your Cart</h2>
    <button type="button" class="cart-drawer-close" id="cartDrawerClose" aria-label="Close cart">&times;</button>
  </div>
  <div class="cart-drawer-body" id="cartDrawerBody">
    <div class="cart-empty">Loading…</div>
  </div>
  <div class="cart-drawer-footer">
    <div id="cartDrawerError" class="cart-drawer-error hidden"></div>
    <div class="form-group" style="margin-bottom:0.75rem;">
      <label for="cartTruckId" style="display:block; font-size:0.82rem; font-weight:600; color:var(--ink); margin-bottom:0.35rem;">
        <span>🚚 Assign to Truck (Plate Number)</span> <span id="cartTruckReqMark" style="color:var(--red-danger);" aria-hidden="true">*</span>
      </label>
      <select id="cartTruckId" aria-describedby="cartTruckReqMark" style="width:100%; padding:0.55rem 0.65rem; border:1px solid var(--line); border-radius:6px; font-size:0.85rem; background:var(--surface); font-family:var(--font-body); color:var(--ink);">
        <option value="">-- Choose Truck Plate Number --</option>
        <?php foreach ($drawer_trucks as $trk):
          $st_info = truck_status_info($trk['status']);
        ?>
          <option value="<?= (int)$trk['id'] ?>" data-status="<?= htmlspecialchars($trk['status']) ?>">
            <?= htmlspecialchars((string)($trk['plate_number'] ?? '')) ?> — <?= htmlspecialchars((string)($trk['model'] ?? '')) ?> [<?= htmlspecialchars((string)($st_info['label'] ?? '')) ?>]
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" id="cartMaintReqGroup" style="margin-bottom:0.75rem; display:none;">
      <label style="display:flex; align-items:flex-start; gap:0.4rem; font-weight:500; font-size:0.85rem;">
        <input type="checkbox" id="cartIsMaintenanceRequest" value="1" style="margin-top:0.2rem;">
        <span>Truck maintenance request</span>
      </label>
      <div id="cartMaintReqHint" style="font-size:0.78rem; color:var(--ink-soft, #666); margin:0.25rem 0 0 1.4rem;"></div>
    </div>
    <textarea aria-label="Purpose or notes" id="cartPurpose" rows="2" placeholder="Notes (optional)"></textarea>
    <button type="button" class="btn btn-primary" id="cartSubmitBtn">Submit Requisition</button>
  </div>
</div>

<script>
  (function () {
    var drawer   = document.getElementById('cartDrawer');
    var backdrop = document.getElementById('cartBackdrop');
    var body     = document.getElementById('cartDrawerBody');
    var errorBox = document.getElementById('cartDrawerError');
    var navLink  = document.getElementById('cartNavLink');
    var closeBtn = document.getElementById('cartDrawerClose');
    var submitBtn = document.getElementById('cartSubmitBtn');
    var badge    = document.getElementById('cartBadge');
    if (!drawer || !navLink) return;

    var csrfToken = <?= json_encode(csrf_token()) ?>;
    var apiUrl = <?= json_encode(BASE_URL . '/requisition/cart_api.php') ?>;

    function api(action, extra) {
      var params = new URLSearchParams(Object.assign({ action: action, csrf_token: csrfToken }, extra || {}));
      return fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      }).then(function (r) { return r.json(); });
    }

    function updateBadge(count) {
      if (!badge) return;
      if (count > 0) {
        badge.textContent = count;
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
      badge.classList.remove('cart-badge-pulse');
      void badge.offsetWidth; // restart animation
      badge.classList.add('cart-badge-pulse');
    }

    function showError(msg) {
      if (!msg) { errorBox.style.display = 'none'; return; }
      errorBox.textContent = msg;
      errorBox.style.display = 'block';
    }

    // Truck is only required when the cart holds items that need one
    // (same rule as requisition/cart.php); the fragment tells us.
    function cartNeedsTruck() {
      return !!body.querySelector('[data-needs-truck="1"]');
    }

    function renderBody(html) {
      body.innerHTML = html;
      var need = cartNeedsTruck();
      var mark = document.getElementById('cartTruckReqMark');
      var sel = document.getElementById('cartTruckId');
      if (mark) mark.style.display = need ? '' : 'none';
      if (sel && sel.options.length) {
        sel.options[0].textContent = need ? '-- Choose Truck Plate Number --' : '-- No truck --';
      }
    }

    function openDrawer() {
      drawer.classList.add('open');
      backdrop.classList.add('open');
      drawer.setAttribute('aria-hidden', 'false');
      showError('');
      api('list').then(function (res) {
        if (res.success) {
          renderBody(res.html);
        } else {
          renderBody('<div class="cart-empty">Could not load your cart.</div>');
          showError(res.error || 'Could not load your cart. Please refresh the page.');
        }
      }).catch(function (err) {
        renderBody('<div class="cart-empty">Could not load your cart.</div>');
        showError('Could not reach the server. Please check your connection and refresh the page.');
        console.error('cart_api list failed:', err);
      });
    }

    function closeDrawer() {
      drawer.classList.remove('open');
      backdrop.classList.remove('open');
      drawer.setAttribute('aria-hidden', 'true');
    }

    navLink.addEventListener('click', function (e) {
      e.preventDefault();
      openDrawer();
    });
    closeBtn.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);

    // Show "Para sa pag-aayos ng truck" for ANY selected truck (parts for
    // an Available truck are a valid repair request too). Auto-check it
    // when the chosen truck is Under Maintenance.
    var truckSelect = document.getElementById('cartTruckId');
    var maintGroup = document.getElementById('cartMaintReqGroup');
    var maintCheckbox = document.getElementById('cartIsMaintenanceRequest');
    var maintHint = document.getElementById('cartMaintReqHint');
    function syncMaintCheckbox(fromUser) {
      if (!truckSelect || !maintGroup || !maintCheckbox) return;
      var opt = truckSelect.options[truckSelect.selectedIndex];
      var status = opt ? (opt.dataset.status || '') : '';
      var hasTruck = !!opt && opt.value !== '';
      maintGroup.style.display = hasTruck ? '' : 'none';
      if (!hasTruck) { maintCheckbox.checked = false; return; }
      if (status === 'under_maintenance') {
        if (fromUser === true) maintCheckbox.checked = true;
        if (maintHint) maintHint.textContent = 'Repair only.';
      } else if (status === 'on_trip') {
        if (maintHint) maintHint.textContent = 'Verify if repair.';
      } else {
        if (maintHint) maintHint.textContent = 'e.g. Pads, bulbs, rims';
      }
    }
    if (truckSelect) {
      truckSelect.addEventListener('change', function () { syncMaintCheckbox(true); });
      syncMaintCheckbox(false);
    }

    // Delegate qty +/- and remove clicks inside the drawer body
    body.addEventListener('click', function (e) {
      var line = e.target.closest('.cart-line');
      if (!line) return;
      var itemId = line.getAttribute('data-item-id');

      if (e.target.classList.contains('qty-inc') || e.target.classList.contains('qty-dec')) {
        var numEl = line.querySelector('.qty-num');
        var current = parseInt(numEl.textContent, 10) || 1;
        var next = e.target.classList.contains('qty-inc') ? current + 1 : current - 1;
        if (next < 1) next = 1;
        showError('');
        api('update', { item_id: itemId, qty: next }).then(function (res) {
          if (!res.success) showError(res.error);
          if (res.html) renderBody(res.html);
          updateBadge(res.cart_count);
        });
      }

      if (e.target.classList.contains('remove-line')) {
        showError('');
        api('remove', { item_id: itemId }).then(function (res) {
          renderBody(res.html);
          updateBadge(res.cart_count);
        });
      }
    });

    submitBtn.addEventListener('click', function () {
      showError('');
      var truckEl = document.getElementById('cartTruckId');
      var truckId = truckEl ? truckEl.value.trim() : '';
      if (!truckId && cartNeedsTruck()) {
        showError('Please select which Truck (Plate Number) this request is for.');
        if (truckEl) truckEl.focus();
        return;
      }
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting…';
      api('submit', {
        purpose: document.getElementById('cartPurpose').value,
        truck_id: truckId,
        is_maintenance_request: (maintCheckbox && maintCheckbox.checked) ? 1 : 0
      }).then(function (res) {
        if (res.success) {
          window.location.href = res.redirect;
        } else {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Submit requisition for approval';
          showError(res.error);
          if (res.html) renderBody(res.html);
        }
      });
    });

    // Wire up "Add to cart" forms on the catalog page (progressive
    // enhancement — without JS these forms still POST normally).
    document.querySelectorAll('form.js-add-to-cart').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        var originalText = btn.textContent;
        var data = new FormData(form);
        api('add', {
          item_id: data.get('add_item_id'),
          qty: data.get('add_qty'),
          mode: data.get('add_mode') || '',
          days: data.get('add_days') || '',
          variant: data.get('add_variant') || ''
        }).then(function (res) {
          if (res.success) {
            btn.textContent = 'Added ✓';
            btn.classList.add('btn-add-success');
            updateBadge(res.cart_count);
            var modal = form.closest('#itemModal');
            setTimeout(function () {
              btn.textContent = originalText;
              btn.classList.remove('btn-add-success');
              if (modal) {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                var mb = document.getElementById('itemModalBackdrop');
                if (mb) mb.classList.remove('open');
              }
            }, 1100);
          } else {
            alert(res.error);
          }
        });
      });
    });
  })();
</script>
<?php endif; ?>

<script>
  (function () {
    // Sidebar sub-menus (e.g. Reports & Analytics → Overview / Utilization)
    // stay collapsed until the chevron is actually clicked/tapped, so the
    // submenu items don't sit permanently visible under the parent link.
    // (CSS still force-opens the group when the person is already on one
    // of its sub-pages, so the active link is never hidden on load.)
    document.querySelectorAll('.nav-group').forEach(function (group) {
      var toggle = group.querySelector('.nav-parent-toggle');
      if (!toggle) return;
      function toggleGroup(e) {
        e.preventDefault();
        e.stopPropagation();
        var isOpen = group.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }
      toggle.setAttribute('aria-expanded', group.classList.contains('is-open') ? 'true' : 'false');
      toggle.addEventListener('click', toggleGroup);
      toggle.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') toggleGroup(e);
      });
    });
  })();
</script>

<script>
  (function () {
    var navBtn = document.getElementById('navToggle');
    var nav = document.getElementById('sidebarNav');
    var navClose = document.getElementById('sidebarNavClose');
    var profileBtn = document.getElementById('profileToggle');
    var profile = document.getElementById('userChip');
    var backdrop = document.getElementById('navBackdrop');
    var notifBtns = document.querySelectorAll('.notif-toggle');
    var notifModal = document.getElementById('notifModal');
    var notifBackdrop = document.getElementById('notifModalBackdrop');
    var notifClose = document.getElementById('notifModalClose');
    var notifBody = document.getElementById('notifModalBody');

    function escapeHtml(str) {
      var div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }

    function renderNotifs(items) {
      if (!items.length) {
        notifBody.innerHTML = '<div class="empty-state">Nothing here yet.</div>';
        return;
      }
      var html = items.map(function (n) {
        var tag = n.link ? 'a' : 'div';
        var hrefAttr = n.link ? ' href="' + escapeHtml(n.link) + '"' : '';
        var cls = 'notif-item' + (n.is_read ? '' : ' notif-item-unread') + (n.link ? ' notif-item-link' : '');
        return (
          '<' + tag + ' class="' + cls + '"' + hrefAttr + '>' +
            '<div class="notif-item-message">' + escapeHtml(n.message) + '</div>' +
            '<div class="notif-item-time mono">' + escapeHtml(n.created_at) + '</div>' +
          '</' + tag + '>'
        );
      }).join('');
      notifBody.innerHTML = html;
    }

    // Anchors the notification dropdown just under/beside whichever
    // bell button (sidebar or page-header) triggered it, instead of
    // dead-center on the screen. Skipped on mobile, where the panel
    // is a full-screen drawer instead (see the max-width:640 CSS).
    function positionNotifModal(btn) {
      if (!notifModal || !btn) return;
      if (window.innerWidth <= 640) {
        notifModal.style.top = '';
        notifModal.style.right = '';
        return;
      }
      var rect = btn.getBoundingClientRect();
      var gap = 10;
      notifModal.style.top = (rect.bottom + gap) + 'px';
      notifModal.style.right = Math.max(12, window.innerWidth - rect.right) + 'px';
      notifModal.style.left = 'auto';
    }

    function openNotifModal() {
      if (!notifModal || !notifBackdrop) return;
      notifModal.classList.add('open');
      notifBackdrop.classList.add('open');
      notifModal.setAttribute('aria-hidden', 'false');
      fetch(<?= json_encode(BASE_URL . '/notifications/api.php') ?> + '?action=list')
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            renderNotifs(res.items);
            notifBtns.forEach(function (btn) {
              var badge = btn.querySelector('.notif-toggle-badge');
              if (badge) badge.remove();
              btn.classList.remove('has-unread');
            });
          } else {
            notifBody.innerHTML = '<div class="empty-state">Couldn\'t load notifications.</div>';
          }
        })
        .catch(function () {
          notifBody.innerHTML = '<div class="empty-state">Couldn\'t load notifications.</div>';
        });
    }

    function closeNotifModal() {
      if (!notifModal || !notifBackdrop) return;
      notifModal.classList.remove('open');
      notifBackdrop.classList.remove('open');
      notifModal.setAttribute('aria-hidden', 'true');
    }

    notifBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAll();
        positionNotifModal(btn);
        openNotifModal();
      });
    });
    if (notifClose) notifClose.addEventListener('click', closeNotifModal);
    if (notifBackdrop) notifBackdrop.addEventListener('click', closeNotifModal);
    if (notifBody) {
      notifBody.addEventListener('click', function (e) {
        if (e.target.closest('a')) closeNotifModal();
      });
    }

    function setPanel(btn, panel, open) {
      if (!btn || !panel) return;
      panel.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeAll() {
      setPanel(navBtn, nav, false);
      setPanel(profileBtn, profile, false);
      if (backdrop) backdrop.classList.remove('open');
      closeNotifModal();
    }

    function openOnly(btn, panel) {
      setPanel(navBtn, nav, panel === nav);
      setPanel(profileBtn, profile, panel === profile);
      if (backdrop) backdrop.classList.add('open');
    }

    navBtn.addEventListener('click', function () {
      nav.classList.contains('open') ? closeAll() : openOnly(navBtn, nav);
    });

    // Bottom tab bar's "More" button opens the exact same drawer as the
    // (now hidden-on-mobile) hamburger button, so there's only one nav
    // drawer implementation to keep in sync.
    var bottomMore = document.getElementById('bottomNavMore');
    if (bottomMore) {
      bottomMore.addEventListener('click', function () {
        nav.classList.contains('open') ? closeAll() : openOnly(navBtn, nav);
      });
    }

    if (navClose) {
      navClose.addEventListener('click', closeAll);
    }

    if (profileBtn && profile) {
      profileBtn.addEventListener('click', function () {
        profile.classList.contains('open') ? closeAll() : openOnly(profileBtn, profile);
      });
    }

    if (backdrop) {
      backdrop.addEventListener('click', closeAll);
    }

    // Tapping a link inside either floating panel should close it too
    nav.addEventListener('click', function (e) {
      if (e.target.closest('a')) closeAll();
    });
    if (profile) {
      profile.addEventListener('click', function (e) {
        if (e.target.closest('a')) closeAll();
      });
    }

    // Escape key closes it, same as the other modals on this site
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeAll();
    });
  })();

  // Mobile data-table rows: tapping a row that has collapsed .td-detail
  // cells toggles them open, so the row list can start minimal (see
  // table.data CSS in the max-width:720px block) without hiding info for
  // good. Ignored on rows with nothing to expand, and ignored when the
  // tap lands on an actual control (link, button, form field) inside the
  // row so existing actions like "Edit" or "Mark returned" still work.
  (function () {
    document.addEventListener('click', function (e) {
      var row = e.target.closest('table.data tbody tr');
      if (!row) return;
      if (!row.querySelector('.td-detail')) return;
      if (e.target.closest('a, button, input, select, textarea, label, form')) return;
      row.classList.toggle('expanded');
    });
  })();

  // Auto-hide header + bottom nav on scroll (mobile only): scrolling down
  // hides both to give the page more room, scrolling up brings them back
  // immediately. Skipped above the 720px breakpoint, where both bars are
  // just normal in-flow / non-scrolling chrome. Paused while any drawer,
  // dropdown, or modal is open so the chrome doesn't vanish out from
  // under an open panel.
  (function () {
    var header = document.querySelector('.sidebar-top');
    var footerNav = document.querySelector('.bottom-nav');
    if (!header && !footerNav) return;

    var mq = window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;
    var lastY = window.pageYOffset || document.documentElement.scrollTop;
    var ticking = false;
    var threshold = 6; // ignore tiny/inertial jitter

    function anyOverlayOpen() {
      return document.querySelector(
        '#sidebarNav.open, #userChip.open, .nav-backdrop.open, ' +
        '.notif-modal.open, .cart-drawer.open, .item-modal.open'
      ) !== null;
    }

    function setHidden(hidden) {
      if (header) header.classList.toggle('scroll-hidden', hidden);
      if (footerNav) footerNav.classList.toggle('scroll-hidden', hidden);
    }

    function onScroll() {
      var y = Math.max(0, window.pageYOffset || document.documentElement.scrollTop);

      if (!mq || !mq.matches || anyOverlayOpen() || y < 40) {
        setHidden(false);
        lastY = y;
        ticking = false;
        return;
      }

      var delta = y - lastY;
      if (Math.abs(delta) > threshold) {
        setHidden(delta > 0);
        lastY = y;
      }
      ticking = false;
    }

    window.addEventListener('scroll', function () {
      if (!ticking) {
        window.requestAnimationFrame(onScroll);
        ticking = true;
      }
    }, { passive: true });

    if (mq) {
      var onMqChange = function () { setHidden(false); lastY = window.pageYOffset || document.documentElement.scrollTop; };
      if (mq.addEventListener) mq.addEventListener('change', onMqChange);
      else if (mq.addListener) mq.addListener(onMqChange); // Safari < 14
    }
  })();
</script>

<script>
  // Site-wide: every quantity field uses inputmode="numeric" (right mobile
  // keyboard), but that attribute doesn't stop a physical keyboard from
  // typing letters into a type="text" input — so strip anything non-digit
  // as it's typed. Delegated on document so it also covers fields added
  // dynamically later (e.g. new variant rows, added print-order rows).
  document.addEventListener('input', function (e) {
    var el = e.target;
    if (el.matches && el.matches('input[inputmode="numeric"]')) {
      var digitsOnly = el.value.replace(/[^0-9]/g, '');
      if (digitsOnly !== el.value) el.value = digitsOnly;
    }
  });
</script>

<?php if ($user && in_array($user['role'], ['inventory_staff', 'admin'], true)): ?>
<script>
  if (typeof Html5Qrcode === 'undefined') {
    document.write('<script src="<?= BASE_URL ?>/assets/js/html5-qrcode.min.js"><\/script>');
  }
</script>
<script>
  if (typeof Html5Qrcode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"><\/script>');
  }
</script>
<script>
  if (typeof DuarteQR === 'undefined') {
    document.write('<script src="<?= BASE_URL ?>/assets/js/qr-scan-shared.js"><\/script>');
  }
</script>
<script>
  // Scan modal: opens from the Scan icon (sidebar or mobile topbar,
  // whichever is visible — see .scan-toggle-sidebar/.scan-toggle-top),
  // starts the camera immediately (no extra "Start camera" tap), and
  // forwards the browser to the right page the moment a QR resolves.
  // A successful scan navigates away, which closes the modal for free.
  (function () {
    var toggles = document.querySelectorAll('.scan-toggle');
    var modal = document.getElementById('scanModal');
    var backdrop = document.getElementById('scanModalBackdrop');
    var closeBtn = document.getElementById('scanModalClose');
    var readerEl = document.getElementById('scanModalReader');
    var statusEl = document.getElementById('scanModalStatus');
    var guideEl = modal.querySelector('.scan-guide');
    var guideResetBtn = document.getElementById('scanGuideReset');
    if (!toggles.length || !modal || !readerEl) return;

    DuarteQR.injectFocusHints(modal);

    var BASE = <?= json_encode(BASE_URL) ?>;
    var qr = null;
    var running = false;
    var routed = false;

    // ---- Resizable scan guide -----------------------------------
    // The corner brackets are a purely visual aid, but the actual
    // Html5Qrcode "qrbox" scan region is always centered and sized to
    // match, so letting people resize the guide (and keeping that
    // choice saved) is enough to make the scan area itself adjustable
    // too — we just restart the camera with the new size baked in.
    var GUIDE_KEY = 'duarteScanGuideSize';
    var GUIDE_MIN = 140;
    var guideSize = loadGuideSize();
    var resizeRestartTimer = null;

    function defaultGuideSize() {
      return window.innerWidth <= 640 ? 220 : 260;
    }

    function loadGuideSize() {
      var saved = 0;
      try { saved = parseInt(localStorage.getItem(GUIDE_KEY), 10); } catch (e) {}
      return (saved && saved >= GUIDE_MIN) ? saved : defaultGuideSize();
    }

    function saveGuideSize(size) {
      try { localStorage.setItem(GUIDE_KEY, String(size)); } catch (e) {}
    }

    function guideMax() {
      var rect = readerEl.getBoundingClientRect();
      var side = Math.min(rect.width, rect.height) || defaultGuideSize();
      return Math.max(GUIDE_MIN, side - 16);
    }

    function applyGuideSize(size) {
      guideSize = Math.round(Math.min(Math.max(size, GUIDE_MIN), guideMax()));
      if (guideEl) {
        guideEl.style.width = guideSize + 'px';
        guideEl.style.height = guideSize + 'px';
      }
      return guideSize;
    }

    function scheduleRescan() {
      clearTimeout(resizeRestartTimer);
      resizeRestartTimer = setTimeout(function () {
        if (running) startCamera();
      }, 450);
    }

    function outwardDelta(cornerClass, dx, dy) {
      if (cornerClass.indexOf('scan-guide-br') !== -1) return (dx + dy) / 2;
      if (cornerClass.indexOf('scan-guide-tl') !== -1) return -(dx + dy) / 2;
      if (cornerClass.indexOf('scan-guide-tr') !== -1) return (dx - dy) / 2;
      return (dy - dx) / 2; // scan-guide-bl
    }

    var activeCorner = null;
    var dragStart = null;

    function onCornerDown(e) {
      e.preventDefault();
      activeCorner = e.currentTarget;
      try { activeCorner.setPointerCapture(e.pointerId); } catch (err) {}
      dragStart = { x: e.clientX, y: e.clientY, size: guideSize };
      if (guideEl) guideEl.classList.add('dragging');
      document.addEventListener('pointermove', onCornerMove);
      document.addEventListener('pointerup', onCornerUp);
    }

    function onCornerMove(e) {
      if (!activeCorner || !dragStart) return;
      var dx = e.clientX - dragStart.x;
      var dy = e.clientY - dragStart.y;
      var delta = outwardDelta(activeCorner.className, dx, dy);
      applyGuideSize(dragStart.size + delta * 2);
    }

    function onCornerUp(e) {
      if (activeCorner) {
        try { activeCorner.releasePointerCapture(e.pointerId); } catch (err) {}
      }
      document.removeEventListener('pointermove', onCornerMove);
      document.removeEventListener('pointerup', onCornerUp);
      if (guideEl) guideEl.classList.remove('dragging');
      activeCorner = null;
      dragStart = null;
      saveGuideSize(guideSize);
      scheduleRescan();
    }

    if (guideEl) {
      guideEl.querySelectorAll('.scan-guide-corner').forEach(function (corner) {
        corner.addEventListener('pointerdown', onCornerDown);
      });
    }

    if (guideResetBtn) {
      guideResetBtn.addEventListener('click', function () {
        var size = defaultGuideSize();
        try { localStorage.removeItem(GUIDE_KEY); } catch (e) {}
        applyGuideSize(size);
        scheduleRescan();
      });
    }

    function showStatus(msg, color) {
      statusEl.textContent = msg;
      statusEl.style.color = color || 'var(--ink-soft)';
      statusEl.style.display = 'block';
    }

    function routeScan(decodedText) {
      if (routed) return;
      var text = (decodedText || '').trim();
      if (!text) return;

      try {
        var url = new URL(text, window.location.origin);
        var token = url.searchParams.get('token');
        var tag = url.searchParams.get('tag');
        if (token) {
          routed = true;
          showStatus('Requisition code found — opening Verify & Release…', 'var(--green-ok, #2e7d32)');
          window.location.href = BASE + '/inventory/verify.php?token=' + encodeURIComponent(token);
          return;
        }
        if (tag) {
          routed = true;
          showStatus('Asset tag found — opening asset details…', 'var(--green-ok, #2e7d32)');
          window.location.href = BASE + '/inventory/asset_view.php?tag=' + encodeURIComponent(tag);
          return;
        }
      } catch (e) { /* not a URL — bare code, fall through */ }

      routed = true;
      if (/^AST-/i.test(text)) {
        showStatus('Asset tag found — opening asset details…', 'var(--green-ok, #2e7d32)');
        window.location.href = BASE + '/inventory/asset_view.php?tag=' + encodeURIComponent(text);
      } else {
        showStatus('Requisition code found — opening Verify & Release…', 'var(--green-ok, #2e7d32)');
        window.location.href = BASE + '/inventory/verify.php?token=' + encodeURIComponent(text);
      }
    }

    function stopCamera() {
      if (qr && running) {
        qr.stop().then(function () { qr.clear(); }).catch(function () {});
      }
      running = false;
      qr = null;
    }

    function startCamera() {
      statusEl.style.display = 'none';
      routed = false;

      if (!DuarteQR.checkPrereqs(showStatus)) return;

      qr = new Html5Qrcode('scanModalReader');
      applyGuideSize(guideSize);
      var scanConfig = DuarteQR.buildScanConfig(guideSize);
      DuarteQR.startWithFallback(qr, scanConfig, routeScan, {
        onStarted: function () {
          running = true;
          // Size the reader box to the camera's actual aspect ratio
          // (set once the video track reports its real dimensions)
          // instead of guessing, so the video fills the box exactly —
          // no blank strip, and the guide brackets land on real video.
          var videoEl = readerEl.querySelector('video');
          if (videoEl) {
            var setRatio = function () {
              if (videoEl.videoWidth && videoEl.videoHeight) {
                readerEl.style.aspectRatio = videoEl.videoWidth + ' / ' + videoEl.videoHeight;
                applyGuideSize(guideSize);
              }
            };
            if (videoEl.readyState >= 1) setRatio();
            else videoEl.addEventListener('loadedmetadata', setRatio, { once: true });
          }
        },
        onNoCamera: function () { showStatus('No camera found on this device. Use manual entry below.', 'var(--red-danger)'); },
        onDenied: function () { showStatus('Camera access denied. Allow it in your browser settings — if it\'s already allowed there, your OS camera privacy setting may be blocking it too. Or use manual entry below.', 'var(--red-danger)'); },
        onError: function () { showStatus('Could not start the camera. Use manual entry below.', 'var(--red-danger)'); }
      });
    }

    function openModal() {
      modal.classList.add('open');
      if (backdrop) backdrop.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('variant-modal-open');
      startCamera();
    }

    function closeModal() {
      modal.classList.remove('open');
      if (backdrop) backdrop.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('variant-modal-open');
      stopCamera();
    }

    toggles.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault(); // JS available — use the modal instead of navigating to scan.php
        openModal();
      });
    });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });
  })();
</script>
<?php endif; ?>

<script>
  // The app was reverted to plain web-based (no more installable
  // "app" / Add to Home Screen experience) — service-worker.js is no
  // longer registered for new visits. This block only exists to clean
  // up anyone who had already installed it: it unregisters the old
  // service worker and clears its caches so they fall back to a normal
  // browser tab instead of the old standalone app shell. Safe to leave
  // in permanently, since it's a no-op once nothing is registered.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(function (regs) {
      regs.forEach(function (reg) { reg.unregister(); });
    });
  }
  if (window.caches) {
    caches.keys().then(function (keys) {
      keys.filter(function (key) { return key.indexOf('duarte-') === 0; })
        .forEach(function (key) { caches.delete(key); });
    });
  }

  // Universal Instant Automatic Filter Execution
  (function() {
    var filterForms = document.querySelectorAll('form.filter-bar, form.filters-form, form[role="search"], form.auto-filter, form[data-auto-filter]');
    filterForms.forEach(function(form) {
      function resetPageAndSubmit() {
        var pageInput = form.querySelector('input[name="page"]');
        if (pageInput) {
          pageInput.value = '1';
        } else {
          var urlParams = new URLSearchParams(window.location.search);
          if (urlParams.has('page')) {
            var hiddenPage = document.createElement('input');
            hiddenPage.type = 'hidden';
            hiddenPage.name = 'page';
            hiddenPage.value = '1';
            form.appendChild(hiddenPage);
          }
        }
        form.classList.add('is-filtering');
        form.submit();
      }

      // 1. Instant submit on any dropdown, datepicker, or toggle change
      form.querySelectorAll('select, input[type="date"], input[type="month"], input[type="radio"], input[type="checkbox"]').forEach(function(el) {
        el.addEventListener('change', function() {
          resetPageAndSubmit();
        });
      });

      // 2. Debounced automatic search on typing (400ms after user pauses)
      var textInputs = form.querySelectorAll('input[type="text"], input[type="search"], input:not([type])');
      textInputs.forEach(function(searchInput) {
        var debounceTimer = null;
        var initialVal = searchInput.value;

        // Auto-restore focus and caret position if this input drove the current search
        var urlParams = new URLSearchParams(window.location.search);
        var inputName = searchInput.getAttribute('name') || 'q';
        if (urlParams.has(inputName) && urlParams.get(inputName) === searchInput.value && searchInput.value.length > 0) {
          if (document.activeElement === document.body || !document.activeElement) {
            searchInput.focus();
            var len = searchInput.value.length;
            try { searchInput.setSelectionRange(len, len); } catch (e) {}
          }
        }

        searchInput.addEventListener('input', function() {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function() {
            if (searchInput.value !== initialVal) {
              resetPageAndSubmit();
            }
          }, 400);
        });

        // Instant submit on Enter key
        searchInput.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(debounceTimer);
            resetPageAndSubmit();
          }
        });
      });

      // 3. Clear button instant submit
      var clearBtn = form.querySelector('.search-clear-btn, .btn-clear-search');
      if (clearBtn) {
        clearBtn.addEventListener('click', function() {
          textInputs.forEach(function(ti) { ti.value = ''; });
          resetPageAndSubmit();
        });
      }
    });
  })();
</script>
</body>
</html>
