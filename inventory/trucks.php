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

require_role(['admin', 'field_supervisor', 'inventory_staff']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

$can_manage = in_array($user['role'], ['admin', 'field_supervisor'], true);

// Handle POST actions: Status Update or Add/Edit Truck
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_manage) {
        $errors[] = 'You do not have permission to modify fleet trucks. Fleet management is reserved for Field Supervisors and Administrators.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['update_status'])) {
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

// Query trucks with active requisitions count
$sql = "SELECT t.*,
               (SELECT COUNT(*) FROM requisitions r WHERE r.truck_id = t.id AND r.status IN ('pending', 'approved')) AS active_requisitions_count
        FROM trucks t
        $where
        ORDER BY t.plate_number ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$trucks = $stmt->fetchAll();

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

<!-- Fleet Table -->
<div class="card">
  <div class="table-responsive">
    <table class="data">
      <thead>
        <tr>
          <th style="min-width:140px;">Plate Number</th>
          <th style="min-width:180px;">Model / Vehicle Type</th>
          <th style="min-width:140px;">Current Status</th>
          <th style="min-width:160px;">Active Parts / Requests</th>
          <th style="min-width:180px;">Notes</th>
          <?php if ($can_manage): ?><th style="min-width:150px; text-align:right;">Quick Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$trucks): ?>
          <tr><td colspan="<?= $can_manage ? 6 : 5 ?>">No trucks found in this category.</td></tr>
        <?php else: foreach ($trucks as $trk):
          $st_info = truck_status_info($trk['status']);
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
            <td data-label="Active Requests">
              <?php if ((int)$trk['active_requisitions_count'] > 0): ?>
                <a href="<?= BASE_URL ?>/requisition/all.php?q=<?= urlencode($trk['plate_number']) ?>" class="badge role" title="Open parts requisitions for this truck">
                  <?= (int)$trk['active_requisitions_count'] ?> pending/approved
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
