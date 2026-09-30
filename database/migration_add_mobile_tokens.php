<?php
/**
 * DuaRTE — One-time migration: real mobile API authentication.
 *
 * Adds `mobile_tokens`. Before this, api/login.php generated a token
 * but never stored it, and every other api/*.php endpoint trusted a
 * plain `user_id` (and for requisitions, `role`) sent by the client —
 * so anyone could act as any user just by changing that number. This
 * table lets api/login.php persist the token it hands out, and lets
 * includes/auth.php's api_authenticate() resolve the real user from
 * an Authorization header instead of trusting client-supplied fields.
 *
 * SAFE TO RE-RUN: checks first and only adds what's missing.
 *
 *   php database/migration_add_mobile_tokens.php
 *
 * or visit it in the browser as an admin.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    require_role(['admin']);
    header('Content-Type: text/plain');
}

$pdo = get_db();

function duarte_table_exists_3(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $stmt->execute(['t' => $table]);
    return (bool)$stmt->fetchColumn();
}

if (!duarte_table_exists_3($pdo, 'mobile_tokens')) {
    $pdo->exec(
        "CREATE TABLE mobile_tokens (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id       INT UNSIGNED NOT NULL,
            token         VARCHAR(64)  NOT NULL UNIQUE,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at  TIMESTAMP    NULL DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_mobile_tokens_token (token)
        ) ENGINE=InnoDB"
    );
    echo "Created mobile_tokens table.\n";
} else {
    echo "mobile_tokens table already exists.\n";
}

echo "Done.\n";
