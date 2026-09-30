<?php
/**
 * DuaRTE — QR Code Asset Tracking: 1-Click Catalog Auto-Sync
 *
 * Automatically generates asset records and unique QR tags for every
 * catalog item/variant that doesn't have an asset record yet.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/audit.php';
if (php_sapi_name() !== 'cli') {
    require_role(['inventory_staff', 'admin']);
}

$pdo = get_db();
$user = current_user() ?: ['id' => 1, 'full_name' => 'System Administrator'];

/**
 * Auto-syncs all catalog items/variants into the assets table.
 */
function sync_catalog_to_assets(PDO $pdo, array $user): array
{
    // Fetch all active items with their stall and layer
    $sql = "SELECT i.*, r.room_number, s.stall_number, sl.layer_name
            FROM items i
            LEFT JOIN stall_layers sl ON sl.id = i.stall_layer_id
            LEFT JOIN stalls s ON s.id = sl.stall_id
            LEFT JOIN rooms r ON r.id = s.room_id
            WHERE i.status = 'active'
            ORDER BY i.id ASC";
    $items = $pdo->query($sql)->fetchAll();

    $created_count = 0;
    $item_count = 0;
    $created_tags = [];

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            $item_id = (int)$item['id'];
            $location = null;
            if (!empty($item['stall_number']) && !empty($item['layer_name'])) {
                $location = 'Room ' . $item['room_number'] . ' · Stall ' . $item['stall_number'] . ' — ' . $item['layer_name'];
            }

            // Check if item has variants
            $stmt = $pdo->prepare("SELECT * FROM item_variants WHERE item_id = :item_id ORDER BY id ASC");
            $stmt->execute(['item_id' => $item_id]);
            $variants = $stmt->fetchAll();

            $lines_to_create = [];

            if ($variants) {
                foreach ($variants as $v) {
                    $variant_id = (int)$v['id'];
                    $chk = $pdo->prepare("SELECT COUNT(*) c FROM assets WHERE item_id = :item_id AND item_variant_id = :vid");
                    $chk->execute(['item_id' => $item_id, 'vid' => $variant_id]);
                    if ((int)$chk->fetch()['c'] === 0) {
                        $lines_to_create[] = [
                            'variant_id' => $variant_id,
                            'qty'        => max(1, (int)$v['quantity_on_hand']),
                            'label'      => ' (' . $v['variant_value'] . ')',
                        ];
                    }
                }
            } else {
                $chk = $pdo->prepare("SELECT COUNT(*) c FROM assets WHERE item_id = :item_id");
                $chk->execute(['item_id' => $item_id]);
                if ((int)$chk->fetch()['c'] === 0) {
                    $lines_to_create[] = [
                        'variant_id' => null,
                        'qty'        => max(1, (int)$item['quantity_on_hand']),
                        'label'      => '',
                    ];
                }
            }

            if (!$lines_to_create) {
                continue;
            }

            $is_borrowable = borrow_mode_is_borrowable($item['borrow_mode']);
            $item_created = 0;

            foreach ($lines_to_create as $line) {
                if ($is_borrowable) {
                    $units = min(20, $line['qty']);
                    for ($i = 0; $i < $units; $i++) {
                        $tag = generate_unique_asset_tag($pdo);
                        $ins = $pdo->prepare(
                            "INSERT INTO assets (item_id, item_variant_id, asset_tag, serial_number, quantity, status, condition_note, location_note, acquired_at, registered_by)
                             VALUES (:item_id, :variant_id, :tag, NULL, 1, 'available', NULL, :loc, :acquired, :by)"
                        );
                        $ins->execute([
                            'item_id'    => $item_id,
                            'variant_id' => $line['variant_id'],
                            'tag'        => $tag,
                            'loc'        => $location,
                            'acquired'   => date('Y-m-d', strtotime($item['created_at'] ?? 'now')),
                            'by'         => $user['id'],
                        ]);
                        $asset_id = (int)$pdo->lastInsertId();
                        record_asset_event($pdo, $asset_id, 'registered', $user, 'Auto-synced from Catalog Management: ' . $item['name'] . $line['label'] . '.');
                        $created_tags[] = $tag;
                        $created_count++;
                        $item_created++;
                    }
                } else {
                    $tag = generate_unique_asset_tag($pdo);
                    $ins = $pdo->prepare(
                        "INSERT INTO assets (item_id, item_variant_id, asset_tag, serial_number, quantity, status, condition_note, location_note, acquired_at, registered_by)
                         VALUES (:item_id, :variant_id, :tag, NULL, :qty, 'available', NULL, :loc, :acquired, :by)"
                    );
                    $ins->execute([
                        'item_id'    => $item_id,
                        'variant_id' => $line['variant_id'],
                        'tag'        => $tag,
                        'qty'        => $line['qty'],
                        'loc'        => $location,
                        'acquired'   => date('Y-m-d', strtotime($item['created_at'] ?? 'now')),
                        'by'         => $user['id'],
                    ]);
                    $asset_id = (int)$pdo->lastInsertId();
                    record_asset_event($pdo, $asset_id, 'registered', $user, 'Auto-synced from Catalog Management as lot of ' . $line['qty'] . ' ' . $item['name'] . $line['label'] . '.');
                    $created_tags[] = $tag;
                    $created_count++;
                    $item_created++;
                }
            }

            if ($item_created > 0) {
                $item_count++;
            }
        }

        if ($created_count > 0) {
            log_audit_event($pdo, $user, 'asset_sync', 'assets', null,
                $user['full_name'] . ' auto-synced ' . $created_count . ' QR asset tag(s) across ' . $item_count . ' catalog item(s).');
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'created_count' => $created_count,
        'item_count'    => $item_count,
        'created_tags'  => $created_tags,
    ];
}

// Handle POST request from the web UI
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        header('Location: ' . BASE_URL . '/inventory/assets.php?err=csrf');
        exit;
    }

    try {
        $result = sync_catalog_to_assets($pdo, $user);
        header('Location: ' . BASE_URL . '/inventory/assets.php?synced=' . (int)$result['created_count'] . '&items=' . (int)$result['item_count']);
    } catch (Exception $e) {
        error_log($e->getMessage());
        header('Location: ' . BASE_URL . '/inventory/assets.php?err=sync_failed');
    }
    exit;
}

if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'asset_sync.php') {
    header('Location: ' . BASE_URL . '/inventory/assets.php');
    exit;
}
