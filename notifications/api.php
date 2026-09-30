<?php
/**
 * DuaRTE — Notifications AJAX API, backing the notification modal.
 * Mirrors requisition/cart_api.php's pattern: JSON in, JSON out, no
 * page reload. GET-only, read-only from the caller's point of view
 * (it does flip is_read server-side, same as notifications/list.php
 * already did on page load).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

header('Content-Type: application/json');

$user = current_user();
$action = $_GET['action'] ?? 'list';

if ($action !== 'list') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

$items = recent_notifications($user['id'], 30);
mark_notifications_read($user['id']);

$out = array_map(function ($n) {
    return [
        'message'    => $n['message'],
        'link'       => $n['link'],
        'created_at' => $n['created_at'],
        'is_read'    => (bool)$n['is_read'],
    ];
}, $items);

echo json_encode(['success' => true, 'items' => $out]);
