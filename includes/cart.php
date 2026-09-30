<?php
/**
 * DuaRTE — Requisition cart (session-based).
 * Personnel add items here while browsing the catalog, then
 * submits everything at once as a single requisition.
 *
 * Each entry is ['qty' => int, 'mode' => 'consume'|'borrow'|null, 'days' => int|null, 'variant' => string|null].
 * `mode` is the requester's actual pick for this line: forced to
 * 'consume'/'borrow' for items whose catalog borrow_mode is fixed,
 * or freely chosen for items whose catalog borrow_mode is 'choice'.
 * `days` is only meaningful when mode is 'borrow' — how many days the
 * requester expects to need it, used to set the due date once it's
 * released. `variant` is the picked option (e.g. a size, amperage, or
 * color) for items that have variant_label set — each option now has
 * its own stock count, tracked in the item_variants table.
 */

function cart_get(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    return count(cart_get());
}

function cart_add(int $item_id, int $qty, ?string $mode = null, ?int $days = null, ?string $variant = null): void
{
    if ($qty < 1) {
        return;
    }
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    $existing = $_SESSION['cart'][$item_id] ?? [];
    $same_variant = empty($existing) || (($variant ?? null) === ($existing['variant'] ?? null));
    $new_qty = $same_variant ? (($existing['qty'] ?? 0) + $qty) : $qty;

    $_SESSION['cart'][$item_id] = [
        'qty'     => $new_qty,
        'mode'    => $mode ?? ($existing['mode'] ?? null),
        'days'    => $days ?? ($existing['days'] ?? null),
        'variant' => $variant ?? ($existing['variant'] ?? null),
    ];
}

function cart_update(int $item_id, int $qty, ?string $mode = null, ?int $days = null, ?string $variant = null): void
{
    if ($qty < 1) {
        cart_remove($item_id);
        return;
    }
    $existing = $_SESSION['cart'][$item_id] ?? [];
    $_SESSION['cart'][$item_id] = [
        'qty'     => $qty,
        'mode'    => $mode ?? ($existing['mode'] ?? null),
        'days'    => $days ?? ($existing['days'] ?? null),
        'variant' => $variant ?? ($existing['variant'] ?? null),
    ];
}

function cart_remove(int $item_id): void
{
    unset($_SESSION['cart'][$item_id]);
}

function cart_clear(): void
{
    $_SESSION['cart'] = [];
}
