<?php
/**
 * DuaRTE — Stock Alert Subscriptions ("Notify Me When Available").
 *
 * Allows requesters to subscribe to out-of-stock items (specifically borrowable
 * tools or consumables) and dispatches notifications when stock is restored.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sms.php';

/**
 * Toggles or subscribes a user to an item or specific variant.
 */
function subscribe_stock_alert(PDO $pdo, int $user_id, int $item_id, ?string $variant_value = null): bool
{
    $variant = trim($variant_value ?? '') ?: null;
    if (is_user_subscribed_to_stock($pdo, $user_id, $item_id, $variant)) {
        return false;
    }

    $is_sqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
    $sql = $is_sqlite
        ? 'INSERT INTO item_stock_subscriptions (user_id, item_id, variant_value) VALUES (:user_id, :item_id, :variant)'
        : 'INSERT INTO item_stock_subscriptions (user_id, item_id, variant_value)
           VALUES (:user_id, :item_id, :variant)
           ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP';

    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        'user_id' => $user_id,
        'item_id' => $item_id,
        'variant' => $variant,
    ]);
}

/**
 * Unsubscribes a user from an item or variant.
 */
function unsubscribe_stock_alert(PDO $pdo, int $user_id, int $item_id, ?string $variant_value = null): bool
{
    $variant = trim($variant_value ?? '') ?: null;
    if ($variant === null) {
        $stmt = $pdo->prepare(
            'DELETE FROM item_stock_subscriptions
             WHERE user_id = :user_id AND item_id = :item_id AND variant_value IS NULL'
        );
        return $stmt->execute(['user_id' => $user_id, 'item_id' => $item_id]);
    }

    $stmt = $pdo->prepare(
        'DELETE FROM item_stock_subscriptions
         WHERE user_id = :user_id AND item_id = :item_id AND variant_value = :variant'
    );
    return $stmt->execute([
        'user_id' => $user_id,
        'item_id' => $item_id,
        'variant' => $variant,
    ]);
}

/**
 * Checks whether a user is subscribed to an item/variant.
 */
function is_user_subscribed_to_stock(PDO $pdo, int $user_id, int $item_id, ?string $variant_value = null): bool
{
    $variant = trim($variant_value ?? '') ?: null;
    if ($variant === null) {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM item_stock_subscriptions
             WHERE user_id = :user_id AND item_id = :item_id AND variant_value IS NULL'
        );
        $stmt->execute(['user_id' => $user_id, 'item_id' => $item_id]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM item_stock_subscriptions
             WHERE user_id = :user_id AND item_id = :item_id AND variant_value = :variant'
        );
        $stmt->execute(['user_id' => $user_id, 'item_id' => $item_id, 'variant' => $variant]);
    }
    return (bool)$stmt->fetch();
}

/**
 * Dispatches notifications to all subscribers when an item/variant comes back in stock,
 * and clears the fulfilled subscriptions.
 */
function trigger_stock_available_notifications(
    PDO $pdo,
    int $item_id,
    ?string $variant_value,
    string $item_name
): int {
    $variant = trim($variant_value ?? '') ?: null;

    if ($variant === null) {
        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM item_stock_subscriptions
             WHERE item_id = :item_id AND (variant_value IS NULL OR variant_value = "")'
        );
        $stmt->execute(['item_id' => $item_id]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM item_stock_subscriptions
             WHERE item_id = :item_id AND (variant_value = :variant OR variant_value IS NULL)'
        );
        $stmt->execute(['item_id' => $item_id, 'variant' => $variant]);
    }

    $subs = $stmt->fetchAll();
    if (!$subs) {
        return 0;
    }

    $label = $variant !== null ? "$item_name ($variant)" : $item_name;
    $message = "Good news! $label is back in stock and ready to borrow/request.";
    $link = BASE_URL . '/catalog/browse.php?q=' . urlencode($item_name);

    $sub_ids = [];
    $notified_users = [];
    foreach ($subs as $sub) {
        $uid = (int)$sub['user_id'];
        $sub_ids[] = (int)$sub['id'];
        if (isset($notified_users[$uid])) {
            continue; // Deduplicate: Never spam identical notifications to the same user
        }
        $notified_users[$uid] = true;
        notify_user($uid, $message, $link, $pdo);
        if (function_exists('notify_user_sms')) {
            @notify_user_sms($uid, "DuaRTE Alert: $message", $pdo);
        }
    }

    if ($sub_ids) {
        $ph = implode(',', array_fill(0, count($sub_ids), '?'));
        $del = $pdo->prepare("DELETE FROM item_stock_subscriptions WHERE id IN ($ph)");
        $del->execute($sub_ids);
    }

    return count($notified_users);
}
