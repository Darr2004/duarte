<?php
/**
 * DuaRTE — Authentication & Access Control
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/functions.php';

// ---- Brute-force lockout ----
// Same 15-minute window admin/dashboard.php already uses to flag
// "suspicious" activity — this is what actually enforces it instead of
// only ever reporting it after the fact.
const LOGIN_LOCKOUT_THRESHOLD = 5;   // failed attempts allowed per window
const LOGIN_LOCKOUT_MINUTES   = 15;  // rolling window; attempts age out on their own
const SESSION_MAX_IDLE_SECONDS = 7200; // 2 hours inactivity limit

/**
 * Seconds remaining before this username can try again, or 0 if it's
 * not currently locked out. Checked before the password is even
 * looked at, so a lockout can't be used to distinguish "this username
 * exists" from "it doesn't" — both fail the same way.
 */
function login_lockout_seconds_remaining(PDO $pdo, string $username): int
{
    $stmt = $pdo->prepare(
        'SELECT MAX(attempted_at) AS last_attempt, COUNT(*) AS fails
           FROM login_attempts
          WHERE username = :u AND success = 0
            AND attempted_at >= (NOW() - INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)'
    );
    $stmt->execute(['u' => $username]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['fails'] < LOGIN_LOCKOUT_THRESHOLD || !$row['last_attempt']) {
        return 0;
    }

    $unlocks_at = strtotime($row['last_attempt']) + (LOGIN_LOCKOUT_MINUTES * 60);
    return max(0, $unlocks_at - time());
}

/** Attempt to log a user in. Returns the user array on success, or null. */
function attempt_login(string $username, string $password): ?array
{
    $pdo = get_db();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;

    if (login_lockout_seconds_remaining($pdo, $username) > 0) {
        // Still recorded, so the attempt count (and the unlock timer)
        // keeps moving with every additional try rather than freezing.
        $log = $pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, 0)'
        );
        $log->execute(['u' => $username, 'ip' => $ip]);
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM users WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    $success = $user
        && $user['status'] === 'active'
        && password_verify($password, $user['password_hash']);

    // Audit trail — logged whether or not the attempt succeeded.
    $log = $pdo->prepare(
        'INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, :s)'
    );
    $log->execute(['u' => $username, 'ip' => $ip, 's' => $success ? 1 : 0]);

    if (!$success) {
        // Deliberately no actor — the username may not correspond to a
        // real account at all, so there's nothing to attribute this to
        // beyond the raw text that was typed.
        log_audit_event($pdo, null, 'login_failed', 'user', $user ? (int)$user['id'] : null,
            'Failed login attempt for username "' . $username . '"');
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['position'] = $user['position'] ?? null;
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
    $_SESSION['last_activity'] = time();

    $touch = $pdo->prepare('UPDATE users SET last_login_at = NOW(), pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id');
    $touch->execute(['id' => $user['id']]);

    log_audit_event($pdo, current_user(), 'login_success', 'user', (int)$user['id'],
        current_user()['full_name'] . ' logged in.');

    return $user;
}

function logout_user(): void
{
    if (is_logged_in()) {
        log_audit_event(get_db(), current_user(), 'logout', 'user', (int)$_SESSION['user_id'],
            current_user()['full_name'] . ' logged out.');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain']);
    }
    session_destroy();
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'              => $_SESSION['user_id'],
        'username'        => $_SESSION['username'],
        'role'            => $_SESSION['role'],
        'position'        => $_SESSION['position'] ?? null,
        'full_name'       => $_SESSION['full_name'],
        'profile_picture' => $_SESSION['profile_picture'] ?? null,
    ];
}

/** Call at the top of any page that requires a logged-in user. */
function require_login(string $redirect = '/auth/login.php'): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . $redirect);
        exit;
    }

    // Inactivity timeout guard (2 hours)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_MAX_IDLE_SECONDS)) {
        logout_user();
        header('Location: ' . BASE_URL . $redirect . '?expired=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Re-check against the DB so a deactivated account or a role change
    // takes effect immediately, rather than only at the next login —
    // the session alone can't be trusted to still reflect current status.
    static $revalidated = false;
    if (!$revalidated) {
        $revalidated = true;
        $pdo = get_db();
        $stmt = $pdo->prepare('SELECT role, position, status FROM users WHERE id = :id');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $current = $stmt->fetch();

        if (!$current || $current['status'] !== 'active') {
            logout_user();
            header('Location: ' . BASE_URL . $redirect . '?deactivated=1');
            exit;
        }
        $_SESSION['role'] = $current['role'];
        $_SESSION['position'] = $current['position'];

        // Must happen here — the earliest point common to every
        // authenticated page — not in includes/header.php. Pages like
        // requisition/view.php and inventory/verify.php process their
        // POST (approve/decline/release) *before* they require
        // header.php, so a header.php-only check would let a
        // supervisor/inventory-staff action land on a requisition that
        // is stale but hasn't been auto-cancelled yet.
        expire_stale_requisitions($pdo);
    }
}

/** Call at the top of any page restricted to specific roles. */
function require_role(array|string $allowed_roles, string $redirect = '/auth/login.php'): void
{
    $allowed_roles = (array)$allowed_roles;
    require_login($redirect);
    if (!in_array($_SESSION['role'], $allowed_roles, true)) {
        http_response_code(403);
        die('403 — You do not have permission to view this page.');
    }
}

/** Generate (or reuse) a CSRF token for the current session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Validate a submitted CSRF token. Call this before any state-changing action. */
function csrf_check(?string $submitted): bool
{
    return $submitted !== null
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

/** Render a hidden HTML input with the current CSRF token. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// ---- Mobile API tokens ----
// api/login.php mints one of these and every other api/*.php endpoint
// requires it — the client's own user_id/role fields are never trusted
// on their own (see api_authenticate()).
const MOBILE_TOKEN_DAYS = 30;

/** Issue a fresh token for this user, replacing any token(s) they already had. */
function create_mobile_token(PDO $pdo, int $user_id): string
{
    $token = bin2hex(random_bytes(32));

    $del = $pdo->prepare('DELETE FROM mobile_tokens WHERE user_id = :uid');
    $del->execute(['uid' => $user_id]);

    $ins = $pdo->prepare(
        'INSERT INTO mobile_tokens (user_id, token, expires_at)
         VALUES (:uid, :token, DATE_ADD(NOW(), INTERVAL ' . MOBILE_TOKEN_DAYS . ' DAY))'
    );
    $ins->execute(['uid' => $user_id, 'token' => $token]);

    return $token;
}

/**
 * Resolve the calling user from the Authorization: Bearer <token> header.
 * Returns the user row (only if still active) or null — never trusts
 * any user_id/role the client sent alongside it.
 */
function api_authenticate(PDO $pdo): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $req_headers = apache_request_headers();
        $header = $req_headers['Authorization'] ?? $req_headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        $token = trim($_REQUEST['token'] ?? $_POST['token'] ?? '');
        if ($token === '') {
            $raw = file_get_contents('php://input');
            $json = !empty($raw) ? json_decode($raw, true) : null;
            $token = is_array($json) ? trim($json['token'] ?? '') : '';
        }
        if ($token === '') {
            return null;
        }
    } else {
        $token = trim($m[1]);
    }
    if ($token === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT u.*, mt.last_used_at AS token_last_used_at FROM mobile_tokens mt
           JOIN users u ON u.id = mt.user_id
          WHERE mt.token = :token AND mt.expires_at > NOW() AND u.status = "active"
          LIMIT 1'
    );
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    // Throttle token timestamp updates to at most once every 5 minutes
    // to prevent row-lock wait timeouts during rapid background polling (e.g. notifications.php)
    $lastUsed = !empty($user['token_last_used_at']) ? strtotime($user['token_last_used_at']) : 0;
    if ((time() - $lastUsed) > 300) {
        try {
            $touch = $pdo->prepare('UPDATE mobile_tokens SET last_used_at = NOW() WHERE token = :token');
            $touch->execute(['token' => $token]);
        } catch (Exception $e) {
            // Non-critical metadata update failure should not break the request
        }
    }
    unset($user['token_last_used_at']);

    $user['api_token'] = $token;
    return $user;
}

/** Call at the top of every api/*.php endpoint that acts on a specific user. Exits with 401 on failure. */
function require_api_auth(PDO $pdo): array
{
    $user = api_authenticate($pdo);
    if (!$user) {
        api_response(false, null, 'Missing or invalid access token.', 401);
    }
    return $user;
}
