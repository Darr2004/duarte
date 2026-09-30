<?php
/**
 * DuaRTE — General helper functions.
 */

/**
 * Null-safe HTML escaping helper for PHP 8.1+.
 * Prevents deprecation warnings when rendering nullable database values.
 */
function h(mixed $val): string
{
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Fallback low-stock threshold for items that have no category (so
 * there's nowhere to read a threshold from). Every category normally
 * carries its own low_stock_threshold, editable by inventory staff
 * from Inventory > Categories — see category_low_stock_threshold().
 */
const DEFAULT_STOCK_ALERT_THRESHOLD = 5;

/**
 * Common units of measurement offered on the item add/edit "Measurement"
 * select, keyed by the value actually stored in items.unit. Covers every
 * unit already used by the seeded catalog (pc, pair, pack, set, liter,
 * can) plus a few obvious others; anything not on this list is still
 * supported via the "Other (specify)" option in the form.
 * @return array<string,string>
 */
function item_unit_options(): array
{
    return [
        'pc'    => 'Piece (pc)',
        'pair'  => 'Pair',
        'set'   => 'Set',
        'pack'  => 'Pack',
        'box'   => 'Box',
        'roll'  => 'Roll',
        'can'   => 'Can',
        'liter' => 'Liter',
        'kg'    => 'Kilogram (kg)',
        'meter' => 'Meter',
        'unit'  => 'Generic unit',
    ];
}

/**
 * Returns ['label' => ..., 'class' => ...] describing stock status.
 * $threshold is the low-stock cutoff to flag against — pass the
 * item's category threshold (see category_low_stock_threshold())
 * rather than relying on the DEFAULT_STOCK_ALERT_THRESHOLD default,
 * since that default only applies to items with no category.
 */
function stock_status(int $quantity, int $threshold = DEFAULT_STOCK_ALERT_THRESHOLD): array
{
    if ($quantity === 0) {
        return ['label' => 'Out of stock', 'class' => 'out-stock'];
    }
    if ($quantity <= $threshold) {
        return ['label' => 'Low stock', 'class' => 'low-stock'];
    }
    return ['label' => 'In stock', 'class' => 'in-stock'];
}

/**
 * The low-stock threshold that applies to a given item, based on the
 * threshold inventory staff set for its category (Inventory >
 * Categories). Items with no category — or whose category row is
 * somehow missing a value — fall back to DEFAULT_STOCK_ALERT_THRESHOLD.
 *
 * Accepts either a raw value already fetched from
 * categories.low_stock_threshold (int, or null if the item has no
 * category / the column wasn't joined), so callers that already have
 * the item row (with a joined category threshold column) don't need
 * a second query.
 */
function category_low_stock_threshold(?int $category_threshold): int
{
    return $category_threshold ?? DEFAULT_STOCK_ALERT_THRESHOLD;
}

/**
 * Looks up the low-stock threshold for one item by id, via its
 * category. Use category_low_stock_threshold() instead when the
 * item's row (with categories.low_stock_threshold already joined) is
 * on hand, to avoid an extra query per item.
 */
function item_low_stock_threshold(PDO $pdo, int $item_id): int
{
    $stmt = $pdo->prepare(
        'SELECT c.low_stock_threshold FROM items i
         LEFT JOIN categories c ON c.id = i.category_id
         WHERE i.id = :id'
    );
    $stmt->execute(['id' => $item_id]);
    $threshold = $stmt->fetchColumn();
    return $threshold !== false ? category_low_stock_threshold(
        $threshold !== null ? (int)$threshold : null
    ) : DEFAULT_STOCK_ALERT_THRESHOLD;
}

/**
 * Cosmetic-only cleanup for names typed as "Last,First" with no space
 * after the comma (still reads fine, just cramped) — adds the space
 * back for display without ever touching the stored value.
 */
function display_name(string $name): string
{
    return preg_replace('/,(?=\S)/', ', ', $name);
}

/**
 * Human-readable label for a role stored as a snake_case DB value
 * (e.g. 'driver_helper' -> 'Personnel'). Used anywhere a role is
 * shown to a person, so the UI never leaks raw enum values.
 */
function role_label(string $role): string
{
    return match ($role) {
        'admin'             => 'Admin',
        'inventory_staff'   => 'Inventory Staff',
        'driver_helper'     => 'Personnel',
        'field_supervisor'  => 'Field Supervisor',
        default             => ucwords(str_replace('_', ' ', $role)),
    };
}

/**
 * Human-readable label for a Personnel account's specific position
 * (e.g. 'office_staff' -> 'Office Staff'). Only meaningful when
 * role === 'driver_helper'; returns '' for null/unset positions.
 */
function position_label(?string $position): string
{
    return match ($position) {
        'driver'       => 'Driver',
        'helper'       => 'Helper',
        'mechanic'     => 'Mechanic',
        'electrician'  => 'Electrician',
        'office_staff' => 'Office Staff',
        default        => '',
    };
}

/**
 * Combined role + position label for display, e.g. 'Personnel — Driver'.
 * For roles other than 'driver_helper', or when no position is set,
 * this is identical to role_label($role).
 */
function role_display(string $role, ?string $position = null): string
{
    $label = role_label($role);
    if ($role === 'driver_helper' && $position) {
        $label .= ' — ' . position_label($position);
    }
    return $label;
}

/** Count of requisitions currently awaiting a Field Supervisor's decision. */
function count_pending_requisitions(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) c FROM requisitions WHERE status = 'pending'");
    return (int)$stmt->fetch()['c'];
}

/** Count of approved requisitions waiting to be verified/released by Inventory Staff. */
function count_awaiting_release(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) c FROM requisitions WHERE status = 'approved'");
    return (int)$stmt->fetch()['c'];
}

/**
 * True when "now" (Asia/Manila, per date_default_timezone_set in config.php)
 * falls inside the office-hours window that new requisitions may be
 * submitted in.
 */
function is_within_office_hours(): bool
{
    $now = date('H:i');
    return $now >= OFFICE_HOURS_OPEN && $now < OFFICE_HOURS_CLOSE;
}

/** Human-readable explanation shown when someone tries to submit outside office hours. */
function office_hours_message(): string
{
    return 'Requisitions can only be submitted during office hours, '
        . format_hour_12(OFFICE_HOURS_OPEN) . ' – ' . format_hour_12(OFFICE_HOURS_CLOSE) . ' (Philippine time).';
}

/** Formats a 'H:i' 24-hour string (e.g. '08:00') as a 12-hour clock time (e.g. '8:00 AM'). */
function format_hour_12(string $time24): string
{
    return date('g:i A', strtotime($time24));
}

/**
 * Neutralizes CSV/formula injection before a row goes to fputcsv().
 * Spreadsheet apps treat a cell starting with =, +, -, @ (or a tab/CR)
 * as the start of a formula, so free-text fields that round-trip
 * through us into a CSV export — a requisition's purpose, a decision
 * note, someone's own display name — could otherwise smuggle a live
 * formula into whatever admin/inventory account opens the file.
 * Prefixing with a leading apostrophe forces spreadsheet apps to
 * render the value as plain text instead of evaluating it; it's
 * invisible in the cell once opened. Only strings are touched —
 * numbers, null, etc. pass through as-is.
 */
function csv_row(array $row): array
{
    return array_map(function ($value) {
        if (is_string($value) && $value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }, $row);
}

/**
 * A requisition is only valid on the calendar day it was submitted.
 * Any requisition still 'pending' or 'approved' once that day has
 * passed is auto-cancelled so it can no longer be approved/declined
 * or released. Safe to call on every request — it only touches rows
 * that have actually gone stale.
 *
 * Call this once near the top of a request (see includes/header.php)
 * rather than relying solely on cron, since XAMPP installs often
 * don't have a cron/Task Scheduler entry set up.
 */
function expire_stale_requisitions(PDO $pdo): int
{
    // decided_by is cleared (not just left alone) because a row that
    // was already 'approved' still has a supervisor's id sitting in
    // that column from the original decision. Leaving it in place
    // makes the view page render "Decided by <supervisor> — <this
    // expiry timestamp>", which reads as that person personally
    // cancelling it just now — they didn't; a script did. Clearing it
    // lets the UI fall back to its "system" wording instead of
    // misattributing the auto-cancel to a human.
    $stmt = $pdo->prepare(
        "UPDATE requisitions
            SET status = 'cancelled',
                decision_note = 'Auto-expired — requisitions are only valid on the day they were submitted.',
                decided_by = NULL,
                decided_at = NOW()
          WHERE status IN ('pending', 'approved')
            AND DATE(created_at) < CURDATE()
            AND (purpose IS NULL OR purpose NOT LIKE '[Backorder%')"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/** Two-letter initials used as a placeholder when an item has no photo. */
function item_initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $initials .= strtoupper(substr($w, 0, 1));
    }
    return $initials !== '' ? $initials : '—';
}

/**
 * Renders an account's avatar — the uploaded profile photo if one is
 * set, otherwise the same initials-circle fallback used everywhere
 * else in the app. Centralized here so every avatar spot (sidebar,
 * user chip, dashboard hero) stays in sync automatically.
 *
 * $classes is appended to the wrapping element's class list (e.g.
 * 'avatar-circle-lg mono') on top of the base 'avatar-circle' class.
 */
function avatar_html(array $user, string $classes = ''): string
{
    require_once __DIR__ . '/uploads.php';
    $class = trim('avatar-circle ' . $classes);
    if (!empty($user['profile_picture'])) {
        return '<img src="' . PROFILE_UPLOAD_URL . htmlspecialchars((string)($user['profile_picture'] ?? ''))
            . '" alt="" class="' . htmlspecialchars($class) . '" aria-hidden="true">';
    }
    return '<span class="' . htmlspecialchars($class) . '" aria-hidden="true">'
        . htmlspecialchars(item_initials($user['full_name'])) . '</span>';
}

/** Generates a unique QR token for an approved requisition, retrying on collision. */
function generate_unique_qr_token(PDO $pdo): string
{
    do {
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM requisitions WHERE qr_token = :t');
        $stmt->execute(['t' => $token]);
    } while ($stmt->fetch()['c'] > 0);

    return $token;
}

/**
 * A category's borrow/consume setting: 'consume', 'borrow', or
 * 'choice' (requester picks per request line). Backed by
 * categories.borrow_mode — a real, editable flag on the category row
 * (set from Inventory > Categories), not a match against the
 * category's name. This is looked up per category, not hardcoded per
 * item, so an item's borrow_mode is always derived from its category.
 */
function category_borrow_mode(PDO $pdo, ?int $category_id): string
{
    if (!$category_id) {
        return 'consume';
    }
    $stmt = $pdo->prepare('SELECT borrow_mode FROM categories WHERE id = :id');
    $stmt->execute(['id' => $category_id]);
    $mode = $stmt->fetchColumn();
    return in_array($mode, ['consume', 'borrow', 'choice'], true) ? $mode : 'consume';
}

/** Whether a borrow_mode ever results in something borrowed (used to derive items.is_borrowable). */
function borrow_mode_is_borrowable(string $mode): bool
{
    return $mode === 'borrow' || $mode === 'choice';
}

/**
 * Resolves what actually happens to a single request line — 'consume'
 * or 'borrow' — given the catalog item's borrow_mode and (for
 * borrow_mode 'choice' items only) what the requester picked on the
 * catalog/cart form. Catalog items with a fixed borrow_mode ignore
 * $requested_mode entirely, so a tampered POST can never force a
 * 'consume'-only item to be borrowed or vice versa. An invalid/missing
 * pick on a 'choice' item defaults to 'consume' — the safer of the two
 * (nothing left outstanding to track/return).
 */
function resolve_line_borrow_mode(string $catalog_borrow_mode, ?string $requested_mode): string
{
    if ($catalog_borrow_mode === 'borrow') {
        return 'borrow';
    }
    if ($catalog_borrow_mode === 'consume') {
        return 'consume';
    }
    return $requested_mode === 'borrow' ? 'borrow' : 'consume';
}

/** Human-readable label for a borrow_mode value, for badges and audit log text. */
function category_borrow_mode_label(string $mode): string
{
    return match ($mode) {
        'borrow' => 'Equipment (Returnable)',
        'choice' => 'Borrow or Consume',
        default  => 'Consumable (Non-Returnable)',
    };
}

/**
 * Adds a number of *business* days (Mon–Fri) to a date, skipping
 * Saturdays and Sundays entirely — so a loan set to "3 days" always
 * means 3 working days, and its due date never lands on a weekend.
 */
function add_business_days(string $from_date, int $days): string
{
    $date = new DateTime($from_date);
    $days = max(1, $days);
    $added = 0;
    while ($added < $days) {
        $date->modify('+1 day');
        if ((int)$date->format('N') < 6) { // 1=Mon ... 5=Fri, 6=Sat, 7=Sun
            $added++;
        }
    }
    return $date->format('Y-m-d');
}

/**
 * All categories with their borrow/consume mode, for populating dropdowns
 * that need to show a borrow hint (item_add.php, item_edit.php) without a
 * second query per row.
 * @return array<int, array{id:int, name:string, borrow_mode:string}>
 */
function get_categories_with_flags(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, code_prefix, borrow_mode FROM categories ORDER BY name')->fetchAll();
}

/**
 * Derives a short (max 4 char) uppercase code prefix from a category
 * name, e.g. "Oil Filter" -> "OILF", "PPE" -> "PPE". Used the first
 * time a category needs a prefix and none has been set yet.
 */
function derive_code_prefix_from_name(string $name): string
{
    $letters = preg_replace('/[^A-Za-z0-9]/', '', $name);
    $prefix = strtoupper(substr($letters, 0, 4));
    return $prefix !== '' ? $prefix : 'GEN';
}

/**
 * Returns the category's code_prefix, deriving and persisting one
 * from its name on the fly if it doesn't have one yet (so the value
 * stays stable for every item code generated after this point).
 * Returns 'ITM' for "no category" (category_id null/0).
 */
function resolve_category_code_prefix(PDO $pdo, ?int $category_id): string
{
    if (!$category_id) {
        return 'ITM';
    }
    $stmt = $pdo->prepare('SELECT name, code_prefix FROM categories WHERE id = :id');
    $stmt->execute(['id' => $category_id]);
    $cat = $stmt->fetch();
    if (!$cat) {
        return 'ITM';
    }
    if (!empty($cat['code_prefix'])) {
        return strtoupper($cat['code_prefix']);
    }

    $prefix = derive_code_prefix_from_name($cat['name']);
    $upd = $pdo->prepare('UPDATE categories SET code_prefix = :prefix WHERE id = :id AND (code_prefix IS NULL OR code_prefix = \'\')');
    $upd->execute(['prefix' => $prefix, 'id' => $category_id]);
    return $prefix;
}

/**
 * Next available "PREFIX-NNN" item code for a category, e.g.
 * "FLT-001", then "FLT-002". Looks at the highest existing numeric
 * suffix already used under that prefix (across all categories, since
 * item_code is globally unique) and pads to at least 3 digits,
 * widening automatically past 999 instead of colliding.
 *
 * This is a best-effort suggestion for the form, not a hard
 * reservation — item_add.php still relies on the items.item_code
 * UNIQUE constraint (and retries with a fresh code on collision) to
 * stay correct if two people submit at the same moment.
 */
function next_item_code_for_category(PDO $pdo, ?int $category_id): string
{
    $prefix = resolve_category_code_prefix($pdo, $category_id);

    $stmt = $pdo->prepare("SELECT item_code FROM items WHERE item_code LIKE :pattern");
    $stmt->execute(['pattern' => $prefix . '-%']);
    $max = 0;
    $width = 3;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $code, $m)) {
            $num = (int)$m[1];
            if ($num > $max) {
                $max = $num;
            }
            $width = max($width, strlen($m[1]));
        }
    }
    $next = $max + 1;
    return $prefix . '-' . str_pad((string)$next, $width, '0', STR_PAD_LEFT);
}

/**
 * All stalls, in stall_number order.
 * @return array<int, array{id:int, stall_number:int, name:string}>
 */
function get_stalls(PDO $pdo): array
{
    return $pdo->query(
        'SELECT s.id, s.room_id, s.stall_number, s.name, r.room_number, r.name AS room_name
         FROM stalls s JOIN rooms r ON r.id = s.room_id
         ORDER BY r.room_number, s.stall_number'
    )->fetchAll();
}

/**
 * All rooms, in room_number order.
 * @return array<int, array{id:int, room_number:int, name:string}>
 */
function get_rooms(PDO $pdo): array
{
    return $pdo->query('SELECT id, room_number, name FROM rooms ORDER BY room_number')->fetchAll();
}

/**
 * All layers for every stall in one query, grouped by stall_id and
 * ordered top-to-bottom (layer_number ASC) within each stall — handy
 * for building cascading Stall -> Layer selects without an extra
 * round trip per stall.
 * @return array<int, array<int, array{id:int, layer_number:int, layer_name:string}>>
 */
function get_stall_layers_grouped(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, stall_id, layer_number, layer_name FROM stall_layers ORDER BY stall_id, layer_number'
    )->fetchAll();
    $by_stall = [];
    foreach ($rows as $row) {
        $by_stall[(int)$row['stall_id']][] = [
            'id'           => (int)$row['id'],
            'layer_number' => (int)$row['layer_number'],
            'layer_name'   => $row['layer_name'],
        ];
    }
    return $by_stall;
}

/** Badge CSS class for a requisition's status. */
function requisition_status_class(string $status): string
{
    return match ($status) {
        'approved', 'released' => 'active',
        'declined', 'cancelled' => 'inactive',
        default => 'role',
    };
}

/**
 * Normalizes and sanitizes a vehicle license plate number or fleet code.
 * Removes extraneous punctuation, collapses whitespace, and standardizes casing.
 * e.g. "ndx-4059" -> "NDX 4059", "ndx4059" -> "NDX 4059", "  abc   123  " -> "ABC 123"
 */
function sanitize_license_plate(?string $raw): string
{
    if ($raw === null) {
        return '';
    }
    $plate = strtoupper(trim($raw));
    // Replace hyphens or underscores with space
    $plate = preg_replace('/[_\-]+/', ' ', $plate);
    // Collapse multiple whitespace
    $plate = preg_replace('/\s+/', ' ', $plate);

    // If 3 letters followed immediately by 3-4 digits (e.g. NDX4059), insert space
    if (preg_match('/^([A-Z]{3})([0-9]{3,4})$/', $plate, $m)) {
        $plate = $m[1] . ' ' . $m[2];
    }
    // If 2 letters followed immediately by 4 digits (e.g. NC1234), insert space
    elseif (preg_match('/^([A-Z]{2})([0-9]{4})$/', $plate, $m)) {
        $plate = $m[1] . ' ' . $m[2];
    }

    return trim($plate);
}

/**
 * Validates whether a sanitized plate number matches accepted Philippine LTO / fleet formats.
 * Accepts:
 * - Standard LTO private/commercial (3 letters + 3 or 4 numbers): e.g. "NDX 4059", "ABC 123"
 * - 2 letters + 4 numbers: e.g. "NB 1234"
 * - Conduction / MV File / Fleet ID: e.g. "MV 123456", "FLT 01", "1301 123456"
 * Minimum length 4 chars, maximum length 15 chars, alphanumeric with optional space/hyphen.
 */
function validate_license_plate(string $plate): bool
{
    $plate = trim($plate);
    if (strlen($plate) < 4 || strlen($plate) > 15) {
        return false;
    }
    // Check against standard LTO 3-letter or 2-letter plate formats
    if (preg_match('/^[A-Z]{2,3}\s[0-9]{3,4}$/', $plate)) {
        return true;
    }
    // Check against fleet/conduction/MV file format
    if (preg_match('/^[A-Z0-9]{2,5}(\s|-)[A-Z0-9]{2,7}$/', $plate)) {
        return true;
    }
    // Standalone fleet tag e.g. "FLT-01" or "FLT01"
    if (preg_match('/^[A-Z]{2,4}[0-9]{1,4}$/', $plate)) {
        return true;
    }
    return false;
}

/**
 * Renders an authentic commercial-grade license plate badge for a fleet truck.
 * Eliminates generic casual emoji badges in favor of professional fleet typography.
 */
function truck_plate_badge(?string $plate, bool $with_icon = true): string
{
    if (!$plate) {
        return '<span class="text-muted">—</span>';
    }
    $esc = htmlspecialchars(trim($plate));
    $icon = $with_icon
        ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px; margin-right:4px; opacity:0.75;"><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>'
        : '';
    return '<span class="fleet-plate-badge" title="Fleet Vehicle Plate">'
         . $icon
         . '<span class="fleet-plate-text">' . $esc . '</span>'
         . '</span>';
}

/**
 * Count of active items at or below their CATEGORY's stock alert
 * threshold (out of stock counts too). Items with no category are
 * measured against DEFAULT_STOCK_ALERT_THRESHOLD.
 */
function count_stock_alerts(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) c FROM items i
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
    return (int)$stmt->fetch()['c'];
}

/**
 * All variants for one item, in the order they were created.
 * @return array<int, array{id:int, variant_value:string, variant_note:?string, image_filename:?string, quantity_on_hand:int}>
 */
function get_item_variants(PDO $pdo, int $item_id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, variant_value, variant_note, image_filename, quantity_on_hand FROM item_variants WHERE item_id = :item_id ORDER BY id'
    );
    $stmt->execute(['item_id' => $item_id]);
    return $stmt->fetchAll();
}

/**
 * Variants for a whole set of items in one query, keyed by item_id.
 * @param int[] $item_ids
 * @return array<int, array<int, array{id:int, variant_value:string, variant_note:?string, image_filename:?string, quantity_on_hand:int}>>
 */
function get_item_variants_for(PDO $pdo, array $item_ids): array
{
    $item_ids = array_values(array_unique(array_map('intval', $item_ids)));
    if (!$item_ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, item_id, variant_value, variant_note, image_filename, quantity_on_hand FROM item_variants
         WHERE item_id IN ($placeholders) ORDER BY id"
    );
    $stmt->execute($item_ids);
    $by_item = [];
    foreach ($stmt->fetchAll() as $row) {
        $by_item[(int)$row['item_id']][] = [
            'id'               => (int)$row['id'],
            'variant_value'    => $row['variant_value'],
            'variant_note'     => $row['variant_note'],
            'image_filename'   => $row['image_filename'],
            'quantity_on_hand' => (int)$row['quantity_on_hand'],
        ];
    }
    return $by_item;
}

/**
 * How many units of an item (or one specific variant) are already
 * spoken for by *other* requisitions that are pending or approved but
 * not yet released — i.e. not yet actually subtracted from
 * quantity_on_hand (that only happens at release, see
 * includes/stock.php). Declined, cancelled, and released requisitions
 * don't hold a reservation: declined/cancelled never happened, and
 * released has already been deducted from quantity_on_hand directly.
 */
function reserved_stock_for(PDO $pdo, int $item_id, ?string $variant_value): int
{
    if ($variant_value === null) {
        // When variant_value is null, sum all approved reservations for this item
        // across all lines (both unvarianted and varianted) to protect aggregate stock.
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(ri.quantity_requested), 0) AS reserved
               FROM requisition_items ri
               JOIN requisitions r ON r.id = ri.requisition_id
              WHERE ri.item_id = :item_id
                AND r.status = 'approved'"
        );
        $stmt->execute(['item_id' => $item_id]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(ri.quantity_requested), 0) AS reserved
               FROM requisition_items ri
               JOIN requisitions r ON r.id = ri.requisition_id
              WHERE ri.item_id = :item_id
                AND ri.variant_selected = :variant
                AND r.status = 'approved'"
        );
        $stmt->execute(['item_id' => $item_id, 'variant' => $variant_value]);
    }
    return (int)$stmt->fetch()['reserved'];
}

/**
 * Returns a list of active approved requisitions currently committing stock for this item/variant.
 * @return array<array{requisition_id: int, requester_name: string, quantity: int, variant: ?string}>
 */
function get_committed_requisitions_for_item(PDO $pdo, int $item_id, ?string $variant_value = null): array
{
    $where = "ri.item_id = :item_id AND r.status = 'approved'";
    $params = ['item_id' => $item_id];
    if ($variant_value !== null) {
        $where .= " AND ri.variant_selected = :variant";
        $params['variant'] = $variant_value;
    }

    $stmt = $pdo->prepare(
        "SELECT r.id AS requisition_id, u.full_name AS requester_name,
                ri.quantity_requested AS quantity, ri.variant_selected AS variant
           FROM requisition_items ri
           JOIN requisitions r ON r.id = ri.requisition_id
           JOIN users u ON u.id = r.requester_id
          WHERE {$where}
          ORDER BY r.decided_at ASC, r.id ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Checks whether adjusting or reducing stock for an item/variant down to $new_target_quantity
 * would violate active approved requisition commitments.
 *
 * @return array{safe: bool, committed: int, shortfall: int, conflicts: array, message: string}
 */
function verify_stock_reduction_safety(
    PDO $pdo,
    int $item_id,
    int $new_target_quantity,
    ?int $variant_id = null
): array {
    $variant_value = null;
    $variant_qty = null;
    if ($variant_id !== null) {
        $vstmt = $pdo->prepare('SELECT variant_value, quantity_on_hand FROM item_variants WHERE id = :id AND item_id = :item_id');
        $vstmt->execute(['id' => $variant_id, 'item_id' => $item_id]);
        $vrow = $vstmt->fetch();
        if ($vrow) {
            $variant_value = $vrow['variant_value'];
            $variant_qty = (int)$vrow['quantity_on_hand'];
        }
    }

    // 1. Check specific variant commitment if variant_id provided
    if ($variant_value !== null) {
        $committed = reserved_stock_for($pdo, $item_id, $variant_value);
        if ($new_target_quantity < $committed) {
            $shortfall = $committed - $new_target_quantity;
            $conflicts = get_committed_requisitions_for_item($pdo, $item_id, $variant_value);
            $conflict_refs = array_map(function ($c) {
                return "#{$c['requisition_id']} for {$c['requester_name']} (qty {$c['quantity']})";
            }, $conflicts);
            $refs_str = implode(', ', $conflict_refs);
            return [
                'safe'      => false,
                'committed' => $committed,
                'shortfall' => $shortfall,
                'conflicts' => $conflicts,
                'message'   => "Cannot reduce stock of option \"{$variant_value}\" to {$new_target_quantity}. There are {$committed} unit(s) committed to active approved requisition(s) awaiting release: {$refs_str}. Either release or decline/cancel the approved request(s) before reducing stock below committed levels.",
            ];
        }

        // 2. Check aggregate item commitment if reducing this variant
        if ($variant_qty !== null && $new_target_quantity < $variant_qty) {
            $istmt = $pdo->prepare('SELECT quantity_on_hand FROM items WHERE id = :id');
            $istmt->execute(['id' => $item_id]);
            $parent_on_hand = (int)$istmt->fetchColumn();
            $delta = $variant_qty - $new_target_quantity;
            $new_parent_qty = max(0, $parent_on_hand - $delta);
            $parent_committed = reserved_stock_for($pdo, $item_id, null);
            if ($new_parent_qty < $parent_committed) {
                $shortfall = $parent_committed - $new_parent_qty;
                $conflicts = get_committed_requisitions_for_item($pdo, $item_id, null);
                $conflict_refs = array_map(function ($c) {
                    $var_label = !empty($c['variant']) ? " ({$c['variant']})" : '';
                    return "#{$c['requisition_id']} for {$c['requester_name']} (qty {$c['quantity']}{$var_label})";
                }, $conflicts);
                $refs_str = implode(', ', $conflict_refs);
                return [
                    'safe'      => false,
                    'committed' => $parent_committed,
                    'shortfall' => $shortfall,
                    'conflicts' => $conflicts,
                    'message'   => "Cannot reduce stock of option \"{$variant_value}\" to {$new_target_quantity}. Doing so would lower total item stock on hand to {$new_parent_qty}, but {$parent_committed} unit(s) across all options are committed to active approved requisition(s): {$refs_str}.",
                ];
            }
        }

        return [
            'safe'      => true,
            'committed' => $committed,
            'shortfall' => 0,
            'conflicts' => [],
            'message'   => '',
        ];
    }

    // 3. Unvarianted parent item check
    $committed = reserved_stock_for($pdo, $item_id, null);
    if ($new_target_quantity < $committed) {
        $shortfall = $committed - $new_target_quantity;
        $conflicts = get_committed_requisitions_for_item($pdo, $item_id, null);
        $conflict_refs = array_map(function ($c) {
            $var_label = !empty($c['variant']) ? " ({$c['variant']})" : '';
            return "#{$c['requisition_id']} for {$c['requester_name']} (qty {$c['quantity']}{$var_label})";
        }, $conflicts);
        $refs_str = implode(', ', $conflict_refs);
        return [
            'safe'      => false,
            'committed' => $committed,
            'shortfall' => $shortfall,
            'conflicts' => $conflicts,
            'message'   => "Cannot reduce stock of this item to {$new_target_quantity}. There are {$committed} unit(s) committed to active approved requisition(s) awaiting release: {$refs_str}. Either release or decline/cancel the approved request(s) before reducing stock below committed levels.",
        ];
    }

    return [
        'safe'      => true,
        'committed' => $committed,
        'shortfall' => 0,
        'conflicts' => [],
        'message'   => '',
    ];
}

/**
 * Resolves how much stock is actually available for a cart/requisition line:
 * the specific variant's own count if the item has variant_label set and a
 * variant was chosen, otherwise the item's own (shared) quantity_on_hand —
 * in both cases minus whatever's already reserved by other people's
 * pending/approved requisitions for the same item/variant, so two
 * requesters can't both be told "yes, available" for the last unit.
 * @return array{available:int, label:string, ok:bool} `ok` is false only
 *         when the item has variants but the given value doesn't match one.
 */
function available_stock_for(PDO $pdo, array $item, ?string $variant_value): array
{
    if (!$item['variant_label']) {
        $reserved = reserved_stock_for($pdo, (int)$item['id'], null);
        return ['available' => max(0, (int)$item['quantity_on_hand'] - $reserved), 'label' => $item['name'], 'ok' => true];
    }
    if (!$variant_value) {
        return ['available' => 0, 'label' => $item['name'], 'ok' => false];
    }
    $variant = find_item_variant($pdo, (int)$item['id'], $variant_value);
    if (!$variant) {
        return ['available' => 0, 'label' => $item['name'], 'ok' => false];
    }
    $reserved = reserved_stock_for($pdo, (int)$item['id'], $variant['variant_value']);
    return [
        'available' => max(0, (int)$variant['quantity_on_hand'] - $reserved),
        'label'     => $item['name'] . ' (' . $variant['variant_value'] . ')',
        'ok'        => true,
    ];
}

/** Finds a specific item's variant row by its text value (e.g. "20A", "Red"). */
function find_item_variant(PDO $pdo, int $item_id, string $variant_value): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, variant_value, variant_note, quantity_on_hand FROM item_variants
         WHERE item_id = :item_id AND variant_value = :value'
    );
    $stmt->execute(['item_id' => $item_id, 'value' => $variant_value]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Whether $user's requisitions need Field Supervisor approval before
 * being releasable. Matches the actual reporting structure: drivers,
 * mechanics, and helpers report to a Field Supervisor, so their
 * requests go through approval as normal. Two groups have nobody
 * above them to decide their own request, so both are auto-approved
 * on submission instead of sitting in a queue nobody is responsible
 * for: office staff (no Field Supervisor assigned to them at all),
 * and Field Supervisors themselves (the approver role — there's no
 * one senior to them in this workflow to approve their own request,
 * and letting a supervisor decide their own would be a conflict of
 * interest even if there were another supervisor to fall back on).
 */
function requisition_needs_supervisor_approval(array $user): bool
{
    if ($user['role'] === 'field_supervisor') {
        return false;
    }
    return ($user['position'] ?? null) !== 'office_staff';
}

/** Short reason stored for a requisition approved without review — varies by which of the two groups the requester belongs to. */
function requisition_auto_approve_note(array $user): string
{
    return $user['role'] === 'field_supervisor'
        ? 'Approved without review — no higher approver.'
        : 'Approved without review — no supervisor assigned.';
}

/**
 * Is this decision note one of the "approved without review" notes?
 * Also matches the legacy wording ("Auto-approved …") so rows saved
 * before the rename are still recognised.
 */
function requisition_is_no_review_note(?string $note): bool
{
    if ($note === null || $note === '') {
        return false;
    }
    return str_starts_with($note, 'Approved without review') || str_starts_with($note, 'Auto-approved');
}

/** Short display reason (3 words) for an approved-without-review note, new or legacy wording. */
function requisition_no_review_reason(?string $note): string
{
    $n = (string)$note;
    if (stripos($n, 'no one above') !== false || stripos($n, 'no higher approver') !== false) {
        return 'No higher approver';
    }
    if (stripos($n, 'no Field Supervisor') !== false || stripos($n, 'no supervisor') !== false) {
        return 'No supervisor assigned';
    }
    return 'Approved without review';
}

class RequisitionShortfallException extends RuntimeException {}

/**
 * Creates a requisition (and its line items) for $user from a validated
 * cart, and decides its initial status per
 * requisition_needs_supervisor_approval(). For an auto-approved
 * (office staff) submission, this locks the same item rows
 * requisition/view.php's decision handler locks before checking
 * shortfalls — closing off the same race a pending→approved decision
 * guards against, just at submission time instead of approval time,
 * since there's no separate approval step here to catch it later.
 * On a shortfall the whole submission is rolled back and
 * RequisitionShortfallException is thrown; nothing is left half-created.
 *
 * @param array $cart          [item_id => ['qty','mode','days','variant']], already stock-validated by the caller
 * @param array $catalog_items [item_id => items row], for the item_ids in $cart
 * @return int The new requisition's id.
 */
function submit_requisition(PDO $pdo, array $user, array $cart, array $catalog_items, ?string $purpose, ?int $truck_id = null, bool $is_maintenance_request = false): int
{
    $auto_approve = !requisition_needs_supervisor_approval($user);

    $truck_plate = null;
    if ($truck_id !== null) {
        $tstmt = $pdo->prepare('SELECT plate_number FROM trucks WHERE id = :id');
        $tstmt->execute(['id' => $truck_id]);
        $truck_plate = $tstmt->fetch()['plate_number'] ?? null;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO requisitions (requester_id, purpose, truck_id, truck_plate_snapshot, is_maintenance_request)
             VALUES (:uid, :purpose, :truck_id, :plate, :is_maint)'
        );
        $stmt->execute([
            'uid'      => $user['id'],
            'purpose'  => $purpose ?: null,
            'truck_id' => $truck_id,
            'plate'    => $truck_plate,
            'is_maint' => $is_maintenance_request ? 1 : 0,
        ]);
        $requisition_id = (int)$pdo->lastInsertId();

        $line_stmt = $pdo->prepare(
            'INSERT INTO requisition_items
                (requisition_id, item_id, item_name_snapshot, unit_snapshot, quantity_requested,
                 is_borrowable, requested_days, variant_selected)
             VALUES (:rid, :iid, :name, :unit, :qty, :is_borrowable, :days, :variant)'
        );
        foreach ($cart as $item_id => $line) {
            $ci = $catalog_items[$item_id];
            $mode = resolve_line_borrow_mode($ci['borrow_mode'], $line['mode'] ?? null);
            $line_stmt->execute([
                'rid' => $requisition_id, 'iid' => $item_id,
                'name' => $ci['name'], 'unit' => $ci['unit'], 'qty' => $line['qty'],
                'is_borrowable' => $mode === 'borrow' ? 1 : 0,
                'days' => $mode === 'borrow' ? ($line['days'] ?? 3) : null,
                'variant' => $line['variant'] ?? null,
            ]);
        }

        if ($auto_approve) {
            $item_ids = array_values(array_unique(array_map('intval', array_keys($cart))));
            if ($item_ids) {
                $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
                $pdo->prepare("SELECT id FROM items WHERE id IN ($placeholders) FOR UPDATE")
                    ->execute($item_ids);
            }

            $shortfalls = requisition_stock_shortfalls($pdo, $requisition_id);
            if ($shortfalls) {
                $pdo->rollBack();
                throw new RequisitionShortfallException(
                    'Not enough stock right now for: ' . implode(', ', $shortfalls)
                    . '. Please try again shortly or check with Inventory Staff.'
                );
            }

            $upd = $pdo->prepare(
                "UPDATE requisitions SET status = 'approved', decided_at = NOW(),
                 decision_note = :note, qr_token = :qr_token WHERE id = :id"
            );
            $upd->execute([
                'note'     => requisition_auto_approve_note($user),
                'qr_token' => generate_unique_qr_token($pdo),
                'id'       => $requisition_id,
            ]);
        }

        $pdo->commit();
        return $requisition_id;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * For a still-pending requisition, the item labels whose combined
 * pending/approved reservations — this requisition's own line
 * included — now add up to more than what's on hand. An empty result
 * means every line is still safely coverable. Meant to run right
 * before a Field Supervisor's approval commits, since approving
 * doesn't touch quantity_on_hand itself (only release does) and so
 * can't rely on a stock-movement failure to catch this the way
 * release can.
 */
function requisition_stock_shortfalls(PDO $pdo, int $requisition_id): array
{
    $stmt_status = $pdo->prepare('SELECT status FROM requisitions WHERE id = :id');
    $stmt_status->execute(['id' => $requisition_id]);
    $req_status = $stmt_status->fetch()['status'] ?? 'pending';

    $stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id');
    $stmt->execute(['id' => $requisition_id]);

    // Aggregate quantities requested across all lines by item & variant so multiple lines
    // for the same item cannot bypass stock validation.
    $aggregated = [];
    foreach ($stmt->fetchAll() as $line) {
        if ($line['item_id'] === null) {
            continue; // catalog item was deleted since the request was made
        }
        $key = (int)$line['item_id'] . '::' . ($line['variant_selected'] ?? '');
        if (!isset($aggregated[$key])) {
            $aggregated[$key] = [
                'item_id'            => (int)$line['item_id'],
                'variant_selected'   => $line['variant_selected'] ?: null,
                'item_name_snapshot' => $line['item_name_snapshot'],
                'total_qty'          => 0,
            ];
        }
        $aggregated[$key]['total_qty'] += (int)$line['quantity_requested'];
    }

    $shortfalls = [];
    foreach ($aggregated as $group) {
        $itemId = $group['item_id'];
        $variant = $group['variant_selected'];

        if ($variant !== null) {
            $vRow = find_item_variant($pdo, $itemId, $variant);
            $on_hand = $vRow ? (int)$vRow['quantity_on_hand'] : 0;
        } else {
            $item_stmt = $pdo->prepare('SELECT quantity_on_hand FROM items WHERE id = :id');
            $item_stmt->execute(['id' => $itemId]);
            $on_hand = (int)($item_stmt->fetch()['quantity_on_hand'] ?? 0);
        }

        $reserved = reserved_stock_for($pdo, $itemId, $variant);
        $additional = ($req_status === 'pending') ? $group['total_qty'] : 0;

        if (($reserved + $additional) > $on_hand) {
            $shortfalls[] = $group['item_name_snapshot'] . ($variant ? ' (' . $variant . ')' : '');
        }
    }
    return array_values(array_unique($shortfalls));
}

/** Bar width as a percentage of the largest value in a set, with a visible floor for non-zero values. */
function bar_pct(float $value, float $max): float
{
    if ($max <= 0) {
        return 0;
    }
    $pct = ($value / $max) * 100;
    return $value > 0 ? max($pct, 4) : 0;
}

/**
 * Inline SVG icon, drawn in the same feather-style outline set already
 * used in the sidebar (24x24 viewBox, stroke-based, inherits currentColor).
 * Centralized here so the dashboards share one small icon set instead of
 * each page hand-rolling its own <svg> markup.
 */
function icon_svg(string $name): string
{
    $paths = [
        'users'          => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-check'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
        'shield'         => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'shield-alert'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'box'            => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'alert-triangle' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'clock'          => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'check-circle'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'clipboard'      => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
        'x-circle'       => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'tool'           => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'package-check'  => '<path d="M16 16l2 2 4-4"/><path d="M21 12.5V7a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 7v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l1.5-.87"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'inbox'          => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'arrow-right'    => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'log-in'         => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'plus-circle'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>',
        'refresh'        => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'truck'          => '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>',
    ];
    $body = $paths[$name] ?? $paths['box'];
    // width/height attributes are a safety net, not the real sizing —
    // any wrapper with its own `svg { width: ... }` rule (stat card icon,
    // timeline dot, etc.) overrides this. Without them, a bare icon
    // dropped into a button or banner falls back to the browser's
    // default SVG box (~300x150) instead of a sane icon size.
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

/**
 * Inline SVG sparkline for a short numeric series (e.g. the last 7 days
 * of activity). No JS charting library, same "keep it simple and server-
 * rendered" approach as the bar charts already used in Reports & Analytics.
 * @param int[]|float[] $values
 */
function sparkline_svg(array $values, int $width = 120, int $height = 30): string
{
    $n = count($values);
    if ($n < 2) {
        return '';
    }
    $max = max(max($values), 1);
    $min = min(min($values), 0);
    $range = max($max - $min, 1);
    $stepX = $width / ($n - 1);
    $points = [];
    foreach (array_values($values) as $i => $v) {
        $x = round($i * $stepX, 1);
        $y = round($height - (($v - $min) / $range) * $height, 1);
        $points[] = "$x,$y";
    }
    $line = implode(' ', $points);
    $fillPoints = '0,' . $height . ' ' . $line . ' ' . $width . ',' . $height;
    [$lastX, $lastY] = explode(',', end($points));

    return '<svg class="sparkline" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" preserveAspectRatio="none">'
        . '<polygon class="spark-fill" points="' . htmlspecialchars($fillPoints) . '"></polygon>'
        . '<polyline class="spark-line" points="' . htmlspecialchars($line) . '"></polyline>'
        . '<circle class="spark-dot" cx="' . $lastX . '" cy="' . $lastY . '" r="2.5"></circle>'
        . '</svg>';
}

/**
 * Inline SVG radial gauge for a single 0–100 percentage (fleet
 * utilization, approval rate, etc.) — a rounded-cap arc over a faint
 * track, colored by how "good" the value is for the given $tone.
 * Same server-rendered, no-JS-library approach as sparkline_svg().
 *
 * @param float $pct   0–100
 * @param string $tone 'higher-is-better' (default) or 'lower-is-better'
 *                      controls which end of the scale reads as green.
 */
function gauge_svg(float $pct, string $label = '', string $tone = 'higher-is-better', int $size = 116): string
{
    $pct = max(0, min(100, $pct));
    $good = $tone === 'lower-is-better' ? (100 - $pct) : $pct;
    $color = $good >= 60 ? 'var(--green-ok)' : ($good >= 30 ? 'var(--amber-dim)' : 'var(--red-danger)');

    $r = ($size / 2) - 10;
    $cx = $size / 2;
    $cy = $size / 2;
    $circumference = 2 * M_PI * $r;
    $offset = $circumference * (1 - $pct / 100);

    return '<svg class="gauge" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" role="img" aria-label="' . htmlspecialchars($label) . ': ' . round($pct, 1) . ' percent">'
        . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="var(--line)" stroke-width="9"/>'
        . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="9" '
        . 'stroke-linecap="round" stroke-dasharray="' . round($circumference, 2) . '" stroke-dashoffset="' . round($offset, 2) . '" '
        . 'transform="rotate(-90 ' . $cx . ' ' . $cy . ')"/>'
        . '<text x="' . $cx . '" y="' . ($cy - 2) . '" text-anchor="middle" class="gauge-value">' . round($pct) . '%</text>'
        . '<text x="' . $cx . '" y="' . ($cy + 16) . '" text-anchor="middle" class="gauge-caption">' . htmlspecialchars($label) . '</text>'
        . '</svg>';
}

/** Compact relative time ("5m ago", "3h ago", "2d ago") for activity feeds; falls back to a date once it's old. */
function time_ago(?string $datetime): string
{
    if ($datetime === null || trim($datetime) === '') {
        return 'recently';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return 'recently';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    if ($diff < 7 * 86400) {
        return floor($diff / 86400) . 'd ago';
    }
    return date('M j', $ts);
}

/**
 * Fills in zero-count days across a fixed trailing window, so a sparse
 * GROUP BY DATE(...) query becomes a complete, gap-free series ready for
 * sparkline_svg() — a day with no activity should show as 0, not vanish.
 * @param array<string,int> $counts_by_date Keyed by 'Y-m-d'.
 * @return int[] Oldest day first.
 */
function fill_daily_series(array $counts_by_date, int $days): array
{
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $series[] = $counts_by_date[$d] ?? 0;
    }
    return $series;
}

/**
 * Returns all registered trucks with status and recent activity.
 * @return array<int, array{id:int, plate_number:string, model:string, status:string, notes:?string}>
 */
function get_all_trucks(PDO $pdo): array
{
    return $pdo->query(
        'SELECT * FROM trucks ORDER BY plate_number ASC'
    )->fetchAll();
}

/**
 * Checks if any item in the cart belongs to a category that requires a truck assignment.
 */
function cart_requires_truck(PDO $pdo, array $cart): bool
{
    if (!$cart) {
        return false;
    }
    $ids = array_keys($cart);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM items i
         JOIN categories c ON c.id = i.category_id
         WHERE i.id IN ($ph) AND c.requires_truck = 1"
    );
    $stmt->execute($ids);
    return (int)$stmt->fetch()['c'] > 0;
}

/**
 * Does releasing this requisition actually dispatch the truck (mark it
 * "On Trip")?
 *
 * Only a real trip does: not a repair request (parts to fix the truck —
 * the truck is sitting in the garage, not out on the road), and only when
 * the requisition carries borrowed equipment. The "back to Available"
 * transition is driven by returning borrowed tools (includes/loans.php),
 * so flipping the truck to On Trip for a consume-only release (brake
 * pads, bulbs, ...) would leave it stuck On Trip forever.
 */
function requisition_dispatches_truck(PDO $pdo, array $requisition): bool
{
    if (empty($requisition['truck_id']) || !empty($requisition['is_maintenance_request'])) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM requisition_items WHERE requisition_id = :rid AND is_borrowable = 1'
    );
    $stmt->execute(['rid' => $requisition['id']]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Status label and badge class for a truck status.
 */
function truck_status_info(string $status): array
{
    return match ($status) {
        'available'         => ['label' => 'Available', 'class' => 'active'],
        'on_trip'           => ['label' => 'On Trip', 'class' => 'role'],
        'under_maintenance' => ['label' => 'Under Maintenance', 'class' => 'inactive'],
        default             => ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'role'],
    };
}

/**
 * Universal DuaRTE 10-per-page Pagination Component.
 * Produces Page X of Y, Prev, Next, and numbered page carousel buttons.
 */
function render_pagination(int $page, int $total_pages, int $per_page = 10, ?array $query_params = null): string
{
    if ($total_pages <= 1) {
        return '';
    }

    $params = $query_params !== null ? $query_params : $_GET;

    $make_url = function(int $p) use ($params): string {
        $q = $params;
        $q['page'] = $p;
        return '?' . http_build_query($q);
    };

    $html = '<div class="pagination">';
    $html .= '<div class="pagination-info">';
    $html .= 'Page <strong>' . (int)$page . '</strong> of <strong>' . (int)$total_pages . '</strong> ';
    $html .= '<span>(' . (int)$per_page . ' per page)</span>';
    $html .= '</div>';

    $html .= '<div class="pagination-nav">';

    // Prev
    $prev_disabled = $page <= 1;
    $html .= '<a href="' . ($prev_disabled ? 'javascript:void(0)' : htmlspecialchars($make_url(max(1, $page - 1)))) . '" '
          . 'class="pagination-btn pagination-prev' . ($prev_disabled ? ' disabled' : '') . '" '
          . 'aria-label="Previous page"' . ($prev_disabled ? ' aria-disabled="true"' : '') . '>&larr; Prev</a>';

    $start_p = max(1, $page - 2);
    $end_p = min($total_pages, $page + 2);

    if ($start_p > 1) {
        $html .= '<a href="' . htmlspecialchars($make_url(1)) . '" class="pagination-btn pagination-num">1</a>';
        if ($start_p > 2) {
            $html .= '<span class="pagination-ellipsis">&hellip;</span>';
        }
    }

    for ($p = $start_p; $p <= $end_p; $p++) {
        if ($p === $page) {
            $html .= '<span class="pagination-btn pagination-num active" aria-current="page">' . $p . '</span>';
        } else {
            $html .= '<a href="' . htmlspecialchars($make_url($p)) . '" class="pagination-btn pagination-num">' . $p . '</a>';
        }
    }

    if ($end_p < $total_pages) {
        if ($end_p < $total_pages - 1) {
            $html .= '<span class="pagination-ellipsis">&hellip;</span>';
        }
        $html .= '<a href="' . htmlspecialchars($make_url($total_pages)) . '" class="pagination-btn pagination-num">' . $total_pages . '</a>';
    }

    // Next
    $next_disabled = $page >= $total_pages;
    $html .= '<a href="' . ($next_disabled ? 'javascript:void(0)' : htmlspecialchars($make_url(min($total_pages, $page + 1)))) . '" '
          . 'class="pagination-btn pagination-next' . ($next_disabled ? ' disabled' : '') . '" '
          . 'aria-label="Next page"' . ($next_disabled ? ' aria-disabled="true"' : '') . '>Next &rarr;</a>';

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * Detects high-frequency consumable part / supply requisitions for the same truck.
 * Flagged if a non-borrowable item (e.g. oil, tire, battery, coolant) was already
 * approved or released for this truck within $window_days (default 7 days).
 *
 * @param PDO $pdo
 * @param int $truck_id
 * @param array $cart_items Array of items (e.g. [['id' => 10, 'is_borrowable' => 0, 'name' => '...']])
 * @param int $window_days
 * @return array Array of warning records (empty if clean)
 */
function check_truck_consumable_velocity(PDO $pdo, int $truck_id, array $cart_items, int $window_days = 7): array
{
    if ($truck_id <= 0 || empty($cart_items)) {
        return [];
    }

    $consumable_ids = [];
    foreach ($cart_items as $ci) {
        $iid = (int)($ci['id'] ?? $ci['item_id'] ?? 0);
        $is_borrow = !empty($ci['is_borrowable']);
        if ($iid > 0 && !$is_borrow) {
            $consumable_ids[] = $iid;
        }
    }

    if (empty($consumable_ids)) {
        return [];
    }

    $in_clause = implode(',', array_fill(0, count($consumable_ids), '?'));
    $sql = "
        SELECT r.id AS requisition_id, r.created_at, ri.item_id, ri.item_name_snapshot, t.plate_number
        FROM requisitions r
        JOIN requisition_items ri ON ri.requisition_id = r.id
        LEFT JOIN trucks t ON t.id = r.truck_id
        WHERE r.truck_id = ?
          AND ri.item_id IN ($in_clause)
          AND r.status IN ('approved', 'released')
          AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY r.created_at DESC
    ";

    $params = array_merge([$truck_id], $consumable_ids, [$window_days]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent_matches = $stmt->fetchAll();

    $warnings = [];
    foreach ($recent_matches as $m) {
        $days_ago = (int)ceil((time() - strtotime($m['created_at'])) / 86400);
        $plate = $m['plate_number'] ?? "Truck #$truck_id";
        $warnings[] = [
            'item_id'        => (int)$m['item_id'],
            'item_name'      => $m['item_name_snapshot'],
            'requisition_id' => (int)$m['requisition_id'],
            'plate_number'   => $plate,
            'days_ago'       => max(0, $days_ago),
            'message'        => "Kaka-request lang ng \"{$m['item_name_snapshot']}\" para sa $plate noong nakaraang $days_ago araw (REQ-#{$m['requisition_id']})."
        ];
    }

    return $warnings;
}
