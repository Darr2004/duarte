<?php
/**
 * DuaRTE — Cart AJAX API, backing the cart drawer modal.
 * Mirrors the validation logic in catalog/browse.php and
 * requisition/cart.php, but responds with JSON instead of a redirect,
 * so the drawer can update in place without a page reload.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['driver_helper', 'field_supervisor']);

header('Content-Type: application/json');

$pdo = get_db();
$user = current_user();
$action = $_POST['action'] ?? '';

function load_cart_items(PDO $pdo): array
{
    $cart = cart_get();
    $items = [];
    if (!$cart) {
        return $items;
    }
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $mode = resolve_line_borrow_mode($row['borrow_mode'], $cart[$row['id']]['mode'] ?? null);
        $items[] = array_merge($row, [
            'qty_requested'  => $cart[$row['id']]['qty'],
            'mode_requested' => $mode,
            'days_requested' => $cart[$row['id']]['days'] ?? 3,
            'variant_selected' => $cart[$row['id']]['variant'] ?? null,
        ]);
    }
    return $items;
}

function render_drawer(array $cart_items): string
{
    global $pdo;
    $drawer_needs_truck = $cart_items ? cart_requires_truck($pdo, cart_get()) : false;
    ob_start();
    require __DIR__ . '/../includes/cart_drawer_fragment.php';
    return ob_get_clean();
}

function respond(array $data): void
{
    $data['cart_count'] = cart_count();
    echo json_encode($data);
    exit;
}

if (!csrf_check($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.']);
}

switch ($action) {
    case 'list':
        respond(['success' => true, 'html' => render_drawer(load_cart_items($pdo))]);
        break;

    case 'add': {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $qty     = max(1, (int)($_POST['qty'] ?? 1));
        $days    = isset($_POST['days']) && $_POST['days'] !== '' ? min(30, max(1, (int)$_POST['days'])) : null;
        $variant = trim($_POST['variant'] ?? '') ?: null;
        $requested_mode = $_POST['mode'] ?? null;

        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $item_id]);
        $item = $stmt->fetch();

        if (!$item) {
            respond(['success' => false, 'error' => 'That item is no longer available.']);
        }
        if ($item['variant_label'] && !$variant) {
            respond(['success' => false, 'error' => 'Please choose a ' . $item['variant_label'] . ' for ' . $item['name'] . '.']);
        }

        $stock = available_stock_for($pdo, $item, $variant);
        if (!$stock['ok']) {
            respond(['success' => false, 'error' => 'That ' . $item['variant_label'] . ' option isn\'t available for ' . $item['name'] . '.']);
        }
        if ($stock['available'] < 1) {
            respond(['success' => false, 'error' => $stock['label'] . ' is currently out of stock.']);
        }
        $already = cart_get()[$item_id]['qty'] ?? 0;
        if ($already + $qty > $stock['available']) {
            respond(['success' => false, 'error' => 'Only ' . $stock['available'] . ' ' . $item['unit'] . ' of ' . $stock['label'] . ' available.']);
        }

        $mode = resolve_line_borrow_mode($item['borrow_mode'], $requested_mode);
        cart_add($item_id, $qty, $mode, $mode === 'borrow' ? $days : null, $variant);
        respond(['success' => true, 'html' => render_drawer(load_cart_items($pdo)), 'added_name' => $item['name']]);
        break;
    }

    case 'update': {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $qty     = (int)($_POST['qty'] ?? 0);

        if ($qty > 0) {
            $stmt = $pdo->prepare("SELECT * FROM items WHERE id = :id");
            $stmt->execute(['id' => $item_id]);
            $item = $stmt->fetch();
            if ($item) {
                $variant = cart_get()[$item_id]['variant'] ?? null;
                $stock = available_stock_for($pdo, $item, $variant);
                if ($qty > $stock['available']) {
                    respond(['success' => false, 'error' => 'Only ' . $stock['available'] . ' ' . $item['unit'] . ' of ' . $stock['label'] . ' available.', 'html' => render_drawer(load_cart_items($pdo))]);
                }
            }
        }
        cart_update($item_id, $qty);
        respond(['success' => true, 'html' => render_drawer(load_cart_items($pdo))]);
        break;
    }

    case 'remove': {
        $item_id = (int)($_POST['item_id'] ?? 0);
        cart_remove($item_id);
        respond(['success' => true, 'html' => render_drawer(load_cart_items($pdo))]);
        break;
    }

    case 'submit': {
        if (!is_within_office_hours()) {
            respond(['success' => false, 'error' => office_hours_message()]);
        }
        $cart = cart_get();
        if (!$cart) {
            respond(['success' => false, 'error' => 'Your cart is empty.']);
        }
        $purpose = trim($_POST['purpose'] ?? '');

        $ids = array_keys($cart);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders) AND status = 'active'");
        $stmt->execute($ids);
        $catalog_items = [];
        foreach ($stmt->fetchAll() as $row) {
            $catalog_items[$row['id']] = $row;
        }

        foreach ($cart as $item_id => $line) {
            if (!isset($catalog_items[$item_id])) {
                respond(['success' => false, 'error' => 'One of the items in your cart is no longer available.', 'html' => render_drawer(load_cart_items($pdo))]);
            }
            $stock = available_stock_for($pdo, $catalog_items[$item_id], $line['variant'] ?? null);
            if (!$stock['ok'] || $line['qty'] > $stock['available']) {
                respond(['success' => false, 'error' => $stock['label'] . ' now only has ' . $stock['available'] . ' available.', 'html' => render_drawer(load_cart_items($pdo))]);
            }
        }

        // Block ANY new requisition — borrow or consume-only — while an
        // earlier loan is overdue (mirrors requisition/cart.php's
        // non-AJAX submit path — previously this only checked carts that
        // themselves contained a borrow line).
        if (user_has_overdue_loans($pdo, (int)$user['id'])) {
            respond(['success' => false, 'error' => 'You have an overdue borrowed item. Please return it before submitting any new request.', 'html' => render_drawer(load_cart_items($pdo))]);
        }
        $truck_id = !empty($_POST['truck_id']) ? (int)$_POST['truck_id'] : null;
        $is_maintenance_request = !empty($_POST['is_maintenance_request']);
        if (!$truck_id && cart_requires_truck($pdo, $cart)) {
            respond(['success' => false, 'error' => 'Please select which Truck (Plate Number) this request is for.', 'html' => render_drawer(load_cart_items($pdo))]);
        }
        $trk = null;
        if ($truck_id) {
            $t_chk = $pdo->prepare('SELECT id, plate_number, status FROM trucks WHERE id = :id');
            $t_chk->execute(['id' => $truck_id]);
            $trk = $t_chk->fetch();
            if (!$trk) {
                respond(['success' => false, 'error' => 'The selected truck is invalid.', 'html' => render_drawer(load_cart_items($pdo))]);
            }
        }
        if ($trk && $trk['status'] === 'under_maintenance' && !$is_maintenance_request) {
            respond(['success' => false, 'error' => 'Truck ' . $trk['plate_number'] . ' is currently under maintenance and cannot be scheduled. Check "Para sa pag-aayos ng truck" if this request is to repair it.', 'html' => render_drawer(load_cart_items($pdo))]);
        }

        try {
            $requisition_id = submit_requisition($pdo, $user, $cart, $catalog_items, $purpose, $truck_id, $is_maintenance_request);
            cart_clear();

            if (requisition_needs_supervisor_approval($user)) {
                notify_role('field_supervisor', $user['full_name'] . ' submitted a new' . ($is_maintenance_request ? ' TRUCK REPAIR' : '') . ' request (#' . $requisition_id . ') for approval.', BASE_URL . '/requisition/view.php?id=' . $requisition_id);
                notify_role_sms('field_supervisor', 'DuaRTE Alert: ' . $user['full_name'] . ' submitted ' . ($is_maintenance_request ? 'TRUCK REPAIR ' : '') . 'Requisition #' . $requisition_id . ' awaiting your approval.');
            } else {
                log_audit_event($pdo, null, 'requisition_approve', 'requisition', $requisition_id,
                    'Request #' . $requisition_id . ' from ' . $user['full_name'] . ' approved without review (no supervisor assigned).');
                notify_role('inventory_staff', $user['full_name'] . ' submitted request #' . $requisition_id . '. Ready for release.', BASE_URL . '/requisition/view.php?id=' . $requisition_id);
            }

            respond(['success' => true, 'redirect' => BASE_URL . '/requisition/view.php?id=' . $requisition_id . '&submitted=1']);
        } catch (RequisitionShortfallException $e) {
            respond(['success' => false, 'error' => $e->getMessage(), 'html' => render_drawer(load_cart_items($pdo))]);
        } catch (Exception $e) {
            error_log($e->getMessage());
            respond(['success' => false, 'error' => 'Something went wrong submitting your request. Please try again.']);
        }
        break;
    }

    default:
        http_response_code(400);
        respond(['success' => false, 'error' => 'Unknown action.']);
}
