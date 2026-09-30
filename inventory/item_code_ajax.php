<?php
/**
 * DuaRTE — tiny JSON endpoint backing the "Item code" field on
 * Inventory > Add Item. Given a category_id (or none), returns the
 * next suggested "PREFIX-NNN" code for that category so the form can
 * fill it in as soon as a category is picked. See
 * next_item_code_for_category() in includes/functions.php for the
 * actual logic — this file is just the thin HTTP wrapper.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['inventory_staff', 'admin']);

header('Content-Type: application/json');
// This must never be cached: the "next" code changes every time an item
// is added under the category, so a stale cached response here is what
// causes the form to keep suggesting an already-used code (e.g. showing
// TRUC-001 again after an item with that exact code already exists).
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$pdo = get_db();
$category_id = ($_GET['category_id'] ?? '') !== '' ? (int)$_GET['category_id'] : null;

echo json_encode([
    'success' => true,
    'code'    => next_item_code_for_category($pdo, $category_id),
]);
