<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'spruce-trapdoor-unsorted.ngrok-free.dev';
$base = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false || strpos($host, 'ngrok') !== false || strpos($host, 'trycloudflare') !== false) ? '/duarte' : '';

$response = [
    'status' => 'success',
    'app_name' => 'DuaRTE Mobile',
    'latest_version_code' => 15,
    'latest_version_name' => '1.3.0',
    'min_supported_version_code' => 1,
    'apk_url' => 'https://github.com/Darr2004/duarte/releases/download/v1.3.0/duarte-app.apk',
    'direct_apk_url' => 'https://github.com/Darr2004/duarte/releases/download/v1.3.0/duarte-app.apk',
    'force_update' => false,
    'release_title' => 'Malinaw na Reference Code sa Ilalim ng QR at Manual Fallback',
    'release_notes' => "• May malinaw na 'Reference Code' (hal. REQ-081) na nakalimbag sa mismong ilalim ng QR ng driver/requester kung sakaling hindi ma-scan ang QR.\n• Pwedeng i-type o piliin nang direkta ng Inventory Staff sa bodega nang walang aberya.",
    'updated_at' => date('Y-m-d')
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
