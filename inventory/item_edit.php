<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/item_form.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['inventory_staff', 'admin']);

// Requested via fetch() from the Catalog Management modal instead of a
// full page load — same convention stock_in.php already uses. On success
// this responds with JSON instead of redirecting; on GET or a failed
// save it renders the same form markup as a fragment (no site chrome),
// meant to be injected straight into the modal body.
$is_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$pdo = get_db();
$user = current_user();
$is_admin = ($user['role'] ?? '') === 'admin';
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM items WHERE id = :id');
$stmt->execute(['id' => $id]);
$target = $stmt->fetch();

if (!$target) {
    http_response_code(404);
    die('Item not found.');
}

require_once __DIR__ . '/../includes/stock.php';

$errors = [];
$values = [
    'item_code'        => $target['item_code'],
    'name'             => $target['name'],
    'brand'            => $target['brand'],
    'description'      => $target['description'],
    'specification'    => $target['specification'],
    'category_id'      => $target['category_id'],
    'unit'             => $target['unit'],
    'quantity_on_hand' => $target['quantity_on_hand'],
    'variant_label'    => $target['variant_label'],
    'stall_layer_id'   => $target['stall_layer_id'],
];
$existing_variants = get_item_variants($pdo, $id);
// New rows the user is adding this time.
$new_variant_rows = [];
// Existing rows the user renamed/re-noted this time (id => ['value'=>..,'note'=>..]).
$edited_variants = [];
// Existing rows the user marked for removal this time (variant id list).
$delete_variant_ids = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $values['item_code']        = trim($_POST['item_code'] ?? '');
        $values['name']             = trim($_POST['name'] ?? '');
        $values['brand']            = trim($_POST['brand'] ?? '');
        $values['description']      = trim($_POST['description'] ?? '');
        $values['specification']    = trim($_POST['specification'] ?? '');
        $values['category_id']      = ($_POST['category_id'] ?? '') ?: null;
        $values['unit']             = trim($_POST['unit'] ?? 'pc') ?: 'pc';
        $values['variant_label']    = trim($_POST['variant_label'] ?? '');
        $values['stall_layer_id']   = ($_POST['stall_layer_id'] ?? '') ?: null;

        if (!$is_admin) {
            // Inventory staff can only update physical location, brand, description, and specs.
            // Master catalog rules (item_code, category_id, unit) are strictly locked to Admin.
            $values['item_code']   = $target['item_code'];
            $values['category_id'] = $target['category_id'];
            $values['unit']        = $target['unit'];
        }

        $borrow_mode = category_borrow_mode($pdo, $values['category_id'] ? (int)$values['category_id'] : null);
        $is_borrowable = borrow_mode_is_borrowable($borrow_mode) ? 1 : 0;
        $remove_image               = !empty($_POST['remove_image']);

        // Rows marked with the "Delete" toggle are disabled client-side, so
        // browsers omit their value/note/photo inputs entirely — we only
        // ever see their id show up here, in variant_delete[].
        $delete_variant_ids = array_map('intval', array_keys(array_filter($_POST['variant_delete'] ?? [])));
        $existing_by_id = [];
        foreach ($existing_variants as $v) {
            $existing_by_id[(int)$v['id']] = $v;
        }
        // Only ids that actually belong to this item are honored.
        $delete_variant_ids = array_values(array_intersect($delete_variant_ids, array_keys($existing_by_id)));

        // Guard against deleting an option that still has registered assets or active commitments
        if ($delete_variant_ids) {
            $asset_check_stmt = $pdo->prepare(
                "SELECT COUNT(*) c FROM assets WHERE item_variant_id = :vid AND status != 'disposed'"
            );
            foreach ($delete_variant_ids as $dvid) {
                $asset_check_stmt->execute(['vid' => $dvid]);
                $cnt = (int)$asset_check_stmt->fetch()['c'];
                $opt_name = $existing_by_id[$dvid]['variant_value'] ?? 'this';
                if ($cnt > 0) {
                    $errors[] = 'Cannot delete option "' . $opt_name . '": there are ' . $cnt . ' registered asset(s) linked to this option. Please reassign or dispose of the assets first.';
                }
                $committed_opt = reserved_stock_for($pdo, $id, $opt_name);
                if ($committed_opt > 0) {
                    $errors[] = 'Cannot delete option "' . $opt_name . '": there ' . ($committed_opt === 1 ? 'is' : 'are') . ' ' . $committed_opt . ' unit(s) committed to active approved requisition(s). Fulfill or cancel those requisitions first.';
                }
            }
        }

        // Renamed/re-noted existing options (everything not marked for delete).
        $posted_values = $_POST['variant_value_existing'] ?? [];
        $posted_notes  = $_POST['variant_note_existing'] ?? [];
        foreach ($existing_variants as $v) {
            $vid = (int)$v['id'];
            if (in_array($vid, $delete_variant_ids, true)) {
                continue;
            }
            $new_value = trim((string)($posted_values[$vid] ?? $v['variant_value']));
            $new_note  = trim((string)($posted_notes[$vid] ?? ($v['variant_note'] ?? '')));
            if ($new_value === '') {
                $errors[] = 'The ' . ($values['variant_label'] ?: 'option') . ' value "' . $v['variant_value'] . '" can\'t be blank — use Delete to remove it instead.';
                $new_value = $v['variant_value']; // keep something valid; save is blocked by $errors anyway
            } elseif ($new_value !== $v['variant_value']) {
                $committed_rename = reserved_stock_for($pdo, $id, $v['variant_value']);
                if ($committed_rename > 0) {
                    $errors[] = 'Cannot rename option "' . $v['variant_value'] . '": there ' . ($committed_rename === 1 ? 'is' : 'are') . ' ' . $committed_rename . ' unit(s) committed to active approved requisition(s). Fulfill or cancel those requisitions first.';
                }
            }
            $edited_variants[$vid] = ['value' => $new_value, 'note' => $new_note !== '' ? $new_note : null];
        }

        // Duplicate check across the (now possibly renamed) existing rows.
        $seen_lower_by_id = [];
        foreach ($edited_variants as $vid => $ev) {
            $low = mb_strtolower($ev['value']);
            $clash = array_search($low, $seen_lower_by_id, true);
            if ($clash !== false && $clash !== $vid) {
                $errors[] = 'Two options can\'t share the same value: "' . $ev['value'] . '".';
            }
            $seen_lower_by_id[$vid] = $low;
        }

        $existing_values = array_values(array_unique(array_map(fn($ev) => mb_strtolower($ev['value']), $edited_variants)));
        $parsed = parse_variant_submission($_POST, $existing_values, $values['variant_label']);
        $new_variant_rows = $parsed['rows'];
        $errors = array_merge($errors, $parsed['errors']);

        $has_any_variants = $edited_variants || $new_variant_rows;
        if ($has_any_variants && $values['variant_label'] === '') {
            $errors[] = 'Give the variant a label (e.g. "Size", "Amperage", "Color") since this item has options.';
        }
        if (!$has_any_variants) {
            $values['variant_label'] = '';
        }

        $errors = array_merge($errors, validate_item_common_fields($values));

        // Pending "Adjust stock" change staged via the modal — only present
        // when the item has no variants (the per-variant table has its own
        // adjust flow). Validated here so it blocks the save the same way
        // any other field error would; actually recorded further down,
        // inside the same transaction as the rest of the save.
        $adjust_quantity = null;
        $adjust_reason = trim($_POST['adjust_reason'] ?? '');
        if (!$existing_variants && isset($_POST['adjust_quantity']) && $_POST['adjust_quantity'] !== '') {
            $adjust_quantity = (int)$_POST['adjust_quantity'];
            if ($adjust_quantity < 0) {
                $errors[] = 'The adjusted quantity can\'t be negative.';
            } elseif ($adjust_quantity !== (int)$target['quantity_on_hand'] && $adjust_reason === '') {
                $errors[] = 'A reason is required for the pending stock adjustment.';
            } elseif ($adjust_quantity < (int)$target['quantity_on_hand']) {
                $safety = verify_stock_reduction_safety($pdo, $id, $adjust_quantity);
                if (!$safety['safe']) {
                    $errors[] = $safety['message'];
                }
            }
        }

        $new_image_filename = $target['image_filename'];
        $old_image_to_retire = null; // only deleted after a successful save
        if (!$errors) {
            try {
                $uploaded = handle_item_image_upload($_FILES['image'] ?? []);
                if ($uploaded) {
                    $old_image_to_retire = $target['image_filename'];
                    $new_image_filename = $uploaded;
                } elseif ($remove_image) {
                    $old_image_to_retire = $target['image_filename'];
                    $new_image_filename = null;
                }
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Per-option photos — one file input per existing variant row,
        // named variant_image[<variant_id>] / variant_remove_image[<variant_id>].
        $variant_new_images = [];          // variant_id => new filename to save (or null to clear)
        $variant_old_images_to_retire = []; // variant_id => old filename, deleted only after a successful save
        $variant_uploaded_this_request = []; // variant_id => filename, cleaned up if save fails
        if (!$errors) {
            foreach ($existing_variants as $v) {
                $vid = (int)$v['id'];
                if (in_array($vid, $delete_variant_ids, true)) {
                    continue; // being removed — no point handling its photo
                }
                $vfile = [
                    'name'     => $_FILES['variant_image']['name'][$vid] ?? '',
                    'type'     => $_FILES['variant_image']['type'][$vid] ?? '',
                    'tmp_name' => $_FILES['variant_image']['tmp_name'][$vid] ?? '',
                    'error'    => $_FILES['variant_image']['error'][$vid] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $_FILES['variant_image']['size'][$vid] ?? 0,
                ];
                $remove_this_variant_image = !empty($_POST['variant_remove_image'][$vid]);
                try {
                    $uploaded_v = handle_item_image_upload($vfile, 'variant_');
                    if ($uploaded_v) {
                        $variant_new_images[$vid] = $uploaded_v;
                        $variant_uploaded_this_request[$vid] = $uploaded_v;
                        if ($v['image_filename']) {
                            $variant_old_images_to_retire[$vid] = $v['image_filename'];
                        }
                    } elseif ($remove_this_variant_image && $v['image_filename']) {
                        $variant_new_images[$vid] = null;
                        $variant_old_images_to_retire[$vid] = $v['image_filename'];
                    }
                } catch (RuntimeException $e) {
                    $errors[] = $v['variant_value'] . ' photo: ' . $e->getMessage();
                }
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Handle removed options first: zero out their stock (so the
                // item's aggregate total stays correct) via the normal
                // stock-movement path, then delete the row. Any requisition
                // history referencing this option keeps its record — the
                // link just becomes NULL (see item_variants FK).
                foreach ($delete_variant_ids as $vid) {
                    $v = $existing_by_id[$vid];
                    if ((int)$v['quantity_on_hand'] > 0) {
                        record_stock_movement(
                            $pdo, $id, 'adjustment', -1 * (int)$v['quantity_on_hand'],
                            'variant_delete', null, current_user()['id'],
                            'Option "' . $v['variant_value'] . '" removed', $vid
                        );
                    }
                    $del_stmt = $pdo->prepare('DELETE FROM item_variants WHERE id = :vid AND item_id = :item_id');
                    $del_stmt->execute(['vid' => $vid, 'item_id' => $id]);
                    log_audit_event($pdo, current_user(), 'item_update', 'item', $id,
                        current_user()['full_name'] . ' removed option "' . $v['variant_value'] . '" from "' . $values['name'] . '".');
                    if ($v['image_filename']) {
                        $variant_old_images_to_retire[$vid] = $v['image_filename'];
                    }
                }

                $stmt = $pdo->prepare(
                    'UPDATE items SET item_code=:item_code, name=:name, brand=:brand, description=:description,
                     specification=:specification,
                     category_id=:category_id, unit=:unit, is_borrowable=:is_borrowable, borrow_mode=:borrow_mode,
                     variant_label=:variant_label,
                     image_filename=:image_filename, stall_layer_id=:stall_layer_id WHERE id=:id'
                );
                $stmt->execute([
                    'item_code'        => $values['item_code'],
                    'name'             => $values['name'],
                    'brand'            => $values['brand'] ?: null,
                    'description'      => $values['description'] ?: null,
                    'specification'    => $values['specification'] ?: null,
                    'category_id'      => $values['category_id'],
                    'unit'             => $values['unit'],
                    'is_borrowable'    => $is_borrowable,
                    'borrow_mode'      => $borrow_mode,
                    'variant_label'    => $values['variant_label'] ?: null,
                    'image_filename'   => $new_image_filename,
                    'stall_layer_id'   => $values['stall_layer_id'],
                    'id'               => $id,
                ]);

                if ($variant_new_images) {
                    $variant_img_upd = $pdo->prepare('UPDATE item_variants SET image_filename = :img WHERE id = :vid');
                    foreach ($variant_new_images as $vid => $img) {
                        $variant_img_upd->execute(['img' => $img, 'vid' => $vid]);
                    }
                }

                // Renamed/re-noted existing options — only write the ones
                // that actually changed, so we don't bump updated_at on
                // every row every time the form is saved.
                $variant_edit_upd = $pdo->prepare('UPDATE item_variants SET variant_value=:value, variant_note=:note WHERE id=:vid');
                foreach ($edited_variants as $vid => $ev) {
                    $orig = $existing_by_id[$vid];
                    if ($ev['value'] !== $orig['variant_value'] || $ev['note'] !== ($orig['variant_note'] ?? null)) {
                        $variant_edit_upd->execute(['value' => $ev['value'], 'note' => $ev['note'], 'vid' => $vid]);
                    }
                }

                if ($new_variant_rows) {
                    $variant_ins = $pdo->prepare(
                        'INSERT INTO item_variants (item_id, variant_value, variant_note, quantity_on_hand) VALUES (:item_id, :value, :note, 0)'
                    );
                    foreach ($new_variant_rows as $row) {
                        $variant_ins->execute(['item_id' => $id, 'value' => $row['value'], 'note' => $row['note']]);
                        if ($row['qty'] > 0) {
                            $variant_id = (int)$pdo->lastInsertId();
                            record_stock_movement(
                                $pdo, $id, 'stock_in', $row['qty'],
                                'initial_stock', null, current_user()['id'],
                                'Starting stock for new option (' . $row['value'] . ')', $variant_id
                            );
                        }
                    }
                }

                if ($adjust_quantity !== null) {
                    $before_qty = (int)$target['quantity_on_hand'];
                    $delta = $adjust_quantity - $before_qty;
                    if ($delta !== 0) {
                        $adj_result = record_stock_movement(
                            $pdo, $id, 'adjustment', $delta,
                            'manual', null, current_user()['id'], $adjust_reason, null
                        );
                        maybe_alert_stock_threshold($id, $adj_result['before'], $adj_result['after'], $adj_result['name'], $adj_result['unit']);
                        resolve_stock_alert_notifications($pdo, $id);
                        log_audit_event($pdo, current_user(), 'stock_adjustment', 'item', $id,
                            current_user()['full_name'] . ' ' . ($delta > 0 ? 'increased' : 'decreased') . ' stock of "' . $values['name'] . '" by ' . abs($delta) . ' (' . $before_qty . ' → ' . $adjust_quantity . '). Reason: ' . $adjust_reason);
                    }
                }

                log_audit_event($pdo, current_user(), 'item_update', 'item', $id,
                    current_user()['full_name'] . ' updated item "' . $values['name'] . '" (' . $values['item_code'] . ').');

                $pdo->commit();

                // Only now that the row is saved is it safe to remove the old files.
                if ($old_image_to_retire) {
                    delete_item_image($old_image_to_retire);
                }
                foreach ($variant_old_images_to_retire as $old_variant_filename) {
                    delete_item_image($old_variant_filename);
                }

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true]);
                    exit;
                }
                header('Location: ' . BASE_URL . '/inventory/items.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                // Save failed — clean up any newly uploaded files (item and
                // variant photos alike) instead of the old ones, so
                // everything keeps its original photo.
                if ($uploaded ?? null) {
                    delete_item_image($uploaded);
                }
                foreach ($variant_uploaded_this_request as $filename) {
                    delete_item_image($filename);
                }
                $new_image_filename = $target['image_filename'];
                $errors[] = ($e instanceof PDOException && $e->getCode() === '23000')
                    ? 'That item code is already in use.'
                    : 'Something went wrong. Please try again.';
            }
        }
    }
    $target['image_filename'] = $new_image_filename ?? $target['image_filename'];
}

$categories = get_categories_with_flags($pdo);
$stalls = get_stalls($pdo);
$layers_by_stall = get_stall_layers_grouped($pdo);
$selected_stall_id = selected_stall_for_layer($layers_by_stall, $values['stall_layer_id'] ? (int)$values['stall_layer_id'] : null);

$page_title = 'Edit Item';
if (!$is_ajax) {
    require __DIR__ . '/../includes/header.php';
}
?>
<?php if (!$is_ajax): ?>
<a href="<?= BASE_URL ?>/inventory/items.php" class="back-link">&larr; Back to Catalog Management</a>
<div class="page-header">
  <div>
    <div class="eyebrow">Digital inventory catalog</div>
    <h1>Edit item</h1>
  </div>
</div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<?php if (($_GET['recorded'] ?? '') === '1'): ?>
  <div class="alert alert-success">Stock adjustment recorded.</div>
<?php endif; ?>

<div class="card" style="max-width:900px;">
  <form method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div style="display:flex; gap:2rem; flex-wrap:wrap;">
    <div style="flex:1 1 340px;">
    <div class="form-section-title mt-0">Item details</div>
    <?php if ($is_admin): ?>
    <div class="form-group">
      <label for="item_code">Item code</label>
      <input type="text" id="item_code" name="item_code" value="<?= htmlspecialchars((string)($values['item_code'] ?? '')) ?>" required>
    </div>
    <?php else: ?>
      <input type="hidden" name="item_code" value="<?= htmlspecialchars((string)($values['item_code'] ?? '')) ?>">
    <?php endif; ?>
    <div class="form-group">
      <label for="name">Item name</label>
      <input type="text" id="name" name="name" value="<?= htmlspecialchars((string)($values['name'] ?? '')) ?>" required>
    </div>
    <div class="form-group">
      <label for="brand">Brand / Supplier</label>
      <input type="text" id="brand" name="brand" value="<?= htmlspecialchars($values['brand'] ?? '') ?>" placeholder='e.g. "ASUKI", "BOSCH"'>
    </div>
    <div class="form-group">
      <label for="description">Description</label>
      <textarea id="description" name="description" rows="3" style="width:100%; padding:0.55rem 0.7rem; border:1px solid var(--line); border-radius:6px; font-family:var(--font-body);"><?= htmlspecialchars($values['description'] ?? '') ?></textarea>
    </div>
    <div class="form-group hidden" id="specificationGroup" <?= $existing_variants ? 'data-has-existing="1" ' : '' ?>>
      <label for="specification">Specification</label>
      <input type="text" id="specification" name="specification" value="<?= htmlspecialchars($values['specification'] ?? '') ?>" placeholder='e.g. "Giga / Forward RH, FH 3T"'>
    </div>
    <?php if ($is_admin): ?>
    <div class="form-group" style="margin-bottom:0;">
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id">
        <option value="">— None —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" data-borrow-mode="<?= htmlspecialchars((string)($c['borrow_mode'] ?? '')) ?>" <?= $values['category_id'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($c['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
      <input type="hidden" name="category_id" value="<?= htmlspecialchars($values['category_id'] ?? '') ?>">
    <?php endif; ?>
    </div>

    <div style="flex:1 1 340px; border-left:1px solid var(--line); padding-left:2rem;">
    <div class="form-section-title mt-0">Stock, location &amp; photo</div>

    <div style="display:flex; gap:1rem;">
      <?php if ($is_admin): ?>
      <div class="form-group flex-1">
        <label for="unit_select">Unit</label>
        <select id="unit_select">
          <?php foreach (item_unit_options() as $val => $label): ?>
            <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
          <option value="__other__">Other (specify)…</option>
        </select>
        <input aria-label="Unit of measurement" type="text" id="unit_other" name="unit_other" placeholder="Enter unit" style="display:none; margin-top:0.5rem;">
        <input type="hidden" id="unit" name="unit" value="<?= htmlspecialchars((string)($values['unit'] ?? '')) ?>">
      </div>
      <?php else: ?>
        <input type="hidden" id="unit" name="unit" value="<?= htmlspecialchars((string)($values['unit'] ?? '')) ?>">
      <?php endif; ?>
      <div class="form-group flex-1">
        <label><?= $existing_variants ? 'Total on hand (all options)' : 'Quantity on hand' ?></label>
        <div id="onHandDisplay" style="padding:0.55rem 0 0.25rem; font-family:var(--font-mono);"><?= (int)$target['quantity_on_hand'] ?> <span id="onHandUnitLabel"><?= htmlspecialchars((string)($target['unit'] ?? '')) ?></span></div>
        <?php if (!$existing_variants): ?>
          <input type="hidden" id="adjustQuantityField" name="adjust_quantity" value="">
          <input type="hidden" id="adjustReasonField" name="adjust_reason" value="">
          <button type="button" class="btn btn-outline btn-sm" style="margin-top:0.15rem;" id="openStockAdjustModal">Adjust stock &rarr;</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-divider"></div>

    <div style="display:flex; gap:1rem;">
      <div class="form-group flex-1">
        <label for="stall_select">Room / Stall</label>
        <select id="stall_select">
          <option value="">— None —</option>
          <?php foreach ($stalls as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $selected_stall_id == $s['id'] ? 'selected' : '' ?>>Room <?= (int)$s['room_number'] ?> · Stall <?= (int)$s['stall_number'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group flex-1">
        <label for="stall_layer_id">Layer</label>
        <select id="stall_layer_id" name="stall_layer_id"></select>
      </div>
    </div>

    <input type="hidden" id="variant_label" name="variant_label" value="<?= htmlspecialchars($values['variant_label'] ?? '') ?>">

    <?php if ($existing_variants): ?>
      <div class="form-group">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
          <label class="m-0">Existing options</label>
          <button type="button" id="addVariantRow" class="btn btn-outline btn-sm">+ Add stock</button>
        </div>
        <div class="table-responsive">
        <table class="data" id="existingVariantsTable" style="margin-top:0.15rem;">
          <thead><tr><th style="min-width:160px;">Stock</th><th style="min-width:130px;">Quantity</th><th style="min-width:110px; text-align:right;"></th></tr></thead>
          <tbody>
            <?php
            $variant_modals_html = '';
            foreach ($existing_variants as $v):
              $vid = (int)$v['id'];
              $repop_value = htmlspecialchars($edited_variants[$vid]['value'] ?? $v['variant_value']);
              $repop_note  = htmlspecialchars($edited_variants[$vid]['note'] ?? ($v['variant_note'] ?? ''));
              $is_marked_delete = in_array($vid, $delete_variant_ids, true);
            ?>
              <tr class="variant-row<?= $is_marked_delete ? ' variant-row-deleted' : '' ?>" data-variant-id="<?= $vid ?>">
                <td data-label="Option">
                  <span class="variant-value-display" data-variant-id="<?= $vid ?>"><?= $repop_value ?></span>
                </td>
                <td class="mono" data-label="Stock"><?= (int)$v['quantity_on_hand'] ?> <?= htmlspecialchars((string)($values['unit'] ?? '')) ?></td>
                <td data-label="" class="variant-actions">
                  <button type="button" class="btn btn-sm btn-outline btn-icon variant-edit-toggle" data-variant-id="<?= $vid ?>" <?= $is_marked_delete ? 'disabled' : '' ?> title="Edit option" aria-label="Edit option">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                  </button>
                  <a href="<?= BASE_URL ?>/inventory/stock_adjust.php?item_id=<?= $id ?>&variant_id=<?= $vid ?>&return_to=item_edit" class="btn btn-outline btn-sm btn-icon variant-adjust-link<?= $is_marked_delete ? ' hidden' : '' ?>" title="Adjust stock" aria-label="Adjust stock">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 16 16 12 12 8"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                  </a>
                  <input type="hidden" name="variant_delete[<?= $vid ?>]" value="<?= $is_marked_delete ? '1' : '0' ?>" class="variant-delete-flag">
                  <button type="button" class="btn btn-sm btn-outline btn-icon variant-delete-toggle" data-variant-id="<?= $vid ?>" title="<?= $is_marked_delete ? 'Restore option' : 'Delete option' ?>" aria-label="<?= $is_marked_delete ? 'Restore option' : 'Delete option' ?>">
                    <svg class="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="<?= $is_marked_delete ? 'display:none;' : '' ?>"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    <svg class="icon-undo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="<?= $is_marked_delete ? '' : 'display:none;' ?>"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                  </button>
                </td>
              </tr>
            <?php
              ob_start();
              ?>
              <div class="variant-modal-backdrop" data-variant-id="<?= $vid ?>"></div>
              <div class="variant-modal" data-variant-id="<?= $vid ?>" role="dialog" aria-modal="true" aria-label="Edit option">
                <button type="button" class="variant-modal-close" data-variant-id="<?= $vid ?>" aria-label="Close">&times;</button>
                <div class="variant-modal-body">
                  <h3 class="variant-modal-title">Edit option</h3>
                  <div class="variant-card-field">
                    <label>Value</label>
                    <input type="text" name="variant_value_existing[<?= $vid ?>]" value="<?= $repop_value ?>" class="variant-edit-input variant-value-input" data-variant-id="<?= $vid ?>" style="width:100%;" <?= $is_marked_delete ? 'disabled' : '' ?>>
                  </div>
                  <div class="variant-card-field">
                    <label>Description</label>
                    <input aria-label="Variant description" type="text" name="variant_note_existing[<?= $vid ?>]" value="<?= $repop_note ?>" placeholder="—" class="variant-edit-input" style="width:100%;" <?= $is_marked_delete ? 'disabled' : '' ?>>
                  </div>
                  <div class="variant-card-field">
                    <label>Photo</label>
                    <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                      <?php if ($v['image_filename']): ?>
                        <img src="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($v['image_filename'] ?? '')) ?>" alt="Current photo for this variant" style="width:40px; height:40px; object-fit:cover; border-radius:6px; border:1px solid var(--line); flex-shrink:0;">
                      <?php endif; ?>
                      <input type="file" name="variant_image[<?= $vid ?>]" accept=".jpg,.jpeg,.png,.webp" style="max-width:180px; font-size:0.78rem;" <?= $is_marked_delete ? 'disabled' : '' ?>>
                      <?php if ($v['image_filename']): ?>
                        <label style="font-weight:400; font-size:0.78rem; display:flex; align-items:center; gap:0.25rem; white-space:nowrap; margin:0;">
                          <input type="checkbox" name="variant_remove_image[<?= $vid ?>]" value="1" style="width:auto;" <?= $is_marked_delete ? 'disabled' : '' ?>> Remove
                        </label>
                      <?php endif; ?>
                    </div>
                  </div>
                  <button type="button" class="btn btn-primary btn-sm variant-modal-done" data-variant-id="<?= $vid ?>">Done</button>
                </div>
              </div>
              <?php
              $variant_modals_html .= ob_get_clean();
            endforeach; ?>
          </tbody>
        </table>
        <?= $variant_modals_html ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="form-divider"></div>

    <div class="form-group" id="variantRowsGroup">
      <?php if (!$existing_variants): ?>
        <label>Options & starting stock</label>
      <?php endif; ?>
      <div id="variantRows"></div>
      <?php if (!$existing_variants): ?>
        <button type="button" id="addVariantRow" class="btn btn-outline btn-sm">+ Add stock</button>
      <?php endif; ?>
    </div>

    <div class="form-divider"></div>

    <div class="photo-panel">
      <div style="display:flex; gap:1rem; flex-wrap:wrap;">
        <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
          <label>Current photo</label>
          <?php if ($target['image_filename']): ?>
            <div style="display:flex; align-items:center; gap:0.75rem;">
              <img src="<?= ITEM_UPLOAD_URL . htmlspecialchars((string)($target['image_filename'] ?? '')) ?>" alt="Current photo of <?= htmlspecialchars((string)($target['name'] ?? '')) ?>" style="width:64px; height:64px; object-fit:cover; border-radius:6px; border:1px solid var(--line);">
              <label style="font-weight:400; display:flex; align-items:center; gap:0.35rem;">
                <input type="checkbox" name="remove_image" value="1" style="width:auto;"> Remove photo
              </label>
            </div>
          <?php else: ?>
            <span style="color:var(--ink-soft); font-size:0.88rem;">No photo uploaded.</span>
          <?php endif; ?>
        </div>
        <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
          <label for="image">Replace photo</label>
          <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary" id="itemEditSubmit" style="margin-top:1.1rem; width:100%;">Save changes</button>
    </div>
    </div>
  </form>
</div>

<?php if (!$existing_variants): ?>
<div class="variant-modal-backdrop" id="stockAdjustBackdrop"></div>
<div class="variant-modal" id="stockAdjustModal" role="dialog" aria-modal="true" aria-label="Adjust stock">
  <button type="button" class="variant-modal-close" id="stockAdjustClose" aria-label="Close">&times;</button>
  <div class="variant-modal-body">
    <h3 class="variant-modal-title">Adjust stock</h3>
    <div class="form-group">
      <label for="stockAdjustQty">Quantity</label>
      <div class="qty-stepper">
        <button type="button" class="qty-stepper-btn" id="stockAdjustMinus" aria-label="Lower the count">&minus;</button>
        <input type="text" inputmode="numeric" id="stockAdjustQty" value="<?= (int)$target['quantity_on_hand'] ?>">
        <button type="button" class="qty-stepper-btn" id="stockAdjustPlus" aria-label="Raise the count">&plus;</button>
      </div>
    </div>
    <div class="form-group">
      <label for="stockAdjustReason">Reason <span class="text-muted-normal">(required)</span></label>
      <input type="text" id="stockAdjustReason" placeholder="e.g. Damaged in yard">
      <p id="stockAdjustError" style="display:none; margin:0.35rem 0 0; font-size:0.82rem; color:var(--red-danger);"></p>
    </div>
    <button type="button" class="btn btn-primary" id="stockAdjustApply">Apply</button>
  </div>
</div>
<script>
  (function () {
    var openBtn = document.getElementById('openStockAdjustModal');
    var backdrop = document.getElementById('stockAdjustBackdrop');
    var modal = document.getElementById('stockAdjustModal');
    var closeBtn = document.getElementById('stockAdjustClose');
    var minusBtn = document.getElementById('stockAdjustMinus');
    var plusBtn = document.getElementById('stockAdjustPlus');
    var qtyInput = document.getElementById('stockAdjustQty');
    var reasonInput = document.getElementById('stockAdjustReason');
    var errorEl = document.getElementById('stockAdjustError');
    var applyBtn = document.getElementById('stockAdjustApply');
    var adjustQtyField = document.getElementById('adjustQuantityField');
    var adjustReasonField = document.getElementById('adjustReasonField');
    if (!openBtn || !backdrop || !modal) return;

    var originalQty = <?= (int)$target['quantity_on_hand'] ?>;

    function currentPendingQty() {
      return adjustQtyField.value !== '' ? parseInt(adjustQtyField.value, 10) : originalQty;
    }

    function openModal() {
      qtyInput.value = currentPendingQty();
      reasonInput.value = adjustReasonField.value || '';
      errorEl.style.display = 'none';
      backdrop.classList.add('open');
      modal.classList.add('open');
      document.body.classList.add('variant-modal-open');
    }
    function closeModal() {
      backdrop.classList.remove('open');
      modal.classList.remove('open');
      document.body.classList.remove('variant-modal-open');
    }
    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });
    minusBtn.addEventListener('click', function () {
      var v = parseInt(qtyInput.value, 10);
      if (isNaN(v)) v = 0;
      qtyInput.value = Math.max(0, v - 1);
    });
    plusBtn.addEventListener('click', function () {
      var v = parseInt(qtyInput.value, 10);
      if (isNaN(v)) v = 0;
      qtyInput.value = v + 1;
    });

    // Stages the change into the main form's hidden fields and updates the
    // on-page display right away — nothing is sent to the server here.
    // The actual stock movement is only recorded when the main "Save
    // changes" button submits the whole form.
    applyBtn.addEventListener('click', function () {
      var typed = parseInt(qtyInput.value, 10);
      if (isNaN(typed) || typed < 0) {
        errorEl.textContent = 'Enter a valid quantity.';
        errorEl.style.display = '';
        return;
      }
      var reason = reasonInput.value.trim();
      if (typed !== originalQty && reason === '') {
        errorEl.textContent = 'A reason is required when changing the quantity.';
        errorEl.style.display = '';
        return;
      }

      if (typed === originalQty) {
        adjustQtyField.value = '';
        adjustReasonField.value = '';
      } else {
        adjustQtyField.value = String(typed);
        adjustReasonField.value = reason;
      }
      if (window.refreshOnHandDisplay) window.refreshOnHandDisplay();
      closeModal();
    });
  })();
</script>
<?php endif; ?>

<script>
  window.ItemFormData = {
    layersByStall: <?= json_encode($layers_by_stall) ?>,
    selectedLayerId: <?= json_encode($values['stall_layer_id'] ? (int)$values['stall_layer_id'] : null) ?>,
    currentUnit: <?= json_encode($values['unit']) ?>
  };
  window.ItemFormPrefillVariantRows = <?= json_encode($new_variant_rows) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/item-form.js"></script>

<script>
  // Keeps the "Quantity on hand" unit label in sync with whatever's
  // currently picked in the Unit dropdown (or the "Other" text field),
  // including while a stock adjustment is still pending. Nothing here
  // touches the server — the unit itself is only actually saved when the
  // whole form is submitted via "Save changes", same as every other field.
  (function () {
    var onHandDisplay = document.getElementById('onHandDisplay');
    var unitHidden = document.getElementById('unit');
    var adjustQtyField = document.getElementById('adjustQuantityField');
    var originalQty = <?= (int)$target['quantity_on_hand'] ?>;
    var originalUnit = <?= json_encode($target['unit']) ?>;
    if (!onHandDisplay || !unitHidden) return;

    function currentUnit() {
      return unitHidden.value !== '' ? unitHidden.value : originalUnit;
    }

    window.refreshOnHandDisplay = function () {
      var unit = currentUnit();
      if (adjustQtyField && adjustQtyField.value !== '') {
        onHandDisplay.innerHTML = originalQty + ' \u2192 <strong>' + adjustQtyField.value + '</strong> ' +
          '<span id="onHandUnitLabel">' + unit + '</span> ' +
          '<span style="font-size:0.78rem; font-family:var(--font-body); color:var(--amber-dim);">(pending \u2014 click "Save changes")</span>';
      } else {
        onHandDisplay.innerHTML = originalQty + ' <span id="onHandUnitLabel">' + unit + '</span>';
      }
    };

    var unitSelect = document.getElementById('unit_select');
    var unitOther = document.getElementById('unit_other');
    // setTimeout(..., 0) defers to right after item-form.js's own change
    // handler (which writes the picked unit into the hidden #unit field),
    // regardless of which listener happened to be attached first.
    if (unitSelect) unitSelect.addEventListener('change', function () { setTimeout(window.refreshOnHandDisplay, 0); });
    if (unitOther) unitOther.addEventListener('input', function () { setTimeout(window.refreshOnHandDisplay, 0); });
  })();
</script>

<script>
  // Delete/Undo toggle for existing options (Value/Note/Photo edits already
  // save normally with the rest of the form — this just marks a row so the
  // server removes it on submit, instead of deleting it right away).
  (function () {
    var table = document.getElementById('existingVariantsTable');
    if (!table) return;

    function getModal(vid) {
      return {
        backdrop: document.querySelector('.variant-modal-backdrop[data-variant-id="' + vid + '"]'),
        modal: document.querySelector('.variant-modal[data-variant-id="' + vid + '"]')
      };
    }

    function openVariantModal(vid) {
      var m = getModal(vid);
      if (!m.modal) return;
      m.backdrop.classList.add('open');
      m.modal.classList.add('open');
      document.body.classList.add('variant-modal-open');
    }

    function closeVariantModal(vid) {
      var m = getModal(vid);
      if (!m.modal) return;
      m.backdrop.classList.remove('open');
      m.modal.classList.remove('open');
      document.body.classList.remove('variant-modal-open');
    }

    function setRowDeleted(row, deleted) {
      row.classList.toggle('variant-row-deleted', deleted);
      row.style.display = deleted ? 'none' : '';
      row.querySelector('.variant-delete-flag').value = deleted ? '1' : '0';

      var adjustLink = row.querySelector('.variant-adjust-link');
      if (adjustLink) adjustLink.style.display = deleted ? 'none' : '';
      var editToggle = row.querySelector('.variant-edit-toggle');
      if (editToggle) editToggle.disabled = deleted;
      var toggleBtn = row.querySelector('.variant-delete-toggle');
      var trashIcon = toggleBtn.querySelector('.icon-trash');
      var undoIcon = toggleBtn.querySelector('.icon-undo');
      if (trashIcon) trashIcon.style.display = deleted ? 'none' : '';
      if (undoIcon) undoIcon.style.display = deleted ? '' : 'none';
      toggleBtn.title = deleted ? 'Restore option' : 'Delete option';
      toggleBtn.setAttribute('aria-label', toggleBtn.title);

      // Keep the modal (Value/Note/Photo) in sync: disable its fields
      // and close it back up if it was left open.
      var vid = row.dataset.variantId;
      var m = getModal(vid);
      if (m.modal) {
        m.modal.querySelectorAll('.variant-edit-input, input[type="file"], input[type="checkbox"]').forEach(function (el) {
          el.disabled = deleted;
        });
        if (deleted) closeVariantModal(vid);
      }
    }

    table.querySelectorAll('.variant-edit-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openVariantModal(btn.dataset.variantId);
      });
    });

    document.querySelectorAll('.variant-modal-close, .variant-modal-done').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeVariantModal(btn.dataset.variantId);
      });
    });

    document.querySelectorAll('.variant-modal-backdrop').forEach(function (backdrop) {
      backdrop.addEventListener('click', function () {
        closeVariantModal(backdrop.dataset.variantId);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.variant-modal.open').forEach(function (modal) {
          closeVariantModal(modal.dataset.variantId);
        });
      }
    });

    // Keep the read-only value shown in the table row in sync with what's
    // typed inside the modal, without letting the row itself be edited.
    document.querySelectorAll('.variant-value-input').forEach(function (input) {
      input.addEventListener('input', function () {
        var vid = input.dataset.variantId;
        var display = table.querySelector('.variant-value-display[data-variant-id="' + vid + '"]');
        if (display) display.textContent = input.value;
      });
    });

    table.querySelectorAll('.variant-delete-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = btn.closest('.variant-row');
        var willDelete = row.querySelector('.variant-delete-flag').value !== '1';
        setRowDeleted(row, willDelete);
      });
    });

    var form = table.closest('form');
    var submitBtn = document.getElementById('itemEditSubmit');
    if (form && submitBtn) {
      form.addEventListener('submit', function (e) {
        var markedCount = Array.prototype.filter.call(
          table.querySelectorAll('.variant-delete-flag'),
          function (el) { return el.value === '1'; }
        ).length;
        if (markedCount > 0) {
          var word = markedCount === 1 ? 'option' : 'options';
          if (!confirm('This will permanently remove ' + markedCount + ' ' + word + ' and its stock record. Continue?')) {
            e.preventDefault();
          }
        }
      });
    }
  })();
</script>

<?php if (!$is_ajax): ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
