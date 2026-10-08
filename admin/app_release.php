<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin']);

$pdo = get_db();
$me  = current_user();

$apk_path = __DIR__ . '/../duarte-app.apk';
$version_file = __DIR__ . '/../api/version.php';

// Helper to push APK to GitHub Releases CDN
function publish_apk_to_github_cdn(string $apkPath, string $version, string $notes): array {
    if (file_exists(__DIR__ . '/../config/config.local.php')) {
        require_once __DIR__ . '/../config/config.local.php';
    }
    $token = defined('GITHUB_RELEASE_TOKEN') ? GITHUB_RELEASE_TOKEN : (getenv('GITHUB_RELEASE_TOKEN') ?: '');
    $repo = 'Darr2004/duarte';
    $tag = 'v' . ltrim($version, 'v');

    if (!file_exists($apkPath)) {
        return ['success' => false, 'error' => 'APK file not found on server.'];
    }

    $filesize = filesize($apkPath);

    // 1. Create or get release
    $ch = curl_init("https://api.github.com/repos/$repo/releases");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'tag_name' => $tag,
            'target_commitish' => 'main',
            'name' => "DuaRTE Mobile $tag",
            'body' => $notes ?: "Official Release $tag for DuaRTE Logistics System.",
            'draft' => false,
            'prerelease' => false
        ]),
        CURLOPT_HTTPHEADER => [
            "Authorization: token $token",
            "User-Agent: DuaRTE-App",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    $release = json_decode($res, true);

    if (empty($release['id'])) {
        $ch2 = curl_init("https://api.github.com/repos/$repo/releases/tags/$tag");
        curl_setopt_array($ch2, [
            CURLOPT_HTTPHEADER => [
                "Authorization: token $token",
                "User-Agent: DuaRTE-App"
            ],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $res2 = curl_exec($ch2);
        $release = json_decode($res2, true);
    }

    if (empty($release['id'])) {
        return ['success' => false, 'error' => 'Could not create release on GitHub. Response: ' . substr($res, 0, 200)];
    }

    $releaseId = $release['id'];

    // Delete existing asset with same name if exists
    if (!empty($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            if ($asset['name'] === 'duarte-app.apk') {
                $delCh = curl_init($asset['url']);
                curl_setopt_array($delCh, [
                    CURLOPT_CUSTOMREQUEST => 'DELETE',
                    CURLOPT_HTTPHEADER => [
                        "Authorization: token $token",
                        "User-Agent: DuaRTE-App"
                    ],
                    CURLOPT_RETURNTRANSFER => true
                ]);
                curl_exec($delCh);
                break;
            }
        }
    }

    // Upload asset
    $uploadUrl = "https://uploads.github.com/repos/$repo/releases/$releaseId/assets?name=duarte-app.apk";
    $fp = fopen($apkPath, 'rb');
    $upCh = curl_init($uploadUrl);
    curl_setopt_array($upCh, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => fread($fp, $filesize),
        CURLOPT_HTTPHEADER => [
            "Authorization: token $token",
            "User-Agent: DuaRTE-App",
            "Content-Type: application/vnd.android.package-archive",
            "Content-Length: $filesize"
        ],
        CURLOPT_RETURNTRANSFER => true
    ]);
    $upRes = curl_exec($upCh);
    fclose($fp);

    $uploaded = json_decode($upRes, true);
    if (!empty($uploaded['browser_download_url'])) {
        return ['success' => true, 'url' => $uploaded['browser_download_url']];
    }

    return ['success' => false, 'error' => 'Asset upload failed. Response: ' . substr($upRes, 0, 200)];
}

// Read current version info
$current_version = '1.2.7';
$current_code = 12;
$current_title = '1-Tap Checklist & Fast Release';
$current_notes = "• 1-Tap checklist\n• Tinanggal ang 2nd scan\n• Naka-lock hangga't hindi ini-scan ang QR";

if (file_exists($version_file)) {
    $vContent = file_get_contents($version_file);
    if (preg_match("/'latest_version_name'\s*=>\s*'([^']+)'/", $vContent, $m)) {
        $current_version = $m[1];
    }
    if (preg_match("/'latest_version_code'\s*=>\s*([0-9]+)/", $vContent, $m)) {
        $current_code = (int)$m[1];
    }
    if (preg_match("/'release_title'\s*=>\s*'([^']+)'/", $vContent, $m)) {
        $current_title = $m[1];
    }
}

$flash_success = null;
$flash_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $flash_error = 'Your session has expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload_apk') {
            $ver = trim($_POST['version_name'] ?? '');
            $code = (int)($_POST['version_code'] ?? ($current_code + 1));
            $title = trim($_POST['release_title'] ?? 'DuaRTE Mobile Update');
            $notes = trim($_POST['release_notes'] ?? '');

            if (empty($ver) || !preg_match('/^[0-9.]+$/', $ver)) {
                $flash_error = 'Invalid version name format. Example: 1.3.1';
            } elseif (empty($_FILES['apk_file']['tmp_name']) || $_FILES['apk_file']['error'] !== UPLOAD_ERR_OK) {
                $flash_error = 'Please select a valid APK file to upload.';
            } else {
                $origName = $_FILES['apk_file']['name'];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($ext !== 'apk') {
                    $flash_error = 'Only Android APK files (.apk) are accepted.';
                } else {
                    if (move_uploaded_file($_FILES['apk_file']['tmp_name'], $apk_path)) {
                        @copy($apk_path, __DIR__ . "/../duarte-app-v{$ver}.apk");

                        // Publish to GitHub CDN
                        $pubRes = publish_apk_to_github_cdn($apk_path, $ver, $notes);
                        if ($pubRes['success']) {
                            // Update version.php
                            $cdnUrl = $pubRes['url'];
                            $newVersionPhp = "<?php\nheader('Content-Type: application/json; charset=UTF-8');\nheader('Access-Control-Allow-Origin: *');\nheader('Access-Control-Allow-Methods: GET, OPTIONS');\nheader('Access-Control-Allow-Headers: Content-Type, Authorization');\n\nif ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {\n    http_response_code(200);\n    exit;\n}\n\nrequire_once __DIR__ . '/../config/config.php';\n\n\$proto = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';\n\$host = \$_SERVER['HTTP_HOST'] ?? 'spruce-trapdoor-unsorted.ngrok-free.dev';\n\$base = (strpos(\$host, 'localhost') !== false || strpos(\$host, '127.0.0.1') !== false || strpos(\$host, 'ngrok') !== false || strpos(\$host, 'trycloudflare') !== false) ? '/duarte' : '';\n\n\$response = [\n    'status' => 'success',\n    'app_name' => 'DuaRTE Mobile',\n    'latest_version_code' => $code,\n    'latest_version_name' => '$ver',\n    'min_supported_version_code' => 1,\n    'apk_url' => '$cdnUrl',\n    'direct_apk_url' => '$cdnUrl',\n    'force_update' => false,\n    'release_title' => " . var_export($title, true) . ",\n    'release_notes' => " . var_export($notes, true) . ",\n    'updated_at' => date('Y-m-d')\n];\n\necho json_encode(\$response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);\n";
                            file_put_contents($version_file, $newVersionPhp);

                            log_audit_event($pdo, $me, 'apk_published', 'system', null, "Uploaded and published DuaRTE APK v{$ver} (Build {$code}) to Cloud CDN.");
                            $flash_success = "APK v{$ver} published successfully to Cloud CDN. Mobile devices will automatically receive this update.";
                            $current_version = $ver;
                            $current_code = $code;
                            $current_title = $title;
                            $current_notes = $notes;
                        } else {
                            $flash_error = 'Local file saved, but CDN upload failed: ' . $pubRes['error'];
                        }
                    } else {
                        $flash_error = 'Failed to save uploaded APK to the server.';
                    }
                }
            }
        } elseif ($action === 'sync_local') {
            if (!file_exists($apk_path)) {
                $flash_error = 'No duarte-app.apk found on server. Please build or upload first.';
            } else {
                $pubRes = publish_apk_to_github_cdn($apk_path, $current_version, $current_notes);
                if ($pubRes['success']) {
                    log_audit_event($pdo, $me, 'apk_synced', 'system', null, "Re-synced local APK v{$current_version} to Cloud CDN.");
                    $flash_success = "Current duarte-app.apk synced successfully to Cloud CDN.";
                } else {
                    $flash_error = 'CDN synchronization error: ' . $pubRes['error'];
                }
            }
        }
    }
}

$page_title = 'Mobile App Releases';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-wrapper" style="max-width: 1100px; margin: 0 auto; padding: 20px 16px;">
  <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px;">
    <div>
      <div style="font-size: 0.82rem; color: var(--ink-soft, #7A6A58); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;">Deployment Governance</div>
      <h1 style="margin:4px 0 0; font-size:24px; font-weight:700; color:var(--ink, #1F1710);">Mobile App Release Management</h1>
      <p style="margin:4px 0 0; color:var(--ink-soft); font-size:14px;">Distribute official Android APK binaries across fleet operations via high-speed Cloud CDN.</p>
    </div>
  </div>

  <?php if ($flash_success): ?>
    <div class="alert alert-success" style="background:#EEF6F2; border:1px solid #C2E0CE; color:#2D6A4F; padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:12px;">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
      <span><?= h($flash_success) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($flash_error): ?>
    <div class="alert alert-danger" style="background:#FAF1EF; border:1px solid #EFC7C1; color:#94382C; padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:12px;">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span><?= h($flash_error) ?></span>
    </div>
  <?php endif; ?>

  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:24px; margin-bottom:28px;">
    <!-- Active Version Card -->
    <div class="card" style="background:#fff; border:1px solid var(--line); border-radius:12px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <span style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--amber, #8C5A32); background:var(--amber-tint, #FAF4EB); border:1px solid var(--amber-border, #EDDCBE); padding:3px 8px; border-radius:4px;">ACTIVE VERSION</span>
        <span style="font-size:13px; font-weight:600; color:var(--ink-soft);"><?= file_exists($apk_path) ? round(filesize($apk_path)/1024/1024, 1) . ' MB' : '0 MB' ?></span>
      </div>
      <h2 style="margin:0 0 6px; font-size:28px; font-weight:800; color:var(--ink);">v<?= h($current_version) ?> <span style="font-size:14px; font-weight:500; color:var(--ink-soft);">(Build <?= $current_code ?>)</span></h2>
      <p style="margin:0 0 16px; font-size:14px; color:var(--ink-soft);"><?= h($current_title) ?></p>

      <div style="background:var(--paper); border:1px solid var(--line); border-radius:8px; padding:14px; margin-bottom:18px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--ink-light); margin-bottom:6px;">CDN Distribution Link</div>
        <div style="display:flex; gap:8px;">
          <input type="text" id="cdnLinkInput" readonly value="https://github.com/Darr2004/duarte/releases/download/v<?= h($current_version) ?>/duarte-app.apk" style="flex:1; padding:8px 12px; font-size:12px; font-family:monospace; background:#fff; border:1px solid var(--line); border-radius:6px;">
          <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('cdnLinkInput').value); alert('CDN download link copied to clipboard!');" class="btn btn-secondary" style="padding:8px 14px; font-size:12px; cursor:pointer;">Copy</button>
        </div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="https://github.com/Darr2004/duarte/releases/download/v<?= h($current_version) ?>/duarte-app.apk" target="_blank" class="btn btn-primary" style="flex:1; display:flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:600; font-size:14px; text-align:center;">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Download APK
        </a>
        <form method="POST" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="sync_local">
          <button type="submit" onclick="return confirm('Synchronize current local duarte-app.apk to Cloud CDN?');" class="btn btn-secondary" style="padding:10px 16px; border-radius:6px; font-weight:600; font-size:14px; display:flex; align-items:center; gap:6px; cursor:pointer;" title="Re-sync current build to CDN">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            Sync CDN
          </button>
        </form>
      </div>
    </div>

    <!-- QR Code Card for Instant Phone Scanning -->
    <div class="card" style="background:#fff; border:1px solid var(--line); border-radius:12px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; flex-direction:column; align-items:center; text-align:center;">
      <span style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--ink-soft); margin-bottom:12px;">Mobile Device Direct Scan</span>
      <div style="background:#fff; padding:12px; border:1px solid var(--line); border-radius:8px; margin-bottom:14px;">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode('https://github.com/Darr2004/duarte/releases/download/v' . $current_version . '/duarte-app.apk') ?>" alt="QR Code for APK" style="width:160px; height:160px; display:block;">
      </div>
      <p style="margin:0; font-size:13px; color:var(--ink-soft); max-width:260px;">
        Scan with mobile camera to install the latest build directly to device with zero server bandwidth.
      </p>
    </div>
  </div>

  <!-- Direct Upload Form -->
  <div class="card" style="background:#fff; border:1px solid var(--line); border-radius:12px; padding:28px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
    <h3 style="margin:0 0 6px; font-size:18px; font-weight:700; color:var(--ink);">Publish Production Release</h3>
    <p style="margin:0 0 24px; font-size:14px; color:var(--ink-soft);">
      Upload a newly compiled APK binary to distribute to the field via GitHub Cloud CDN.
    </p>

    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_apk">

      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:20px;">
        <div>
          <label style="display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px;">Version Name</label>
          <input type="text" name="version_name" value="<?= h(preg_replace_callback('/(\d+)$/', fn($m) => $m[1]+1, $current_version)) ?>" required placeholder="e.g. 1.3.1" style="width:100%; padding:10px 14px; border:1px solid var(--line); border-radius:6px; font-size:14px;">
        </div>

        <div>
          <label style="display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px;">Build Code</label>
          <input type="number" name="version_code" value="<?= $current_code + 1 ?>" required style="width:100%; padding:10px 14px; border:1px solid var(--line); border-radius:6px; font-size:14px;">
        </div>

        <div>
          <label style="display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px;">Release Title</label>
          <input type="text" name="release_title" value="DuaRTE Logistics Mobile Update" required style="width:100%; padding:10px 14px; border:1px solid var(--line); border-radius:6px; font-size:14px;">
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label style="display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px;">Release Notes</label>
        <textarea name="release_notes" rows="3" style="width:100%; padding:10px 14px; border:1px solid var(--line); border-radius:6px; font-size:14px; font-family:inherit;">• System optimizations and priority workflow enhancements</textarea>
      </div>

      <div style="margin-bottom:24px;">
        <label style="display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px;">Select APK Binary (.apk)</label>
        <input type="file" name="apk_file" accept=".apk" required style="display:block; width:100%; padding:12px; border:2px dashed var(--line); border-radius:8px; background:var(--paper); cursor:pointer;">
      </div>

      <button type="submit" class="btn btn-primary" style="padding:12px 24px; font-size:15px; font-weight:700; border-radius:8px; display:inline-flex; align-items:center; gap:10px; cursor:pointer;">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload &amp; Publish Release
      </button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
