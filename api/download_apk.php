<?php
/**
 * DuaRTE — Direct Mobile APK Downloader
 * Streams the latest Android APK with forced download headers,
 * explicit versioned filename, and no-cache headers to prevent
 * mobile browsers from serving stale cached copies.
 */

require_once __DIR__ . '/../config/config.php';

$apk_path = __DIR__ . '/../duarte-app.apk';

if (!file_exists($apk_path)) {
    http_response_code(404);
    die('APK build not found on server.');
}

$version = preg_replace('/[^0-9.]/', '', $_GET['v'] ?? '1.2.7');
if (empty($version)) {
    $version = '1.2.7';
}

$filename = "duarte-app-v{$version}.apk";
$filesize = filesize($apk_path);

// Disable output buffering to stream large files cleanly
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Direct Cloud CDN redirect to save 100% ngrok bandwidth
$cloud_url = 'https://github.com/Darr2004/duarte/releases/download/v' . $version . '/duarte-app.apk';

// If cloud redirect is requested or running over a tunnel, redirect to CDN
if (!empty($cloud_url)) {
    header("Location: $cloud_url", true, 302);
    exit;
}

readfile($apk_path);
exit;
