<?php
/**
 * DuaRTE — QR Code Asset Tracking: scan verification endpoint.
 *
 * Called by inventory/verify.php's JS the moment the camera decodes a
 * QR tag while releasing a request — before this endpoint existed, a
 * successful decode just set the <select>'s value directly in the
 * browser, and the release handler trusted whatever asset_choice[]
 * value showed up in the POST. That made "must scan, can't pick by
 * hand" a client-side convention only: anyone posting the release
 * form directly (browser devtools, curl, a modified page) could claim
 * any available asset without ever scanning it.
 *
 * This endpoint re-checks the scan server-side against the same
 * item/variant/lot rules verify.php already enforces, and — only if
 * the scanned tag is a genuine match — mints a short-lived, single-use
 * token (see create_asset_scan_verification() in includes/assets.php).
 * verify.php's release handler then requires that exact token per
 * line and consumes it in the same transaction as the release, so a
 * token can't be reused or forged after the fact.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assets.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['inventory_staff', 'admin']);

header('Content-Type: application/json');

$pdo = get_db();
$user = current_user();

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!csrf_check($_POST['csrf_token'] ?? null)) {
    respond(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}

$requisition_item_id = (int)($_POST['requisition_item_id'] ?? 0);
$tag = trim($_POST['tag'] ?? '');
if ($tag !== '' && preg_match('/[?&]tag=([^&]+)/', $tag, $m)) {
    $tag = urldecode($m[1]);
}
$tag = strtoupper(trim($tag));

if ($requisition_item_id <= 0 || $tag === '') {
    respond(['success' => false, 'error' => 'Missing requisition line or scanned tag.'], 400);
}

$line_stmt = $pdo->prepare(
    'SELECT ri.*, r.status AS requisition_status
     FROM requisition_items ri
     JOIN requisitions r ON r.id = ri.requisition_id
     WHERE ri.id = :id'
);
$line_stmt->execute(['id' => $requisition_item_id]);
$line = $line_stmt->fetch();

if (!$line) {
    respond(['success' => false, 'error' => 'That request line was not found.'], 404);
}
if ($line['requisition_status'] !== 'approved') {
    respond(['success' => false, 'error' => 'This request is no longer awaiting release.'], 409);
}
if ($line['item_id'] === null) {
    respond(['success' => false, 'error' => 'This line has no catalog item to match against.'], 409);
}

$variant_id = null;
if (!empty($line['variant_selected'])) {
    $v = find_item_variant($pdo, (int)$line['item_id'], $line['variant_selected']);
    $variant_id = $v ? $v['id'] : null;
}

$available_assets = get_available_assets_for_item($pdo, (int)$line['item_id'], $variant_id);

$matched = null;
$matched_but_short = false;
foreach ($available_assets as $a) {
    if ($a['asset_tag'] === $tag) {
        if (!$line['is_borrowable'] && (int)$a['quantity'] < (int)$line['quantity_requested']) {
            $matched_but_short = true;
            continue;
        }
        $matched = $a;
        break;
    }
}

if ($matched_but_short && !$matched) {
    respond(['success' => false, 'error' => 'That lot doesn\'t have enough left for this request — scan a different lot.'], 409);
}
if (!$matched) {
    respond(['success' => false, 'error' => 'That scanned tag isn\'t an available match for this item — wrong item, or it\'s already checked out/used up.'], 409);
}

$token = create_asset_scan_verification($pdo, $requisition_item_id, (int)$matched['id'], (int)$user['id']);

respond([
    'success'    => true,
    'asset_id'   => (int)$matched['id'],
    'asset_tag'  => $matched['asset_tag'],
    'token'      => $token,
]);
