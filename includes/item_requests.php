<?php
/**
 * DuaRTE — Item Availability Requests.
 * Personnel flag an item that's out of stock, or missing from the
 * catalog entirely, so it shows up on Inventory Staff's dashboard —
 * separate from the requisition/approval flow (see schema.sql).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notifications.php';

/**
 * Units a requester can pick on the "Request an Item" form. Matches
 * the vocabulary already used by items.unit in the catalog, plus
 * "box" which the catalog doesn't currently use but requesters do.
 */
function item_request_unit_options(): array
{
    return [
        'pc'    => 'Piece',
        'pair'  => 'Pair',
        'pack'  => 'Pack',
        'box'   => 'Box',
        'set'   => 'Set',
        'liter' => 'Liter',
        'can'   => 'Can',
    ];
}

/**
 * "Container" units bundle a variable number of individual pieces, so
 * the form asks for pieces-per-unit alongside them. A pair is always
 * 2 and liter/can aren't bundles of pieces, so they're excluded.
 */
function item_request_container_units(): array
{
    return ['box', 'pack', 'set'];
}

function item_request_unit_label(string $unit): string
{
    return item_request_unit_options()[$unit] ?? ucfirst($unit);
}

/** Pluralizes a unit label (irregular cases like "box" -> "boxes" handled explicitly). */
function item_request_unit_label_plural(string $unit): string
{
    $label = item_request_unit_label($unit);
    return $unit === 'box' ? 'Boxes' : $label . 's';
}

/**
 * item_requests has no `unit` column, and we're not adding one. For a
 * free-text request (item_id NULL — item isn't in the catalog at
 * all), there's nowhere else to record what unit the requester meant,
 * so we tuck a small machine-readable tag onto the front of the
 * `reason` text they already type, e.g.:
 *   "[[unit=set;pcs=4]] Need for the delivery truck brakes"
 * item_request_parse_reason() strips it back off before the reason is
 * ever shown to a person. A catalog-linked request (item_id set)
 * never needs this — its unit is read straight from items.unit
 * instead, since that column already exists.
 */
function item_request_encode_reason(string $reason, string $unit, ?int $pieces_per_unit): string
{
    if ($unit === 'pc' && !$pieces_per_unit) {
        // Default unit, nothing to tag — keep the reason untouched.
        return $reason;
    }
    $meta = 'unit=' . $unit;
    if ($pieces_per_unit) {
        $meta .= ';pcs=' . $pieces_per_unit;
    }
    return '[[' . $meta . ']] ' . $reason;
}

/**
 * Splits a stored `reason` back into its unit metadata (if any) and
 * the clean text the requester actually wrote. Always safe to call —
 * requests made before this feature existed, or catalog-linked ones,
 * simply have no tag and come back as unit 'pc'.
 */
function item_request_parse_reason(?string $stored): array
{
    $stored = $stored ?? '';
    if (preg_match('/^\[\[unit=([a-z]+)(?:;pcs=(\d+))?\]\]\s*(.*)$/s', $stored, $m)) {
        $unit = array_key_exists($m[1], item_request_unit_options()) ? $m[1] : 'pc';
        return [
            'unit'            => $unit,
            'pieces_per_unit' => isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null,
            'reason'          => $m[3],
        ];
    }
    return ['unit' => 'pc', 'pieces_per_unit' => null, 'reason' => $stored];
}

/**
 * Resolves the effective unit/pieces/clean-reason for a request row.
 * Pass $catalog_unit (items.unit, looked up via a JOIN on item_id —
 * see inventory/item_requests.php etc.) when the request is linked to
 * a catalog item; its unit always wins over anything in the tag.
 * Pass null for a free-text request and it's parsed out of `reason`.
 */
function item_request_resolve(array $row, ?string $catalog_unit): array
{
    if ($catalog_unit) {
        return ['unit' => $catalog_unit, 'pieces_per_unit' => null, 'reason' => $row['reason'] ?? ''];
    }
    return item_request_parse_reason($row['reason'] ?? '');
}

/** Total individual pieces implied by a resolved unit/quantity. */
function item_request_total_pieces(int $quantity, array $resolved): int
{
    $per = $resolved['pieces_per_unit'] ?? null;
    return $per ? $quantity * (int)$per : $quantity;
}

/**
 * Human-readable quantity for display, e.g. "5 Pieces", "2 Sets",
 * or "2 Sets (× 4 = 8 pcs)" when pieces-per-unit is known.
 */
function item_request_qty_display(int $quantity, array $resolved): string
{
    $unit = $resolved['unit'] ?? 'pc';
    $label = $quantity === 1 ? item_request_unit_label($unit) : item_request_unit_label_plural($unit);
    $out = $quantity . ' ' . $label;

    if (!empty($resolved['pieces_per_unit']) && in_array($unit, item_request_container_units(), true)) {
        $total = item_request_total_pieces($quantity, $resolved);
        $out .= " (\u{00D7} " . (int)$resolved['pieces_per_unit'] . ' = ' . $total . ' pcs)';
    }

    return $out;
}

/** Count of item requests still awaiting Inventory Staff action. */
function count_pending_item_requests(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) c FROM item_requests WHERE status = 'pending'");
    return (int)$stmt->fetch()['c'];
}

/** Badge CSS class for an item request's status. */
function item_request_status_class(string $status): string
{
    return match ($status) {
        'fulfilled' => 'active',
        'rejected'  => 'inactive',
        default     => 'role',
    };
}

/**
 * Marks an item request as fulfilled or rejected and notifies the
 * requester. Assumes the caller has already verified the acting user
 * is Inventory Staff and the request is still pending.
 */
function decide_item_request(PDO $pdo, int $request_id, string $decision, array $decided_by, ?string $note): void
{
    $stmt = $pdo->prepare(
        'UPDATE item_requests SET status = :status, decided_by = :by, decision_note = :note, decided_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $decision,
        'by'     => $decided_by['id'],
        'note'   => $note !== '' ? $note : null,
        'id'     => $request_id,
    ]);

    $req_stmt = $pdo->prepare('SELECT requester_id, item_name FROM item_requests WHERE id = :id');
    $req_stmt->execute(['id' => $request_id]);
    $req = $req_stmt->fetch();
    if (!$req) {
        return;
    }

    $msg = $decision === 'fulfilled'
        ? '"' . $req['item_name'] . '" is now available — you can request it from the catalog.'
        : 'Your item request for "' . $req['item_name'] . '" was closed by ' . $decided_by['full_name'] . '.';
    notify_user((int)$req['requester_id'], $msg, BASE_URL . '/catalog/browse.php');
}
