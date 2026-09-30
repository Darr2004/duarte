<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin']);

$pdo = get_db();
$errors = [];
$values = ['employee_id' => '', 'full_name' => '', 'email' => '', 'contact_number' => '', 'username' => '', 'role' => 'driver_helper', 'position' => ''];
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $values['employee_id']    = trim($_POST['employee_id'] ?? '');
        $values['full_name']      = trim($_POST['full_name'] ?? '');
        $values['email']          = trim($_POST['email'] ?? '');
        $values['contact_number'] = trim($_POST['contact_number'] ?? '');
        $values['username']       = trim($_POST['username'] ?? '');
        $values['role']           = $_POST['role'] ?? 'driver_helper';
        $values['position']       = $_POST['position'] ?? '';
        $password                 = $_POST['password'] ?? '';

        if ($values['employee_id'] === '') $errors[] = 'Employee ID is required.';
        if ($values['full_name'] === '')   $errors[] = 'Full name is required.';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if ($values['username'] === '')    $errors[] = 'Username is required.';
        if ($password === '')              $errors[] = 'Temporary password is required.';
        if (strlen($password) < 8)         $errors[] = 'Password must be at least 8 characters.';
        if (!array_key_exists($values['role'], $roles)) $errors[] = 'Invalid role selected.';
        if ($values['role'] === 'driver_helper') {
            if ($values['position'] === '' || !array_key_exists($values['position'], $positions)) {
                $errors[] = 'Please choose a position for Personnel accounts (Driver, Helper, Mechanic, Electrician, or Office Staff).';
            }
        } else {
            $values['position'] = '';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (employee_id, full_name, email, contact_number, username, password_hash, role, position)
                     VALUES (:employee_id, :full_name, :email, :contact_number, :username, :password_hash, :role, :position)'
                );
                $stmt->execute([
                    'employee_id'    => $values['employee_id'],
                    'full_name'      => $values['full_name'],
                    'email'          => $values['email'],
                    'contact_number' => $values['contact_number'] !== '' ? $values['contact_number'] : null,
                    'username'       => $values['username'],
                    'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                    'role'           => $values['role'],
                    'position'       => $values['position'] !== '' ? $values['position'] : null,
                ]);
                log_audit_event($pdo, current_user(), 'user_create', 'user', (int)$pdo->lastInsertId(),
                    current_user()['full_name'] . ' created account "' . $values['username'] . '" (' . role_display($values['role'], $values['position']) . ').');
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

$page_title = 'Add Account';
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= BASE_URL ?>/admin/users.php" class="back-link">&larr; Back to User Accounts</a>
<div class="page-header">
  <div>
    <div class="eyebrow">User accounts</div>
    <h1>Add account</h1>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:480px;">
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

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
      <input type="text" id="contact_number" name="contact_number" value="<?= htmlspecialchars((string)($values['contact_number'] ?? '')) ?>" placeholder="e.g. 09171234567">
      <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.25rem;">Used for PhilSMS notifications (Requisition approvals, stock alerts, overdue tool loans).</div>
    </div>
    <div class="form-group">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= htmlspecialchars((string)($values['username'] ?? '')) ?>" required>
    </div>
    <div class="form-group">
      <label for="password">Temporary password</label>
      <input type="password" id="password" name="password" minlength="8" required>
    </div>
    <div class="form-group">
      <label for="role">Role</label>
      <select id="role" name="role">
        <?php foreach ($roles as $val => $label): ?>
          <option value="<?= $val ?>" <?= $values['role'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" id="position-group" style="<?= $values['role'] === 'driver_helper' ? '' : 'display:none;' ?>">
      <label for="position">Position</label>
      <select id="position" name="position">
        <option value="">Select position&hellip;</option>
        <?php foreach ($positions as $val => $label): ?>
          <option value="<?= $val ?>" <?= $values['position'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Create account</button>
  </form>
</div>

<script>
  document.getElementById('role').addEventListener('change', function () {
    document.getElementById('position-group').style.display =
      this.value === 'driver_helper' ? '' : 'none';
  });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
