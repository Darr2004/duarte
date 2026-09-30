<?php
/**
 * DuaRTE — tiny JSON endpoint backing the "this looks like an existing
 * item" hint on Inventory > Add Item. Given a name (and optionally a
 * brand / category), returns any active items that already look like
 * the same part, so staff restocking something can be pointed at
 * "Record stock in" instead of accidentally creating a duplicate item
 * with a brand-new code.
 *
 * Matching is intentionally loose (name substring, case-insensitive)
 * since this is just a nudge for a human to double-check — not a hard
 * duplicate block. It never prevents submission.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['inventory_staff', 'admin']);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$pdo = get_db();
$name = trim($_GET['name'] ?? '');
$brand = trim($_GET['brand'] ?? '');
$category_id = ($_GET['category_id'] ?? '') !== '' ? (int)$_GET['category_id'] : null;

// Require a couple real characters before searching — avoids a flood
// of near-useless matches while someone's still typing the first letter.
if (mb_strlen($name) < 3) {
    echo json_encode(['success' => true, 'matches' => []]);
    exit;
}

$sql = "SELECT id, item_code, name, brand, quantity_on_hand, unit
        FROM items
        WHERE status = 'active' AND name LIKE :name";
$params = ['name' => '%' . $name . '%'];

if ($category_id) {
    $sql .= ' AND category_id = :category_id';
    $params['category_id'] = $category_id;
}
if ($brand !== '') {
    $sql .= ' AND brand LIKE :brand';
    $params['brand'] = '%' . $brand . '%';
}

$sql .= ' ORDER BY name LIMIT 5';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode([
    'success' => true,
    'matches' => $stmt->fetchAll(),
]);
