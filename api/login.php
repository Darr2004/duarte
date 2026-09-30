<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(false, null, 'Method not allowed. Use POST.', 405);
}

$input = api_json_input();
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    api_response(false, null, 'Username and password are required.', 400);
}

$pdo = get_db();
$wait = login_lockout_seconds_remaining($pdo, $username);
if ($wait > 0) {
    $mins = max(1, (int)ceil($wait / 60));
    api_response(false, null, "Too many failed attempts. Try again in $mins minute(s).", 429);
}

$user = attempt_login($username, $password);
if ($user) {
    // Clear any previous PIN lockout now that master password was verified
    $pdo->prepare('UPDATE users SET pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id')
        ->execute(['id' => $user['id']]);

    // Persisted, single-active-token-per-user. Every other api/*.php
    // endpoint requires this via Authorization: Bearer <token> — see
    // require_api_auth() in includes/auth.php.
    $token = create_mobile_token($pdo, (int)$user['id']);

    api_response(true, [
        'token'       => $token,
        'user'        => [
            'id'             => (int)$user['id'],
            'full_name'      => $user['full_name'],
            'employee_id'    => $user['employee_id'],
            'username'       => $user['username'],
            'role'           => $user['role'],
            'position'       => $user['position'] ?? '',
            'contact_number' => $user['contact_number'] ?? '',
            'status'         => $user['status'],
            'has_pin'        => !empty($user['pin_hash']),
        ]
    ]);
} else {
    api_response(false, null, 'Incorrect username or password.', 401);
}
