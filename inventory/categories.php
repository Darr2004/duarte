<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$is_admin = ($user['role'] ?? '') === 'admin';
$errors = [];
$flash_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_admin) {
        log_audit_event($pdo, $user, 'category_mutation_blocked', 'category', 0,
            $user['full_name'] . ' attempted to modify categories — blocked (Admin only).');
        $errors[] = 'Permission Denied: Category management is restricted to Administrators.';
    } elseif (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['add_name'])) {
        $name = trim($_POST['add_name']);
        $borrow_mode = in_array($_POST['add_borrow_mode'] ?? '', ['consume', 'borrow', 'choice'], true)
            ? $_POST['add_borrow_mode'] : 'consume';
        $is_equipment = $borrow_mode === 'borrow' ? 1 : 0; // kept in sync for legacy readers
        $code_prefix = strtoupper(trim($_POST['add_code_prefix'] ?? ''));
        $threshold_raw = trim($_POST['add_low_stock_threshold'] ?? '');
        $low_stock_threshold = $threshold_raw === '' ? DEFAULT_STOCK_ALERT_THRESHOLD : (int)$threshold_raw;
        $requires_truck = !empty($_POST['add_requires_truck']) ? 1 : 0;
        if ($name === '') {
            $errors[] = 'Category name is required.';
        } elseif ($code_prefix !== '' && !preg_match('/^[A-Z0-9]{1,10}$/', $code_prefix)) {
            $errors[] = 'Item code prefix can only contain letters and numbers (max 10 characters).';
        } elseif ($threshold_raw !== '' && (!ctype_digit($threshold_raw) || $low_stock_threshold < 0)) {
            $errors[] = 'Stock alert threshold must be a whole number of 0 or more.';
        } else {
            if ($code_prefix === '') {
                $code_prefix = derive_code_prefix_from_name($name);
            }
            try {
                $stmt = $pdo->prepare('INSERT INTO categories (name, code_prefix, is_equipment, borrow_mode, low_stock_threshold, requires_truck) VALUES (:name, :code_prefix, :is_equipment, :borrow_mode, :threshold, :truck)');
                $stmt->execute(['name' => $name, 'code_prefix' => $code_prefix, 'is_equipment' => $is_equipment, 'borrow_mode' => $borrow_mode, 'threshold' => $low_stock_threshold, 'truck' => $requires_truck]);
                log_audit_event($pdo, current_user(), 'category_create', 'category', (int)$pdo->lastInsertId(),
                    current_user()['full_name'] . ' added category "' . $name . '" (item codes: ' . $code_prefix . '-xxx, '
                    . category_borrow_mode_label($borrow_mode) . ', stock alert at ' . $low_stock_threshold . ', requires truck: ' . ($requires_truck ? 'Yes' : 'No') . ').');
                $flash_success = 'Category added.';
            } catch (PDOException $e) {
                $errors[] = ($e->getCode() === '23000')
                    ? 'That category already exists.'
                    : 'Something went wrong. Please try again.';
            }
        }
    } elseif (isset($_POST['update_category_id'])) {
        $id = (int)$_POST['update_category_id'];
        $code_prefix = strtoupper(trim($_POST['code_prefix'] ?? ''));
        $threshold_raw = trim($_POST['low_stock_threshold'] ?? '');
        $borrow_mode = in_array($_POST['borrow_mode'] ?? '', ['consume', 'borrow', 'choice'], true)
            ? $_POST['borrow_mode'] : 'consume';
        $requires_truck = !empty($_POST['requires_truck']) ? 1 : 0;

        if ($code_prefix === '' || !preg_match('/^[A-Z0-9]{1,10}$/', $code_prefix)) {
            $errors[] = 'Item code prefix can only contain letters and numbers (max 10 characters).';
        } elseif ($threshold_raw === '' || !ctype_digit($threshold_raw)) {
            $errors[] = 'Stock alert threshold must be a whole number of 0 or more.';
        } else {
            $low_stock_threshold = (int)$threshold_raw;
            $is_equipment = $borrow_mode === 'borrow' ? 1 : 0; // kept in sync for legacy readers

            $stmt = $pdo->prepare('UPDATE categories SET code_prefix = :prefix, low_stock_threshold = :threshold, borrow_mode = :mode, is_equipment = :is_equipment, requires_truck = :truck WHERE id = :id');
            $stmt->execute(['prefix' => $code_prefix, 'threshold' => $low_stock_threshold, 'mode' => $borrow_mode, 'is_equipment' => $is_equipment, 'truck' => $requires_truck, 'id' => $id]);

            // Cascade the borrow/consume setting to every item already filed
            // under this category, so the catalog badge/behavior matches the
            // category setting immediately instead of only on the item's next edit.
            $is_borrowable = borrow_mode_is_borrowable($borrow_mode) ? 1 : 0;
            $cascade = $pdo->prepare('UPDATE items SET borrow_mode = :mode, is_borrowable = :is_borrowable WHERE category_id = :id');
            $cascade->execute(['mode' => $borrow_mode, 'is_borrowable' => $is_borrowable, 'id' => $id]);
            $affected = $cascade->rowCount();

            $cat_stmt = $pdo->prepare('SELECT name FROM categories WHERE id = :id');
            $cat_stmt->execute(['id' => $id]);
            $cat_name = $cat_stmt->fetchColumn();
            if ($cat_name) {
                log_audit_event($pdo, current_user(), 'category_update', 'category', $id,
                    current_user()['full_name'] . ' updated category "' . $cat_name . '" (item codes: ' . $code_prefix
                    . '-xxx, stock alert at ' . $low_stock_threshold . ', ' . category_borrow_mode_label($borrow_mode)
                    . ($affected > 0 ? ", {$affected} item(s) updated" : '') . ').');
            }
            $flash_success = 'Category updated' . ($affected > 0 ? " — {$affected} item(s) in this category now use " . category_borrow_mode_label($borrow_mode) . '.' : '.');
        }
    } elseif (isset($_POST['sync_all'])) {
        // One-click safety net: matches every item's borrow/consume setting
        // to its category's CURRENT setting right now, without needing to
        // reopen and re-save each category one by one.
        $sync = $pdo->query(
            "UPDATE items i
             INNER JOIN categories c ON i.category_id = c.id
             SET i.borrow_mode = c.borrow_mode,
                 i.is_borrowable = CASE WHEN c.borrow_mode IN ('borrow','choice') THEN 1 ELSE 0 END"
        );
        $affected = $sync->rowCount();
        log_audit_event($pdo, current_user(), 'category_update', 'category', 0,
            current_user()['full_name'] . ' synced all items to their category settings (' . $affected . ' item(s) updated).');
        $flash_success = $affected > 0
            ? "Done — {$affected} item(s) updated to match their category's current setting."
            : 'All items already match their category settings — nothing to update.';
    } elseif (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $in_use = $pdo->prepare('SELECT COUNT(*) c FROM items WHERE category_id = :id');
        $in_use->execute(['id' => $id]);
        if ($in_use->fetch()['c'] > 0) {
            $errors[] = 'This category is still assigned to one or more items and cannot be deleted.';
        } else {
            $cat_stmt = $pdo->prepare('SELECT name FROM categories WHERE id = :id');
            $cat_stmt->execute(['id' => $id]);
            $cat_name = $cat_stmt->fetchColumn();

            $del = $pdo->prepare('DELETE FROM categories WHERE id = :id');
            $del->execute(['id' => $id]);
            log_audit_event($pdo, current_user(), 'category_delete', 'category', $id,
                current_user()['full_name'] . ' deleted category "' . ($cat_name ?: $id) . '".');
            $flash_success = 'Category deleted.';
        }
    }
}

$categories = $pdo->query(
    "SELECT c.id, c.name, c.code_prefix, c.borrow_mode, c.low_stock_threshold, c.requires_truck, COUNT(i.id) AS item_count
     FROM categories c LEFT JOIN items i ON i.category_id = c.id
     GROUP BY c.id, c.name, c.code_prefix, c.borrow_mode, c.low_stock_threshold, c.requires_truck ORDER BY c.name"
)->fetchAll();

$page_title = 'Categories';
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= BASE_URL ?>/inventory/items.php" class="back-link">&larr; Back to Catalog Management</a>
<div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; flex-wrap:wrap;">
  <div>
    <div class="eyebrow">Digital inventory catalog</div>
    <h1>Categories</h1>
  </div>
  <?php if ($is_admin): ?>
    <form method="post" onsubmit="return confirm('Synchronize all items to match their category rules?');" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="sync_all" value="1">
      <button type="submit" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:0.4rem;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Sync Category Rules
      </button>
    </form>
  <?php else: ?>
    <span class="badge" style="align-self:center; font-size:0.85rem; padding:0.45rem 0.85rem; background:var(--bg-subtle, #f1f5f9); color:var(--ink-soft); border:1px solid var(--line);">
      👁️ View-only
    </span>
  <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php if ($flash_success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<style>
.cat-add-grid-1 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
.cat-add-grid-2 { display: grid; grid-template-columns: 1.5fr 1.5fr auto; gap: 1rem; align-items: end; }
@media (max-width: 880px) {
  .cat-add-grid-1, .cat-add-grid-2 { grid-template-columns: 1fr !important; gap: 0.85rem !important; }
}
</style>

<?php if ($is_admin): ?>
<div class="card" style="margin-bottom:1.5rem; padding:1.25rem 1.5rem;">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; padding-bottom:0.75rem; border-bottom:1px solid var(--border-soft, #f0e6d9);">
      <div style="display:flex; align-items:center; gap:0.6rem;">
        <div style="width:32px; height:32px; border-radius:6px; background:var(--panel-soft, #f4ede2); color:var(--accent, #9e5b10); display:flex; align-items:center; justify-content:center;">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= nav_icon('categories') ?></svg>
        </div>
        <div>
          <div style="font-weight:700; font-size:0.98rem; color:var(--ink);">Add New Category</div>
          <div style="font-size:0.78rem; color:var(--ink-soft);">Set borrowing and truck rules.</div>
        </div>
      </div>
    </div>

    <div class="cat-add-grid-1">
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_name" style="font-weight:600; font-size:0.82rem; margin-bottom:0.35rem;">Category Name <span style="color:var(--danger, #c0392b);">*</span></label>
        <input type="text" id="add_name" name="add_name" placeholder="e.g. Rigging" required style="width:100%;">
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_code_prefix" style="font-weight:600; font-size:0.82rem; margin-bottom:0.35rem;">Code Prefix</label>
        <input type="text" id="add_code_prefix" name="add_code_prefix" placeholder="e.g. RIG" maxlength="10" style="width:100%; text-transform:uppercase;" class="mono">
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_low_stock_threshold" style="font-weight:600; font-size:0.82rem; margin-bottom:0.35rem;">Stock Alert Level</label>
        <input type="number" id="add_low_stock_threshold" name="add_low_stock_threshold" placeholder="<?= DEFAULT_STOCK_ALERT_THRESHOLD ?>" min="0" step="1" class="mono" style="width:100%;">
      </div>
    </div>

    <div class="cat-add-grid-2">
      <div class="form-group" style="margin-bottom:0;">
        <label for="add_borrow_mode" style="font-weight:600; font-size:0.82rem; margin-bottom:0.35rem;">Issuance Type</label>
        <select id="add_borrow_mode" name="add_borrow_mode" style="width:100%; padding:0.55rem;">
          <option value="consume" selected>Consumable (Non-Returnable)</option>
          <option value="borrow">Equipment (Returnable)</option>
          <option value="choice">Borrow or Consume</option>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:0;">
        <label style="display:flex; align-items:center; gap:0.65rem; padding:0.45rem 0.85rem; border:1px solid var(--border, #d8c9b3); border-radius:6px; background:var(--panel-soft, #faf7f2); cursor:pointer; min-height:40px;">
          <input type="checkbox" name="add_requires_truck" value="1" style="width:16px; height:16px; margin:0; cursor:pointer;">
          <div>
            <div style="font-weight:600; font-size:0.84rem; color:var(--ink); line-height:1.2;">Mandatory Truck Link</div>
            <div style="font-size:0.72rem; color:var(--ink-soft); line-height:1.2;">Requires truck plate</div>
          </div>
        </label>
      </div>

      <div style="margin-bottom:0;">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.6rem 1.4rem; white-space:nowrap; font-weight:600;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Category
        </button>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
<table class="data">
    <thead>
      <tr>
        <th>Name</th>
        <th>Code Prefix</th>
        <th>Stock Alert At</th>
        <th>Issuance Type</th>
        <th>Vehicle Requirement</th>
        <th>Catalog Items</th>
        <?php if ($is_admin): ?><th>Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (!$categories): ?>
        <tr><td colspan="<?= $is_admin ? 7 : 6 ?>">No categories yet.</td></tr>
      <?php else: $row_forms = ''; foreach ($categories as $c): ?>
        <?php
        if ($is_admin) {
            $formId = 'catForm' . $c['id'];
            $row_forms .= '<form id="' . $formId . '" method="post" onsubmit="return confirm(\'Save changes to \\\'' . addslashes(htmlspecialchars((string)($c['name'] ?? ''))) . '\\\'?\');"></form>';
        }
        ?>
        <tr>
          <td data-label="Name" style="font-weight:600;"><?= htmlspecialchars((string)($c['name'] ?? '')) ?></td>
          <td data-label="Code Prefix">
            <?php if ($is_admin): ?>
              <input type="hidden" form="<?= $formId ?>" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" form="<?= $formId ?>" name="update_category_id" value="<?= $c['id'] ?>">
              <input aria-label="Code prefix: <?= htmlspecialchars((string)($c['name'] ?? '')) ?>" type="text" form="<?= $formId ?>" name="code_prefix" value="<?= htmlspecialchars($c['code_prefix'] ?? '') ?>" maxlength="10" style="width:90px; text-transform:uppercase;" class="mono">
              <span style="font-size:0.78rem; color:var(--ink-soft);">e.g. <?= htmlspecialchars($c['code_prefix'] ?: '—') ?>-001</span>
            <?php else: ?>
              <span class="mono" style="font-weight:700; font-size:0.88rem;"><?= htmlspecialchars($c['code_prefix'] ?: '—') ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Stock Alert At">
            <?php if ($is_admin): ?>
              <input aria-label="Low stock threshold: <?= htmlspecialchars((string)($c['name'] ?? '')) ?>" type="number" form="<?= $formId ?>" name="low_stock_threshold" value="<?= (int)$c['low_stock_threshold'] ?>" min="0" step="1" style="width:70px;" class="mono">
              <span style="font-size:0.78rem; color:var(--ink-soft);">&le; <?= (int)$c['low_stock_threshold'] ?> units</span>
            <?php else: ?>
              <span class="mono">&le; <?= (int)$c['low_stock_threshold'] ?> units</span>
            <?php endif; ?>
          </td>
          <td data-label="Issuance Type">
            <?php if ($is_admin): ?>
              <select aria-label="Borrow mode: <?= htmlspecialchars((string)($c['name'] ?? '')) ?>" form="<?= $formId ?>" name="borrow_mode" style="padding:0.3rem;">
                <option value="consume" <?= $c['borrow_mode'] === 'consume' ? 'selected' : '' ?>>Consumable</option>
                <option value="borrow" <?= $c['borrow_mode'] === 'borrow' ? 'selected' : '' ?>>Equipment (Returnable)</option>
                <option value="choice" <?= $c['borrow_mode'] === 'choice' ? 'selected' : '' ?>>Borrow or Consume</option>
              </select>
            <?php else: ?>
              <span class="badge" style="font-size:0.78rem;"><?= category_borrow_mode_label($c['borrow_mode']) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Vehicle Requirement">
            <?php if ($is_admin): ?>
              <label style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.84rem; cursor:pointer;">
                <input type="checkbox" form="<?= $formId ?>" name="requires_truck" value="1" <?= !empty($c['requires_truck']) ? 'checked' : '' ?>>
                <span class="badge <?= !empty($c['requires_truck']) ? 'role' : '' ?>" style="font-size:0.74rem;">
                  <?= !empty($c['requires_truck']) ? 'Vehicle Required' : 'General Stock' ?>
                </span>
              </label>
            <?php else: ?>
              <span class="badge <?= !empty($c['requires_truck']) ? 'role' : '' ?>" style="font-size:0.74rem;">
                <?= !empty($c['requires_truck']) ? 'Vehicle Required' : 'General Stock' ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="mono" data-label="Catalog Items"><?= (int)$c['item_count'] ?></td>
          <?php if ($is_admin): ?>
            <td style="white-space:nowrap;" data-label="Actions">
              <button type="submit" form="<?= $formId ?>" class="btn btn-outline btn-sm">Save</button>
              <form method="post" onsubmit="return confirm('Delete category <?= htmlspecialchars(addslashes($c['name'])) ?>?');" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" <?= $c['item_count'] > 0 ? 'disabled title="In use — cannot delete"' : '' ?>>Delete</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</div>
<?= $row_forms ?? '' ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
