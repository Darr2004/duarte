<?php
/**
 * Quick login-flow diagnostic — simulates what attempt_login() does
 * without actually creating a session.
 */
header('Content-Type: text/plain');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "=== Login Flow Diagnostic ===\n";
echo "BASE_URL: '" . BASE_URL . "'\n";
echo "APP_ENV: " . APP_ENV . "\n\n";

$pdo = get_db();

// 1. Test login_attempts query
echo "1. login_attempts query... ";
try {
    $stmt = $pdo->prepare(
        'SELECT MAX(attempted_at) AS last_attempt, COUNT(*) AS fails
           FROM login_attempts
          WHERE username = :u AND success = 0
            AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute(['u' => 'admin']);
    $row = $stmt->fetch();
    echo "OK (fails: {$row['fails']})\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 2. Test user lookup
echo "2. User lookup... ";
try {
    $stmt = $pdo->prepare('SELECT id, username, role, status, full_name FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => 'admin']);
    $user = $stmt->fetch();
    if ($user) {
        echo "OK (id:{$user['id']}, role:{$user['role']}, status:{$user['status']})\n";
    } else {
        echo "NOT FOUND\n";
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 3. Test login_attempts INSERT
echo "3. login_attempts INSERT... ";
try {
    $stmt = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, :s)');
    $stmt->execute(['u' => '_diag_', 'ip' => '0.0.0.0', 's' => 0]);
    $pdo->exec("DELETE FROM login_attempts WHERE username = '_diag_'");
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 4. Test audit_logs INSERT (failed login path — null actor)
echo "4. audit_logs INSERT (null actor)... ";
try {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
            (actor_id, actor_name_snapshot, actor_role_snapshot, action, entity_type, entity_id, description, ip_address)
         VALUES (:actor_id, :actor_name, :actor_role, :action, :entity_type, :entity_id, :description, :ip)'
    );
    $stmt->execute([
        'actor_id'    => null,
        'actor_name'  => 'System',
        'actor_role'  => null,
        'action'      => '_diag_',
        'entity_type' => 'user',
        'entity_id'   => null,
        'description' => 'Diagnostic test',
        'ip'          => '0.0.0.0',
    ]);
    $pdo->exec("DELETE FROM audit_logs WHERE action = '_diag_'");
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 5. Test audit_logs INSERT (success login path — with actor)
echo "5. audit_logs INSERT (with actor)... ";
try {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
            (actor_id, actor_name_snapshot, actor_role_snapshot, action, entity_type, entity_id, description, ip_address)
         VALUES (:actor_id, :actor_name, :actor_role, :action, :entity_type, :entity_id, :description, :ip)'
    );
    $stmt->execute([
        'actor_id'    => 1,
        'actor_name'  => 'Test User',
        'actor_role'  => 'admin',
        'action'      => '_diag_success_',
        'entity_type' => 'user',
        'entity_id'   => 1,
        'description' => 'Test User logged in.',
        'ip'          => '0.0.0.0',
    ]);
    $pdo->exec("DELETE FROM audit_logs WHERE action = '_diag_success_'");
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 6. Test expire_stale_requisitions
echo "6. expire_stale_requisitions query... ";
try {
    $rows = $pdo->query(
        "SELECT id FROM requisitions 
         WHERE status IN ('pending','approved') 
           AND DATE(created_at) < CURDATE()"
    )->fetchAll();
    echo "OK (found " . count($rows) . " stale)\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 7. Test dashboard queries
echo "7. Dashboard: items count... ";
try {
    $c = $pdo->query("SELECT COUNT(*) c FROM items WHERE status = 'active'")->fetch()['c'];
    echo "OK ($c items)\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 8. system_settings
echo "8. system_settings query... ";
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'mcda_%'");
    $rows = $stmt->fetchAll();
    echo "OK (" . count($rows) . " rows)\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 9. Check user last_login_at update
echo "9. users UPDATE last_login_at... ";
try {
    $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW(), pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id');
    $stmt->execute(['id' => 99999]); // non-existent ID, safe
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "\n=== ALL TESTS COMPLETE ===\n";
