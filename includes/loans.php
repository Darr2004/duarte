<?php
/**
 * DuaRTE — Tool loan status + overdue monitoring.
 */

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/audit.php';

// How far back a late return still counts against a borrower's
// reliability score (includes/priority.php). Without a window, one
// late return from years ago weighs exactly the same as one from last
// week, forever — a borrower can never actually live it down. Only
// affects the RELIABILITY criterion's score; the full loan history is
// still visible everywhere else (Tool Loans list, reports, audit log).
const RELIABILITY_LOOKBACK_DAYS = 180;

/** 'returned' | 'overdue' | 'extension_pending' | 'borrowed' */
function loan_status(string $due_date, ?string $returned_at, string $extension_status = 'none'): string
{
    if ($returned_at !== null) {
        return 'returned';
    }
    if ($extension_status === 'pending') {
        return 'extension_pending';
    }
    return $due_date < date('Y-m-d') ? 'overdue' : 'borrowed';
}

/**
 * Whether this user currently has any tool loan that's overdue and not
 * yet returned — used to block new requisitions (borrow AND consume)
 * until they return what's already overdue, instead of letting
 * overdue items pile up while the borrower keeps submitting requests.
 * Exempts loans with an extension currently pending review.
 */
function user_has_overdue_loans(PDO $pdo, int $user_id): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM tool_loans 
         WHERE borrower_id = :uid 
           AND returned_at IS NULL 
           AND due_date < CURDATE()
           AND extension_status != 'pending'"
    );
    $stmt->execute(['uid' => $user_id]);
    return (int)$stmt->fetch()['c'] > 0;
}

/**
 * Grouped count of RECENT late returns per borrower (returned, but
 * after their due date, within the last RELIABILITY_LOOKBACK_DAYS
 * days) — cheap accountability signal for Inventory Staff/Admin
 * without a schema change. One query for everyone, not one per row.
 * Windowed so an old, since-corrected pattern doesn't permanently cap
 * a borrower's reliability score — only recent behavior counts toward
 * the live score. Older late returns still exist in the data and
 * still show up in the Tool Loans history/reports; they just stop
 * feeding the score after the window passes.
 * @return array<int, int> user_id => recent late return count
 */
function get_late_return_counts(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT borrower_id, COUNT(*) c FROM tool_loans
         WHERE returned_at IS NOT NULL AND DATE(returned_at) > due_date
           AND returned_at >= DATE_SUB(NOW(), INTERVAL " . RELIABILITY_LOOKBACK_DAYS . " DAY)
         GROUP BY borrower_id"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['borrower_id']] = (int)$r['c'];
    }
    return $out;
}

/**
 * Grouped count of loans per borrower that are *currently* overdue and
 * still not returned — distinct from get_late_return_counts(), which
 * only ever sees a late return once it's finally handed back. A tool
 * that's still missing stays completely outside that count for as
 * long as it's out, which is backwards: still-missing is a worse
 * accountability signal than late-but-returned, not an invisible one.
 * Used by priority.php to weight "still missing" harder than "returned
 * late" in the reliability score.
 * @return array<int, int> user_id => count of currently-overdue, unreturned loans
 */
function get_missing_loan_counts(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT borrower_id, COUNT(*) c FROM tool_loans
         WHERE returned_at IS NULL AND due_date < CURDATE()
         GROUP BY borrower_id"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['borrower_id']] = (int)$r['c'];
    }
    return $out;
}

/**
 * Creates the tool_loans row for a manual/direct checkout (see
 * inventory/asset_view.php's "Check out to" action) — an asset handed
 * out straight from its own page, outside the requisition flow.
 * requisition_id/requisition_item_id are left NULL so this loan is
 * clearly not tied to any request, but it otherwise gets the exact
 * same due-date + overdue tracking as a requisition-released loan:
 * shows up in Tool Loans / My Borrowed Tools, gets overdue alerts,
 * counts toward late-return history, and blocks new borrow requests
 * the same way — a direct handout carries the same accountability as
 * a paperwork one instead of quietly having none.
 */
function record_direct_checkout_loan(PDO $pdo, array $asset, int $holder_id, int $days): int
{
    $due_date = add_business_days(date('Y-m-d'), $days);
    $stmt = $pdo->prepare(
        'INSERT INTO tool_loans (requisition_id, requisition_item_id, item_id, asset_id, borrower_id, quantity, due_date)
         VALUES (NULL, NULL, :iid, :asset_id, :borrower, :qty, :due_date)'
    );
    $stmt->execute([
        'iid'      => $asset['item_id'],
        'asset_id' => $asset['id'],
        'borrower' => $holder_id,
        'qty'      => $asset['quantity'] ?? 1,
        'due_date' => $due_date,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Closes out one tool_loans row: marks it returned and restores stock.
 * A loan from a requisition release deducted aggregate stock at that
 * time; a direct/manual checkout (requisition_id NULL) now deducts it
 * too, at checkout time (see asset_view.php's 'checkout' action) — so
 * both kinds of loan restore stock the same way on return. Only a loan
 * with neither a requisition nor an asset behind it (shouldn't
 * normally occur) skips the restore, since there's nothing to resolve
 * a movement against. Logs the tool_return audit event either way.
 *
 * Does NOT check the physical asset back in — callers do that
 * separately via checkin_asset(), since they differ on whether a
 * damage/condition note comes with the return.
 *
 * @return array{item_id:int, borrower_id:int, name:string, unit:string,
 *               stock_restored:bool, before:?int, after:?int}
 * @throws Exception on failure (caller rolls back)
 */
function return_tool_loan(PDO $pdo, array $loan, array $user): array
{
    $loan_id = (int)$loan['id'];

    $upd = $pdo->prepare(
        'UPDATE tool_loans SET returned_at = CURRENT_TIMESTAMP, returned_by = :by WHERE id = :id AND returned_at IS NULL'
    );
    $upd->execute(['by' => $user['id'], 'id' => $loan_id]);

    if ($upd->rowCount() === 0) {
        throw new Exception('Tool loan #' . $loan_id . ' has already been returned or does not exist.');
    }

    $stock_restored = false;
    $before = null;
    $after = null;

    if ($loan['requisition_id'] !== null) {
        // Came from a requisition release, which deducted aggregate stock
        // at that time — restore it now, variant-aware, mirroring how
        // inventory/verify.php resolved the variant on release.
        $item_variant_id = null;
        if (!empty($loan['requisition_item_id'])) {
            $ri_stmt = $pdo->prepare('SELECT variant_selected FROM requisition_items WHERE id = :id');
            $ri_stmt->execute(['id' => $loan['requisition_item_id']]);
            $ri = $ri_stmt->fetch();
            if ($ri && !empty($ri['variant_selected'])) {
                $v = find_item_variant($pdo, (int)$loan['item_id'], $ri['variant_selected']);
                // If the option was renamed/removed since borrowing, fall
                // back to the aggregate total rather than blocking the return.
                $item_variant_id = $v ? $v['id'] : null;
            }
        }

        $result = record_stock_movement(
            $pdo, (int)$loan['item_id'], 'return', (int)$loan['quantity'],
            'tool_loan', $loan_id, $user['id'], 'Tool returned', $item_variant_id
        );
        $stock_restored = true;
        $before = $result['before'];
        $after = $result['after'];
        $name = $result['name'];
        $unit = $result['unit'] ?? 'pc';
        $variant_before = $result['variant_before'] ?? null;
        $variant_after  = $result['variant_after'] ?? null;
        $variant_value  = $result['variant_value'] ?? null;
    } elseif (!empty($loan['asset_id'])) {
        // Direct/manual checkout (record_direct_checkout_loan()) — this
        // deducted aggregate stock at checkout time (see asset_view.php's
        // 'checkout' action), so the return restores it the same way a
        // requisition-released loan does. There's no requisition_items
        // row to read a variant from here, so resolve it straight from
        // the asset's own item_variant_id instead.
        $a_stmt = $pdo->prepare('SELECT item_variant_id FROM assets WHERE id = :id');
        $a_stmt->execute(['id' => $loan['asset_id']]);
        $arow = $a_stmt->fetch();
        $item_variant_id = ($arow && $arow['item_variant_id'] !== null) ? (int)$arow['item_variant_id'] : null;

        $result = record_stock_movement(
            $pdo, (int)$loan['item_id'], 'return', (int)$loan['quantity'],
            'direct_checkout', (int)$loan['asset_id'], $user['id'], 'Tool returned (direct checkout)', $item_variant_id
        );
        $stock_restored = true;
        $before = $result['before'];
        $after = $result['after'];
        $name = $result['name'];
        $unit = $result['unit'];
        $variant_before = $result['variant_before'] ?? null;
        $variant_after  = $result['variant_after'] ?? null;
        $variant_value  = $result['variant_value'] ?? null;
    } else {
        // No requisition and no asset behind this loan — nothing to
        // resolve a stock movement against. Shouldn't normally happen
        // (every loan comes from one path or the other), kept only as
        // a safe fallback so a malformed row can't fatal the check-in.
        $item_stmt = $pdo->prepare('SELECT name, unit FROM items WHERE id = :id');
        $item_stmt->execute(['id' => $loan['item_id']]);
        $item = $item_stmt->fetch();
        $name = $item['name'] ?? 'item';
        $unit = $item['unit'] ?? 'pc';
        $variant_before = null;
        $variant_after  = null;
        $variant_value  = null;
    }

    if ($loan['requisition_id'] !== null) {
        // Check if all loans for this requisition are now returned
        $rem_stmt = $pdo->prepare('SELECT COUNT(*) FROM tool_loans WHERE requisition_id = :rid AND returned_at IS NULL');
        $rem_stmt->execute(['rid' => $loan['requisition_id']]);
        if ((int)$rem_stmt->fetchColumn() === 0) {
            // Find truck_id for this requisition
            $r_stmt = $pdo->prepare('SELECT truck_id FROM requisitions WHERE id = :rid');
            $r_stmt->execute(['rid' => $loan['requisition_id']]);
            $req_truck_id = $r_stmt->fetchColumn();

            if ($req_truck_id) {
                // Check if truck has any other active released requisitions with unreturned tools
                $other_stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM tool_loans tl
                    JOIN requisitions r ON r.id = tl.requisition_id
                    WHERE r.truck_id = :tid AND tl.returned_at IS NULL
                ");
                $other_stmt->execute(['tid' => $req_truck_id]);
                if ((int)$other_stmt->fetchColumn() === 0) {
                    // No other active dispatches for this truck -> return to available if on_trip
                    $pdo->prepare("UPDATE trucks SET status = 'available' WHERE id = :tid AND status = 'on_trip'")
                        ->execute(['tid' => $req_truck_id]);
                }
            }
        }
    }

    $userName = is_array($user) ? ($user['full_name'] ?? $user['name'] ?? ('Staff #' . ($user['id'] ?? ''))) : 'Staff';
    log_audit_event($pdo, $user, 'tool_return', 'tool_loan', $loan_id,
        $userName . ' checked in returned "' . $name . '" (qty ' . $loan['quantity'] . ') from loan #' . $loan_id . '.');

    return [
        'item_id'        => (int)$loan['item_id'],
        'borrower_id'    => (int)($loan['borrower_id'] ?? $loan['user_id'] ?? 0),
        'name'           => $name,
        'unit'           => $unit,
        'stock_restored' => $stock_restored,
        'before'         => $before,
        'after'          => $after,
        'variant_before' => $variant_before,
        'variant_after'  => $variant_after,
        'variant_value'  => $variant_value,
    ];
}

function loan_status_class(string $status): string
{
    return match ($status) {
        'returned'           => 'active',
        'overdue'            => 'inactive',
        'extension_pending'  => 'role',
        default              => 'role',
    };
}

function count_overdue_loans(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) c FROM tool_loans WHERE returned_at IS NULL AND due_date < CURDATE() AND extension_status != 'pending'"
    );
    return (int)$stmt->fetch()['c'];
}

function count_pending_loan_extensions(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) c FROM tool_loans WHERE returned_at IS NULL AND extension_status = 'pending'");
    return (int)$stmt->fetch()['c'];
}

/**
 * Finds loans that are overdue and either haven't been alerted on yet or
 * weren't alerted in the last 3 days, notifies the borrower and every
 * Inventory Staff/Admin, and stamps them so the same loan doesn't alert
 * again until the next window. Repeats every 3 days for as long as the
 * loan stays unreturned, instead of alerting just once and going quiet.
 * Exempts loans with pending extensions so drivers on trips are not spammed.
 *
 * @return int number of overdue loans re-alerted on this run
 */
function check_overdue_loans(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT tl.*, i.name AS item_name, u.full_name AS borrower_name
         FROM tool_loans tl
         JOIN items i ON i.id = tl.item_id
         JOIN users u ON u.id = tl.borrower_id
         WHERE tl.returned_at IS NULL
           AND tl.due_date < CURDATE()
           AND tl.extension_status != 'pending'
           AND (tl.overdue_notified_at IS NULL OR tl.overdue_notified_at < DATE_SUB(NOW(), INTERVAL 3 DAY))"
    );
    $newly_overdue = $stmt->fetchAll();

    foreach ($newly_overdue as $loan) {
        if ($loan['requisition_id'] !== null) {
            $link = BASE_URL . '/requisition/view.php?id=' . $loan['requisition_id'];
        } elseif (!empty($loan['asset_id'])) {
            $link = BASE_URL . '/inventory/asset_view.php?id=' . $loan['asset_id'];
        } else {
            $link = BASE_URL . '/inventory/loans.php';
        }

        notify_user(
            (int)$loan['borrower_id'],
            'Your borrowed ' . $loan['item_name'] . ' (qty ' . (int)$loan['quantity'] . ') was due back on '
                . $loan['due_date'] . ' and is now overdue. Please return it as soon as possible.',
            $link
        );

        require_once __DIR__ . '/sms.php';
        notify_user_sms(
            (int)$loan['borrower_id'],
            'DuaRTE Alert: Your borrowed ' . $loan['item_name'] . ' was due on ' . $loan['due_date'] . ' and is now OVERDUE. Please return it immediately.'
        );

        notify_role(
            'inventory_staff',
            $loan['borrower_name'] . ' has an overdue tool: ' . $loan['item_name'] . ' (due ' . $loan['due_date'] . ').',
            $link
        );

        $upd = $pdo->prepare('UPDATE tool_loans SET overdue_notified_at = NOW() WHERE id = :id');
        $upd->execute(['id' => $loan['id']]);
    }

    return count($newly_overdue);
}

/**
 * Request an extension for a single tool loan.
 */
function request_tool_loan_extension(PDO $pdo, int $loan_id, int $user_id, int $days, string $reason): array
{
    $days = max(1, min(30, $days));
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('Please provide a reason for the extension.');
    }

    $stmt = $pdo->prepare("
        SELECT tl.*, i.name AS item_name, u.full_name AS borrower_name,
               r.truck_plate_snapshot
        FROM tool_loans tl
        JOIN items i ON i.id = tl.item_id
        JOIN users u ON u.id = tl.borrower_id
        LEFT JOIN requisitions r ON r.id = tl.requisition_id
        WHERE tl.id = :id
    ");
    $stmt->execute(['id' => $loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) {
        throw new RuntimeException("Loan #$loan_id not found.");
    }
    if ((int)$loan['borrower_id'] !== $user_id) {
        throw new RuntimeException("You can only request extensions for your own borrowed tools.");
    }
    if ($loan['returned_at'] !== null) {
        throw new RuntimeException("This tool has already been returned.");
    }

    $upd = $pdo->prepare("
        UPDATE tool_loans 
        SET extension_days = :days,
            extension_reason = :reason,
            extension_status = 'pending',
            extension_requested_at = NOW(),
            extension_decided_at = NULL,
            extension_decided_by = NULL,
            extension_decision_note = NULL
        WHERE id = :id
    ");
    $upd->execute([
        'days'   => $days,
        'reason' => $reason,
        'id'     => $loan_id,
    ]);

    log_audit_event(
        $pdo,
        ['id' => $user_id, 'full_name' => $loan['borrower_name'], 'role' => 'driver_helper'],
        'tool_loan_extension_request',
        'tool_loan',
        $loan_id,
        "{$loan['borrower_name']} requested +{$days} days extension for \"{$loan['item_name']}\" (Loan #$loan_id). Reason: $reason"
    );

    $truck_part = !empty($loan['truck_plate_snapshot']) ? " (Truck {$loan['truck_plate_snapshot']})" : "";
    notify_role(
        'inventory_staff',
        "Extension requested by {$loan['borrower_name']} for \"{$loan['item_name']}\"{$truck_part} (+{$days} days). Reason: $reason",
        BASE_URL . '/inventory/loans.php?tab=extensions'
    );

    return [
        'id'        => $loan_id,
        'item_name' => $loan['item_name'],
        'days'      => $days,
        'reason'    => $reason,
    ];
}

/**
 * Request an extension for all unreturned tools in a requisition (e.g. all tools on a truck trip).
 */
function request_trip_tools_extension(PDO $pdo, int $requisition_id, int $user_id, int $days, string $reason): array
{
    $stmt = $pdo->prepare("
        SELECT id FROM tool_loans 
        WHERE requisition_id = :rid 
          AND borrower_id = :uid 
          AND returned_at IS NULL
    ");
    $stmt->execute(['rid' => $requisition_id, 'uid' => $user_id]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ids)) {
        throw new RuntimeException("No active borrowed tools found for this requisition.");
    }

    $results = [];
    foreach ($ids as $lid) {
        $results[] = request_tool_loan_extension($pdo, (int)$lid, $user_id, $days, $reason);
    }
    return $results;
}

/**
 * Decision on a loan extension by Inventory Staff or Admin.
 */
function decide_tool_loan_extension(PDO $pdo, int $loan_id, array $staff_user, bool $approve, ?string $note = null): array
{
    $note = trim($note ?? '');

    $stmt = $pdo->prepare("
        SELECT tl.*, i.name AS item_name, u.full_name AS borrower_name,
               r.truck_plate_snapshot
        FROM tool_loans tl
        JOIN items i ON i.id = tl.item_id
        JOIN users u ON u.id = tl.borrower_id
        LEFT JOIN requisitions r ON r.id = tl.requisition_id
        WHERE tl.id = :id
    ");
    $stmt->execute(['id' => $loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) {
        throw new RuntimeException("Loan #$loan_id not found.");
    }
    if ($loan['extension_status'] !== 'pending') {
        throw new RuntimeException("Loan #$loan_id does not have a pending extension request.");
    }

    $days = (int)($loan['extension_days'] ?? 1);
    $old_due = $loan['due_date'];
    $new_due = $old_due;

    if ($approve) {
        $base_date = ($old_due < date('Y-m-d')) ? date('Y-m-d') : $old_due;
        $new_due = add_business_days($base_date, $days);

        $upd = $pdo->prepare("
            UPDATE tool_loans 
            SET due_date = :new_due,
                extension_status = 'approved',
                extension_decided_at = NOW(),
                extension_decided_by = :staff_id,
                extension_decision_note = :note,
                overdue_notified_at = NULL
            WHERE id = :id
        ");
        $upd->execute([
            'new_due'  => $new_due,
            'staff_id' => $staff_user['id'],
            'note'     => $note ?: null,
            'id'       => $loan_id,
        ]);

        $audit_desc = "{$staff_user['full_name']} approved +{$days} days extension for \"{$loan['item_name']}\" (Loan #$loan_id) borrowed by {$loan['borrower_name']}. New due date: $new_due.";
        if ($note) $audit_desc .= " Note: $note";

        log_audit_event($pdo, $staff_user, 'tool_loan_extension_approve', 'tool_loan', $loan_id, $audit_desc);

        notify_user(
            (int)$loan['borrower_id'],
            "Extension APPROVED: Your loan for \"{$loan['item_name']}\" has been extended until " . date('M j, Y', strtotime($new_due)) . ". Ingat sa byahe!",
            BASE_URL . '/requisition/my_loans.php'
        );

        require_once __DIR__ . '/sms.php';
        notify_user_sms(
            (int)$loan['borrower_id'],
            "DuaRTE Alert: Your loan extension for {$loan['item_name']} was APPROVED until " . date('M j, Y', strtotime($new_due)) . ". Safe travels!"
        );
    } else {
        $upd = $pdo->prepare("
            UPDATE tool_loans 
            SET extension_status = 'declined',
                extension_decided_at = NOW(),
                extension_decided_by = :staff_id,
                extension_decision_note = :note
            WHERE id = :id
        ");
        $upd->execute([
            'staff_id' => $staff_user['id'],
            'note'     => $note ?: null,
            'id'       => $loan_id,
        ]);

        $audit_desc = "{$staff_user['full_name']} declined extension for \"{$loan['item_name']}\" (Loan #$loan_id) borrowed by {$loan['borrower_name']}.";
        if ($note) $audit_desc .= " Reason: $note";

        log_audit_event($pdo, $staff_user, 'tool_loan_extension_decline', 'tool_loan', $loan_id, $audit_desc);

        $decline_msg = "Your loan extension request for \"{$loan['item_name']}\" was declined by Inventory Staff";
        if ($note) $decline_msg .= " (Reason: $note)";
        $decline_msg .= ". Please return the tool as soon as possible.";

        notify_user((int)$loan['borrower_id'], $decline_msg, BASE_URL . '/requisition/my_loans.php');
    }

    return [
        'id'        => $loan_id,
        'approved'  => $approve,
        'old_due'   => $old_due,
        'new_due'   => $new_due,
        'item_name' => $loan['item_name'],
    ];
}
