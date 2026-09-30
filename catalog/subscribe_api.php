<?php
/**
 * DuaRTE — Stock Alert Subscription API.
 *
 * Handles AJAX POST requests to subscribe/unsubscribe a requester
 * to an out-of-stock item/variant for "Notify Me When Available" alerts.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock_alerts.php';

require_role(['driver_helper', 'field_supervisor', 'inventory_staff', 'admin']);

$user = current_user();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $item_id = (int)($_GET['item_id'] ?? 0);
    $variant = isset($_GET['variant']) ? trim($_GET['variant']) : null;
    if ($variant === '') {
        $variant = null;
    }

    $is_subbed = is_user_subscribed_to_stock($pdo, (int)$user['id'], $item_id, $variant);
    echo json_encode(['subscribed' => $is_subbed]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$csrf = $data['csrf_token'] ?? null;
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or expired session. Please refresh the page.']);
    exit;
}

$item_id = (int)($data['item_id'] ?? 0);
$variant = isset($data['variant']) ? trim($data['variant']) : null;
if ($variant === '') {
    $variant = null;
}
$action  = $data['action'] ?? 'toggle'; // 'subscribe' | 'unsubscribe' | 'toggle'

if ($item_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid item ID.']);
    exit;
}

$is_subbed = is_user_subscribed_to_stock($pdo, (int)$user['id'], $item_id, $variant);

if ($action === 'unsubscribe' || ($action === 'toggle' && $is_subbed)) {
    unsubscribe_stock_alert($pdo, (int)$user['id'], $item_id, $variant);
    echo json_encode([
        'success'    => true,
        'subscribed' => false,
        'message'    => 'Alert removed. You will not receive a notification.',
    ]);
    exit;
}

subscribe_stock_alert($pdo, (int)$user['id'], $item_id, $variant);
echo json_encode([
    'success'    => true,
    'subscribed' => true,
    'message'    => 'Subscribed! We will notify you via the bell icon as soon as this is back in stock.',
]);
exit;
