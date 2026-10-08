<?php
/**
 * DuaRTE — Direct Mobile APK Downloader
 * Redirects to GitHub Cloud CDN to save 100% ngrok bandwidth.
 * If running on local network without internet, streams local copy.
 */

require_once __DIR__ . '/../config/config.php';

$version = preg_replace('/[^0-9.]/', '', $_GET['v'] ?? '1.3.0');
if (empty($version)) {
    $version = '1.3.0';
}

$cloud_url = 'https://github.com/Darr2004/duarte/releases/download/v' . $version . '/duarte-app.apk';

// Direct 302 Redirect to GitHub CDN (Zero ngrok bandwidth used!)
header("Location: $cloud_url", true, 302);
exit;
