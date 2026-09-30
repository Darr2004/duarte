<?php
/**
 * Renders the cart drawer body fragment. Expects $cart_items (array of
 * items merged with 'qty_requested' and 'days_requested') to be in scope.
 * Used by requisition/cart_api.php so the drawer's HTML is generated in
 * one place, the same PHP-templating style as the rest of the app.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/uploads.php';
?>
<?php if (!$cart_items): ?>
  <div class="cart-empty">Your cart is empty.<br>Add items from the catalog to build a requisition.</div>
<?php else: ?>
  <span data-needs-truck="<?= !empty($drawer_needs_truck) ? '1' : '0' ?>" hidden></span>
  <?php foreach ($cart_items as $ci): ?>
    <div class="cart-line" data-item-id="<?= $ci['id'] ?>">
      <div class="thumb-mini">
        <?php if ($ci['image_filename']): ?>
          <img src="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($ci['image_filename'] ?? '')) ?>" alt="<?= htmlspecialchars((string)($ci['name'] ?? '')) ?>">
        <?php else: ?>
          <?= htmlspecialchars(item_initials($ci['name'])) ?>
        <?php endif; ?>
      </div>
      <div class="info">
        <div class="name"><?= htmlspecialchars((string)($ci['name'] ?? '')) ?><?php if (!empty($ci['variant_selected'])): ?> <span class="mono" style="color:var(--ink-soft); font-weight:400;">(<?= htmlspecialchars((string)($ci['variant_selected'] ?? '')) ?>)</span><?php endif; ?></div>
        <div class="meta">
          <?= (int)$ci['quantity_on_hand'] ?> <?= htmlspecialchars((string)($ci['unit'] ?? '')) ?> available
          <?php if ($ci['mode_requested'] === 'borrow'): ?> · borrow <?= (int)($ci['days_requested'] ?? 3) ?>d<?php elseif ($ci['borrow_mode'] === 'choice'): ?> · consume<?php endif; ?>
        </div>
        <div class="qty-controls">
          <button type="button" class="qty-dec" aria-label="Decrease quantity">&minus;</button>
          <span class="qty-num"><?= (int)$ci['qty_requested'] ?></span>
          <button type="button" class="qty-inc" aria-label="Increase quantity">&plus;</button>
        </div>
        <button type="button" class="remove-line">Remove</button>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
