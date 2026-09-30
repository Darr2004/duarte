<?php
/**
 * api/item_lookup.php — Item Stock Checker
 *
 * Accepts an item_code (from QR scan or manual entry) and returns
 * real-time stock status, location, active loans, variants, and
 * recent requisition activity for that item.
 *
 * GET ?item_code=ITEM-001
 *
 * Restricted to: admin, inventory_staff
 */
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db();

// ── Auth gate: admin + inventory_staff only ──
$api_user = api_authenticate($pdo);
if (!$api_user) {
    api_response(false, null, 'Authentication required.', 401);
}
$role = $api_user['role'] ?? '';
if (!in_array($role, ['admin', 'inventory_staff'], true)) {
    api_response(false, null, 'Access denied. Staff role required.', 403);
}

// ── Read item_code from query string ──
$item_code = trim($_GET['item_code'] ?? '');
if (preg_match('/[?&](?:item_code|code|q)=([^&]+)/i', $item_code, $m)) {
    $item_code = urldecode($m[1]);
}
$item_code = trim(preg_replace('/^(?:item:|\#)/i', '', $item_code));
if ($item_code === '') {
    api_response(false, null, 'Missing item_code parameter.', 400);
}

// ── 1. Fetch item master record ──
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https://' : 'http://';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Check if input is an asset tag (e.g. AST-... or contains tag=AST-...)
$matched_asset = null;
$asset_tag_candidate = $item_code;
if (preg_match('/[?&]tag=([^&]+)/i', $item_code, $m)) {
    $asset_tag_candidate = urldecode($m[1]);
}
$asset_tag_candidate = strtoupper(trim($asset_tag_candidate));

if (preg_match('/^AST-[0-9A-F]{6,16}$/i', $asset_tag_candidate)) {
    $ast_stmt = $pdo->prepare("
        SELECT a.id, a.asset_tag, a.status, a.condition_note, a.serial_number, a.location_note, a.item_id
        FROM assets a
        WHERE a.asset_tag = :tag
        LIMIT 1
    ");
    $ast_stmt->execute(['tag' => $asset_tag_candidate]);
    $matched_asset = $ast_stmt->fetch() ?: null;
}

if ($matched_asset) {
    $item_stmt = $pdo->prepare("
        SELECT i.id, i.item_code, i.name, i.brand, i.description,
               i.specification, i.unit, i.quantity_on_hand, i.is_borrowable,
               i.borrow_mode, i.variant_label, i.image_filename, i.status,
               c.name AS category_name,
               s.name AS stall_name, sl.layer_name
        FROM items i
        LEFT JOIN categories c  ON c.id = i.category_id
        LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
        LEFT JOIN stalls s       ON s.id  = sl.stall_id
        WHERE i.id = :id
        LIMIT 1
    ");
    $item_stmt->execute(['id' => (int)$matched_asset['item_id']]);
} else {
    $item_stmt = $pdo->prepare("
        SELECT i.id, i.item_code, i.name, i.brand, i.description,
               i.specification, i.unit, i.quantity_on_hand, i.is_borrowable,
               i.borrow_mode, i.variant_label, i.image_filename, i.status,
               c.name AS category_name,
               s.name AS stall_name, sl.layer_name
        FROM items i
        LEFT JOIN categories c  ON c.id = i.category_id
        LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
        LEFT JOIN stalls s       ON s.id  = sl.stall_id
        WHERE i.item_code = :code
        LIMIT 1
    ");
    $item_stmt->execute(['code' => $item_code]);
}
$item = $item_stmt->fetch();

if (!$item) {
    api_response(false, null, 'Item not found.', 404);
}

$item_id = (int)$item['id'];

// Build image URL
$img_url = null;
if (!empty($item['image_filename'])) {
    $img_url = $protocol . $host . BASE_URL . '/uploads/items/' . $item['image_filename'];
}

// Stock status
$stock = (int)$item['quantity_on_hand'];
$stock_status = 'in_stock';
if ($stock <= 0)      $stock_status = 'out_of_stock';
elseif ($stock <= 5)  $stock_status = 'low_stock';

$location = !empty($item['stall_name'])
    ? $item['stall_name'] . (!empty($item['layer_name']) ? ' (' . $item['layer_name'] . ')' : '')
    : 'Warehouse Main';

// ── 2. Active loans for this item ──
$loan_stmt = $pdo->prepare("
    SELECT SUM(tl.quantity) AS loaned_qty,
           MIN(tl.due_date) AS earliest_due,
           COUNT(*)         AS loan_count
    FROM tool_loans tl
    WHERE tl.item_id = :iid AND tl.returned_at IS NULL
");
$loan_stmt->execute(['iid' => $item_id]);
$loan_data = $loan_stmt->fetch();

$active_loans = [
    'count'        => (int)($loan_data['loan_count']  ?? 0),
    'loaned_qty'   => (int)($loan_data['loaned_qty']  ?? 0),
    'earliest_due' => !empty($loan_data['earliest_due'])
        ? date('M j, Y', strtotime($loan_data['earliest_due']))
        : null,
];

// ── 3. Variants (if applicable) ──
$variants = [];
if (!empty($item['variant_label'])) {
    $var_stmt = $pdo->prepare("
        SELECT id, variant_value, variant_note, image_filename, quantity_on_hand
        FROM item_variants
        WHERE item_id = :iid
        ORDER BY variant_value ASC
    ");
    $var_stmt->execute(['iid' => $item_id]);
    while ($v = $var_stmt->fetch()) {
        $v_img = null;
        if (!empty($v['image_filename'])) {
            $v_img = $protocol . $host . BASE_URL . '/uploads/items/' . $v['image_filename'];
        }
        $variants[] = [
            'id'               => (int)$v['id'],
            'variant_value'    => $v['variant_value'],
            'variant_note'     => $v['variant_note'] ?? '',
            'image_url'        => $v_img,
            'quantity_on_hand' => (int)$v['quantity_on_hand'],
        ];
    }
}

// ── 4. Recent requisition activity (last 10 lines involving this item) ──
$activity = [];
try {
    $act_stmt = $pdo->prepare("
        SELECT r.id AS req_id, r.status, r.created_at, r.released_at,
               ri.quantity_requested, ri.variant_selected,
               u.full_name AS requester_name
        FROM requisition_items ri
        JOIN requisitions r ON r.id = ri.requisition_id
        JOIN users u        ON u.id = r.requester_id
        WHERE ri.item_id = :iid
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $act_stmt->execute(['iid' => $item_id]);
    while ($a = $act_stmt->fetch()) {
        $activity[] = [
            'req_id'       => (int)$a['req_id'],
            'status'       => $a['status'],
            'requester'    => $a['requester_name'],
            'qty'          => (int)$a['quantity_requested'],
            'variant'      => $a['variant_selected'],
            'requested_at' => date('M j, Y g:i A', strtotime($a['created_at'])),
            'released_at'  => $a['released_at']
                ? date('M j, Y g:i A', strtotime($a['released_at']))
                : null,
        ];
    }
} catch (Exception $e) {
    // Non-critical — still return the item data
    error_log('[item_lookup] activity query failed: ' . $e->getMessage());
}

// ── 5. Build response ──
api_response(true, [
    'item' => [
        'id'               => $item_id,
        'item_code'        => $item['item_code'],
        'name'             => $item['name'],
        'brand'            => $item['brand'] ?? '',
        'description'      => $item['description'] ?? '',
        'specification'    => $item['specification'] ?? '',
        'category_name'    => $item['category_name'] ?? 'General',
        'unit'             => $item['unit'],
        'quantity_on_hand' => $stock,
        'stock_status'     => $stock_status,
        'is_borrowable'    => (bool)$item['is_borrowable'],
        'borrow_mode'      => $item['borrow_mode'],
        'variant_label'    => $item['variant_label'] ?? '',
        'image_url'        => $img_url,
        'location'         => $location,
        'status'           => $item['status'],
    ],
    'matched_asset' => $matched_asset,
    'active_loans'  => $active_loans,
    'variants'      => $variants,
    'activity'      => $activity,
]);
