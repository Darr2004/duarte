<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db();

// Resolve authenticated user to apply role/position-based restrictions
$api_user = api_authenticate($pdo);
$is_office_staff = ($api_user && ($api_user['position'] ?? null) === 'office_staff');

// 1. Categories
$cat_sql = "SELECT id, name, borrow_mode, code_prefix FROM categories";
if ($is_office_staff) {
    $cat_sql .= " WHERE name = 'Office Supplies' OR code_prefix = 'OS'";
}
$cat_sql .= " ORDER BY name ASC";
$cat_stmt = $pdo->query($cat_sql);
$categories = $cat_stmt->fetchAll();

// 2. Items
$item_filter = $is_office_staff ? " AND (c.name = 'Office Supplies' OR c.code_prefix = 'OS') " : "";
$items_stmt = $pdo->query("
    SELECT i.id, i.item_code, i.name, i.brand, i.description, i.category_id, 
           c.name AS category_name, i.unit, i.quantity_on_hand, i.is_borrowable, 
           i.borrow_mode, i.variant_label, i.image_filename,
           s.name AS stall_name, sl.layer_name
    FROM items i
    LEFT JOIN categories c ON c.id = i.category_id
    LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
    LEFT JOIN stalls s ON s.id = sl.stall_id
    WHERE i.status = 'active' {$item_filter}
    ORDER BY i.name ASC
");
$raw_items = $items_stmt->fetchAll();

// 2b. Active Loans for parity
$active_loans_by_item = [];
try {
    $loan_stmt = $pdo->query("
        SELECT tl.item_id, SUM(tl.quantity) AS count, MIN(tl.due_date) AS earliest_due
        FROM tool_loans tl
        WHERE tl.returned_at IS NULL
        GROUP BY tl.item_id
    ");
    while ($row = $loan_stmt->fetch()) {
        $active_loans_by_item[$row['item_id']] = [
            'count' => (int)$row['count'],
            'earliest_due' => !empty($row['earliest_due']) ? date('M j, Y', strtotime($row['earliest_due'])) : null,
        ];
    }
} catch (Exception $e) {}

// 3. Variants & Host configuration
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$var_stmt = $pdo->query("SELECT id, item_id, variant_value, variant_note, image_filename, quantity_on_hand FROM item_variants ORDER BY id ASC");
$raw_variants = $var_stmt->fetchAll();
$variants_by_item = [];
foreach ($raw_variants as $v) {
    $v_img_url = null;
    if (!empty($v['image_filename'])) {
        $v_img_url = $protocol . $host . BASE_URL . '/uploads/items/' . $v['image_filename'];
    }
    $variants_by_item[$v['item_id']][] = [
        'id'               => (int)$v['id'],
        'variant_value'    => $v['variant_value'],
        'variant_note'     => $v['variant_note'] ?? '',
        'image_filename'   => $v['image_filename'] ?? null,
        'image_url'        => $v_img_url,
        'quantity_on_hand' => (int)$v['quantity_on_hand']
    ];
}

$items = [];
foreach ($raw_items as $item) {
    $img_url = null;
    if (!empty($item['image_filename'])) {
        $img_url = $protocol . $host . BASE_URL . '/uploads/items/' . $item['image_filename'];
    }
    
    $items[] = [
        'id'               => (int)$item['id'],
        'item_code'        => $item['item_code'],
        'name'             => $item['name'],
        'brand'            => $item['brand'] ?? '',
        'description'      => $item['description'] ?? '',
        'category_id'      => $item['category_id'] ? (int)$item['category_id'] : null,
        'category_name'    => $item['category_name'] ?? 'General',
        'unit'             => $item['unit'],
        'quantity_on_hand' => (int)$item['quantity_on_hand'],
        'stock'            => (int)$item['quantity_on_hand'],
        'is_borrowable'    => (bool)$item['is_borrowable'],
        'borrow_mode'      => $item['borrow_mode'],
        'variant_label'    => $item['variant_label'] ?? '',
        'image_filename'   => $item['image_filename'] ?? null,
        'image_url'        => $img_url,
        'stall_name'       => $item['stall_name'] ?? null,
        'layer_name'       => $item['layer_name'] ?? null,
        'location'         => (!empty($item['stall_name']) ? $item['stall_name'] . (!empty($item['layer_name']) ? ' (' . $item['layer_name'] . ')' : '') : 'Warehouse Main'),
        'loan_count'       => $active_loans_by_item[$item['id']]['count'] ?? 0,
        'earliest_due'     => $active_loans_by_item[$item['id']]['earliest_due'] ?? null,
        'variants'         => $variants_by_item[$item['id']] ?? []
    ];
}

// 4. Trucks
$truck_stmt = $pdo->query("SELECT id, plate_number, model, status FROM trucks ORDER BY plate_number ASC");
$trucks = $truck_stmt->fetchAll();

api_response(true, [
    'categories' => $categories,
    'items'      => $items,
    'trucks'     => $trucks,
    'synced_at'  => date('Y-m-d H:i:s')
]);
