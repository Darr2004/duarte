<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/item_requests.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT ir.*, u.full_name AS requester_name, d.full_name AS decided_by_name, i.unit AS catalog_unit,
            i.item_code, i.quantity_on_hand
     FROM item_requests ir
     JOIN users u ON u.id = ir.requester_id
     LEFT JOIN users d ON d.id = ir.decided_by
     LEFT JOIN items i ON i.id = ir.item_id
     WHERE ir.id = :id"
);
$stmt->execute(['id' => $id]);
$req = $stmt->fetch();

if (!$req) {
    http_response_code(404);
    die('Item request not found.');
}
$req_resolved = item_request_resolve($req, $req['catalog_unit']);

// Other pending requests for the same item (by name, case-insensitive),
// so Inventory Staff can see the total demand before deciding how much
// to actually order — the ordered qty on the slip shouldn't just be
// whatever this one requester happened to type.
$related_stmt = $pdo->prepare(
    "SELECT ir.id, ir.quantity, ir.reason, ir.item_id, u.full_name AS requester_name, i.unit AS catalog_unit
     FROM item_requests ir
     JOIN users u ON u.id = ir.requester_id
     LEFT JOIN items i ON i.id = ir.item_id
     WHERE ir.status = 'pending' AND ir.id != :id AND LOWER(ir.item_name) = LOWER(:item_name)
     ORDER BY ir.created_at ASC"
);
$related_stmt->execute(['id' => $id, 'item_name' => $req['item_name']]);
$related = $related_stmt->fetchAll();
foreach ($related as &$rel) {
    $rel['resolved'] = item_request_resolve($rel, $rel['catalog_unit']);
}
unset($rel);

// Requests for the "same" item can still be in different units (one
// requester typed "1 set", another typed "4 pcs") — summing the raw
// quantity column blindly would silently mix those up. If everyone
// used the exact same unit (and, for container units, the same
// pieces-per-unit), the combined total stays in that unit. Otherwise
// fall back to combining in individual pieces, which is always
// well-defined, and flag it so Inventory Staff knows it was converted.
$all = array_merge(
    [['quantity' => (int)$req['quantity'], 'resolved' => $req_resolved]],
    array_map(fn($r) => ['quantity' => (int)$r['quantity'], 'resolved' => $r['resolved']], $related)
);
$same_unit = true;
foreach ($all as $row) {
    if ($row['resolved']['unit'] !== $req_resolved['unit'] || $row['resolved']['pieces_per_unit'] !== $req_resolved['pieces_per_unit']) {
        $same_unit = false;
        break;
    }
}
if ($same_unit) {
    $suggested_qty = array_sum(array_column($all, 'quantity'));
    $suggested_unit_label = item_request_unit_label($req_resolved['unit']);
    $suggested_unit_label_plural = item_request_unit_label_plural($req_resolved['unit']);
    $mixed_units = false;
} else {
    $suggested_qty = array_sum(array_map(fn($r) => item_request_total_pieces($r['quantity'], $r['resolved']), $all));
    $suggested_unit_label = 'Piece';
    $suggested_unit_label_plural = 'Pieces';
    $mixed_units = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase Requisition Slip — Request #<?= $req['id'] ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0;
    padding: 2rem 1.5rem 3rem; background: #fff;
  }

  /* ---------- On-screen chrome (hidden when printing) ---------- */
  .toolbar {
    max-width: 760px; margin: 0 auto 1.25rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  }
  .toolbar a.back {
    font-weight: 600; font-size: 0.85rem; color: #444; text-decoration: none;
    display: inline-flex; align-items: center; gap: 0.35rem;
  }
  .toolbar a.back:hover { color: #000; }
  .toolbar-actions { display: flex; align-items: center; gap: 0.6rem; }
  .print-btn {
    font-family: inherit; font-weight: 600; font-size: 0.85rem;
    background: #333; color: #fff; border: none; border-radius: 6px;
    padding: 0.55rem 1.1rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
  }
  .print-btn:hover { background: #000; }
  .print-btn svg { width: 16px; height: 16px; }

  .sheet-wrap { max-width: 760px; margin: 0 auto; position: relative; }

  /* ---------- The slip itself ---------- */
  .slip {
    background: #fff; border: 2px solid #000; padding: 1.5rem 1.75rem; position: relative;
  }

  .letterhead { text-align: center; margin-bottom: 0.75rem; }
  .letterhead h1 {
    margin: 0; font-weight: 700; font-size: 1.4rem; letter-spacing: 0.03em;
  }
  .letterhead .addr { font-size: 0.85rem; margin-top: 0.1rem; }
  .letterhead .title {
    display: inline-block; margin-top: 0.6rem; font-weight: 700; font-size: 1rem;
    letter-spacing: 0.04em; text-transform: uppercase; text-decoration: underline;
  }

  .meta-row { display: flex; gap: 1.5rem; margin: 1rem 0 0.3rem; font-size: 0.9rem; }
  .meta-row .field { flex: 1; display: flex; align-items: baseline; gap: 0.4rem; }
  .meta-row .field .label { font-weight: 600; white-space: nowrap; }
  .meta-row .field span.line { display: inline-block; border-bottom: 1px solid #000; flex: 1; min-height: 1.1rem; padding: 0 2px 0.1rem; }

  table.slip-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.85rem; }
  table.slip-table th, table.slip-table td { border: 1px solid #000; padding: 0.5rem 0.45rem; text-align: center; }
  table.slip-table th { font-weight: 700; font-size: 0.78rem; }
  table.slip-table td.desc { text-align: left; font-weight: 500; }
  table.slip-table input.qty-input {
    width: 100%; border: none; text-align: center; font: inherit; font-weight: 700; color: #000; background: transparent;
  }
  table.slip-table input.qty-input:focus { outline: 1px dashed #666; }
  .blank-row td { height: 26px; }

  .related-box {
    margin-top: 0.9rem; padding: 0.6rem 0.8rem; background: #f5f5f5;
    border: 1px solid #999; font-size: 0.8rem; position: relative;
  }
  .related-box .flag { font-weight: 700; font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; display: block; margin-bottom: 0.35rem; }
  .related-box .related-total { font-size: 0.82rem; }
  .related-box ul { margin: 0.35rem 0 0; padding-left: 1.1rem; color: #333; }
  .related-box li { margin-bottom: 0.1rem; }

  .remarks { margin-top: 1rem; font-size: 0.9rem; display: flex; align-items: baseline; gap: 0.4rem; }
  .remarks .label { font-weight: 600; white-space: nowrap; }
  .remarks .line { border-bottom: 1px solid #000; flex: 1; min-height: 1.1rem; }

  .sign-row { display: flex; justify-content: space-between; gap: 1.5rem; margin-top: 2.5rem; font-size: 0.8rem; }
  .sign-row .box { flex: 1; text-align: center; }
  .sign-row .box .sign-line { border-top: 1px solid #000; margin-top: 2.2rem; padding-top: 0.3rem; font-weight: 500; }

  /* ---------- Decision stamp, plain black outline (only when printed) ---------- */
  .status-stamp {
    position: absolute; top: 46%; left: 50%; z-index: 3; pointer-events: none;
    transform: translate(-50%, -50%) rotate(-14deg);
    font-weight: 700; font-size: 2.2rem; letter-spacing: 0.1em;
    text-transform: uppercase; white-space: nowrap; color: #000;
    padding: 0.4rem 1.3rem; border: 4px double #000; opacity: 0.55;
  }

  @media (max-width: 640px) {
    .slip { padding: 1.25rem 1rem; }
    .meta-row { flex-direction: column; gap: 0.6rem; }
    .sign-row { flex-direction: column; gap: 2rem; }
  }

  @media print {
    body { background: #fff; padding: 20px 0 0; }
    .toolbar { display: none; }
    .related-box { display: none; }
    .sheet-wrap { max-width: 100%; margin-top: 0; }
    .slip { border: 1.5px solid #000; }
    table.slip-table input.qty-input { color: #000; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a class="back" href="<?= BASE_URL ?>/inventory/item_requests.php">&larr; Back to Purchase Requests (PO)</a>
  <div class="toolbar-actions">
    <button class="print-btn" onclick="window.print()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print slip
    </button>
  </div>
</div>

<div class="sheet-wrap">
  <div class="slip">
    <?php if ($req['status'] === 'fulfilled'): ?>
      <div class="status-stamp">Fulfilled</div>
    <?php elseif ($req['status'] === 'rejected'): ?>
      <div class="status-stamp">Closed</div>
    <?php endif; ?>

    <div class="letterhead">
      <h1>DUARTE TRUCKING</h1>
      <div class="addr">Brgy. Polomolok, Tupi, South Cotabato</div>
      <div class="title">Purchase Requisition Slip</div>
    </div>

    <div class="meta-row">
      <div class="field"><span class="label">To:</span><span class="line">Purchasing / Inventory Staff</span></div>
      <div class="field"><span class="label">Date:</span><span class="line"><?= htmlspecialchars(date('m-d-Y', strtotime($req['created_at']))) ?></span></div>
    </div>
    <div class="meta-row">
      <div class="field"><span class="label">Name of Requestor:</span><span class="line"><?= htmlspecialchars((string)($req['requester_name'] ?? '')) ?></span></div>
    </div>

    <?php if ($related): ?>
      <div class="related-box">
        <span class="flag">Combined Pending Demand<?= $mixed_units ? ' (converted to pieces)' : '' ?></span>
        <div class="related-total">
          This request: <?= htmlspecialchars(item_request_qty_display((int)$req['quantity'], $req_resolved)) ?>
          &nbsp;·&nbsp; Combined total: <strong><?= $suggested_qty ?> <?= htmlspecialchars(strtolower($suggested_qty === 1 ? $suggested_unit_label : $suggested_unit_label_plural)) ?></strong>
        </div>
        <ul>
          <?php foreach ($related as $rel): ?>
            <li>Request #<?= $rel['id'] ?> — <?= htmlspecialchars((string)($rel['requester_name'] ?? '')) ?>: <?= htmlspecialchars(item_request_qty_display((int)$rel['quantity'], $rel['resolved'])) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <table class="slip-table">
      <thead>
        <tr>
          <th style="width:10%;">Item No.</th>
          <th style="width:30%;">Description (Item Specifications)</th>
          <th style="width:10%;">Requested</th>
          <th style="width:10%;">Reserve</th>
          <th style="width:12%;">Total PO Qty</th>
          <th style="width:9%;">Unit</th>
          <th style="width:9%;">Qty on Hand</th>
          <th style="width:10%;">Est. Unit Cost (₱)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?= htmlspecialchars($req['item_code'] ?? '—') ?></td>
          <td class="desc"><?= htmlspecialchars((string)($req['item_name'] ?? '')) ?></td>
          <td id="baseReqQty"><?= $suggested_qty ?></td>
          <td><input aria-label="Reserve quantity" type="text" inputmode="numeric" class="qty-input" id="reserveQtyInput" name="reserve_qty" placeholder="0" value="0"></td>
          <td><input aria-label="Ordered quantity" type="text" inputmode="numeric" class="qty-input" id="orderedQtyInput" name="ordered_qty" value="<?= $suggested_qty ?>" style="font-weight:700;"></td>
          <td><?= htmlspecialchars($suggested_qty === 1 ? $suggested_unit_label : $suggested_unit_label_plural) ?></td>
          <td><?= $req['item_id'] ? (int)$req['quantity_on_hand'] : '0' ?></td>
          <td></td>
        </tr>
        <?php for ($i = 0; $i < 4; $i++): ?>
          <tr class="blank-row"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <div class="remarks">
      <span class="label">Remarks:</span>
      <span class="line"><?= htmlspecialchars((string)($req_resolved['reason'] ?? '')) ?></span>
    </div>

    <?php if ($req['status'] !== 'pending'): ?>
      <div class="remarks" style="margin-top:0.6rem;">
        <span class="label"><?= $req['status'] === 'fulfilled' ? 'Fulfilled' : 'Closed' ?> by:</span>
        <span class="line"><?= htmlspecialchars($req['decided_by_name'] ?? '—') ?><?php if ($req['decision_note']): ?> — <?= htmlspecialchars((string)($req['decision_note'] ?? '')) ?><?php endif; ?></span>
      </div>
    <?php endif; ?>


    <div class="sign-row">
      <div class="box">
        <div class="sign-line">Prepared by (signature / date needed / plate no.)</div>
      </div>
      <div class="box">
        <div class="sign-line">Approved by (Executive Assistant for Operations)</div>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    var baseEl = document.getElementById('baseReqQty');
    var reserveEl = document.getElementById('reserveQtyInput');
    var orderedEl = document.getElementById('orderedQtyInput');
    if (!baseEl || !reserveEl || !orderedEl) return;

    var baseQty = parseInt(baseEl.textContent, 10) || 0;

    reserveEl.addEventListener('input', function () {
      var r = parseInt(reserveEl.value, 10) || 0;
      orderedEl.value = Math.max(0, baseQty + r);
    });

    orderedEl.addEventListener('input', function () {
      var o = parseInt(orderedEl.value, 10) || 0;
      var diff = o - baseQty;
      reserveEl.value = diff > 0 ? diff : 0;
    });
  })();
</script>

</body>
</html>
