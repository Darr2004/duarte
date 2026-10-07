<?php
/**
 * DuaRTE — QR Code Asset Tracking.
 *
 * One `assets` row = one permanent QR tag, scanned repeatedly over its
 * life — distinct from requisitions.qr_token, which is per-TRANSACTION
 * and dies after a single scan at release. Two shapes of row, driven
 * by the item's borrow_mode (see category_borrow_mode() in
 * functions.php):
 *   - borrow_mode 'borrow'/'choice' (tools/equipment): one row per
 *     PHYSICAL UNIT, quantity always 1 — individual accountability,
 *     since who has it and when it's due matters.
 *   - borrow_mode 'consume' (bolts, gasoline, etc.): one row per
 *     received LOT/batch, quantity = how many are left in that lot —
 *     one tag on the box, not one per bolt. Shrinks via
 *     record_asset_consumption() until retired at 0.
 * See the `assets` table comment in database/schema.sql for the full
 * reasoning.
 *
 * When the item has catalog options (item_variants — e.g. several
 * Part # choices under one "Oil filter" listing), each asset also
 * carries item_variant_id so a tag points at ONE specific option, not
 * the item's blended aggregate — a C-110 filter and a D-306 filter are
 * different physical parts and must never be pooled under one tag or
 * offered interchangeably at release.
 *
 * record_asset_event() is the ONLY function that should ever write to
 * asset_events, the same way record_stock_movement() is the only
 * writer for stock_movements and log_audit_event() the only writer
 * for audit_logs.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

/** Thrown when a release requires the staff to pick/scan a specific
 *  tagged unit but none was provided (or the one provided is no longer
 *  valid) — caught separately from other release failures so the error
 *  message can point at exactly which item still needs a scan. */
class MissingAssetSelectionException extends RuntimeException {}

/** How long a scan-verification token stays usable before it expires
 *  unconsumed — long enough to finish scanning the rest of the line
 *  items and hit Confirm release, short enough that a token can't be
 *  saved and replayed against some other release much later. */
const ASSET_SCAN_TOKEN_TTL_MINUTES = 10;

/**
 * Mints a short-lived, single-use token proving asset $asset_id was
 * actually scanned (its QR decoded a matching tag) for requisition
 * line $requisition_item_id. Called ONLY from
 * inventory/asset_scan_verify.php, right after the browser reports a
 * successful decode — never from the release handler itself, since
 * the whole point is that the token has to come from a real scan
 * event, separate from (and earlier than) the release POST.
 */
function create_asset_scan_verification(PDO $pdo, int $requisition_item_id, int $asset_id, int $scanned_by): string
{
    do {
        $token = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM asset_scan_verifications WHERE verify_token = :t');
        $stmt->execute(['t' => $token]);
    } while ($stmt->fetch()['c'] > 0);

    $expires_at = date('Y-m-d H:i:s', time() + (ASSET_SCAN_TOKEN_TTL_MINUTES * 60));
    $ins = $pdo->prepare(
        'INSERT INTO asset_scan_verifications (requisition_item_id, asset_id, verify_token, scanned_by, expires_at)
         VALUES (:riid, :aid, :token, :by, :expires_at)'
    );
    $ins->execute([
        'riid'       => $requisition_item_id,
        'aid'        => $asset_id,
        'token'      => $token,
        'by'         => $scanned_by,
        'expires_at' => $expires_at,
    ]);

    return $token;
}

/**
 * Validates and consumes a scan-verification token at release time —
 * this is the actual enforcement point. Requires the token to (a)
 * exist, (b) belong to this exact requisition line AND this exact
 * asset (a token minted for a different line/unit doesn't transfer),
 * (c) not be expired, and (d) not already be spent. On success, marks
 * it consumed atomically in the same transaction as the release so it can never
 * be reused. Returns false rather than throwing — the caller (verify.php)
 * turns a false into the same MissingAssetSelectionException it already
 * uses for "nothing scanned yet", so the error message stays consistent
 * whether the staff never scanned at all or scanned then the token expired.
 */
function consume_asset_scan_verification(PDO $pdo, string $token, int $requisition_item_id, int $asset_id): bool
{
    if ($token === '') {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'UPDATE asset_scan_verifications
         SET consumed_at = :now_consumed
         WHERE verify_token = :token
           AND requisition_item_id = :riid
           AND asset_id = :aid
           AND consumed_at IS NULL
           AND expires_at > :now_check'
    );
    $stmt->execute([
        'now_consumed' => $now,
        'token'        => $token,
        'riid'         => $requisition_item_id,
        'aid'          => $asset_id,
        'now_check'    => $now,
    ]);

    return $stmt->rowCount() === 1;
}

/**
 * Purges expired and/or already consumed asset scan verifications older than $hours.
 * Keeps the asset_scan_verifications table lean and prevents unbounded growth.
 */
function purge_expired_asset_scan_verifications(PDO $pdo, int $hours = 24): int
{
    $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));
    $stmt = $pdo->prepare(
        'DELETE FROM asset_scan_verifications 
         WHERE (consumed_at IS NOT NULL AND consumed_at < :cutoff1)
            OR (expires_at < :cutoff2)'
    );
    $stmt->execute([
        'cutoff1' => $cutoff,
        'cutoff2' => $cutoff,
    ]);
    return $stmt->rowCount();
}


/** Generates a unique asset QR tag, retrying on the rare collision. */
function generate_unique_asset_tag(PDO $pdo): string
{
    do {
        $tag = 'AST-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM assets WHERE asset_tag = :t');
        $stmt->execute(['t' => $tag]);
    } while ($stmt->fetch()['c'] > 0);

    return $tag;
}

/**
 * Writes one row to asset_events and always updates assets.updated_at,
 * so "last activity" is cheap to show without scanning the log. Does
 * NOT change assets.status itself — callers update status (and any
 * other asset columns) in the same transaction, then call this to log
 * what happened, mirroring how record_stock_movement() and
 * log_audit_event() are called right after their own state change.
 *
 * @param string   $event_type 'registered'|'checked_out'|'checked_in'|
 *                              'transferred'|'damage_reported'|
 *                              'maintenance_completed'|'retired'|
 *                              'audit_confirmed'|'audit_missing'
 * @param array|null $actor    current_user()-shaped array, or null for
 *                              system-triggered events.
 */
function record_asset_event(
    PDO $pdo,
    int $asset_id,
    string $event_type,
    ?array $actor,
    ?string $note = null,
    ?string $reference_type = null,
    ?int $reference_id = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO asset_events (asset_id, event_type, actor_id, actor_name_snapshot, reference_type, reference_id, note)
         VALUES (:asset_id, :event_type, :actor_id, :actor_name, :ref_type, :ref_id, :note)'
    );
    $stmt->execute([
        'asset_id'   => $asset_id,
        'event_type' => $event_type,
        'actor_id'   => $actor['id'] ?? null,
        'actor_name' => $actor['full_name'] ?? 'System',
        'ref_type'   => $reference_type,
        'ref_id'     => $reference_id,
        'note'       => $note,
    ]);

    $pdo->prepare('UPDATE assets SET updated_at = NOW() WHERE id = :id')->execute(['id' => $asset_id]);
}

/** Human-readable label for an asset's current status. */
function asset_status_label(string $status): string
{
    return match ($status) {
        'available'          => 'Available',
        'checked_out'        => 'Checked out',
        'under_maintenance'  => 'Under maintenance',
        'missing'            => 'Missing',
        'retired'            => 'Retired',
        default              => ucwords(str_replace('_', ' ', $status)),
    };
}

/** Badge CSS class for an asset's current status — reuses the same badge
 *  classes already defined for stock/requisition status (components.css). */
function asset_status_class(string $status): string
{
    return match ($status) {
        'available'         => 'active',
        'checked_out'       => 'role',
        'under_maintenance' => 'low-stock',
        'missing', 'retired' => 'inactive',
        default             => 'role',
    };
}

/** Human-readable label for an asset_events.event_type value. */
function asset_event_label(string $event_type): string
{
    return match ($event_type) {
        'registered'             => 'Registered',
        'checked_out'            => 'Checked out',
        'checked_in'             => 'Checked in / returned',
        'transferred'            => 'Transferred',
        'damage_reported'        => 'Damage reported',
        'maintenance_completed'  => 'Maintenance completed',
        'retired'                => 'Retired',
        'audit_confirmed'        => 'Confirmed present (audit)',
        'audit_missing'          => 'Flagged missing (audit)',
        'consumed'                => 'Used from lot',
        default                  => ucwords(str_replace('_', ' ', $event_type)),
    };
}

/**
 * Every available (not checked out, not retired/missing) asset for a
 * given item, oldest-registered first — the pool verify.php picks from
 * when releasing a borrowable line, and asset_add.php checks against
 * to avoid confusion between units of the same item.
 *
 * @param int|null $variant_id When the item has catalog options
 *        (item_variants), pass the specific option's id to only return
 *        assets tagged for THAT option — a C-110 filter and a D-306
 *        filter are different physical parts and must never be offered
 *        interchangeably at release. Pass null for items with no
 *        options (the normal case).
 */
function get_available_assets_for_item(PDO $pdo, int $item_id, ?int $variant_id = null): array
{
    $sql = "SELECT * FROM assets WHERE item_id = :item_id AND status = 'available' AND assigned_truck_id IS NULL";
    $params = ['item_id' => $item_id];
    if ($variant_id !== null) {
        $sql .= ' AND item_variant_id = :variant_id';
        $params['variant_id'] = $variant_id;
    }
    $sql .= ' ORDER BY created_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Count of assets needing attention (missing or under maintenance) —
 *  drives the "Assets" nav badge for Inventory Staff/Admin. */
function count_assets_needing_attention(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) c FROM assets WHERE status IN ('missing', 'under_maintenance')"
    );
    return (int)$stmt->fetch()['c'];
}

/**
 * Total registered units and how many are currently available, for a
 * given item — used on the catalog management list and asset list to
 * show "3 of 5 available" alongside the aggregate quantity_on_hand.
 * @param int|null $variant_id Scope to one catalog option instead of
 *        the whole item — see get_available_assets_for_item().
 * @return array{total:int, available:int}
 */
function asset_counts_for_item(PDO $pdo, int $item_id, ?int $variant_id = null): array
{
    $sql = "SELECT COUNT(*) total, SUM(status = 'available') available
            FROM assets WHERE item_id = :item_id AND status != 'retired'";
    $params = ['item_id' => $item_id];
    if ($variant_id !== null) {
        $sql .= ' AND item_variant_id = :variant_id';
        $params['variant_id'] = $variant_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return ['total' => (int)($row['total'] ?? 0), 'available' => (int)($row['available'] ?? 0)];
}

/**
 * Marks an asset checked out to $holder_id, logs the event, and — if
 * this checkout is happening inside a caller's own transaction (e.g.
 * verify.php releasing a requisition) — participates in it rather than
 * opening a nested one. Does NOT touch quantity_on_hand or stock_movements;
 * those are a separate concern already handled by record_stock_movement()
 * at release time. This only tracks the specific unit's own status.
 */
function checkout_asset(
    PDO $pdo,
    int $asset_id,
    int $holder_id,
    ?array $actor,
    ?string $note = null,
    ?string $reference_type = null,
    ?int $reference_id = null
): void {
    // Atomically lock and transition only if the asset is currently available
    $upd = $pdo->prepare(
        "UPDATE assets SET status = 'checked_out', current_holder_id = :holder WHERE id = :id AND status = 'available'"
    );
    $upd->execute(['holder' => $holder_id, 'id' => $asset_id]);

    if ($upd->rowCount() === 0) {
        // Find current status to provide actionable diagnostic error
        $status_stmt = $pdo->prepare('SELECT status, asset_tag FROM assets WHERE id = :id');
        $status_stmt->execute(['id' => $asset_id]);
        $curr = $status_stmt->fetch();
        $tag_label = $curr ? $curr['asset_tag'] : "#$asset_id";
        $status_label = $curr ? $curr['status'] : 'unknown/deleted';
        throw new RuntimeException("Asset {$tag_label} is not available for checkout (current status: {$status_label}).");
    }

    record_asset_event($pdo, $asset_id, 'checked_out', $actor, $note, $reference_type, $reference_id);
}

/**
 * Checks an asset back in. $damaged flips it to under_maintenance
 * instead of available, and always logs 'checked_in' first (the
 * physical handback event) — a separate 'damage_reported' event is
 * only added if the condition note actually changed, so the history
 * doesn't imply damage was reported when nothing was said.
 */
function checkin_asset(
    PDO $pdo,
    int $asset_id,
    ?array $actor,
    bool $damaged = false,
    ?string $condition_note = null,
    ?string $reference_type = null,
    ?int $reference_id = null
): void {
    $new_status = $damaged ? 'under_maintenance' : 'available';
    $upd = $pdo->prepare(
        "UPDATE assets SET status = :status, current_holder_id = NULL, condition_note = :note WHERE id = :id"
    );
    $upd->execute(['status' => $new_status, 'note' => $condition_note, 'id' => $asset_id]);

    record_asset_event(
        $pdo, $asset_id, 'checked_in', $actor,
        $damaged ? 'Returned — flagged for maintenance.' : 'Returned in good condition.',
        $reference_type, $reference_id
    );

    if ($damaged) {
        record_asset_event($pdo, $asset_id, 'damage_reported', $actor, $condition_note, $reference_type, $reference_id);
    }
}

/**
 * Uses $amount units out of a consumable lot's asset row. Never used
 * for borrow_mode 'borrow'/'choice' assets — those are per-unit and
 * move through checkout_asset()/checkin_asset() instead. Decrements
 * quantity, logs a 'consumed' event, and retires the row once the lot
 * is used up (quantity reaches 0) — mirroring how the physical box is
 * empty and its tag no longer refers to anything on the shelf.
 *
 * Does NOT touch items.quantity_on_hand or stock_movements — same
 * separation of concerns as checkout_asset()/checkin_asset(): the
 * aggregate stock ledger is recorded by record_stock_movement() at the
 * point the consumption actually happens (stock-out/release), this
 * only tracks what's left in the specific tagged lot.
 *
 * @throws InvalidArgumentException if $amount exceeds what's left in
 *         the lot — callers should check first, this is a last-resort
 *         guard against a stale read racing another consumption.
 */
function record_asset_consumption(
    PDO $pdo,
    int $asset_id,
    int $amount,
    ?array $actor,
    ?string $note = null,
    ?string $reference_type = null,
    ?int $reference_id = null
): void {
    // Pessimistic row lock on the asset lot to prevent race conditions
    $for_update = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    $stmt = $pdo->prepare("SELECT quantity, status, asset_tag FROM assets WHERE id = :id" . $for_update);
    $stmt->execute(['id' => $asset_id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Asset lot not found.');
    }
    if ($row['status'] !== 'available') {
        throw new RuntimeException("Asset lot {$row['asset_tag']} is not available for consumption (current status: {$row['status']}).");
    }
    $remaining = (int)$row['quantity'];
    if ($amount > $remaining) {
        throw new InvalidArgumentException("Cannot use {$amount} from lot {$row['asset_tag']}: only {$remaining} remain(s).");
    }

    $new_qty = $remaining - $amount;
    $new_status = $new_qty === 0 ? 'retired' : 'available';
    $upd = $pdo->prepare("UPDATE assets SET quantity = :qty, status = :status WHERE id = :id AND quantity >= :amt AND status = 'available'");
    $upd->execute(['qty' => $new_qty, 'status' => $new_status, 'id' => $asset_id, 'amt' => $amount]);
    if ($upd->rowCount() === 0) {
        throw new RuntimeException("Concurrent conflict updating lot {$row['asset_tag']}. Please retry.");
    }

    $used_note = 'Used ' . $amount . ' from this lot' . ($new_qty === 0 ? ' — lot now empty.' : ' — ' . $new_qty . ' remaining.');
    record_asset_event(
        $pdo, $asset_id, 'consumed', $actor,
        $note ? $used_note . ' ' . $note : $used_note,
        $reference_type, $reference_id
    );
    if ($new_qty === 0) {
        record_asset_event($pdo, $asset_id, 'retired', $actor, 'Lot fully used.', $reference_type, $reference_id);
    }
}

/**
 * Returns all physical assets permanently assigned to a truck as its onboard equipment kit.
 *
 * @param PDO $pdo
 * @param int $truck_id
 * @return array
 */
function get_truck_onboard_assets(PDO $pdo, int $truck_id): array
{
    $stmt = $pdo->prepare("
        SELECT a.*,
               i.name AS item_name,
               i.item_code,
               i.borrow_mode,
               iv.variant_value
        FROM assets a
        JOIN items i ON i.id = a.item_id
        LEFT JOIN item_variants iv ON iv.id = a.item_variant_id
        WHERE a.assigned_truck_id = :tid
        ORDER BY i.name ASC, a.created_at ASC
    ");
    $stmt->execute(['tid' => $truck_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Permanently assigns an asset to a truck's onboard kit (Kit ng Sasakyan).
 * This tool stays with the vehicle and does NOT expire or trigger loan overdue alerts.
 */
function assign_asset_to_truck(
    PDO $pdo,
    int $asset_id,
    int $truck_id,
    ?array $actor = null,
    ?string $note = null
): void {
    $t_stmt = $pdo->prepare("SELECT plate_number, model FROM trucks WHERE id = :id");
    $t_stmt->execute(['id' => $truck_id]);
    $truck = $t_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$truck) {
        throw new InvalidArgumentException("Truck #$truck_id not found.");
    }

    $a_stmt = $pdo->prepare("
        SELECT a.*, i.name AS item_name 
        FROM assets a 
        JOIN items i ON i.id = a.item_id 
        WHERE a.id = :id
    ");
    $a_stmt->execute(['id' => $asset_id]);
    $asset = $a_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        throw new InvalidArgumentException("Asset #$asset_id not found.");
    }
    if ($asset['status'] === 'retired' || $asset['status'] === 'checked_out') {
        throw new RuntimeException("Asset {$asset['asset_tag']} cannot be assigned while {$asset['status']}.");
    }

    $loc = "Onboard: " . $truck['plate_number'];
    $upd = $pdo->prepare("
        UPDATE assets 
        SET assigned_truck_id = :tid,
            assigned_to_truck_at = NOW(),
            location_note = :loc,
            status = 'available'
        WHERE id = :id
    ");
    $upd->execute([
        'tid' => $truck_id,
        'loc' => $loc,
        'id'  => $asset_id,
    ]);

    $audit_note = "Assigned to {$truck['plate_number']} ({$truck['model']}) as permanent onboard kit.";
    if ($note) {
        $audit_note .= " " . $note;
    }

    record_asset_event(
        $pdo,
        $asset_id,
        'assigned_to_truck',
        $actor,
        $audit_note,
        'truck',
        $truck_id
    );
}

/**
 * Unassigns an asset from a truck back to warehouse stock.
 */
function unassign_asset_from_truck(
    PDO $pdo,
    int $asset_id,
    ?array $actor = null,
    ?string $note = null
): void {
    $a_stmt = $pdo->prepare("
        SELECT a.*, t.plate_number 
        FROM assets a 
        LEFT JOIN trucks t ON t.id = a.assigned_truck_id 
        WHERE a.id = :id
    ");
    $a_stmt->execute(['id' => $asset_id]);
    $asset = $a_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        throw new InvalidArgumentException("Asset #$asset_id not found.");
    }

    $old_plate = $asset['plate_number'] ?? 'truck';

    $upd = $pdo->prepare("
        UPDATE assets 
        SET assigned_truck_id = NULL,
            assigned_to_truck_at = NULL,
            location_note = 'Warehouse Tool Room'
        WHERE id = :id
    ");
    $upd->execute(['id' => $asset_id]);

    $audit_note = "Unassigned from {$old_plate}. Returned to warehouse stock.";
    if ($note) {
        $audit_note .= " " . $note;
    }

    record_asset_event(
        $pdo,
        $asset_id,
        'unassigned_from_truck',
        $actor,
        $audit_note
    );
}

