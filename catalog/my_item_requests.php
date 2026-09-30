<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/item_requests.php';
require_role(['driver_helper', 'field_supervisor']);

$pdo = get_db();
$user = current_user();

$stmt = $pdo->prepare(
    "SELECT ir.*, d.full_name AS decided_by_name, i.unit AS catalog_unit
     FROM item_requests ir
     LEFT JOIN users d ON d.id = ir.decided_by
     LEFT JOIN items i ON i.id = ir.item_id
     WHERE ir.requester_id = :uid
     ORDER BY ir.created_at ASC"
);
$stmt->execute(['uid' => $user['id']]);
$requests = $stmt->fetchAll();

$req_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM requisitions WHERE requester_id = :uid");
$req_count_stmt->execute(['uid' => $user['id']]);
$my_req_count = (int)$req_count_stmt->fetchColumn();

$page_title = 'My Purchase Requests (PO)';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Procurement &amp; Purchasing</div>
    <h1>My Purchase Requests (PO)</h1>
  </div>
  <a href="<?= BASE_URL ?>/catalog/request_item.php" class="btn btn-primary">+ Request to Purchase (PO)</a>
</div>

<div class="range-tabs" role="tablist" style="margin-bottom:1.25rem;">
  <a href="<?= BASE_URL ?>/requisition/my_requests.php" class="range-tab">
    Warehouse Requisitions (<?= $my_req_count ?>)
  </a>
  <a href="<?= BASE_URL ?>/catalog/my_item_requests.php" class="range-tab active">
    Purchase Requests / PO (<?= count($requests) ?>)
  </a>
</div>

<?php if (isset($_GET['submitted'])): ?>
  <div class="alert alert-success">Your Purchase Request (PO) was sent to Inventory Staff for purchasing / procurement. You'll be notified once purchased and available.</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
<table class="data">
    <thead><tr><th style="min-width:80px;">PO #</th><th style="min-width:180px;">Item Requested</th><th style="min-width:90px;">Qty</th><th style="min-width:200px;">Reason / Purpose</th><th style="min-width:160px;">Date Requested</th><th style="min-width:140px;">PO Status</th></tr></thead>
    <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="6" style="text-align:center; padding:2rem 1rem;">You haven't submitted any purchase requests yet.</td></tr>
      <?php else: foreach ($requests as $r): $resolved = item_request_resolve($r, $r['catalog_unit']); 
        $status_label = match($r['status']) {
            'fulfilled' => 'Purchased / Fulfilled',
            'rejected'  => 'Closed / Cancelled',
            default     => 'Pending PO',
        };
      ?>
        <tr>
          <td class="mono" data-label="PO #"><?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></td>
          <td data-label="Item Requested"><strong><?= htmlspecialchars((string)($r['item_name'] ?? '')) ?></strong></td>
          <td class="mono" data-label="Qty"><?= htmlspecialchars(item_request_qty_display((int)$r['quantity'], $resolved)) ?></td>
          <td data-label="Reason / Purpose" class="td-detail"><?= htmlspecialchars((string)($resolved['reason'] ?? '')) ?></td>
          <td class="mono td-detail" data-label="Date Requested"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($r['created_at']))) ?></td>
          <td data-label="PO Status">
            <span class="badge <?= item_request_status_class($r['status']) ?>"><?= htmlspecialchars($status_label) ?></span>
            <?php if ($r['status'] !== 'pending' && $r['decision_note']): ?>
              <div class="section-sub" style="margin-top:0.2rem;"><?= htmlspecialchars((string)($r['decision_note'] ?? '')) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
