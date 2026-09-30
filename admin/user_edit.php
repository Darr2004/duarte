<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin']);

$pdo = get_db();
$me  = current_user();
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$target = $stmt->fetch();

if (!$target) {
    http_response_code(404);
    die('Account not found.');
}

$roles = [
    'driver_helper'    => 'Personnel',
    'inventory_staff'  => 'Inventory Staff',
    'field_supervisor' => 'Field Supervisor',
    'admin'            => 'Admin (incl. Management)',
];
$positions = [
    'driver'       => 'Driver',
    'helper'       => 'Helper',
    'mechanic'     => 'Mechanic',
    'electrician'  => 'Electrician',
    'office_staff' => 'Office Staff',
];

$errors = [];
$values = [
    'employee_id'    => $target['employee_id'],
    'full_name'      => $target['full_name'],
    'email'          => $target['email'],
    'contact_number' => $target['contact_number'] ?? '',
    'username'       => $target['username'],
    'role'           => $target['role'],
    'position'       => $target['position'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $values['employee_id']    = trim($_POST['employee_id'] ?? '');
        $values['full_name']      = trim($_POST['full_name'] ?? '');
        $values['email']          = trim($_POST['email'] ?? '');
        $values['contact_number'] = trim($_POST['contact_number'] ?? '');
        $values['username']       = trim($_POST['username'] ?? '');
        $values['role']           = $_POST['role'] ?? $target['role'];
        $values['position']       = $_POST['position'] ?? '';
        $new_password             = $_POST['password'] ?? '';

        if ($values['employee_id'] === '') $errors[] = 'Employee ID is required.';
        if ($values['full_name'] === '')   $errors[] = 'Full name is required.';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if ($values['username'] === '')    $errors[] = 'Username is required.';
        if ($new_password !== '' && strlen($new_password) < 8) $errors[] = 'New password must be at least 8 characters.';
        if (!array_key_exists($values['role'], $roles)) $errors[] = 'Invalid role selected.';
        if ($id === (int)$me['id'] && $values['role'] !== 'admin') {
            $errors[] = 'You cannot change your own account away from admin.';
        }
        if ($values['role'] === 'driver_helper') {
            if ($values['position'] === '' || !array_key_exists($values['position'], $positions)) {
                $errors[] = 'Select a position for Personnel accounts.';
            }
        } else {
            $values['position'] = '';
        }

        if (!$errors) {
            try {
                if ($new_password !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE users SET employee_id=:employee_id, full_name=:full_name, email=:email, contact_number=:contact_number,
                         username=:username, role=:role, position=:position, password_hash=:password_hash WHERE id=:id'
                    );
                    $stmt->execute([
                        'employee_id'    => $values['employee_id'],
                        'full_name'      => $values['full_name'],
                        'email'          => $values['email'],
                        'contact_number' => $values['contact_number'] !== '' ? $values['contact_number'] : null,
                        'username'       => $values['username'],
                        'role'           => $values['role'],
                        'position'       => $values['position'] !== '' ? $values['position'] : null,
                        'password_hash'  => password_hash($new_password, PASSWORD_DEFAULT),
                        'id'             => $id,
                    ]);

                    // Security: Invalidate all active mobile sessions for this user upon password reset
                    $del_tokens = $pdo->prepare('DELETE FROM mobile_tokens WHERE user_id = :id');
                    $del_tokens->execute(['id' => $id]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users SET employee_id=:employee_id, full_name=:full_name, email=:email, contact_number=:contact_number,
                         username=:username, role=:role, position=:position WHERE id=:id'
                    );
                    $stmt->execute([
                        'employee_id'    => $values['employee_id'],
                        'full_name'      => $values['full_name'],
                        'email'          => $values['email'],
                        'contact_number' => $values['contact_number'] !== '' ? $values['contact_number'] : null,
                        'username'       => $values['username'],
                        'role'           => $values['role'],
                        'position'       => $values['position'] !== '' ? $values['position'] : null,
                        'id'             => $id,
                    ]);
                }
                $changes = [];
                $old_position = $target['position'] ?? '';
                $new_position = $values['position'] ?? '';
                if ($values['role'] !== $target['role'] || $new_position !== $old_position) {
                    $changes[] = 'role changed from ' . role_display($target['role'], $target['position']) . ' to ' . role_display($values['role'], $values['position']);
                }
                if ($new_password !== '') {
                    $changes[] = 'password reset';
                }
                $summary = $changes ? ' (' . implode('; ', $changes) . ')' : '';
                log_audit_event($pdo, $me, 'user_update', 'user', $id,
                    $me['full_name'] . ' updated account "' . $values['username'] . '"' . $summary . '.');

                // Editing your own account changes what's stored in the DB,
                // but the session (full_name/username/role) was only ever
                // set at login time — refresh it here so the sidebar and
                // dashboard greeting reflect the new name immediately
                // instead of waiting for the next login.
                if ($id === (int)$me['id']) {
                    $_SESSION['full_name'] = $values['full_name'];
                    $_SESSION['username']  = $values['username'];
                    $_SESSION['role']      = $values['role'];
                    $_SESSION['position']  = $values['position'] !== '' ? $values['position'] : null;
                }

                header('Location: ' . BASE_URL . '/admin/users.php');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'That employee ID, email, or username is already in use.';
                } else {
                    error_log($e->getMessage());
                    $errors[] = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

$page_title = 'Edit Account';
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= BASE_URL ?>/admin/users.php" class="back-link">&larr; Back to User Accounts</a>
<div class="page-header">
  <div>
    <div class="eyebrow">User accounts</div>
    <h1>Edit account</h1>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:480px;">
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-group">
      <label for="employee_id">Employee ID</label>
      <input type="text" id="employee_id" name="employee_id" value="<?= htmlspecialchars((string)($values['employee_id'] ?? '')) ?>" required>
    </div>
    <div class="form-group">
      <label for="full_name">Full name</label>
      <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars((string)($values['full_name'] ?? '')) ?>" required>
    </div>
    <div class="form-group">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= htmlspecialchars((string)($values['email'] ?? '')) ?>" required>
    </div>
    <div class="form-group">
      <label for="contact_number">Phone Number (SMS) <span class="text-muted-normal">(Optional)</span></label>
      <input type="text" id="contact_number" name="contact_number" value="<?= htmlspecialchars((string)$values['contact_number']) ?>" placeholder="e.g. 09171234567">
      <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.25rem;">Used for PhilSMS notifications (Requisition approvals, stock alerts, overdue tool loans).</div>
    </div>
    <div class="form-group">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= htmlspecialchars((string)($values['username'] ?? '')) ?>" required>
    </div>
    <div class="form-group">
      <label for="role">Role</label>
      <?php if ($id === (int)$me['id']): ?>
        <select id="role" name="role" disabled>
          <option value="admin" selected>Admin (incl. Management)</option>
        </select>
        <input type="hidden" name="role" value="admin">
        <div style="font-size:0.78rem; color:var(--ink-soft); margin-top:0.3rem;">You can't change your own role.</div>
      <?php else: ?>
        <select id="role" name="role">
          <?php foreach ($roles as $val => $label): ?>
            <option value="<?= $val ?>" <?= $values['role'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
    <div class="form-group" id="position-group" style="<?= $values['role'] === 'driver_helper' ? '' : 'display:none;' ?>">
      <label for="position">Position</label>
      <select id="position" name="position" <?= $id === (int)$me['id'] ? 'disabled' : '' ?>>
        <option value="">Select position&hellip;</option>
        <?php foreach ($positions as $val => $label): ?>
          <option value="<?= $val ?>" <?= $values['position'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="password">New password <span class="text-muted-normal">(optional)</span></label>
      <input type="password" id="password" name="password" minlength="8">
    </div>

    <button type="submit" class="btn btn-primary">Save changes</button>
  </form>
</div>

<?php if ($id !== (int)$me['id']): ?>
<script>
  document.getElementById('role').addEventListener('change', function () {
    document.getElementById('position-group').style.display =
      this.value === 'driver_helper' ? '' : 'none';
  });
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
