<?php
/**
 * Shared layout header. Expects $page_title and optional $eyebrow
 * to be set by the including page before this file is required.
 */
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/loans.php';
require_once __DIR__ . '/item_requests.php';

$user = current_user();
$current_script = basename($_SERVER['SCRIPT_NAME']);
// Some pages (e.g. requisition/view.php, reachable from more than one
// list) set $active_nav_hint before including this file so the sidebar
// can still highlight the section the person actually drilled in from,
// instead of leaving nothing highlighted at all.
$nav_script = $active_nav_hint ?? $current_script;
// Admin can stand in for inventory_staff (e.g. when no one is on duty),
// so admin gets the same inventory-side notification badges as the
// inventory_staff role, not just page access.
$covers_inventory = $user && in_array($user['role'], ['inventory_staff', 'admin'], true);
if ($covers_inventory) {
    require_once __DIR__ . '/stock.php';
    sync_low_stock_notifications(get_db());
}
$unread_count = $user ? unread_notification_count($user['id']) : 0;
$cart_count = $user && in_array($user['role'], ['driver_helper', 'field_supervisor'], true) ? cart_count() : 0;
$overdue_loan_count = $covers_inventory ? count_overdue_loans(get_db()) : 0;
$pending_approval_count = $user && $user['role'] === 'field_supervisor' ? count_pending_requisitions(get_db()) : 0;
$pending_item_request_count = $covers_inventory ? count_pending_item_requests(get_db()) : 0;

/**
 * Small inline SVG icon set for the sidebar nav — same feather-style
 * stroke icons already used for Settings/Logout/Notifications, so the
 * whole app draws from one consistent icon language instead of nav
 * links being plain text next to iconified buttons everywhere else.
 * Returned as raw markup (safe: fixed, hard-coded set, no user input).
 */
if (!function_exists('nav_icon')) {
    function nav_icon(string $name): string
    {
        $icons = [
            'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'audit'      => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6"/><path d="M9 17h6"/><path d="M9 9h1"/>',
            'inventory'  => '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
            'verify'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3"/><path d="M21 14v3h-3"/><path d="M14 21h3"/>',
            'loans'      => '<path d="m14.7 6.3 3 3L11 16H8v-3Z"/><path d="m17.5 3.5 3 3-2 2-3-3Z"/><path d="M5 13v6a2 2 0 0 0 2 2h6"/>',
            'requests'   => '<path d="M21 8a2 2 0 0 0-2-2h-5l-2-3H8L6 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2Z"/><path d="M2 12h6l1 2h6l1-2h6" fill="none"/>',
            'catalog'    => '<path d="M20 7 12 3 4 7l8 4 8-4Z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
            'categories' => '<path d="M20.6 12.6 12 21.2a2 2 0 0 1-2.8 0l-7.4-7.4a2 2 0 0 1 0-2.8L10.4 2.4A2 2 0 0 1 11.8 2H19a2 2 0 0 1 2 2v7.2a2 2 0 0 1-.4 1.4Z"/><circle cx="15" cy="8" r="1.5"/>',
            'ledger'     => '<path d="M8 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 12h6"/><path d="M9 16h6"/><path d="M9 8h1"/>',
            'assets'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 17h3v3"/><path d="M17 14v3h3"/>',
            'reports'    => '<path d="M3 3v18h18"/><rect x="7" y="13" width="3" height="5"/><rect x="12" y="9" width="3" height="9"/><rect x="17" y="5" width="3" height="13"/>',
            'scan'       => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="3" y1="12" x2="21" y2="12"/>',
            'browse'     => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
            'my-requests'=> '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="m9 14 2 2 4-4"/>',
            'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
            'all'        => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
            'truck'      => '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>',
            'locations'  => '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/>',
            'mobile'     => '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
        ];
        $path = $icons[$name] ?? $icons['all'];
        return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title ?? APP_NAME) ?> · <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
  <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL ?>/assets/icons/icon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/icons/favicon.png">
  <meta name="theme-color" content="#1A2129">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-top">
      <div class="brand-group">
        <button type="button" class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="sidebarNav" aria-label="Toggle menu">
          <span class="hamburger" aria-hidden="true"><span></span><span></span><span></span></span>
          <?php if ($unread_count + $cart_count + $overdue_loan_count + $pending_approval_count + $pending_item_request_count > 0): ?>
            <span class="badge inactive nav-toggle-badge"><?= $unread_count + $cart_count + $overdue_loan_count + $pending_approval_count + $pending_item_request_count ?></span>
          <?php endif; ?>
        </button>
        <div class="brand">
          <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="Duarte Logo" class="brand-logo-img">
          <div class="brand-details">
            <span class="brand-name">DuaRTE</span>
            <small>Item Requests &amp; Equipment Borrowing</small>
          </div>
        </div>
      </div>
      <div class="sidebar-top-controls">
        <?php if ($user && in_array($user['role'], ['inventory_staff', 'admin'], true)): ?>
        <a href="<?= BASE_URL ?>/inventory/scan.php" class="scan-toggle scan-toggle-sidebar" id="scanToggleSidebar" aria-haspopup="dialog" aria-controls="scanModal" aria-label="Scan QR code" title="Scan QR code">
          <?= nav_icon('scan') ?>
        </a>
        <?php endif; ?>
        <?php if ($user): ?>
        <button type="button" class="notif-toggle notif-toggle-sidebar<?= $unread_count > 0 ? ' has-unread' : '' ?>" id="notifToggleSidebar" aria-haspopup="dialog" aria-controls="notifModal" aria-label="Notifications<?= $unread_count > 0 ? ', ' . $unread_count . ' unread' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if ($unread_count > 0): ?>
            <span class="badge inactive notif-toggle-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
          <?php endif; ?>
        </button>
        <button type="button" class="profile-toggle" id="profileToggle" aria-expanded="false" aria-controls="userChip" aria-label="Account menu">
          <?= avatar_html($user, 'mono') ?>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <div class="nav-backdrop" id="navBackdrop"></div>
    <nav id="sidebarNav">
      <div class="nav-drawer-header">
        <div class="brand mono" style="border:none;padding:0;margin:0;display:flex;align-items:center;gap:10px;">
          <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="Duarte Logo" style="height:32px;width:auto;object-fit:contain;">
          <span>DuaRTE</span>
        </div>
        <button type="button" class="nav-drawer-close" id="sidebarNavClose" aria-label="Close menu">&times;</button>
      </div>
      <?php if ($user && $user['role'] === 'admin'): ?>
        <div class="nav-section-label">Overview</div>
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="<?= $nav_script === 'dashboard.php' && str_starts_with($_SERVER['SCRIPT_NAME'], BASE_URL . '/admin/') ? 'active' : '' ?>"><?= nav_icon('dashboard') ?><span>Dashboard</span></a>

        <div class="nav-section-label">Administration</div>
        <a href="<?= BASE_URL ?>/admin/users.php" class="<?= in_array($nav_script, ['users.php','user_add.php','user_edit.php']) ? 'active' : '' ?>"><?= nav_icon('users') ?><span>User Accounts</span></a>
        <a href="<?= BASE_URL ?>/admin/mcda_settings.php" class="<?= $nav_script === 'mcda_settings.php' ? 'active' : '' ?>"><?= nav_icon('inventory') ?><span>MCDA Algorithm</span></a>
        <a href="<?= BASE_URL ?>/admin/audit_logs.php" class="<?= in_array($nav_script, ['audit_logs.php','audit_logs_export.php']) ? 'active' : '' ?>"><?= nav_icon('audit') ?><span>Audit Logs</span></a>
        <a href="<?= BASE_URL ?>/admin/app_release.php" class="<?= $nav_script === 'app_release.php' ? 'active' : '' ?>"><?= nav_icon('mobile') ?><span>Mobile App Release</span></a>

        <div class="nav-section-label">Inventory (Standing In)</div>
        <a href="<?= BASE_URL ?>/inventory/dashboard.php" class="<?= $nav_script === 'dashboard.php' && str_starts_with($_SERVER['SCRIPT_NAME'], BASE_URL . '/inventory/') ? 'active' : '' ?>">
          <?= nav_icon('inventory') ?><span>Inventory Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/inventory/verify.php" class="<?= $nav_script === 'verify.php' ? 'active' : '' ?>"><?= nav_icon('verify') ?><span>Verify &amp; Release</span></a>
        <a href="<?= BASE_URL ?>/inventory/loans.php" class="<?= $nav_script === 'loans.php' ? 'active' : '' ?>">
          <?= nav_icon('loans') ?><span>Tool Loans</span><?= $overdue_loan_count > 0 ? ' <span class="badge inactive ml-xs">' . $overdue_loan_count . '</span>' : '' ?>
        </a>
        <a href="<?= BASE_URL ?>/inventory/item_requests.php" class="<?= $nav_script === 'item_requests.php' ? 'active' : '' ?>">
          <?= nav_icon('requests') ?><span>Purchase Requests (PO)</span><?= $pending_item_request_count > 0 ? ' <span class="badge inactive ml-xs">' . $pending_item_request_count . '</span>' : '' ?>
        </a>
        <a href="<?= BASE_URL ?>/inventory/assets.php" class="<?= in_array($nav_script, ['assets.php','asset_add.php','asset_view.php','asset_audit.php']) ? 'active' : '' ?>">
          <?= nav_icon('assets') ?><span>Assets</span>
        </a>
        <a href="<?= BASE_URL ?>/inventory/trucks.php" class="<?= $nav_script === 'trucks.php' ? 'active' : '' ?>">
          <?= nav_icon('truck') ?><span>Fleet Trucks</span>
        </a>

        <div class="nav-section-label">Catalog</div>
        <a href="<?= BASE_URL ?>/inventory/items.php" class="<?= in_array($nav_script, ['items.php','item_add.php','item_edit.php']) ? 'active' : '' ?>"><?= nav_icon('catalog') ?><span>Catalog Management</span></a>
        <a href="<?= BASE_URL ?>/inventory/bulk_photos.php" class="<?= $nav_script === 'bulk_photos.php' ? 'active' : '' ?>"><?= nav_icon('catalog') ?><span>Missing Photos</span></a>
        <a href="<?= BASE_URL ?>/inventory/categories.php" class="<?= $nav_script === 'categories.php' ? 'active' : '' ?>"><?= nav_icon('categories') ?><span>Categories</span></a>
        <a href="<?= BASE_URL ?>/admin/locations.php" class="<?= $nav_script === 'locations.php' ? 'active' : '' ?>"><?= nav_icon('locations') ?><span>Storage Locations</span></a>
        <a href="<?= BASE_URL ?>/inventory/stock_ledger.php" class="<?= in_array($nav_script, ['stock_ledger.php','stock_in.php','stock_adjust.php']) ? 'active' : '' ?>"><?= nav_icon('ledger') ?><span>Stock Ledger</span></a>

        <div class="nav-section-label">Reports</div>
        <div class="nav-group<?= str_starts_with($_SERVER['SCRIPT_NAME'], BASE_URL . '/reports/') ? ' is-open' : '' ?>">
          <a href="<?= BASE_URL ?>/reports/index.php" class="nav-parent">
            <?= nav_icon('reports') ?><span>Reports &amp; Analytics</span>
            <span class="nav-parent-toggle" role="button" tabindex="0" aria-label="Toggle Reports &amp; Analytics submenu">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
          </a>
          <div class="nav-subnav">
            <a href="<?= BASE_URL ?>/reports/index.php" class="<?= $nav_script === 'index.php' && str_starts_with($_SERVER['SCRIPT_NAME'], BASE_URL . '/reports/') ? 'active' : '' ?>">Overview</a>
            <a href="<?= BASE_URL ?>/reports/utilization.php" class="<?= $nav_script === 'utilization.php' ? 'active' : '' ?>">Fleet &amp; Equipment Utilization</a>
            <a href="<?= BASE_URL ?>/reports/requester_history.php" class="<?= $nav_script === 'requester_history.php' ? 'active' : '' ?>">Requester History</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($user && $user['role'] === 'inventory_staff'): ?>
        <div class="nav-section-label">Overview</div>
        <a href="<?= BASE_URL ?>/inventory/dashboard.php" class="<?= $nav_script === 'dashboard.php' ? 'active' : '' ?>"><?= nav_icon('dashboard') ?><span>Dashboard</span></a>

        <div class="nav-section-label">Operations</div>
        <a href="<?= BASE_URL ?>/inventory/verify.php" class="<?= $nav_script === 'verify.php' ? 'active' : '' ?>"><?= nav_icon('verify') ?><span>Verify &amp; Release</span></a>
        <a href="<?= BASE_URL ?>/inventory/loans.php" class="<?= $nav_script === 'loans.php' ? 'active' : '' ?>">
          <?= nav_icon('loans') ?><span>Tool Loans</span><?= $overdue_loan_count > 0 ? ' <span class="badge inactive ml-xs">' . $overdue_loan_count . '</span>' : '' ?>
        </a>
        <a href="<?= BASE_URL ?>/inventory/item_requests.php" class="<?= $nav_script === 'item_requests.php' ? 'active' : '' ?>">
          <?= nav_icon('requests') ?><span>Purchase Requests (PO)</span><?= $pending_item_request_count > 0 ? ' <span class="badge inactive ml-xs">' . $pending_item_request_count . '</span>' : '' ?>
        </a>
        <a href="<?= BASE_URL ?>/inventory/assets.php" class="<?= in_array($nav_script, ['assets.php','asset_add.php','asset_view.php','asset_audit.php']) ? 'active' : '' ?>">
          <?= nav_icon('assets') ?><span>Assets</span>
        </a>

        <div class="nav-section-label">Catalog</div>
        <a href="<?= BASE_URL ?>/inventory/items.php" class="<?= in_array($nav_script, ['items.php','item_add.php','item_edit.php']) ? 'active' : '' ?>"><?= nav_icon('catalog') ?><span>Catalog Management</span></a>
        <a href="<?= BASE_URL ?>/inventory/bulk_photos.php" class="<?= $nav_script === 'bulk_photos.php' ? 'active' : '' ?>"><?= nav_icon('catalog') ?><span>Missing Photos</span></a>
        <a href="<?= BASE_URL ?>/inventory/categories.php" class="<?= $nav_script === 'categories.php' ? 'active' : '' ?>"><?= nav_icon('categories') ?><span>Categories</span></a>
        <a href="<?= BASE_URL ?>/inventory/stock_ledger.php" class="<?= in_array($nav_script, ['stock_ledger.php','stock_in.php','stock_adjust.php']) ? 'active' : '' ?>"><?= nav_icon('ledger') ?><span>Stock Ledger</span></a>

        <div class="nav-section-label">Reports</div>
        <div class="nav-group<?= str_starts_with($_SERVER['SCRIPT_NAME'], BASE_URL . '/reports/') ? ' is-open' : '' ?>">
          <a href="<?= BASE_URL ?>/reports/index.php" class="nav-parent">
            <?= nav_icon('reports') ?><span>Reports &amp; Analytics</span>
            <span class="nav-parent-toggle" role="button" tabindex="0" aria-label="Toggle Reports &amp; Analytics submenu">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
          </a>
          <div class="nav-subnav">
            <a href="<?= BASE_URL ?>/reports/index.php" class="<?= $nav_script === 'index.php' && str_starts_with($_SERVER['SCRIPT_NAME'], BASE_URL . '/reports/') ? 'active' : '' ?>">Warehouse Overview</a>
            <a href="<?= BASE_URL ?>/reports/requester_history.php" class="<?= $nav_script === 'requester_history.php' ? 'active' : '' ?>">Requester History</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($user && in_array($user['role'], ['driver_helper', 'field_supervisor'], true)): ?>
        <div class="nav-section-label">Overview</div>
        <a href="<?= BASE_URL ?>/requisition/<?= $user['role'] === 'driver_helper' ? 'home.php' : 'dashboard.php' ?>" class="<?= in_array($nav_script, ['home.php','dashboard.php']) ? 'active' : '' ?>"><?= nav_icon('dashboard') ?><span>Dashboard</span></a>
        <a href="<?= BASE_URL ?>/catalog/browse.php" class="<?= $nav_script === 'browse.php' ? 'active' : '' ?>"><?= nav_icon('browse') ?><span>Browse Catalog</span></a>

        <div class="nav-section-label">My Activity</div>
        <a href="<?= BASE_URL ?>/requisition/my_history.php" class="<?= $nav_script === 'my_history.php' ? 'active' : '' ?>"><?= nav_icon('reports') ?><span>My Activity Profile</span></a>
        <a href="<?= BASE_URL ?>/requisition/my_requests.php" class="<?= $nav_script === 'my_requests.php' ? 'active' : '' ?>"><?= nav_icon('my-requests') ?><span>My Requests</span></a>
        <a href="<?= BASE_URL ?>/requisition/my_loans.php" class="<?= $nav_script === 'my_loans.php' ? 'active' : '' ?>"><?= nav_icon('loans') ?><span>My Borrowed Tools</span></a>
        <a href="<?= BASE_URL ?>/catalog/my_item_requests.php" class="<?= in_array($nav_script, ['my_item_requests.php','request_item.php'], true) ? 'active' : '' ?>"><?= nav_icon('requests') ?><span>My Purchase Requests (PO)</span></a>
      <?php endif; ?>

      <?php if ($user && $user['role'] === 'field_supervisor'): ?>
        <div class="nav-section-label">Approvals</div>
        <a href="<?= BASE_URL ?>/requisition/pending.php" class="<?= $nav_script === 'pending.php' ? 'active' : '' ?>">
          <?= nav_icon('clock') ?><span>Pending Approvals</span><?= $pending_approval_count > 0 ? ' <span class="badge inactive ml-xs">' . $pending_approval_count . '</span>' : '' ?>
        </a>
        <a href="<?= BASE_URL ?>/requisition/all.php" class="<?= $nav_script === 'all.php' ? 'active' : '' ?>"><?= nav_icon('all') ?><span>All Requests</span></a>
        <a href="<?= BASE_URL ?>/admin/mcda_settings.php" class="<?= $nav_script === 'mcda_settings.php' ? 'active' : '' ?>"><?= nav_icon('inventory') ?><span>MCDA Algorithm</span></a>
        <a href="<?= BASE_URL ?>/inventory/trucks.php" class="<?= $nav_script === 'trucks.php' ? 'active' : '' ?>"><?= nav_icon('truck') ?><span>Fleet Trucks</span></a>
      <?php endif; ?>

    </nav>
    <?php if ($user): ?>
    <div class="user-chip" id="userChip">
      <div class="user-chip-info">
        <?= avatar_html($user, 'avatar-circle-lg mono') ?>
        <div class="user-chip-text">
          <div class="user-chip-name"><?= htmlspecialchars(display_name($user['full_name'])) ?></div>
          <div class="role-line">
            <span class="role mono"><?= htmlspecialchars(role_label($user['role'])) ?></span>
            <?php if ($user['role'] === 'driver_helper' && !empty($user['position'])): ?>
              <span class="role-position"><?= htmlspecialchars(position_label($user['position'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="user-chip-links">
        <a href="<?= BASE_URL ?>/account/settings.php" class="settings-link<?= $current_script === 'settings.php' ? ' active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Settings
        </a>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="logout-link">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Log out
        </a>
      </div>
    </div>
    <?php endif; ?>
  </aside>
  <main class="main<?= !empty($page_has_hero) ? ' has-hero' : '' ?>">
    <?php if ($user): ?>
    <div class="main-topbar">
      <?php if (in_array($user['role'], ['inventory_staff', 'admin'], true)): ?>
      <a href="<?= BASE_URL ?>/inventory/scan.php" class="scan-toggle scan-toggle-top" id="scanToggleTop" aria-haspopup="dialog" aria-controls="scanModal" aria-label="Scan QR code" title="Scan QR Code (Camera Scanner)">
        <span class="scan-toggle-icon"><?= nav_icon('scan') ?></span>
        <span class="scan-toggle-text">Scan QR</span>
      </a>
      <div class="topbar-divider" aria-hidden="true"></div>
      <?php endif; ?>
      <button type="button" class="notif-toggle notif-toggle-top<?= $unread_count > 0 ? ' has-unread' : '' ?>" id="notifToggleTop" aria-haspopup="dialog" aria-controls="notifModal" aria-label="Notifications<?= $unread_count > 0 ? ', ' . $unread_count . ' unread' : '' ?>" title="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($unread_count > 0): ?>
          <span class="badge inactive notif-toggle-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
        <?php endif; ?>
      </button>
    </div>
    <?php endif; ?>
