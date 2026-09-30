<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock_alerts.php';

$pdo = get_db();
$api_user = require_api_auth($pdo);
$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
$input = is_array($json_input) ? $json_input : $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $item_id = (int)($_GET['item_id'] ?? ($input['item_id'] ?? 0));
    $variant = isset($_GET['variant']) ? trim($_GET['variant']) : (isset($input['variant']) ? trim($input['variant']) : null);
    if ($variant === '') {
        $variant = null;
    }

    if ($item_id <= 0) {
        api_response(false, null, 'Invalid item ID.', 400);
    }

    $is_subbed = is_user_subscribed_to_stock($pdo, (int)$api_user['id'], $item_id, $variant);
    api_response(true, ['subscribed' => $is_subbed]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $item_id = (int)($input['item_id'] ?? 0);
    $variant = isset($input['variant']) ? trim($input['variant']) : null;
    if ($variant === '') {
        $variant = null;
    }
    $action = $input['action'] ?? 'toggle'; // 'subscribe' | 'unsubscribe' | 'toggle'

    if ($item_id <= 0) {
        api_response(false, null, 'Invalid item ID.', 400);
    }

    $currently_subbed = is_user_subscribed_to_stock($pdo, (int)$api_user['id'], $item_id, $variant);

    if ($action === 'subscribe') {
        $new_subbed = true;
        subscribe_stock_alert($pdo, (int)$api_user['id'], $item_id, $variant);
        $msg = "You will be notified when this item is back in stock.";
    } elseif ($action === 'unsubscribe') {
        $new_subbed = false;
        unsubscribe_stock_alert($pdo, (int)$api_user['id'], $item_id, $variant);
        $msg = "Stock notification removed.";
    } else {
        // Toggle
        if ($currently_subbed) {
            unsubscribe_stock_alert($pdo, (int)$api_user['id'], $item_id, $variant);
            $new_subbed = false;
            $msg = "Stock notification removed.";
        } else {
            subscribe_stock_alert($pdo, (int)$api_user['id'], $item_id, $variant);
            $new_subbed = true;
            $msg = "You will be notified when this item is back in stock.";
        }
    }

    api_response(true, [
        'subscribed' => $new_subbed,
        'message'    => $msg,
    ]);
}

api_response(false, null, 'Method not allowed.', 405);
