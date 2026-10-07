<?php
/**
 * DuaRTE — Fleet Monitoring & Truck Management.
 *
 * Displays fleet operational status (Available, On Trip, Under Maintenance),
 * assigned maintenance requisitions, and allows updating truck status or adding new trucks.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/assets.php';

require_role(['admin', 'field_supervisor', 'inventory_staff']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

$can_manage = in_array($user['role'], ['admin', 'field_supervisor'], true);
$can_manage_tools = in_array($user['role'], ['admin', 'field_supervisor', 'inventory_staff'], true);

// Handle POST actions: Status Update or Add/Edit Truck
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['update_status'])) {
        if (!$can_manage) {
            $errors[] = 'You do not have permission to modify fleet trucks. Fleet management is reserved for Field Supervisors and Administrators.';
        } else {
            $truck_id = (int)$_POST['truck_id'];
        $new_status = $_POST['status'] ?? '';
        $valid_statuses = ['available', 'on_trip', 'under_maintenance'];

        if (!in_array($new_status, $valid_statuses, true)) {
            $errors[] = 'Invalid status selected.';
        } else {
            $t_stmt = $pdo->prepare('SELECT * FROM trucks WHERE id = :id');
            $t_stmt->execute(['id' => $truck_id]);
            $truck = $t_stmt->fetch();

            if (!$truck) {
                $errors[] = 'Truck not found.';
            } elseif ($new_status === 'under_maintenance') {
                // Guard: Check if truck is currently out on an active trip with unreturned borrowed items
                $active_trips = $pdo->prepare("
                    SELECT COUNT(*) FROM requisitions r
                    JOIN tool_loans tl ON tl.requisition_id = r.id
                    WHERE r.truck_id = :tid AND r.status = 'released' AND tl.returned_at IS NULL
                ");
                $active_trips->execute(['tid' => $truck_id]);
                if ((int)$active_trips->fetchColumn() > 0) {
                    $errors[] = "Truck {$truck['plate_number']} is currently dispatched on an active trip with unreturned tools. Check in all borrowed equipment before placing vehicle under maintenance.";
                } else {
                    $upd = $pdo->prepare('UPDATE trucks SET status = :st WHERE id = :id');
                    $upd->execute(['st' => $new_status, 'id' => $truck_id]);

                    $st_info = truck_status_info($new_status);
                    log_audit_event(
                        $pdo,
                        $user,
                        'truck_status_update',
                        'truck',
                        $truck_id,
                        "Updated truck {$truck['plate_number']} status to {$st_info['label']}."
                    );

                    $flash_success = "Truck {$truck['plate_number']} status changed to {$st_info['label']}.";
                }
            } else {
                $upd = $pdo->prepare('UPDATE trucks SET status = :st WHERE id = :id');
                $upd->execute(['st' => $new_status, 'id' => $truck_id]);

                $st_info = truck_status_info($new_status);
                log_audit_event(
                    $pdo,
                    $user,
                    'truck_status_update',
                    'truck',
                    $truck_id,
                    "Updated truck {$truck['plate_number']} status to {$st_info['label']}."
                );

                $flash_success = "Truck {$truck['plate_number']} status changed to {$st_info['label']}.";
            }
        }
        }
    } elseif (isset($_POST['add_truck'])) {
        $plate = sanitize_license_plate($_POST['plate_number'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $status = $_POST['status'] ?? 'available';
        $notes = trim($_POST['notes'] ?? '');

        if ($plate === '') {
            $errors[] = 'Plate number is required.';
        } elseif (!validate_license_plate($plate)) {
            $errors[] = 'Invalid plate number format. Accepted formats: "NDX 4059", "ABC 123", or "MV 123456".';
        } elseif ($model === '') {
            $errors[] = 'Truck model or type is required.';
        } elseif (!in_array($status, ['available', 'on_trip', 'under_maintenance'], true)) {
            $errors[] = 'Invalid status selected.';
        } else {
            $chk = $pdo->prepare('SELECT id FROM trucks WHERE plate_number = :p');
            $chk->execute(['p' => $plate]);
            if ($chk->fetch()) {
                $errors[] = "Truck with plate number '$plate' already exists.";
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO trucks (plate_number, model, status, notes)
                     VALUES (:p, :m, :s, :n)'
                );
                $ins->execute([
                    'p' => $plate,
                    'm' => $model,
                    's' => $status,
                    'n' => $notes ?: null,
                ]);
                $new_id = (int)$pdo->lastInsertId();

                log_audit_event(
                    $pdo,
                    $user,
                    'truck_add',
                    'truck',
                    $new_id,
                    "Added new truck {$plate} ({$model})."
                );

                $flash_success = "Truck {$plate} added successfully.";
            }
        }
    } elseif (isset($_POST['assign_onboard_tool'])) {
        if (!$can_manage_tools) {
            $errors[] = 'You do not have permission to assign onboard equipment.';
        } else {
            $truck_id = (int)($_POST['truck_id'] ?? 0);
            $asset_id = (int)($_POST['asset_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($truck_id <= 0 || $asset_id <= 0) {
                $errors[] = 'Pumili ng truck at gamit na ia-assign bilang onboard kit.';
            } else {
                try {
                    assign_asset_to_truck($pdo, $asset_id, $truck_id, $user, $note);
                    $flash_success = 'Matagumpay na nai-assign ang gamit bilang Kit ng Sasakyan (Onboard Kit).';
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['unassign_onboard_tool'])) {
        if (!$can_manage_tools) {
            $errors[] = 'You do not have permission to modify onboard equipment.';
        } else {
            $asset_id = (int)($_POST['asset_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($asset_id <= 0) {
                $errors[] = 'Pumili ng gamit na aalisin sa truck.';
            } else {
                try {
                    unassign_asset_from_truck($pdo, $asset_id, $user, $note);
                    $flash_success = 'Naibalik na sa bodega ang gamit mula sa truck kit.';
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// Metrics
$counts = [
    'total'             => 0,
    'available'         => 0,
    'on_trip'           => 0,
    'under_maintenance' => 0,
];

$count_rows = $pdo->query(
    'SELECT status, COUNT(*) c FROM trucks GROUP BY status'
)->fetchAll();

foreach ($count_rows as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['c'];
    }
    $counts['total'] += (int)$row['c'];
}

// Filter
$filter_status = $_GET['status'] ?? '';
$where = '';
$params = [];
if (in_array($filter_status, ['available', 'on_trip', 'under_maintenance'], true)) {
    $where = 'WHERE t.status = :st';
    $params['st'] = $filter_status;
}

// Query trucks with detailed requisition breakdown (repairs vs trip gear)
$sql = "SELECT t.*,
               (SELECT COUNT(*) FROM requisitions r 
                WHERE r.truck_id = t.id 
                  AND r.is_maintenance_request = 1 
                  AND r.status IN ('pending', 'approved')) AS active_repairs_count,
               (SELECT GROUP_CONCAT(r.id ORDER BY r.id ASC SEPARATOR ',') FROM requisitions r 
                WHERE r.truck_id = t.id 
                  AND r.is_maintenance_request = 1 
                  AND r.status IN ('pending', 'approved')) AS active_repair_ids,
               (SELECT COUNT(*) FROM requisitions r 
                WHERE r.truck_id = t.id 
                  AND (r.is_maintenance_request = 0 OR r.is_maintenance_request IS NULL) 
                  AND r.status IN ('pending', 'approved')) AS active_trip_count,
               (SELECT COUNT(*) FROM requisitions r 
                JOIN tool_loans tl ON tl.requisition_id = r.id 
                WHERE r.truck_id = t.id 
                  AND r.status = 'released' 
                  AND tl.returned_at IS NULL) AS unreturned_loans_count
        FROM trucks t
        $where
        ORDER BY 
            CASE t.status 
                WHEN 'under_maintenance' THEN 1 
                WHEN 'on_trip' THEN 2 
                WHEN 'available' THEN 3 
                ELSE 4 
            END,
            t.plate_number ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$trucks = $stmt->fetchAll();

foreach ($trucks as &$trk) {
    $trk['onboard_tools'] = get_truck_onboard_assets($pdo, (int)$trk['id']);
}
unset($trk);

$available_tools_for_assignment = $pdo->query("
    SELECT a.id, a.asset_tag, i.name AS item_name, a.location_note, a.condition_note
    FROM assets a
    JOIN items i ON i.id = a.item_id
    WHERE a.status = 'available' AND a.assigned_truck_id IS NULL AND i.is_borrowable = 1
    ORDER BY i.name ASC, a.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Fleet Monitoring';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="eyebrow">Fleet Management &amp; Dispatch</div>
    <h1>Fleet Monitoring</h1>
  </div>
  <?php if ($can_manage): ?>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('addTruckModal').classList.add('open'); document.getElementById('addTruckBackdrop').classList.add('open');">
      + Add New Truck
    </button>
  <?php else: ?>
    <span class="badge" style="align-self:center; font-size:0.85rem; padding:0.4rem 0.75rem; background:var(--bg-subtle, #f1f5f9); color:var(--ink-soft); border:1px solid var(--line);">
      👁️ View-Only Access
    </span>
  <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php if ($flash_success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
  <div class="card" style="margin:0; padding:1.2rem;">
    <div class="eyebrow meta-mono">Total Fleet</div>
    <div style="font-size:1.8rem; font-weight:700; margin-top:0.3rem;"><?= $counts['total'] ?></div>
    <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.2rem;">Registered vehicles</div>
  </div>
  <div class="card" style="margin:0; padding:1.2rem; border-left:4px solid var(--green-ok);">
    <div class="eyebrow meta-mono" style="color:var(--green-ok);">Available</div>
    <div style="font-size:1.8rem; font-weight:700; margin-top:0.3rem;"><?= $counts['available'] ?></div>
    <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.2rem;">Ready for dispatch</div>
  </div>
  <div class="card" style="margin:0; padding:1.2rem; border-left:4px solid var(--amber);">
    <div class="eyebrow meta-mono" style="color:var(--amber);">On Trip</div>
    <div style="font-size:1.8rem; font-weight:700; margin-top:0.3rem;"><?= $counts['on_trip'] ?></div>
    <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.2rem;">Active road delivery</div>
  </div>
  <div class="card" style="margin:0; padding:1.2rem; border-left:4px solid var(--red-danger);">
    <div class="eyebrow meta-mono" style="color:var(--red-danger);">Under Maintenance</div>
    <div style="font-size:1.8rem; font-weight:700; margin-top:0.3rem;"><?= $counts['under_maintenance'] ?></div>
    <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.2rem;">In yard / repair shop</div>
  </div>
</div>

<!-- Range Filter Tabs -->
<div class="range-tabs" role="tablist" style="margin-bottom:1rem;">
  <a href="?status=" class="range-tab <?= $filter_status === '' ? 'active' : '' ?>">All Trucks (<?= $counts['total'] ?>)</a>
  <a href="?status=available" class="range-tab <?= $filter_status === 'available' ? 'active' : '' ?>">Available (<?= $counts['available'] ?>)</a>
  <a href="?status=on_trip" class="range-tab <?= $filter_status === 'on_trip' ? 'active' : '' ?>">On Trip (<?= $counts['on_trip'] ?>)</a>
  <a href="?status=under_maintenance" class="range-tab <?= $filter_status === 'under_maintenance' ? 'active' : '' ?>">Under Maintenance (<?= $counts['under_maintenance'] ?>)</a>
</div>

<!-- Informative Banner: Permanent Onboard Kit -->
<div style="background:var(--blue-tint, #eff6ff); border:1px solid var(--blue-border, #bfdbfe); border-radius:8px; padding:0.9rem 1.2rem; margin-bottom:1.2rem; display:flex; align-items:flex-start; gap:0.8rem;">
  <span style="font-size:1.4rem; line-height:1;">🧰</span>
  <div style="font-size:0.88rem; color:var(--ink);">
    <strong>Kit ng Sasakyan (Onboard Equipment):</strong>
    Ang mga gamit tulad ng Hydraulic Jack, Tire Wrench, at Fire Extinguisher ay permanenteng nakatalaga sa sasakyan. 
    <em>Hindi ito sakop ng 3-araw na countdown ng borrowing</em> kaya hindi kailangang mag-extend nang mag-extend ang driver araw-araw habang ginagamit sa byahe.
  </div>
</div>

<!-- Fleet Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data">
      <thead>
        <tr>
          <th style="min-width:140px;">Plate Number</th>
          <th style="min-width:170px;">Model / Vehicle Type</th>
          <th style="min-width:130px;">Current Status</th>
          <th style="min-width:240px;">🧰 Kit ng Sasakyan (Onboard)</th>
          <th style="min-width:210px;">Koneksyon sa Requisition</th>
          <th style="min-width:160px;">Notes</th>
          <?php if ($can_manage): ?><th style="min-width:140px; text-align:right;">Quick Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$trucks): ?>
          <tr><td colspan="<?= $can_manage ? 7 : 6 ?>">No trucks found in this category.</td></tr>
        <?php else: foreach ($trucks as $trk):
          $st_info = truck_status_info($trk['status']);
          $repair_count = (int)($trk['active_repairs_count'] ?? 0);
          $trip_count = (int)($trk['active_trip_count'] ?? 0);
          $unreturned = (int)($trk['unreturned_loans_count'] ?? 0);
          $repair_ids = !empty($trk['active_repair_ids']) ? explode(',', $trk['active_repair_ids']) : [];
        ?>
          <tr>
            <td data-label="Plate Number">
              <span class="mono" style="font-size:1.05rem; font-weight:700; letter-spacing:0.04em;">
                🚚 <?= htmlspecialchars((string)($trk['plate_number'] ?? '')) ?>
              </span>
            </td>
            <td data-label="Model">
              <div style="font-weight:600;"><?= htmlspecialchars((string)($trk['model'] ?? '')) ?></div>
            </td>
            <td data-label="Status">
              <span class="badge <?= $st_info['class'] ?>" style="font-size:0.82rem; font-weight:600;">
                <?= htmlspecialchars((string)($st_info['label'] ?? '')) ?>
              </span>
            </td>
            <td data-label="Kit ng Sasakyan">
              <?php if (!empty($trk['onboard_tools'])): ?>
                <div style="display:flex; flex-direction:column; gap:0.35rem;">
                  <?php foreach ($trk['onboard_tools'] as $obt): ?>
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.4rem; background:var(--bg-subtle, #f8fafc); border:1px solid var(--line, #e2e8f0); border-radius:6px; padding:0.25rem 0.5rem; font-size:0.8rem;">
                      <div>
                        <span style="font-weight:600; color:var(--ink);">🛠️ <?= htmlspecialchars($obt['item_name']) ?></span>
                        <span class="mono" style="font-size:0.75rem; color:var(--ink-soft);">(<?= htmlspecialchars($obt['asset_tag']) ?>)</span>
                      </div>
                      <?php if ($can_manage_tools): ?>
                        <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Isauli ang <?= htmlspecialchars(addslashes($obt['item_name'])) ?> sa bodega?');">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                          <input type="hidden" name="asset_id" value="<?= (int)$obt['id'] ?>">
                          <button type="submit" name="unassign_onboard_tool" value="1" class="btn btn-sm btn-ghost" style="padding:0.1rem 0.35rem; font-size:0.72rem; color:var(--red-danger);" title="Alisin sa truck at isauli sa bodega">✕ Isauli</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($can_manage_tools && !empty($available_tools_for_assignment)): ?>
                    <button type="button" class="btn btn-sm" style="font-size:0.75rem; padding:0.2rem 0.5rem; margin-top:0.2rem; align-self:flex-start;" onclick="openAssignToolModal(<?= (int)$trk['id'] ?>, '<?= htmlspecialchars(addslashes($trk['plate_number'])) ?>')">
                      + Mag-assign ng Gamit
                    </button>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:0.3rem;">
                  <span class="text-muted" style="font-size:0.8rem;">— Walang naka-assign na kit —</span>
                  <?php if ($can_manage_tools && !empty($available_tools_for_assignment)): ?>
                    <button type="button" class="btn btn-sm" style="font-size:0.75rem; padding:0.2rem 0.5rem; align-self:flex-start;" onclick="openAssignToolModal(<?= (int)$trk['id'] ?>, '<?= htmlspecialchars(addslashes($trk['plate_number'])) ?>')">
                      + Mag-assign ng Gamit
                    </button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td data-label="Koneksyon sa Requisition">
              <div style="display:flex; flex-direction:column; gap:0.35rem;">
                <?php if ($repair_count > 0): ?>
                  <div style="display:flex; align-items:center; flex-wrap:wrap; gap:0.3rem;">
                    <span class="badge" style="background:var(--blue-tint); color:var(--blue-info); border:1px solid var(--blue-border); font-size:0.75rem; font-weight:600;">
                      🔧 Kumpuni / Parts:
                    </span>
                    <?php foreach ($repair_ids as $rid): ?>
                      <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= (int)$rid ?>" class="badge badge-error mono" title="Tingnan ang Requisition para sa pyesa ng truck na ito" style="font-size:0.75rem; text-decoration:none;">
                        #<?= (int)$rid ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                  <?php if ($trk['status'] === 'available'): ?>
                    <div>
                      <span class="badge is-warn" style="font-size:0.72rem;" title="May nakabinbing repair request pero Available pa ang truck">
                        ⚠️ May nakapilang kumpuni
                      </span>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>

                <?php if ($trip_count > 0): ?>
                  <div>
                    <a href="<?= BASE_URL ?>/requisition/all.php?q=<?= urlencode($trk['plate_number']) ?>" class="badge role" title="Tingnan ang mga gamit sa byahe ng truck" style="font-size:0.75rem; text-decoration:none;">
                      🚛 <?= $trip_count ?> Trip Requisition<?= $trip_count > 1 ? 's' : '' ?>
                    </a>
                  </div>
                <?php endif; ?>

                <?php if ($unreturned > 0): ?>
                  <div>
                    <span class="badge" style="background:var(--amber-tint); color:var(--amber); border:1px solid var(--amber-border); font-size:0.75rem;" title="May mga gamit na hiniram ang crew para sa truck na ito na hindi pa naibabalik">
                      🧰 <?= $unreturned ?> gamit di pa naisasauli
                    </span>
                  </div>
                <?php endif; ?>

                <?php if ($repair_count === 0 && $trip_count === 0 && $unreturned === 0): ?>
                  <?php if ($trk['status'] === 'under_maintenance'): ?>
                    <span class="text-muted" style="font-size:0.78rem;">ℹ️ Walang open repair ticket</span>
                  <?php else: ?>
                    <span class="text-muted" style="font-size:0.78rem;">— Walang active request —</span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Notes" style="font-size:0.85rem; color:var(--ink-soft);">
              <?= htmlspecialchars($trk['notes'] ?? '—') ?>
            </td>
            <?php if ($can_manage): ?>
              <td data-label="Action">
                <form method="post" style="display:inline-flex; align-items:center; gap:0.4rem;">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="truck_id" value="<?= (int)$trk['id'] ?>">
                  <select aria-label="Status for <?= htmlspecialchars((string)($trk['plate_number'] ?? '')) ?>" name="status" style="padding:0.25rem 0.4rem; font-size:0.82rem; border-radius:4px; border:1px solid var(--line);" onchange="this.form.submit()">
                    <option value="available" <?= $trk['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="on_trip" <?= $trk['status'] === 'on_trip' ? 'selected' : '' ?>>On Trip</option>
                    <option value="under_maintenance" <?= $trk['status'] === 'under_maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
                  </select>
                  <input type="hidden" name="update_status" value="1">
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Add New Truck -->
<?php if ($can_manage): ?>
<div class="item-modal-backdrop" id="addTruckBackdrop" onclick="this.classList.remove('open'); document.getElementById('addTruckModal').classList.remove('open');"></div>
<div class="item-modal" id="addTruckModal" role="dialog" aria-modal="true" style="max-width:480px; padding:1.5rem;">
  <button type="button" class="item-modal-close" onclick="document.getElementById('addTruckModal').classList.remove('open'); document.getElementById('addTruckBackdrop').classList.remove('open');">&times;</button>
  <h2 style="margin-top:0; font-size:1.3rem;">Add Fleet Truck</h2>
  <p style="font-size:0.85rem; color:var(--ink-soft); margin-top:-0.3rem; margin-bottom:1.2rem;">Register a truck into the Duarte fleet for maintenance tracking and requisition assignment.</p>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="form-group" style="margin-bottom:1rem;">
      <label for="plate_number" style="font-weight:600;">Plate Number *</label>
      <input type="text" id="plate_number" name="plate_number" required placeholder="e.g. ABC-1234" style="width:100%; padding:0.55rem; text-transform:uppercase; font-family:var(--font-mono);">
    </div>
    <div class="form-group" style="margin-bottom:1rem;">
      <label for="model" style="font-weight:600;">Truck Model / Body Type *</label>
      <input type="text" id="model" name="model" required placeholder="e.g. Isuzu Giga" style="width:100%; padding:0.55rem;">
    </div>
    <div class="form-group" style="margin-bottom:1rem;">
      <label for="status" style="font-weight:600;">Initial Status</label>
      <select id="status" name="status" style="width:100%; padding:0.55rem;">
        <option value="available">Available (Ready for dispatch)</option>
        <option value="on_trip">On Trip (Currently on haul)</option>
        <option value="under_maintenance">Under Maintenance (Under repair)</option>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:1.2rem;">
      <label for="notes" style="font-weight:600;">Notes / Assignment Route (optional)</label>
      <textarea id="notes" name="notes" rows="2" placeholder="e.g. Batangas depot" style="width:100%; padding:0.55rem;"></textarea>
    </div>
    <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('addTruckModal').classList.remove('open'); document.getElementById('addTruckBackdrop').classList.remove('open');">Cancel</button>
      <button type="submit" name="add_truck" value="1" class="btn btn-primary">Save Truck</button>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Modal: Assign Tool to Truck -->
<?php if ($can_manage_tools): ?>
<div class="item-modal-backdrop" id="assignToolBackdrop" onclick="closeAssignToolModal()"></div>
<div class="item-modal" id="assignToolModal" role="dialog" aria-modal="true" style="max-width:480px; padding:1.5rem;">
  <button type="button" class="item-modal-close" onclick="closeAssignToolModal()">&times;</button>
  <h2 style="margin-top:0; font-size:1.3rem;">Mag-assign ng Gamit sa Truck</h2>
  <p style="font-size:0.85rem; color:var(--ink-soft); margin-top:-0.3rem; margin-bottom:1.2rem;">
    I-assign ang gamit bilang permanenteng <strong id="assignToolPlateDisplay"></strong> Onboard Kit (hindi nag-eexpire, walang daily loan extension).
  </p>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="truck_id" id="assignToolTruckId" value="">
    
    <div class="form-group" style="margin-bottom:1rem;">
      <label for="assign_asset_id" style="font-weight:600;">Piliin ang Gamit mula sa Bodega *</label>
      <select id="assign_asset_id" name="asset_id" required style="width:100%; padding:0.55rem;">
        <option value="">-- Piliin ang available na gamit --</option>
        <?php foreach ($available_tools_for_assignment as $av): ?>
          <option value="<?= (int)$av['id'] ?>">
            <?= htmlspecialchars($av['item_name']) ?> (<?= htmlspecialchars($av['asset_tag']) ?>) <?= $av['location_note'] ? '— ' . htmlspecialchars($av['location_note']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group" style="margin-bottom:1.2rem;">
      <label for="assign_note" style="font-weight:600;">Note / Deskripsyon (opsyonal)</label>
      <input type="text" id="assign_note" name="note" placeholder="hal. Bottle Jack para sa reserbang gulong" style="width:100%; padding:0.55rem;">
    </div>

    <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
      <button type="button" class="btn btn-outline" onclick="closeAssignToolModal()">Cancel</button>
      <button type="submit" name="assign_onboard_tool" value="1" class="btn btn-primary">I-assign sa Truck Kit</button>
    </div>
  </form>
</div>

<script>
function openAssignToolModal(truckId, plateNumber) {
  document.getElementById('assignToolTruckId').value = truckId;
  document.getElementById('assignToolPlateDisplay').textContent = plateNumber;
  document.getElementById('assignToolModal').classList.add('open');
  document.getElementById('assignToolBackdrop').classList.add('open');
}
function closeAssignToolModal() {
  document.getElementById('assignToolModal').classList.remove('open');
  document.getElementById('assignToolBackdrop').classList.remove('open');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
