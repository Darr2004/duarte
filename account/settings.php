<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/uploads.php';
require_login();

$pdo = get_db();
$me  = current_user();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $me['id']]);
$account = $stmt->fetch();

if (!$account) {
    http_response_code(404);
    die('Account not found.');
}

// The profile_picture column only exists once
// database/migration_profile_settings.sql has been run. Detect that
// instead of letting every reference below throw "undefined array
// key" warnings, and surface a clear one-time setup notice instead.
$migration_missing = !array_key_exists('profile_picture', $account);
if ($migration_missing) {
    $account['profile_picture'] = null;
}

$photo_errors = [];
$photo_success = false;
$password_errors = [];
$password_success = false;
$profile_errors = [];
$profile_success = false;
$sms_test_result = null;
$sms_test_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile_info') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $profile_errors[] = 'Your session expired. Please try again.';
    } else {
        $new_full_name = trim($_POST['full_name'] ?? '');
        $new_contact   = trim($_POST['contact_number'] ?? '');

        if ($new_full_name === '') {
            $profile_errors[] = 'Name is required.';
        } elseif (mb_strlen($new_full_name) > 100) {
            $profile_errors[] = 'Name is too long.';
        }

        if (!$profile_errors) {
            $old_name = $account['full_name'];
            $old_contact = $account['contact_number'] ?? '';
            $upd = $pdo->prepare('UPDATE users SET full_name = :n, contact_number = :c WHERE id = :id');
            $upd->execute(['n' => $new_full_name, 'c' => $new_contact !== '' ? $new_contact : null, 'id' => $me['id']]);

            $_SESSION['full_name'] = $new_full_name;
            $account['full_name']      = $new_full_name;
            $account['contact_number'] = $new_contact;
            $me['full_name']           = $new_full_name;

            if ($old_name !== $new_full_name || $old_contact !== $new_contact) {
                log_audit_event($pdo, $me, 'user_update', 'user', (int)$me['id'],
                    'Updated profile information (Name: "' . $new_full_name . '", Contact: "' . $new_contact . '").');
            }

            $profile_success = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'send_test_sms') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $sms_test_error = 'Your session expired. Please try again.';
    } else {
        require_once __DIR__ . '/../includes/sms.php';
        $dest = !empty($account['contact_number']) ? $account['contact_number'] : SMS_DEFAULT_RECIPIENT;
        $msg = "DuaRTE: Hello " . $account['full_name'] . "! This is a test SMS notification from your DuaRTE Inventory System via PhilSMS.";
        $res = send_sms($dest, $msg);
        if ($res['success']) {
            $uid = $res['data']['data']['uid'] ?? 'delivered';
            $sms_test_result = "Test SMS successfully sent to {$dest}! (PhilSMS UID: {$uid})";
        } else {
            $sms_test_error = "Failed to send SMS: " . ($res['error'] ?? 'Unknown error');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'photo') {
    if ($migration_missing) {
        $photo_errors[] = 'Profile pictures aren\'t set up yet — run database/migration_profile_settings.sql first.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $photo_errors[] = 'Your session expired. Please try again.';
    } else {
        $remove_photo = !empty($_POST['remove_photo']);
        $new_filename = $account['profile_picture'];
        $old_to_retire = null;
        $uploaded = null;

        try {
            $uploaded = handle_profile_image_upload($_FILES['profile_picture'] ?? []);
            if ($uploaded) {
                $old_to_retire = $account['profile_picture'];
                $new_filename = $uploaded;
            } elseif ($remove_photo) {
                $old_to_retire = $account['profile_picture'];
                $new_filename = null;
            }

            if ($uploaded || $remove_photo) {
                $upd = $pdo->prepare('UPDATE users SET profile_picture = :p WHERE id = :id');
                $upd->execute(['p' => $new_filename, 'id' => $me['id']]);

                if ($old_to_retire) {
                    delete_profile_image($old_to_retire);
                }

                $_SESSION['profile_picture'] = $new_filename;
                $account['profile_picture']  = $new_filename;

                log_audit_event($pdo, $me, 'user_avatar_update', 'user', (int)$me['id'],
                    $me['full_name'] . ($remove_photo && !$uploaded
                        ? ' removed their profile picture.'
                        : ' updated their profile picture.'));

                $photo_success = true;
            } else {
                $photo_errors[] = 'Choose a photo to upload first.';
            }
        } catch (RuntimeException $e) {
            $photo_errors[] = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $password_errors[] = 'Your session expired. Please try again.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password      = $_POST['new_password'] ?? '';
        $confirm_password  = $_POST['confirm_password'] ?? '';

        if ($current_password === '') {
            $password_errors[] = 'Enter your current password.';
        } elseif (!password_verify($current_password, $account['password_hash'])) {
            $password_errors[] = 'Current password is incorrect.';
        }
        if (strlen($new_password) < 8) {
            $password_errors[] = 'New password must be at least 8 characters.';
        }
        if ($new_password !== $confirm_password) {
            $password_errors[] = 'New password and confirmation do not match.';
        }
        if (!$password_errors && $current_password !== '' && password_verify($new_password, $account['password_hash'])) {
            $password_errors[] = 'New password must be different from your current password.';
        }

        if (!$password_errors) {
            $upd = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $upd->execute(['h' => password_hash($new_password, PASSWORD_DEFAULT), 'id' => $me['id']]);

            // Security: Invalidate all active mobile sessions so stolen/compromised devices cannot persist
            $del_tokens = $pdo->prepare('DELETE FROM mobile_tokens WHERE user_id = :id');
            $del_tokens->execute(['id' => $me['id']]);

            log_audit_event($pdo, $me, 'user_password_change', 'user', (int)$me['id'],
                $me['full_name'] . ' changed their own password.');

            $password_success = true;
        }
    }
}

$page_title = 'Account Settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">My account</div>
    <h1>Account Settings</h1>
  </div>
</div>

<div class="card">
  <h2 class="card-heading">Profile & Contact details</h2>

  <?php foreach ($profile_errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>
  <?php if ($profile_success): ?>
    <div class="alert alert-success">Profile details updated.</div>
  <?php endif; ?>

  <form method="post" novalidate style="max-width:480px;">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="form" value="profile_info">

    <div class="form-group">
      <label for="full_name">Full name</label>
      <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars((string)($account['full_name'] ?? '')) ?>" maxlength="100" required>
    </div>

    <div class="form-group">
      <label for="contact_number">Mobile number <span class="text-muted-normal">(SMS)</span></label>
      <input type="text" id="contact_number" name="contact_number" value="<?= htmlspecialchars((string)($account['contact_number'] ?? '')) ?>" placeholder="e.g. 09171234567">
      <div style="font-size:0.8rem; color:var(--ink-soft); margin-top:0.25rem;">Used for PhilSMS notifications (Requisition approvals, stock alerts, overdue tool loans).</div>
    </div>

    <button type="submit" class="btn btn-primary">Save changes</button>
  </form>
</div>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
    <div>
      <h2 class="card-heading" style="margin-bottom:0.25rem;">📱 PhilSMS Gateway Status</h2>
      <p style="margin:0; font-size:0.88rem; color:var(--ink-soft);">
        Live real-time SMS dispatch is <strong>Active</strong>. All notification alerts for this account are sent to <strong><?= !empty($account['contact_number']) ? htmlspecialchars((string)($account['contact_number'] ?? '')) : '<span style="color:var(--danger, #dc3545);">Not set (Add your phone number above)</span>' ?></strong>.
      </p>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="send_test_sms">
      <button type="submit" class="btn btn-outline" style="border-color:var(--brand); color:var(--brand); font-weight:600;">
        📲 Send Test SMS to My Phone
      </button>
    </form>
  </div>

  <?php if ($sms_test_error): ?>
    <div class="alert alert-error" style="margin-top:1rem;"><?= htmlspecialchars($sms_test_error) ?></div>
  <?php endif; ?>
  <?php if ($sms_test_result): ?>
    <div class="alert alert-success" style="margin-top:1rem;"><?= htmlspecialchars($sms_test_result) ?></div>
  <?php endif; ?>
</div>

<div class="account-settings-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; align-items:start;">

  <div class="card m-0">
    <h2 class="card-heading">Profile picture</h2>

    <?php if ($migration_missing): ?>
      <div class="alert alert-warning">
        Profile pictures need a one-time database update. Run
        <code>database/migration_profile_settings.sql</code> against your database, then reload this page.
      </div>
    <?php endif; ?>

    <?php foreach ($photo_errors as $err): ?>
      <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
    <?php if ($photo_success): ?>
      <div class="alert alert-success">Profile picture updated.</div>
    <?php endif; ?>

    <div style="display:flex; align-items:center; gap:1.1rem; margin-bottom:1.25rem;">
      <?= avatar_html($account, 'avatar-circle-xl mono') ?>
      <div style="font-size:0.8rem; color:var(--ink-soft);">
        <?= $account['profile_picture'] ? 'Shown across the app in place of your initials.' : 'No photo yet — your initials are shown instead.' ?>
      </div>
    </div>

    <?php if (!$migration_missing): ?>
    <form method="post" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="photo">

      <div class="form-group">
        <label for="profile_picture">New photo <span class="text-muted-normal">(max 2MB)</span></label>
        <input type="file" id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png,.webp">
      </div>

      <?php if ($account['profile_picture']): ?>
        <div class="form-group" style="display:flex; align-items:center; gap:0.5rem;">
          <input type="checkbox" id="remove_photo" name="remove_photo" value="1" style="width:auto;">
          <label for="remove_photo" class="m-0">Remove current photo</label>
        </div>
      <?php endif; ?>

      <button type="submit" class="btn btn-primary">Save photo</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card m-0">
    <h2 class="card-heading">Change password</h2>

    <?php foreach ($password_errors as $err): ?>
      <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
    <?php if ($password_success): ?>
      <div class="alert alert-success">Password changed.</div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="password">

      <div class="form-group">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required>
      </div>
      <div class="form-group">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" minlength="8" required>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
      </div>

      <button type="submit" class="btn btn-primary">Update password</button>
    </form>
  </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
