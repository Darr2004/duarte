<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['inventory_staff', 'admin']);

$pdo = get_db();
$user = current_user();
$flash_success = null;
$flash_error = null;

// Upload a photo for one item (Shopee-style "click into it, one item at a time").
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $flash_error = 'Your session expired. Please try again.';
    } else {
        $item_id = (int)$_POST['item_id'];
        $stmt = $pdo->prepare("SELECT id, name, image_filename FROM items WHERE id = :id");
        $stmt->execute(['id' => $item_id]);
        $target = $stmt->fetch();

        if (!$target) {
            $flash_error = 'That item could not be found.';
        } else {
            try {
                $uploaded = handle_item_image_upload($_FILES['image'] ?? []);
                if (!$uploaded) {
                    $flash_error = 'Please choose a photo to upload.';
                } else {
                    $upd = $pdo->prepare("UPDATE items SET image_filename = :fn WHERE id = :id");
                    $upd->execute(['fn' => $uploaded, 'id' => $item_id]);
                    log_audit_event($pdo, $user, 'item_photo_uploaded', 'item', $item_id,
                        $user['full_name'] . ' added a catalog photo for ' . $target['name'] . '.');
                    $flash_success = 'Photo saved for ' . $target['name'] . '.';
                }
            } catch (RuntimeException $e) {
                $flash_error = $e->getMessage();
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT i.id, i.item_code, i.name, i.brand, i.unit, c.name AS category_name
        FROM items i
        LEFT JOIN categories c ON c.id = i.category_id
        WHERE i.status = 'active' AND (i.image_filename IS NULL OR i.image_filename = '')";
$params = [];
if ($search !== '') {
    $sql .= " AND (i.name LIKE :q1 OR i.item_code LIKE :q2 OR i.brand LIKE :q3)";
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
}
$sql .= " ORDER BY c.name ASC, i.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$missing_items = $stmt->fetchAll();

$total_active = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE status = 'active'")->fetchColumn();
$total_missing = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE status = 'active' AND (image_filename IS NULL OR image_filename = '')")->fetchColumn();
$total_with_photo = $total_active - $total_missing;

$page_title = 'Missing Catalog Photos';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Catalog Management</div>
    <h1>Missing Catalog Photos</h1>
  </div>
  <a href="<?= BASE_URL ?>/inventory/items.php" class="btn btn-outline btn-sm">Back to Catalog Management</a>
</div>

<div class="alert" style="background:var(--surface-subtle); border:1px solid var(--line); margin-bottom:1rem;">
  <strong><?= $total_with_photo ?></strong> of <strong><?= $total_active ?></strong> active items have a photo —
  <strong><?= $total_missing ?></strong> left. Personnel see the placeholder icon in the app for any item still on this list.
</div>

<?php if ($flash_error): ?><div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>
<?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>

<form method="get" class="filter-bar" style="margin-bottom:1rem;">
  <div class="form-group grow">
    <label for="q">Search</label>
    <input type="text" id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search items without photo">
  </div>
  <?php if ($search !== ''): ?>
    <a href="<?= BASE_URL ?>/inventory/bulk_photos.php" class="filter-clear-btn">Clear</a>
  <?php endif; ?>
</form>

<?php if (!$missing_items): ?>
  <div class="empty-state">
    <?= $search !== '' ? 'No results found.' : 'All items have photos.' ?>
  </div>
<?php else: ?>
  <div class="catalog-grid">
    <?php foreach ($missing_items as $it): ?>
      <div class="item-card" style="cursor:default;">
        <div class="thumb photo-upload-thumb" id="thumb-<?= $it['id'] ?>">
          <span class="placeholder"><?= htmlspecialchars(item_initials($it['name'])) ?></span>
        </div>
        <div class="body">
          <div class="code"><?= htmlspecialchars((string)($it['item_code'] ?? '')) ?></div>
          <div class="name"><?= htmlspecialchars((string)($it['name'] ?? '')) ?><?php if (!empty($it['brand'])): ?> <span class="brand-tag"><?= htmlspecialchars((string)($it['brand'] ?? '')) ?></span><?php endif; ?></div>
          <div class="cat"><?= htmlspecialchars($it['category_name'] ?? 'Uncategorized') ?></div>

          <form method="post" enctype="multipart/form-data" class="photo-upload-form" style="margin-top:0.6rem; display:flex; flex-direction:column; gap:0.4rem;">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" required
                   onchange="previewPhoto(this, <?= $it['id'] ?>)">
            <button type="submit" class="btn btn-primary btn-sm">Upload photo</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
  function previewPhoto(input, itemId) {
    if (!input.files || !input.files[0]) return;
    var thumb = document.getElementById('thumb-' + itemId);
    var reader = new FileReader();
    reader.onload = function (e) {
      thumb.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
    };
    reader.readAsDataURL(input.files[0]);
  }
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
