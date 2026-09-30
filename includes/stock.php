<?php
/**
 * DuaRTE — Stock movement recording.
 *
 * record_stock_movement() is the ONLY function that should ever change
 * items.quantity_on_hand. Every call writes a row to stock_movements,
 * so the catalog's on-hand number and the audit trail can never drift
 * apart. Locks the item row (SELECT ... FOR UPDATE) so concurrent
 * releases/adjustments on the same item can't race each other.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/stock_alerts.php';

class InsufficientStockException extends RuntimeException {}

/**
 * @param string      $movement_type    'stock_in' | 'release' | 'adjustment' | 'return'
 * @param int         $quantity_change  Signed. Positive adds stock, negative removes it.
 * @param string|null $reference_type   e.g. 'requisition', 'manual', 'initial_stock', 'item_edit'
 * @param int|null    $item_variant_id  When set, this movement is scoped to one specific
 *                                      variant of the item (see item_variants). Its own
 *                                      quantity_on_hand is locked and updated by the same
 *                                      $quantity_change, IN ADDITION to the item's aggregate
 *                                      total, so the two never drift apart.
 * @return array{before:int, after:int, name:string, unit:string,
 *               variant_before:?int, variant_after:?int, variant_value:?string}
 */
function record_stock_movement(
    PDO $pdo,
    int $item_id,
    string $movement_type,
    int $quantity_change,
    ?string $reference_type,
    ?int $reference_id,
    int $recorded_by,
    ?string $note = null,
    ?int $item_variant_id = null
): array {
    $own_transaction = !$pdo->inTransaction();
    if ($own_transaction) {
        $pdo->beginTransaction();
    }

    try {
        // Lock the item row first, then (if applicable) the variant row —
        // always in this order, so concurrent calls can't deadlock each
        // other by locking the two in opposite sequence.
        $for_update = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT quantity_on_hand, name, unit FROM items WHERE id = :id' . $for_update
        );
        $stmt->execute(['id' => $item_id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Item not found.');
        }

        $variant_before = null;
        $variant_after  = null;
        $variant_value  = null;

        if ($item_variant_id !== null) {
            $vstmt = $pdo->prepare(
                'SELECT quantity_on_hand, variant_value FROM item_variants
                 WHERE id = :id AND item_id = :item_id' . $for_update
            );
            $vstmt->execute(['id' => $item_variant_id, 'item_id' => $item_id]);
            $vrow = $vstmt->fetch();

            if (!$vrow) {
                throw new RuntimeException('Variant not found for this item.');
            }

            $variant_value  = $vrow['variant_value'];
            $variant_before = (int)$vrow['quantity_on_hand'];
            $variant_after  = $variant_before + $quantity_change;

            if ($variant_after < 0) {
                throw new InsufficientStockException(
                    'This would take ' . $variant_value . ' stock below zero (currently ' . $variant_before . ').'
                );
            }
        }

        $before = (int)$row['quantity_on_hand'];
        $after  = $before + $quantity_change;

        if ($after < 0) {
            throw new InsufficientStockException(
                'This would take stock below zero (currently ' . $before . ').'
            );
        }

        $upd = $pdo->prepare('UPDATE items SET quantity_on_hand = :after WHERE id = :id');
        $upd->execute(['after' => $after, 'id' => $item_id]);

        if ($item_variant_id !== null) {
            $vupd = $pdo->prepare('UPDATE item_variants SET quantity_on_hand = :after WHERE id = :id');
            $vupd->execute(['after' => $variant_after, 'id' => $item_variant_id]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO stock_movements
                (item_id, item_variant_id, movement_type, quantity_change, quantity_before, quantity_after,
                 reference_type, reference_id, recorded_by, note)
             VALUES (:item_id, :variant_id, :type, :change, :before, :after, :ref_type, :ref_id, :by, :note)'
        );
        $ins->execute([
            'item_id'    => $item_id,
            'variant_id' => $item_variant_id,
            'type'       => $movement_type,
            'change'     => $quantity_change,
            'before'     => $before,
            'after'      => $after,
            'ref_type'   => $reference_type,
            'ref_id'     => $reference_id,
            'by'         => $recorded_by,
            'note'       => $note,
        ]);

        if ($own_transaction) {
            $pdo->commit();
        }

        // Notify requesters subscribed to this item/variant if it just became available
        if ($quantity_change > 0) {
            $became_available = false;
            if ($item_variant_id !== null) {
                if (($variant_before ?? 0) <= 0 && ($variant_after ?? 0) > 0) {
                    $became_available = true;
                }
            } else {
                if ($before <= 0 && $after > 0) {
                    $became_available = true;
                }
            }
            if ($became_available) {
                trigger_stock_available_notifications(
                    $pdo,
                    $item_id,
                    $variant_value,
                    $row['name']
                );
            }
        }

        return [
            'before'         => $before,
            'after'          => $after,
            'name'           => $row['name'],
            'unit'           => $row['unit'],
            'variant_before' => $variant_before,
            'variant_after'  => $variant_after,
            'variant_value'  => $variant_value,
        ];
    } catch (Exception $e) {
        if ($own_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Fires a stock alert notification IF this movement crossed a stock-status
 * threshold (in stock ↔ low stock ↔ out of stock). Call this only after
 * the movement's transaction has actually committed — it sends real
 * notifications with a side effect that a later rollback can't undo, so
 * firing it before commit could alert on a change that never happened.
 *
 * Deliberately not called from item creation (a newly catalogued item
 * starting below the stock alert threshold isn't a depletion event
 * worth alerting on) — only from movements that change existing stock.
 */
function maybe_alert_stock_threshold(
    int $item_id,
    int $before,
    int $after,
    string $name,
    string $unit,
    ?int $variant_before = null,
    ?int $variant_after = null,
    ?string $variant_value = null
): void {
    $concern = ['in-stock' => 0, 'low-stock' => 1, 'out-stock' => 2];

    $threshold = item_low_stock_threshold(get_db(), $item_id);

    $before_class = stock_status($before, $threshold)['class'];
    $after_class  = stock_status($after, $threshold)['class'];

    $variant_crossed = false;
    $v_before_class = null;
    $v_after_class  = null;
    if ($variant_before !== null && $variant_after !== null && $variant_value !== null && $variant_value !== '') {
        $v_before_class = stock_status($variant_before, $threshold)['class'];
        $v_after_class  = stock_status($variant_after, $threshold)['class'];
        if ($v_before_class !== $v_after_class) {
            $variant_crossed = true;
        }
    }

    if ($before_class === $after_class && !$variant_crossed) {
        return;
    }

    $link = BASE_URL . '/inventory/stock_ledger.php?item=' . $item_id;
    $pdo = get_db();
    $staff = $pdo->query("SELECT id FROM users WHERE role = 'inventory_staff' AND status = 'active'")->fetchAll();

    if ($before_class !== $after_class) {
        if ($concern[$after_class] > $concern[$before_class]) {
            $message = $after_class === 'out-stock'
                ? $name . ' is now OUT OF STOCK.'
                : $name . ' is now running LOW (' . $after . ' ' . $unit . ' left, reorder at ' . $threshold . ').';
        } elseif ($after_class === 'in-stock') {
            $message = $name . ' is back in stock (' . $after . ' ' . $unit . ').';
        } else {
            $message = null;
        }

        if ($message !== null) {
            $exists_stmt = $pdo->prepare(
                'SELECT COUNT(*) c FROM notifications WHERE user_id = :uid AND link = :link AND is_read = 0'
            );
            foreach ($staff as $s) {
                $exists_stmt->execute(['uid' => $s['id'], 'link' => $link]);
                if ((int)$exists_stmt->fetch()['c'] > 0) {
                    continue;
                }
                notify_user((int)$s['id'], $message, $link);
            }

            if ($after_class === 'out-stock') {
                require_once __DIR__ . '/sms.php';
                notify_role_sms('inventory_staff', "DuaRTE Alert: '{$name}' is now OUT OF STOCK. Reorder required.");
            }
        }
    }

    // If an individual variant crossed a threshold
    if ($variant_crossed && $v_after_class !== null && $v_before_class !== null) {
        $v_name = $name . ' (' . $variant_value . ')';
        if ($concern[$v_after_class] > $concern[$v_before_class]) {
            $v_message = $v_after_class === 'out-stock'
                ? $v_name . ' is now OUT OF STOCK.'
                : $v_name . ' is now running LOW (' . $variant_after . ' ' . $unit . ' left, reorder at ' . $threshold . ').';
        } elseif ($v_after_class === 'in-stock') {
            $v_message = $v_name . ' is back in stock (' . $variant_after . ' ' . $unit . ').';
        } else {
            $v_message = null;
        }

        if ($v_message !== null) {
            $v_check = $pdo->prepare(
                'SELECT COUNT(*) c FROM notifications WHERE user_id = :uid AND message = :msg AND is_read = 0'
            );
            foreach ($staff as $s) {
                $v_check->execute(['uid' => $s['id'], 'msg' => $v_message]);
                if ((int)$v_check->fetch()['c'] > 0) {
                    continue;
                }
                notify_user((int)$s['id'], $v_message, $link);
            }

            if ($v_after_class === 'out-stock') {
                require_once __DIR__ . '/sms.php';
                notify_role_sms('inventory_staff', "DuaRTE Alert: '{$v_name}' is now OUT OF STOCK. Reorder required.");
            }
        }
    }
}

/**
 * Marks any pending (unread) low-stock/out-of-stock bell notifications for
 * this item as read, if the item is no longer flagged. Call this after any
 * stock movement so that resolving the shortage — from any inventory staff
 * member, via Catalog Management, Stock In, an adjustment, a loan return,
 * etc. — automatically clears the alert for everyone instead of leaving a
 * stale notification sitting in the bell.
 */
function resolve_stock_alert_notifications(PDO $pdo, int $item_id): void
{
    $stmt = $pdo->prepare(
        'SELECT i.quantity_on_hand, c.low_stock_threshold FROM items i
         LEFT JOIN categories c ON c.id = i.category_id
         WHERE i.id = :id'
    );
    $stmt->execute(['id' => $item_id]);
    $row = $stmt->fetch();

    if (!$row) {
        return;
    }

    $threshold = category_low_stock_threshold(
        $row['low_stock_threshold'] !== null ? (int)$row['low_stock_threshold'] : null
    );

    if ((int)$row['quantity_on_hand'] <= $threshold) {
        return;
    }

    // Do not resolve if any variant is still at or below threshold
    $vstmt = $pdo->prepare(
        'SELECT 1 FROM item_variants WHERE item_id = :id AND quantity_on_hand <= :threshold LIMIT 1'
    );
    $vstmt->execute(['id' => $item_id, 'threshold' => $threshold]);
    if ($vstmt->fetch()) {
        return;
    }

    $link = BASE_URL . '/inventory/stock_ledger.php?item=' . $item_id;
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE link = :link AND is_read = 0')
        ->execute(['link' => $link]);
}

/**
 * Makes sure every currently flagged item (low stock or out of stock) has
 * a live, unread notification sitting in the bell for inventory staff —
 * not just at the moment it crosses the threshold (maybe_alert_stock_threshold
 * above), but any time this runs and the item is still flagged.
 *
 * Safe to call on every page load: for each staff member + item pair, it
 * only inserts if that person doesn't already have an unread notification
 * pointing at that item, so it won't spam duplicates while one is still
 * sitting unread. Once they read/clear it (or the item stops being
 * flagged), the next run will re-notify if it's still a problem.
 */
function sync_low_stock_notifications(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT i.id, i.name, i.unit, i.quantity_on_hand,
                COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ") AS low_stock_threshold
         FROM items i
         LEFT JOIN categories c ON c.id = i.category_id
         WHERE i.status = 'active'
           AND (
               i.quantity_on_hand <= COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ")
               OR EXISTS (
                   SELECT 1 FROM item_variants iv
                   WHERE iv.item_id = i.id
                     AND iv.quantity_on_hand <= COALESCE(c.low_stock_threshold, " . DEFAULT_STOCK_ALERT_THRESHOLD . ")
               )
           )"
    );
    $flagged = $stmt->fetchAll();

    if (!$flagged) {
        return;
    }

    $staff = $pdo->query("SELECT id FROM users WHERE role = 'inventory_staff' AND status = 'active'")->fetchAll();
    if (!$staff) {
        return;
    }

    $exists_stmt = $pdo->prepare(
        'SELECT COUNT(*) c FROM notifications WHERE user_id = :uid AND link = :link AND is_read = 0'
    );

    $v_low_stmt = $pdo->prepare(
        'SELECT variant_value, quantity_on_hand FROM item_variants
         WHERE item_id = :item_id AND quantity_on_hand <= :thresh
         ORDER BY quantity_on_hand ASC LIMIT 1'
    );

    foreach ($flagged as $it) {
        $link = BASE_URL . '/inventory/stock_ledger.php?item=' . $it['id'];

        $v_low_stmt->execute(['item_id' => $it['id'], 'thresh' => $it['low_stock_threshold']]);
        $low_v = $v_low_stmt->fetch();

        if ($low_v && (int)$it['quantity_on_hand'] > (int)$it['low_stock_threshold']) {
            $v_name = $it['name'] . ' (' . $low_v['variant_value'] . ')';
            $message = (int)$low_v['quantity_on_hand'] === 0
                ? $v_name . ' is OUT OF STOCK.'
                : $v_name . ' is running LOW (' . (int)$low_v['quantity_on_hand'] . ' ' . $it['unit']
                    . ' left, reorder at ' . (int)$it['low_stock_threshold'] . ').';
        } else {
            $message = (int)$it['quantity_on_hand'] === 0
                ? $it['name'] . ' is OUT OF STOCK.'
                : $it['name'] . ' is running LOW (' . (int)$it['quantity_on_hand'] . ' ' . $it['unit']
                    . ' left, reorder at ' . (int)$it['low_stock_threshold'] . ').';
        }

        foreach ($staff as $s) {
            $exists_stmt->execute(['uid' => $s['id'], 'link' => $link]);
            if ((int)$exists_stmt->fetch()['c'] > 0) {
                continue;
            }
            notify_user((int)$s['id'], $message, $link);
        }
    }
}
