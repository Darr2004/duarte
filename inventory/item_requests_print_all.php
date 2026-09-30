<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/item_requests.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();

// One row per distinct item name, combining every pending request for
// it. Requesters can use different units for the "same" item name
// (one typed "1 set", another "4 pcs"), so this can't be a plain
// SUM(quantity) in SQL — it has to be grouped in PHP so mismatched
// units get converted to individual pieces instead of summed as-is.
$stmt = $pdo->query(
    "SELECT ir.item_name, ir.quantity, ir.reason, ir.item_id, ir.created_at, i.unit AS catalog_unit
     FROM item_requests ir
     JOIN users u ON u.id = ir.requester_id
     LEFT JOIN items i ON i.id = ir.item_id
     WHERE ir.status = 'pending'
     ORDER BY ir.created_at ASC"
);
$all_requests = $stmt->fetchAll();
foreach ($all_requests as &$r) {
    $r['resolved'] = item_request_resolve($r, $r['catalog_unit']);
}
unset($r);

$grouped = [];
foreach ($all_requests as $r) {
    $key = strtolower($r['item_name']);
    if (!isset($grouped[$key])) {
        $grouped[$key] = ['item_name' => $r['item_name'], 'rows' => [], 'earliest_at' => $r['created_at']];
    }
    $grouped[$key]['rows'][] = $r;
}

$rows = [];
foreach ($grouped as $g) {
    $rows_for_item = $g['rows'];
    $first = $rows_for_item[0];
    $same_unit = true;
    foreach ($rows_for_item as $r) {
        if ($r['resolved']['unit'] !== $first['resolved']['unit'] || $r['resolved']['pieces_per_unit'] !== $first['resolved']['pieces_per_unit']) {
            $same_unit = false;
            break;
        }
    }
    if ($same_unit) {
        $total_qty = array_sum(array_column($rows_for_item, 'quantity'));
        $unit_label = $total_qty === 1 ? item_request_unit_label($first['resolved']['unit']) : item_request_unit_label_plural($first['resolved']['unit']);
    } else {
        $total_qty = array_sum(array_map(fn($r) => item_request_total_pieces((int)$r['quantity'], $r['resolved']), $rows_for_item));
        $unit_label = $total_qty === 1 ? 'Piece' : 'Pieces';
    }
    $rows[] = [
        'item_name'    => $g['item_name'],
        'total_qty'    => $total_qty,
        'unit_label'   => $unit_label,
        'mixed_units'  => !$same_unit,
        'request_count'=> count($rows_for_item),
        'earliest_at'  => $g['earliest_at'],
    ];
}
usort($rows, fn($a, $b) => strcmp($a['earliest_at'], $b['earliest_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase Requisition Slip — All Pending Requests</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0;
    padding: 2rem 1.5rem 3rem; background: #fff;
  }

  .toolbar {
    max-width: 850px; margin: 0 auto 1.25rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  }
  .toolbar a.back {
    font-weight: 600; font-size: 0.85rem; color: #444; text-decoration: none;
    display: inline-flex; align-items: center; gap: 0.35rem;
  }
  .toolbar a.back:hover { color: #000; }
  .print-btn {
    font-family: inherit; font-weight: 600; font-size: 0.85rem;
    background: #333; color: #fff; border: none; border-radius: 6px;
    padding: 0.55rem 1.1rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
  }
  .print-btn:hover { background: #000; }
  .print-btn svg { width: 16px; height: 16px; }

  .sheet-wrap { max-width: 850px; margin: 0 auto; }

  .slip {
    background: #fff; border: 2px solid #000; padding: 1.5rem 1.75rem;
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
  table.slip-table th, table.slip-table td { border: 1px solid #000; padding: 0.5rem 0.45rem; text-align: center; vertical-align: top; }
  table.slip-table th { font-weight: 700; font-size: 0.78rem; }
  table.slip-table td.desc { text-align: left; }
  table.slip-table td.desc .item-name { font-weight: 600; }
  table.slip-table td.qty { font-weight: 700; }
  table.slip-table input.qty-input {
    width: 100%; border: none; text-align: center; font: inherit; font-weight: 700; color: #000; background: transparent;
  }
  table.slip-table input.qty-input:focus { outline: 1px dashed #666; }
  .blank-row td { height: 26px; }

  .remarks { margin-top: 1rem; font-size: 0.9rem; display: flex; align-items: baseline; gap: 0.4rem; }
  .remarks .label { font-weight: 600; white-space: nowrap; }
  .remarks .line { border-bottom: 1px solid #000; flex: 1; min-height: 1.1rem; }

  .sign-row { display: flex; justify-content: space-between; gap: 1.5rem; margin-top: 2.5rem; font-size: 0.8rem; }
  .sign-row .box { flex: 1; text-align: center; }
  .sign-row .box .sign-line { border-top: 1px solid #000; margin-top: 2.2rem; padding-top: 0.3rem; font-weight: 500; }

  .empty-note { text-align: center; padding: 2.5rem 1rem; color: #555; font-size: 0.9rem; }

  @media (max-width: 640px) {
    .slip { padding: 1.25rem 1rem; }
    .meta-row { flex-direction: column; gap: 0.6rem; }
    .sign-row { flex-direction: column; gap: 2rem; }
  }

  @media print {
    body { background: #fff; padding: 20px 0 0; }
    .toolbar { display: none; }
    .sheet-wrap { max-width: 100%; margin-top: 0; }
    .slip { border: 1.5px solid #000; }
    table.slip-table input.qty-input { color: #000; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a class="back" href="<?= BASE_URL ?>/inventory/item_requests.php">&larr; Back to Purchase Requests (PO)</a>
  <button class="print-btn" onclick="window.print()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Print slip
  </button>
</div>

<div class="sheet-wrap">
  <div class="slip">
    <div class="letterhead">
      <h1>DUARTE TRUCKING</h1>
      <div class="addr">Brgy. Polomolok, Tupi, South Cotabato</div>
      <div class="title">Purchase Requisition Slip</div>
    </div>

    <div class="meta-row">
      <div class="field"><span class="label">To:</span><span class="line">Purchasing / Inventory Staff</span></div>
      <div class="field"><span class="label">Date:</span><span class="line"><?= htmlspecialchars(date('m-d-Y')) ?></span></div>
    </div>
    <div class="meta-row">
      <div class="field"><span class="label">Name of Requestor:</span><span class="line">Various Requesters (Consolidated PO)</span></div>
    </div>

    <table class="slip-table">
      <thead>
        <tr>
          <th style="width:7%;">Item No.</th>
          <th style="width:31%;">Description (Item Specifications)</th>
          <th style="width:10%;">Requested</th>
          <th style="width:10%;">Reserve</th>
          <th style="width:12%;">Total PO Qty</th>
          <th style="width:10%;">Unit</th>
          <th style="width:10%;">Qty on Hand</th>
          <th style="width:10%;">Est. Unit Cost (₱)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="empty-note">No pending requests.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $row): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td class="desc">
                <div class="item-name"><?= htmlspecialchars((string)($row['item_name'] ?? '')) ?></div>
                <?php if ($row['mixed_units']): ?>
                  <div style="font-size:0.72rem; color:#666; margin-top:0.15rem;">Converted to pieces.</div>
                <?php endif; ?>
              </td>
              <td class="js-base-qty"><?= (int)$row['total_qty'] ?></td>
              <td><input aria-label="Reserve quantity: <?= htmlspecialchars((string)($row['item_name'] ?? '')) ?>" type="text" inputmode="numeric" class="qty-input js-reserve-qty" name="reserve_qty[]" placeholder="0" value="0"></td>
              <td><input aria-label="Ordered quantity: <?= htmlspecialchars((string)($row['item_name'] ?? '')) ?>" type="text" inputmode="numeric" class="qty-input js-ordered-qty" name="ordered_qty[]" value="<?= (int)$row['total_qty'] ?>"></td>
              <td><?= htmlspecialchars((string)($row['unit_label'] ?? '')) ?></td>
              <td></td>
              <td></td>
            </tr>
          <?php endforeach; ?>
          <?php for ($i = 0; $i < 3; $i++): ?>
            <tr class="blank-row"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
          <?php endfor; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="remarks">
      <span class="label">Remarks:</span>
      <span class="line">&nbsp;</span>
    </div>

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
    document.querySelectorAll('tbody tr').forEach(function (tr) {
      var baseEl = tr.querySelector('.js-base-qty');
      var reserveEl = tr.querySelector('.js-reserve-qty');
      var orderedEl = tr.querySelector('.js-ordered-qty');
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
    });
  })();
</script>

</body>
</html>
