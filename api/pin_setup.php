<?php
/**
 * DuaRTE Mobile API — Secure PIN Setup & Update
 *
 * Allows an authenticated user to set their PIN (first time) or update it.
 * If updating an existing PIN, requires current_pin OR password verification.
 * Enforces a 30-second cooldown between changes to prevent spamming.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(false, null, 'Method not allowed. Use POST.', 405);
}

$pdo = get_db();
$user = require_api_auth($pdo);

// Fresh fetch of user record to check current pin_hash and pin_set_at
$stmt = $pdo->prepare('SELECT id, full_name, username, password_hash, pin_hash, pin_set_at FROM users WHERE id = :id');
$stmt->execute(['id' => $user['id']]);
$userRecord = $stmt->fetch();

if (!$userRecord) {
    api_response(false, null, 'User not found.', 404);
}

// 1. Anti-Spam Rate Limit: 5-second cooldown between PIN updates
if (!empty($userRecord['pin_set_at'])) {
    $lastSet = strtotime($userRecord['pin_set_at']);
    $elapsed = time() - $lastSet;
    $cooldown = 5; // Reduced from 30s to 5s to avoid false lockout bugs
    if ($elapsed < $cooldown) {
        $wait = $cooldown - $elapsed;
        api_response(
            false,
            ['cooldown_seconds' => $wait],
            "Pakihintay ng $wait segundo bago muling magbago ng PIN.",
            429
        );
    }
}

$input = api_json_input();
$newPin = trim($input['pin'] ?? '');
$currentPin = trim($input['current_pin'] ?? '');
$password = $input['password'] ?? '';

// 2. Format validation
if (!preg_match('/^[0-9]{4}$/', $newPin)) {
    api_response(false, null, 'Ang PIN ay kailangang eksaktong 4 na numero (0-9).', 422);
}

// 3. Security: If a PIN is already active, user MUST verify current PIN or password
$hasExistingPin = !empty($userRecord['pin_hash']);
if ($hasExistingPin) {
    $verified = false;
    if ($currentPin !== '' && password_verify($currentPin, $userRecord['pin_hash'])) {
        $verified = true;
    } elseif ($password !== '' && password_verify($password, $userRecord['password_hash'])) {
        $verified = true;
    }

    if (!$verified) {
        api_response(false, null, 'Maling kasalukuyang PIN o password.', 403);
    }

    if ($currentPin === $newPin) {
        api_response(false, null, 'Ang bagong PIN ay kailangang iba sa kasalukuyang PIN.', 422);
    }
}

// 4. Update PIN
$hash = password_hash($newPin, PASSWORD_BCRYPT);
$upd = $pdo->prepare(
    'UPDATE users
     SET pin_hash = :hash,
         pin_set_at = NOW(),
         pin_failed_attempts = 0,
         pin_locked_until = NULL
     WHERE id = :id'
);
$upd->execute(['hash' => $hash, 'id' => $userRecord['id']]);

log_audit_event(
    $pdo,
    $userRecord,
    $hasExistingPin ? 'user_pin_change' : 'user_pin_setup',
    'user',
    (int)$userRecord['id'],
    $userRecord['full_name'] . ($hasExistingPin ? ' successfully changed their mobile PIN.' : ' configured their initial 4-digit mobile PIN.')
);

api_response(true, [
    'message' => $hasExistingPin ? 'Matagumpay na napalitan ang iyong PIN.' : 'Matagumpay na na-set ang iyong 4-digit PIN.',
    'user_id' => (int)$userRecord['id'],
    'has_pin' => true,
]);
