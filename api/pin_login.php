<?php
/**
 * DuaRTE Mobile API — PIN Authentication
 *
 * Authenticates a remembered user via 4-digit quick PIN.
 * Enforces a 3-attempt lockout and logs audit events.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(false, null, 'Method not allowed. Use POST.', 405);
}

$input = api_json_input();
$userId = !empty($input['user_id']) ? (int)$input['user_id'] : null;
$username = trim($input['username'] ?? '');
$pin = trim($input['pin'] ?? '');

if ((!$userId && $username === '') || $pin === '') {
    api_response(false, null, 'User identifier and PIN are required.', 400);
}

if (!preg_match('/^[0-9]{4}$/', $pin)) {
    api_response(false, null, 'Ang PIN ay kailangang eksaktong 4 na numero.', 422);
}

$pdo = get_db();

if ($userId) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute(['u' => $username]);
}
$user = $stmt->fetch();

if (!$user || $user['status'] !== 'active') {
    api_response(false, null, 'Hindi natagpuan o hindi aktibo ang account.', 404);
}

if (empty($user['pin_hash'])) {
    api_response(false, null, 'Walang naka-set na PIN para sa account na ito. Mangyaring mag-login muna gamit ang password.', 400);
}

// Check Lockout
if (!empty($user['pin_locked_until'])) {
    $lockTime = strtotime($user['pin_locked_until']);
    $now = time();
    if ($lockTime > $now) {
        $remainingSecs = $lockTime - $now;
        $mins = max(1, (int)ceil($remainingSecs / 60));
        api_response(
            false,
            ['locked' => true, 'minutes_remaining' => $mins],
            "Naka-lock ang PIN login ng $mins minuto dahil sa sunod-sunod na maling subok. Gamitin ang password para mag-login.",
            429
        );
    } else {
        // Lockout has expired — reset the counter so the user gets a fresh 3 attempts
        $reset = $pdo->prepare(
            'UPDATE users SET pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id'
        );
        $reset->execute(['id' => $user['id']]);
        $user['pin_failed_attempts'] = 0;
        $user['pin_locked_until'] = null;
    }
}

// Verify PIN
if (password_verify($pin, $user['pin_hash'])) {
    // Reset failed counter
    $upd = $pdo->prepare(
        'UPDATE users
         SET pin_failed_attempts = 0,
             pin_locked_until = NULL,
             last_login_at = NOW()
         WHERE id = :id'
    );
    $upd->execute(['id' => $user['id']]);

    $token = create_mobile_token($pdo, (int)$user['id']);

    log_audit_event(
        $pdo,
        $user,
        'user_pin_login',
        'user',
        (int)$user['id'],
        $user['full_name'] . ' successfully logged in via mobile 4-digit PIN.'
    );

    api_response(true, [
        'token' => $token,
        'user'  => [
            'id'             => (int)$user['id'],
            'full_name'      => $user['full_name'],
            'employee_id'    => $user['employee_id'],
            'username'       => $user['username'],
            'role'           => $user['role'],
            'position'       => $user['position'] ?? '',
            'contact_number' => $user['contact_number'] ?? '',
            'status'         => $user['status'],
            'has_pin'        => true,
        ]
    ]);
} else {
    // Increment failed attempts
    $newFails = (int)$user['pin_failed_attempts'] + 1;
    $isLocked = false;
    $lockedUntil = null;

    if ($newFails >= 3) {
        $isLocked = true;
        $lockedUntil = date('Y-m-d H:i:s', time() + 300); // 5 minutes
        $upd = $pdo->prepare('UPDATE users SET pin_failed_attempts = :f, pin_locked_until = :l WHERE id = :id');
        $upd->execute(['f' => $newFails, 'l' => $lockedUntil, 'id' => $user['id']]);

        log_audit_event(
            $pdo,
            $user,
            'user_pin_locked',
            'user',
            (int)$user['id'],
            $user['full_name'] . ' PIN locked after 3 failed attempts.'
        );

        api_response(
            false,
            ['locked' => true, 'minutes_remaining' => 5],
            'Maling PIN. Naabot mo ang 3 maling subok. Naka-lock ang PIN ng 5 minuto. Maaari kang mag-login gamit ang password.',
            429
        );
    } else {
        $upd = $pdo->prepare('UPDATE users SET pin_failed_attempts = :f WHERE id = :id');
        $upd->execute(['f' => $newFails, 'id' => $user['id']]);

        $remainingAttempts = 3 - $newFails;
        api_response(
            false,
            ['attempts_remaining' => $remainingAttempts],
            "Maling PIN. May natitira ka pang $remainingAttempts subok.",
            401
        );
    }
}
