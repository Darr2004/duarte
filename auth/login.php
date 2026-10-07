<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

function landing_page_for(string $role): string
{
    if ($role === 'admin') return '/admin/dashboard.php';
    if ($role === 'inventory_staff') return '/inventory/dashboard.php';
    if ($role === 'field_supervisor') return '/requisition/dashboard.php';
    if ($role === 'driver_helper') return '/requisition/home.php';
    return '/catalog/browse.php';
}

if (is_logged_in()) {
    header('Location: ' . BASE_URL . landing_page_for($_SESSION['role']));
    exit;
}

$error = null;
$show_admin_modal = false;

if (!empty($_GET['deactivated'])) {
    $error = 'Your account is no longer active. Contact an administrator if this is unexpected.';
} elseif (!empty($_GET['expired'])) {
    $error = 'Your session timed out due to inactivity. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_admin_portal = !empty($_POST['admin_portal']);

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
        if ($is_admin_portal) $show_admin_modal = true;
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both your username and password.';
            if ($is_admin_portal) $show_admin_modal = true;
        } else {
            $wait = login_lockout_seconds_remaining(get_db(), $username);
            if ($wait > 0) {
                $error = 'Too many failed attempts for this account. Try again in '
                    . max(1, (int)ceil($wait / 60)) . ' minute(s).';
                if ($is_admin_portal) $show_admin_modal = true;
            } else {
                $user = attempt_login($username, $password);
                if ($user) {
                    if ($is_admin_portal && $user['role'] !== 'admin') {
                        // Non-admin trying to use the Root Console
                        $_SESSION = [];
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_destroy();
                        }
                        $error = 'Access Denied: Only system administrators are authorized to log in via the Root Console.';
                        $show_admin_modal = true;
                    } else {
                        header('Location: ' . BASE_URL . landing_page_for($user['role']));
                        exit;
                    }
                } else {
                    $error = 'Incorrect username or password.';
                    if ($is_admin_portal) $show_admin_modal = true;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In · <?= APP_NAME ?> Enterprise Fleet &amp; Asset Management</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
  <div class="login-wrap">
    <div class="login-ticket">

      <!-- Left Executive Capability & Branding Panel -->
      <div class="login-stub">
        <div class="login-stub-head">
          <!-- Easter Egg Target: Click logo 5 times to reveal Secret Admin Login -->
          <div class="login-brand-mark" id="secretAdminTrigger" title="<?= APP_NAME ?>">
            <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="<?= APP_NAME ?> Logo" class="login-logo-img">
          </div>
          <div class="login-status-pill">
            <span class="login-status-dot"></span>
            <span>SYSTEM ACTIVE</span>
          </div>
        </div>

        <div class="login-brand-body">
          <h1 class="login-brand-name">DuaRTE</h1>
          <p class="login-brand-tag">Heavy Fleet, Heavy Equipment &amp; Asset Logistics Operations System.</p>

          <ul class="login-feature-list">
            <li class="login-feature-item">
              <div class="login-feature-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
              </div>
              <div class="login-feature-text">
                <strong>Fleet &amp; Tool Dispatch</strong>
                <span>Rapid requisition, QR scanning &amp; vehicle allocation</span>
              </div>
            </li>

            <li class="login-feature-item">
              <div class="login-feature-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
              </div>
              <div class="login-feature-text">
                <strong>Warehouse Stock Visibility</strong>
                <span>Real-time inventory levels, tool custody &amp; bin locations</span>
              </div>
            </li>

            <li class="login-feature-item">
              <div class="login-feature-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              </div>
              <div class="login-feature-text">
                <strong>Full Chain of Custody</strong>
                <span>Instant sign-off, tamper-proof logs &amp; supervisor approvals</span>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <!-- Right Sign-In Interactive Panel -->
      <div class="login-form-panel">
        <div class="login-form-inner">
          <div class="login-category-badge">Authorized Personnel</div>
          <h2 class="login-form-title">Sign In</h2>
          <p class="login-form-subtitle">Enter your credentials to access the fleet workspace.</p>

          <?php if ($error && !$show_admin_modal): ?>
            <div class="alert-error" role="alert">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div><?= htmlspecialchars($error) ?></div>
            </div>
          <?php endif; ?>

          <form method="post" class="login-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="form-group">
              <label for="username">Username or Employee ID</label>
              <div class="input-icon-group">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <input type="text" id="username" name="username" autocomplete="username" required autofocus placeholder="Username or ID">
              </div>
            </div>

            <div class="form-group">
              <label for="password">Password</label>
              <div class="input-icon-group">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input type="password" id="password" name="password" autocomplete="current-password" required placeholder="Password">
                <button type="button" class="input-icon-toggle" id="togglePassword" aria-label="Show password" aria-pressed="false">
                  <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>

            <button type="submit" class="login-submit-btn">
              <span>Sign In</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </button>
          </form>

          <div class="login-footer-note">
            &copy; <?= date('Y') ?> DuaRTE Logistics &amp; Yard Operations.
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Stealth Easter Egg Notification Toast -->
  <div class="easter-toast" id="easterToast"></div>

  <!-- Secret Root Administrator Console Modal -->
  <div class="admin-modal-overlay <?= $show_admin_modal ? 'is-open' : '' ?>" id="adminModal" role="dialog" aria-modal="true" aria-labelledby="adminModalTitle">
    <div class="admin-modal-card">
      <button type="button" class="admin-modal-close" id="closeAdminModal" aria-label="Close Root Console">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </button>

      <div class="admin-badge-pill">
        <span class="admin-pulse-red"></span>
        <span>ROOT CONSOLE · RESTRICTED ACCESS</span>
      </div>

      <h3 class="admin-modal-title" id="adminModalTitle">Admin Gateway</h3>
      <p class="admin-modal-subtitle">Admin-only elevated access.</p>

      <?php if ($error && $show_admin_modal): ?>
        <div class="alert-error" style="background: rgba(220, 38, 38, 0.16); border-color: rgba(239, 68, 68, 0.4); color: #fca5a5;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="admin_portal" value="1">

        <div class="form-group">
          <label for="admin_username">Admin Username</label>
          <div class="input-icon-group">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" id="admin_username" name="username" autocomplete="username" required placeholder="Admin username">
          </div>
        </div>

        <div class="form-group">
          <label for="admin_password">Root Master Password</label>
          <div class="input-icon-group">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="admin_password" name="password" autocomplete="current-password" required placeholder="Admin password">
            <button type="button" class="input-icon-toggle" id="toggleAdminPassword" aria-label="Show password">
              <svg id="adminEyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="admin-submit-btn">
          <span>Authenticate as Administrator</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>

      <div class="admin-modal-notice">
        SECURITY WARNING: Elevated admin sessions are monitored and logged directly to the immutable audit trail.
      </div>
    </div>
  </div>

  <script>
    // -------------------------------------------------------------
    // Standard Form Password Toggle
    // -------------------------------------------------------------
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    if (toggleBtn && passwordInput && eyeIcon) {
      toggleBtn.addEventListener('click', () => {
        const isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        toggleBtn.setAttribute('aria-pressed', String(isHidden));
        toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        toggleBtn.classList.toggle('is-active', isHidden);

        if (isHidden) {
          eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
        } else {
          eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
        }
      });
    }

    // -------------------------------------------------------------
    // Secret Admin Console Modal & Easter Egg (5 Clicks on Logo)
    // -------------------------------------------------------------
    const logoTrigger = document.getElementById('secretAdminTrigger');
    const adminModal = document.getElementById('adminModal');
    const closeAdminModal = document.getElementById('closeAdminModal');
    const adminUsername = document.getElementById('admin_username');
    const adminPassword = document.getElementById('admin_password');
    const toggleAdminPassword = document.getElementById('toggleAdminPassword');
    const adminEyeIcon = document.getElementById('adminEyeIcon');
    const easterToast = document.getElementById('easterToast');

    let clickCount = 0;
    let clickTimer = null;
    let toastTimer = null;

    function showToast(message) {
      if (!easterToast) return;
      easterToast.textContent = message;
      easterToast.classList.add('is-visible');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => {
        easterToast.classList.remove('is-visible');
      }, 1600);
    }

    function openSecretAdminModal() {
      if (!adminModal) return;
      adminModal.classList.add('is-open');
      clickCount = 0;
      clearTimeout(clickTimer);
      if (easterToast) easterToast.classList.remove('is-visible');
      setTimeout(() => {
        if (adminUsername) adminUsername.focus();
      }, 200);
    }

    function closeSecretAdminModal() {
      if (!adminModal) return;
      adminModal.classList.remove('is-open');
    }

    if (logoTrigger) {
      logoTrigger.addEventListener('click', (e) => {
        e.preventDefault();
        clickCount++;

        // Add subtle tactile animation on the logo
        logoTrigger.classList.remove('easter-tap');
        void logoTrigger.offsetWidth; // force reflow
        logoTrigger.classList.add('easter-tap');

        clearTimeout(clickTimer);

        if (clickCount >= 5) {
          openSecretAdminModal();
        } else {
          if (clickCount >= 3) {
            showToast(`Master Access: ${clickCount}/5 sequence...`);
          }
          clickTimer = setTimeout(() => {
            clickCount = 0;
          }, 2400);
        }
      });
    }

    if (closeAdminModal) {
      closeAdminModal.addEventListener('click', closeSecretAdminModal);
    }

    if (adminModal) {
      adminModal.addEventListener('click', (e) => {
        if (e.target === adminModal) {
          closeSecretAdminModal();
        }
      });
    }

    // Keyboard Shortcuts:
    // 1. Ctrl + Shift + A to open secret admin modal
    // 2. Escape to close secret admin modal
    window.addEventListener('keydown', (e) => {
      if (e.ctrlKey && e.shiftKey && (e.key === 'A' || e.key === 'a')) {
        e.preventDefault();
        openSecretAdminModal();
      } else if (e.key === 'Escape' && adminModal && adminModal.classList.contains('is-open')) {
        closeSecretAdminModal();
      }
    });

    // Admin Form Password Toggle
    if (toggleAdminPassword && adminPassword && adminEyeIcon) {
      toggleAdminPassword.addEventListener('click', () => {
        const isHidden = adminPassword.type === 'password';
        adminPassword.type = isHidden ? 'text' : 'password';
        toggleAdminPassword.setAttribute('aria-pressed', String(isHidden));
        toggleAdminPassword.classList.toggle('is-active', isHidden);

        if (isHidden) {
          adminEyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
        } else {
          adminEyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
        }
      });
    }
  </script>
</body>
</html>
