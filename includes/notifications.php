<?php
/**
 * DuaRTE — In-app notifications.
 * Stands in for the "real-time notification or SMS alert" described
 * in the proposal for the requisition/approval workflow.
 */

require_once __DIR__ . '/../config/database.php';

function notify_user(int $user_id, string $message, ?string $link = null, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? get_db();
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, message, link) VALUES (:user_id, :message, :link)'
    );
    $stmt->execute(['user_id' => $user_id, 'message' => $message, 'link' => $link]);
}

/** Notify every active user of a given role (e.g. all field supervisors). */
function notify_role(string $role, string $message, ?string $link = null, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? get_db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = :role AND status = 'active'");
    $stmt->execute(['role' => $role]);
    foreach ($stmt->fetchAll() as $row) {
        notify_user((int)$row['id'], $message, $link, $pdo);
    }
}

function unread_notification_count(int $user_id, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? get_db();
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM notifications WHERE user_id = :id AND is_read = 0');
    $stmt->execute(['id' => $user_id]);
    return (int)$stmt->fetch()['c'];
}

function recent_notifications(int $user_id, int $limit = 20, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = :id ORDER BY created_at DESC LIMIT :lim'
    );
    $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mark_notifications_read(int $user_id, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? get_db();
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :id');
    $stmt->execute(['id' => $user_id]);
}

/**
 * Cleans up old read notifications older than $days (default 30),
 * and unread notifications older than $days * 2 (default 60).
 * Prevents notifications table from ballooning over time.
 * Returns the count of deleted rows.
 */
function cleanup_old_notifications(?PDO $pdo = null, int $days = 30): int
{
    $pdo = $pdo ?? get_db();
    $cutoff_read = date('Y-m-d H:i:s', time() - ($days * 86400));
    $cutoff_all = date('Y-m-d H:i:s', time() - ($days * 2 * 86400));

    $stmt = $pdo->prepare(
        'DELETE FROM notifications 
         WHERE (is_read = 1 AND created_at < :cutoff_read)
            OR (created_at < :cutoff_all)'
    );
    $stmt->execute([
        'cutoff_read' => $cutoff_read,
        'cutoff_all'  => $cutoff_all,
    ]);
    return $stmt->rowCount();
}

