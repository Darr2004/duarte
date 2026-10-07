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
    'latest_version_code' => 13,
    'latest_version_name' => '1.2.8',
    'min_supported_version_code' => 1,
    'apk_url' => 'https://github.com/Darr2004/duarte/releases/download/v1.2.8/duarte-app.apk',
    'direct_apk_url' => 'https://github.com/Darr2004/duarte/releases/download/v1.2.8/duarte-app.apk',
    'force_update' => false,
    'release_title' => 'Pinag-isang Smart Scanner (Driver QR at Tag ng Gamit)',
    'release_notes' => "• Pinag-isang Smart Scanner: Kahit anong QR o barcode ang i-scan (QR ng driver, barcode ng gamit, o asset tag), awtomatikong lilipat sa tamang screen nang hindi na kailangang magpalit ng tab o mag-alala kung aling scanner ang gagamitin.\n• Mas mabilis at madaling gamitin para sa warehouse staff nang walang kalituhan.",
    'updated_at' => date('Y-m-d')
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
