<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$pdo = get_db();
$user = current_user();
$errors = [];
$flash_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['add_room_number'])) {
        // --- Add Room ---------------------------------------------------
        $room_number_raw = trim($_POST['add_room_number']);
        $name = trim($_POST['add_room_name'] ?? '');
        if ($room_number_raw === '' || !ctype_digit($room_number_raw) || (int)$room_number_raw < 1) {
            $errors[] = 'Room number must be a whole number of 1 or more.';
        } elseif ($name === '') {
            $errors[] = 'Room name is required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO rooms (room_number, name) VALUES (:num, :name)');
                $stmt->execute(['num' => (int)$room_number_raw, 'name' => $name]);
                log_audit_event($pdo, $user, 'room_create', 'room', (int)$pdo->lastInsertId(),
                    $user['full_name'] . ' added Room ' . $room_number_raw . ' ("' . $name . '").');
                $flash_success = 'Room added.';
            } catch (PDOException $e) {
                $errors[] = ($e->getCode() === '23000') ? 'That room number already exists.' : 'Something went wrong. Please try again.';
            }
        }
    } elseif (isset($_POST['delete_room_id'])) {
        $id = (int)$_POST['delete_room_id'];
        $in_use = $pdo->prepare('SELECT COUNT(*) c FROM stalls WHERE room_id = :id');
        $in_use->execute(['id' => $id]);
        if ($in_use->fetch()['c'] > 0) {
            $errors[] = 'This room still has stalls under it and cannot be deleted. Delete or move its stalls first.';
        } else {
            $name_stmt = $pdo->prepare('SELECT name FROM rooms WHERE id = :id');
            $name_stmt->execute(['id' => $id]);
            $room_name = $name_stmt->fetchColumn();
            $pdo->prepare('DELETE FROM rooms WHERE id = :id')->execute(['id' => $id]);
            log_audit_event($pdo, $user, 'room_delete', 'room', $id,
                $user['full_name'] . ' deleted room "' . ($room_name ?: $id) . '".');
            $flash_success = 'Room deleted.';
        }
    } elseif (isset($_POST['add_stall_room_id'])) {
        // --- Add Stall ---------------------------------------------------
        $room_id = (int)$_POST['add_stall_room_id'];
        $stall_number_raw = trim($_POST['add_stall_number'] ?? '');
        $name = trim($_POST['add_stall_name'] ?? '');
        if ($room_id <= 0) {
            $errors[] = 'Choose which room this stall belongs to.';
        } elseif ($stall_number_raw === '' || !ctype_digit($stall_number_raw) || (int)$stall_number_raw < 1) {
            $errors[] = 'Stall number must be a whole number of 1 or more.';
        } elseif ($name === '') {
            $errors[] = 'Stall name is required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO stalls (room_id, stall_number, name) VALUES (:room_id, :num, :name)');
                $stmt->execute(['room_id' => $room_id, 'num' => (int)$stall_number_raw, 'name' => $name]);
                $room_name_stmt = $pdo->prepare('SELECT name FROM rooms WHERE id = :id');
                $room_name_stmt->execute(['id' => $room_id]);
                log_audit_event($pdo, $user, 'stall_create', 'stall', (int)$pdo->lastInsertId(),
                    $user['full_name'] . ' added Stall ' . $stall_number_raw . ' ("' . $name . '") to ' . $room_name_stmt->fetchColumn() . '.');
                $flash_success = 'Stall added.';
            } catch (PDOException $e) {
                $errors[] = ($e->getCode() === '23000') ? 'That stall number already exists in this room.' : 'Something went wrong. Please try again.';
            }
        }
    } elseif (isset($_POST['delete_stall_id'])) {
        $id = (int)$_POST['delete_stall_id'];
        $in_use = $pdo->prepare('SELECT COUNT(*) c FROM stall_layers WHERE stall_id = :id');
        $in_use->execute(['id' => $id]);
        if ($in_use->fetch()['c'] > 0) {
            $errors[] = 'This stall still has layers under it and cannot be deleted. Delete its layers first.';
        } else {
            $name_stmt = $pdo->prepare('SELECT name FROM stalls WHERE id = :id');
            $name_stmt->execute(['id' => $id]);
            $stall_name = $name_stmt->fetchColumn();
            $pdo->prepare('DELETE FROM stalls WHERE id = :id')->execute(['id' => $id]);
            log_audit_event($pdo, $user, 'stall_delete', 'stall', $id,
                $user['full_name'] . ' deleted stall "' . ($stall_name ?: $id) . '".');
            $flash_success = 'Stall deleted.';
        }
    } elseif (isset($_POST['add_layer_stall_id'])) {
        // --- Add Layer ---------------------------------------------------
        $stall_id = (int)$_POST['add_layer_stall_id'];
        $layer_number_raw = trim($_POST['add_layer_number'] ?? '');
        $layer_name = trim($_POST['add_layer_name'] ?? '');
        if ($stall_id <= 0) {
            $errors[] = 'Choose which stall this layer belongs to.';
        } elseif ($layer_number_raw === '' || !ctype_digit($layer_number_raw) || (int)$layer_number_raw < 1) {
            $errors[] = 'Layer number must be a whole number of 1 or more.';
        } elseif ($layer_name === '') {
            $errors[] = 'Layer name is required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO stall_layers (stall_id, layer_number, layer_name) VALUES (:stall_id, :num, :name)');
                $stmt->execute(['stall_id' => $stall_id, 'num' => (int)$layer_number_raw, 'name' => $layer_name]);
                $stall_name_stmt = $pdo->prepare('SELECT name FROM stalls WHERE id = :id');
                $stall_name_stmt->execute(['id' => $stall_id]);
                log_audit_event($pdo, $user, 'layer_create', 'stall_layer', (int)$pdo->lastInsertId(),
                    $user['full_name'] . ' added layer "' . $layer_name . '" to ' . $stall_name_stmt->fetchColumn() . '.');
                $flash_success = 'Layer added.';
            } catch (PDOException $e) {
                $errors[] = ($e->getCode() === '23000') ? 'That layer number or name already exists on this stall.' : 'Something went wrong. Please try again.';
            }
        }
    } elseif (isset($_POST['delete_layer_id'])) {
        $id = (int)$_POST['delete_layer_id'];
        $in_use = $pdo->prepare('SELECT COUNT(*) c FROM items WHERE stall_layer_id = :id');
        $in_use->execute(['id' => $id]);
        if ($in_use->fetch()['c'] > 0) {
            $errors[] = 'This layer still has items assigned to it and cannot be deleted. Reassign those items first.';
        } else {
            $name_stmt = $pdo->prepare('SELECT layer_name FROM stall_layers WHERE id = :id');
            $name_stmt->execute(['id' => $id]);
            $layer_name = $name_stmt->fetchColumn();
            $pdo->prepare('DELETE FROM stall_layers WHERE id = :id')->execute(['id' => $id]);
            log_audit_event($pdo, $user, 'layer_delete', 'stall_layer', $id,
                $user['full_name'] . ' deleted layer "' . ($layer_name ?: $id) . '".');
            $flash_success = 'Layer deleted.';
        }
    }
}

$rooms = $pdo->query(
    "SELECT r.id, r.room_number, r.name, COUNT(s.id) AS stall_count
     FROM rooms r LEFT JOIN stalls s ON s.room_id = r.id
     GROUP BY r.id, r.room_number, r.name ORDER BY r.room_number"
)->fetchAll();

$stalls = $pdo->query(
    "SELECT s.id, s.room_id, s.stall_number, s.name, r.room_number, r.name AS room_name,
            COUNT(sl.id) AS layer_count
     FROM stalls s
     JOIN rooms r ON r.id = s.room_id
     LEFT JOIN stall_layers sl ON sl.stall_id = s.id
     GROUP BY s.id, s.room_id, s.stall_number, s.name, r.room_number, r.name
     ORDER BY r.room_number, s.stall_number"
)->fetchAll();

$layers = $pdo->query(
    "SELECT sl.id, sl.stall_id, sl.layer_number, sl.layer_name,
            s.stall_number, s.name AS stall_name, r.room_number, r.name AS room_name,
            COUNT(i.id) AS item_count
     FROM stall_layers sl
     JOIN stalls s ON s.id = sl.stall_id
     JOIN rooms r ON r.id = s.room_id
     LEFT JOIN items i ON i.stall_layer_id = sl.id
     GROUP BY sl.id, sl.stall_id, sl.layer_number, sl.layer_name, s.stall_number, s.name, r.room_number, r.name
     ORDER BY r.room_number, s.stall_number, sl.layer_number"
)->fetchAll();

$page_title = 'Storage Locations';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Digital inventory catalog</div>
    <h1>Storage Locations</h1>
  </div>
</div>
<p style="color:var(--ink-soft); font-size:0.88rem; margin-top:-0.75rem; margin-bottom:1.5rem;">
  Room &rarr; Stall &rarr; Layer.
</p>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php if ($flash_success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<style>
.loc-add-grid { display: grid; grid-template-columns: 1fr 2fr auto; gap: 1rem; align-items: end; }
@media (max-width: 720px) { .loc-add-grid { grid-template-columns: 1fr !important; } }
</style>

<!-- ROOMS -->
<div class="card" style="margin-bottom:1.5rem; padding:1.25rem 1.5rem;">
  <div style="font-weight:700; font-size:0.98rem; color:var(--ink); margin-bottom:0.85rem;">Add Room</div>
  <form method="post" class="loc-add-grid">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="form-group" style="margin-bottom:0;">
      <label for="add_room_number">Room #</label>
      <input type="text" inputmode="numeric" id="add_room_number" name="add_room_number" placeholder="e.g. 2" required>
    </div>
    <div class="form-group" style="margin-bottom:0;">
      <label for="add_room_name">Room Name</label>
      <input type="text" id="add_room_name" name="add_room_name" placeholder="e.g. Annex Storage" required>
    </div>
    <button type="submit" class="btn btn-primary">+ Add Room</button>
  </form>
</div>

<div class="card" style="margin-bottom:2rem;">
  <div class="table-responsive">
    <table class="data">
      <thead><tr><th>Room #</th><th>Name</th><th>Stalls</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rooms): ?>
          <tr><td colspan="4">No rooms yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rooms as $r): ?>
          <tr>
            <td class="mono" data-label="Room #"><?= (int)$r['room_number'] ?></td>
            <td data-label="Name" style="font-weight:600;"><?= htmlspecialchars((string)($r['name'] ?? '')) ?></td>
            <td class="mono" data-label="Stalls"><?= (int)$r['stall_count'] ?></td>
            <td style="text-align:right;" data-label="Actions">
              <form method="post" onsubmit="return confirm('Delete room <?= htmlspecialchars(addslashes($r['name'])) ?>?');" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="delete_room_id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" <?= $r['stall_count'] > 0 ? 'disabled title="In use — cannot delete"' : '' ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- STALLS -->
<div class="card" style="margin-bottom:1.5rem; padding:1.25rem 1.5rem;">
  <div style="font-weight:700; font-size:0.98rem; color:var(--ink); margin-bottom:0.85rem;">Add Stall</div>
  <?php if (!$rooms): ?>
    <p style="color:var(--ink-soft); font-size:0.85rem;">Add a room first.</p>
  <?php else: ?>
    <form method="post" class="loc-add-grid" style="grid-template-columns: 1.4fr 1fr 1.6fr auto;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_stall_room_id">Room</label>
        <select id="add_stall_room_id" name="add_stall_room_id" required>
          <?php foreach ($rooms as $r): ?>
            <option value="<?= $r['id'] ?>">Room <?= (int)$r['room_number'] ?> — <?= htmlspecialchars((string)($r['name'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_stall_number">Stall #</label>
        <input type="text" inputmode="numeric" id="add_stall_number" name="add_stall_number" placeholder="e.g. 1" required>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_stall_name">Stall Name</label>
        <input type="text" id="add_stall_name" name="add_stall_name" placeholder="e.g. Stall 1" required>
      </div>
      <button type="submit" class="btn btn-primary">+ Add Stall</button>
    </form>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:2rem;">
  <div class="table-responsive">
    <table class="data">
      <thead><tr><th>Room</th><th>Stall #</th><th>Name</th><th>Layers</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (!$stalls): ?>
          <tr><td colspan="5">No stalls yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($stalls as $s): ?>
          <tr>
            <td data-label="Room">Room <?= (int)$s['room_number'] ?> — <?= htmlspecialchars((string)($s['room_name'] ?? '')) ?></td>
            <td class="mono" data-label="Stall #"><?= (int)$s['stall_number'] ?></td>
            <td data-label="Name" style="font-weight:600;"><?= htmlspecialchars((string)($s['name'] ?? '')) ?></td>
            <td class="mono" data-label="Layers"><?= (int)$s['layer_count'] ?></td>
            <td style="text-align:right;" data-label="Actions">
              <form method="post" onsubmit="return confirm('Delete stall <?= htmlspecialchars(addslashes($s['name'])) ?>?');" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="delete_stall_id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" <?= $s['layer_count'] > 0 ? 'disabled title="In use — cannot delete"' : '' ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- LAYERS -->
<div class="card" style="margin-bottom:1.5rem; padding:1.25rem 1.5rem;">
  <div style="font-weight:700; font-size:0.98rem; color:var(--ink); margin-bottom:0.85rem;">Add Layer</div>
  <?php if (!$stalls): ?>
    <p style="color:var(--ink-soft); font-size:0.85rem;">Add a stall first.</p>
  <?php else: ?>
    <form method="post" class="loc-add-grid" style="grid-template-columns: 1.6fr 1fr 1.4fr auto;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_layer_stall_id">Stall</label>
        <select id="add_layer_stall_id" name="add_layer_stall_id" required>
          <?php foreach ($stalls as $s): ?>
            <option value="<?= $s['id'] ?>">Room <?= (int)$s['room_number'] ?> · Stall <?= (int)$s['stall_number'] ?> — <?= htmlspecialchars((string)($s['name'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_layer_number">Layer #</label>
        <input type="text" inputmode="numeric" id="add_layer_number" name="add_layer_number" placeholder="e.g. 1" required>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_layer_name">Layer Name</label>
        <input type="text" id="add_layer_name" name="add_layer_name" placeholder="e.g. Top" required>
      </div>
      <button type="submit" class="btn btn-primary">+ Add Layer</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="data">
      <thead><tr><th>Room</th><th>Stall</th><th>Layer #</th><th>Layer Name</th><th>Items</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (!$layers): ?>
          <tr><td colspan="6">No layers yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($layers as $l): ?>
          <tr>
            <td data-label="Room">Room <?= (int)$l['room_number'] ?> — <?= htmlspecialchars((string)($l['room_name'] ?? '')) ?></td>
            <td data-label="Stall">Stall <?= (int)$l['stall_number'] ?> — <?= htmlspecialchars((string)($l['stall_name'] ?? '')) ?></td>
            <td class="mono" data-label="Layer #"><?= (int)$l['layer_number'] ?></td>
            <td data-label="Layer Name" style="font-weight:600;"><?= htmlspecialchars((string)($l['layer_name'] ?? '')) ?></td>
            <td class="mono" data-label="Items"><?= (int)$l['item_count'] ?></td>
            <td style="text-align:right;" data-label="Actions">
              <form method="post" onsubmit="return confirm('Delete layer <?= htmlspecialchars(addslashes($l['layer_name'])) ?>?');" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="delete_layer_id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" <?= $l['item_count'] > 0 ? 'disabled title="In use — cannot delete"' : '' ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
