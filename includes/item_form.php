<?php
/**
 * DuaRTE — shared logic for item_add.php and item_edit.php.
 *
 * Both pages collect the same core item fields and the same "variant
 * rows" (an option value + a starting quantity) from parallel POST
 * arrays, then apply the same validation. They used to each reimplement
 * this independently, and the two copies had already drifted — e.g.
 * different duplicate-value error messages, and item_add.php only
 * checked uniqueness among rows in the current submission while
 * item_edit.php also checked against already-saved variants. This file
 * is the one place that logic lives now, so a fix here reaches both
 * pages automatically.
 */

/**
 * Parses the variant_value[]/variant_qty[]/variant_note[] POST arrays into
 * clean rows, skipping blanks and rejecting any value that collides
 * (case-insensitively) with either an already-saved variant or another row
 * in this same submission.
 *
 * variant_note is optional free text shown as a hover tooltip on the
 * option in the catalog (e.g. vehicle fitment for a Part # option) — it
 * never appears in the dropdown label itself.
 *
 * @param array    $post            $_POST
 * @param string[] $existing_values Lowercased variant_value strings already
 *                                  saved for this item (empty array for a
 *                                  brand-new item).
 * @param string   $variant_label   Used only to phrase the error message.
 * @return array{rows: array<int, array{value:string, qty:int, note:?string}>, errors: string[]}
 */
function parse_variant_submission(array $post, array $existing_values, string $variant_label): array
{
    $errors = [];
    $rows = [];
    $seen = $existing_values;

    $values_in = $post['variant_value'] ?? [];
    $qty_in    = $post['variant_qty'] ?? [];
    $note_in   = $post['variant_note'] ?? [];

    foreach ($values_in as $i => $raw_value) {
        $value = trim((string)$raw_value);
        if ($value === '') {
            continue;
        }
        if (in_array(mb_strtolower($value), $seen, true)) {
            $errors[] = 'That ' . ($variant_label ?: 'option') . ' value already exists: "' . $value . '".';
            continue;
        }
        $seen[] = mb_strtolower($value);
        $note = trim((string)($note_in[$i] ?? ''));
        $rows[] = ['value' => $value, 'qty' => max(0, (int)($qty_in[$i] ?? 0)), 'note' => $note !== '' ? $note : null];
    }

    return ['rows' => $rows, 'errors' => $errors];
}

/**
 * Required-field checks shared by both add and edit. Variant-label
 * requirements are handled separately by the caller since add/edit
 * differ slightly in when the label is allowed to be cleared.
 * @return string[]
 */
function validate_item_common_fields(array $values): array
{
    $errors = [];
    if ($values['item_code'] === '')  $errors[] = 'Item code is required.';
    if ($values['name'] === '')       $errors[] = 'Item name is required.';
    return $errors;
}

/**
 * Works out which stall a stall_layer_id belongs to, so the location
 * dropdowns can be pre-selected correctly when a form is re-rendered
 * after a failed submit.
 */
function selected_stall_for_layer(array $layers_by_stall, ?int $stall_layer_id): ?int
{
    if (!$stall_layer_id) {
        return null;
    }
    foreach ($layers_by_stall as $stall_id => $layers) {
        foreach ($layers as $l) {
            if ((int)$l['id'] === $stall_layer_id) {
                return (int)$stall_id;
            }
        }
    }
    return null;
}
