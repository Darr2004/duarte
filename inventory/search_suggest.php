<?php
/**
 * DuaRTE — Live Search Auto-Suggest JSON Endpoint
 * Provides fast autocomplete suggestions for Assets, Inventory Items, and Catalog.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_login();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$pdo = get_db();
$user = current_user();
$query = trim($_GET['q'] ?? '');
$type  = trim($_GET['type'] ?? 'assets');

if ($query === '' || mb_strlen($query) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

$results = [];
$term = '%' . $query . '%';

if ($type === 'assets') {
    // Only inventory staff and admin can query asset inventory
    if (!in_array($user['role'], ['inventory_staff', 'admin'], true)) {
        echo json_encode(['results' => []]);
        exit;
    }

    $sql = "SELECT a.id, a.asset_tag, a.serial_number, a.status, a.location_note,
                   i.name AS item_name, i.item_code, i.brand, iv.variant_value,
                   h.full_name AS holder_name
            FROM assets a
            JOIN items i ON i.id = a.item_id
            LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
            LEFT JOIN users h ON h.id = a.current_holder_id
            WHERE a.asset_tag LIKE :q1
               OR a.serial_number LIKE :q2
               OR i.name LIKE :q3
               OR i.item_code LIKE :q4
               OR i.brand LIKE :q5
               OR iv.variant_value LIKE :q6
               OR a.location_note LIKE :q7
               OR h.full_name LIKE :q8
            ORDER BY 
              CASE 
                WHEN a.asset_tag LIKE :ex1 THEN 1
                WHEN i.item_code LIKE :ex2 THEN 2
                WHEN i.name LIKE :ex3 THEN 3
                ELSE 4
              END,
              a.asset_tag ASC
            LIMIT 8";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'q1' => $term,
        'q2' => $term,
        'q3' => $term,
        'q4' => $term,
        'q5' => $term,
        'q6' => $term,
        'q7' => $term,
        'q8' => $term,
        'ex1' => $query . '%',
        'ex2' => $query . '%',
        'ex3' => $query . '%',
    ]);

    foreach ($stmt->fetchAll() as $row) {
        $meta = [];
        if (!empty($row['brand'])) $meta[] = $row['brand'];
        if (!empty($row['variant_value'])) $meta[] = $row['variant_value'];
        if ($row['status'] === 'checked_out' && !empty($row['holder_name'])) {
            $meta[] = 'Holder: ' . $row['holder_name'];
        } elseif (!empty($row['location_note'])) {
            $meta[] = $row['location_note'];
        }

        $results[] = [
            'value'       => $row['asset_tag'],
            'title'       => $row['asset_tag'],
            'name'        => $row['item_name'] . ' (' . $row['item_code'] . ')',
            'badge'       => asset_status_label($row['status']),
            'badge_class' => asset_status_class($row['status']),
            'subtitle'    => implode(' · ', $meta),
            'url'         => BASE_URL . '/inventory/asset_view.php?tag=' . urlencode($row['asset_tag']),
        ];
    }
} elseif ($type === 'items' || $type === 'catalog') {
    $is_staff = in_array($user['role'], ['inventory_staff', 'admin'], true);
    
    $where_status = ($type === 'catalog') ? " AND i.status = 'active'" : "";
    
    $sql = "SELECT i.id, i.item_code, i.name, i.brand, i.quantity_on_hand, i.unit, i.status,
                   c.name AS category_name,
                   COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ") AS low_stock_threshold,
                   r.room_number, s.stall_number, sl.layer_name
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
            LEFT JOIN stalls s ON s.id = sl.stall_id
            LEFT JOIN rooms r ON r.id = s.room_id
            WHERE (i.name LIKE :q1
               OR i.item_code LIKE :q2
               OR i.brand LIKE :q3
               OR i.description LIKE :q4
               OR c.name LIKE :q5)
               {$where_status}
            ORDER BY 
              CASE 
                WHEN i.item_code LIKE :ex1 THEN 1
                WHEN i.name LIKE :ex2 THEN 2
                ELSE 3
              END,
              i.name ASC
            LIMIT 8";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'q1' => $term,
        'q2' => $term,
        'q3' => $term,
        'q4' => $term,
        'q5' => $term,
        'ex1' => $query . '%',
        'ex2' => $query . '%',
    ]);

    foreach ($stmt->fetchAll() as $row) {
        $st = stock_status((int)$row['quantity_on_hand'], (int)$row['low_stock_threshold']);
        $meta = [];
        if (!empty($row['category_name'])) $meta[] = $row['category_name'];
        if (!empty($row['brand'])) $meta[] = $row['brand'];
        if ($is_staff && !empty($row['stall_number'])) {
            $meta[] = 'Room ' . $row['room_number'] . ' · Stall ' . $row['stall_number'] . ($row['layer_name'] ? ' — ' . $row['layer_name'] : '');
        }

        $badge_label = ((int)$row['quantity_on_hand']) . ' ' . ($row['unit'] ?? 'pcs');
        if ($row['status'] === 'inactive') {
            $badge_label = 'Inactive';
        }

        $results[] = [
            'value'       => $row['item_code'],
            'title'       => $row['item_code'] . ' — ' . $row['name'],
            'name'        => $row['name'],
            'badge'       => $badge_label,
            'badge_class' => $st['class'] ?? 'badge-available',
            'subtitle'    => implode(' · ', $meta),
            'url'         => $type === 'catalog' 
                ? BASE_URL . '/catalog/browse.php?q=' . urlencode($row['item_code'])
                : BASE_URL . '/inventory/items.php?q=' . urlencode($row['item_code']),
        ];
    }
}

echo json_encode(['results' => $results]);
