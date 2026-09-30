<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/cart.php';
require_role(['driver_helper']);

$pdo = get_db();
$user = current_user();

$active_requests = $pdo->prepare(
    "SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid AND status IN ('pending', 'approved')"
);
$active_requests->execute(['uid' => $user['id']]);
$active_requests = (int)$active_requests->fetch()['c'];

$borrowed_now = $pdo->prepare(
    "SELECT COUNT(*) c FROM tool_loans WHERE borrower_id = :uid AND returned_at IS NULL"
);
$borrowed_now->execute(['uid' => $user['id']]);
$borrowed_now = (int)$borrowed_now->fetch()['c'];

$overdue_now = $pdo->prepare(
    "SELECT COUNT(*) c FROM tool_loans WHERE borrower_id = :uid AND returned_at IS NULL AND due_date < CURDATE()"
);
$overdue_now->execute(['uid' => $user['id']]);
$overdue_now = (int)$overdue_now->fetch()['c'];

$ready_for_pickup = $pdo->prepare(
    "SELECT COUNT(*) c FROM requisitions WHERE requester_id = :uid AND status = 'approved'"
);
$ready_for_pickup->execute(['uid' => $user['id']]);
$ready_for_pickup = (int)$ready_for_pickup->fetch()['c'];

$recent_requests = $pdo->prepare(
    "SELECT r.*, (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
     FROM requisitions r WHERE r.requester_id = :uid
     ORDER BY r.created_at DESC LIMIT 5"
);
$recent_requests->execute(['uid' => $user['id']]);
$recent_requests = $recent_requests->fetchAll();

$my_cart_count = cart_count();
$first_name = explode(' ', $user['full_name'])[0];
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$page_title = 'Dashboard';
$page_has_hero = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="welcome-hero">
  <div class="welcome-hero-main">
    <?= avatar_html($user, 'welcome-hero-avatar mono') ?>
    <div class="welcome-hero-text">
      <div class="welcome-hero-eyebrow"><?= htmlspecialchars($greeting) ?>, welcome</div>
      <h1 class="welcome-hero-name"><?= htmlspecialchars((string)($user['full_name'] ?? '')) ?></h1>
      <div class="welcome-hero-role"><?= htmlspecialchars(role_display($user['role'], $user['position'] ?? null)) ?></div>
    </div>
  </div>
</div>

<div class="quick-actions-row">
  <a href="<?= BASE_URL ?>/catalog/browse.php" class="btn btn-primary"><?= icon_svg('plus-circle') ?> Browse Catalog</a>
  <a href="<?= BASE_URL ?>/requisition/my_requests.php" class="btn btn-outline">My Requests</a>
</div>
<?php if ($ready_for_pickup > 0): ?>
  <div class="alert alert-success"><?= icon_svg('check-circle') ?> <?= $ready_for_pickup ?> approved and ready for QR pickup</div>
<?php endif; ?>

<?php if ($overdue_now > 0): ?>
<div class="alert alert-error">
  You have <?= $overdue_now ?> overdue tool(s). <a href="<?= BASE_URL ?>/requisition/my_loans.php">Review My Borrowed Tools →</a>
</div>
<?php endif; ?>

<div class="stat-grid-v2">
  <a href="<?= BASE_URL ?>/requisition/my_requests.php" class="stat-card-v2<?= $active_requests > 0 ? ' is-ok' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('clipboard') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Active requests</div>
      <div class="stat-card-v2-value"><?= $active_requests ?></div>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/requisition/my_loans.php" class="stat-card-v2<?= $overdue_now > 0 ? ' is-danger' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('tool') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Tools borrowed</div>
      <div class="stat-card-v2-value"><?= $borrowed_now ?></div>
      <?php if ($overdue_now > 0): ?>
        <div class="stat-card-v2-sub" style="color:var(--red-danger); font-weight:600;"><?= $overdue_now ?> overdue</div>
      <?php endif; ?>
    </div>
  </a>
  <a href="<?= BASE_URL ?>/requisition/my_requests.php" class="stat-card-v2<?= $ready_for_pickup > 0 ? ' is-ok' : '' ?>">
    <div class="stat-card-v2-icon"><?= icon_svg('package-check') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Ready for pickup</div>
      <div class="stat-card-v2-value<?= $ready_for_pickup > 0 ? ' is-warn' : '' ?>"><?= $ready_for_pickup ?></div>
    </div>
  </a>
  <?php if ($my_cart_count > 0): ?>
  <a href="<?= BASE_URL ?>/requisition/cart.php" class="stat-card-v2 is-warn">
    <div class="stat-card-v2-icon"><?= icon_svg('inbox') ?></div>
    <div class="stat-card-v2-body">
      <div class="stat-card-v2-label">Items in cart</div>
      <div class="stat-card-v2-value"><?= $my_cart_count ?></div>
    </div>
  </a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-head">
    <div>
      <h2>Your recent requests</h2>
      <div class="section-sub">Last 5 requests you've submitted</div>
    </div>
    <a href="<?= BASE_URL ?>/requisition/my_requests.php" class="btn btn-outline btn-sm">See all →</a>
  </div>

  <?php if (!$recent_requests): ?>
    <div class="empty-state-mini">
      <?= icon_svg('clipboard') ?>
      <div>You haven't submitted any requests yet.<br><a href="<?= BASE_URL ?>/catalog/browse.php">Browse the catalog</a> to get started.</div>
    </div>
  <?php else: ?>
    <div class="request-card-list">
      <?php foreach ($recent_requests as $r): ?>
        <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $r['id'] ?>" class="request-card">
          <div class="request-card-id mono">#<?= $r['id'] ?></div>
          <div class="request-card-body">
            <div class="request-card-title"><?= (int)$r['item_count'] ?> item(s)</div>
            <div class="request-card-meta mono"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></div>
          </div>
          <div class="request-card-status">
            <span class="badge <?= requisition_status_class($r['status']) ?>"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
