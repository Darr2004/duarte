<?php
/**
 * DuaRTE — Mobile Notifications API.
 * Token-authenticated equivalent of notifications/api.php (which uses
 * require_login()/session auth and is unreachable from the Flutter app).
 * GET  -> list recent notifications for the authenticated user, plus an
 *         unread_count (fetched BEFORE marking read, mirroring the web
 *         modal's "list marks read on load" behavior — see mark param
 *         below to opt out of that if the app wants a peek without
 *         clearing the badge).
 * POST -> mark_read: flips all of the user's notifications to read
 *         (used by the mobile bell/badge when the user opens the list).
 */
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

$pdo = get_db();
$api_user = require_api_auth($pdo);

$user_id = (int)$api_user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // unread_count first — reading it after mark_notifications_read()
    // would always report 0, which defeats the point of a badge count.
    $unread_count = unread_notification_count($user_id);

    $limit = (int)($_GET['limit'] ?? 30);
    if ($limit < 1) {
        $limit = 30;
    }
    if ($limit > 100) {
        $limit = 100;
    }
    $items = recent_notifications($user_id, $limit);

    // Default true so a plain poll still clears the badge, same as the
    // web modal; pass mark=0 to peek (e.g. a background badge refresh)
    // without marking everything read out from under an open list screen.
    $mark = ($_GET['mark'] ?? '1') !== '0';
    if ($mark) {
        mark_notifications_read($user_id);
    }

    $out = array_map(function ($n) {
        return [
            'id'         => (int)$n['id'],
            'message'    => $n['message'],
            'link'       => $n['link'],
            'created_at' => $n['created_at'],
            'is_read'    => (bool)$n['is_read'],
        ];
    }, $items);

    api_response(true, [
        'items'         => $out,
        'unread_count'  => $unread_count,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? '';

    if ($action === 'mark_read') {
        mark_notifications_read($user_id);
        api_response(true, ['unread_count' => 0]);
    }

    api_response(false, null, 'Unknown action.', 400);
}

api_response(false, null, 'Method not allowed.', 405);
