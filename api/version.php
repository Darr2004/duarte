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

// Direct GitHub Cloud CDN URL to save 100% of ngrok bandwidth
$github_apk_url = 'https://github.com/Darr2004/duarte/releases/download/v1.3.1/duarte-app.apk';

$response = [
    'status' => 'success',
    'app_name' => 'DuaRTE Mobile',
    'latest_version_code' => 16,
    'latest_version_name' => '1.3.1',
    'min_supported_version_code' => 16,
    'apk_url' => $github_apk_url,
    'direct_apk_url' => $github_apk_url,
    'force_update' => true,
    'release_title' => '3-Tier Complaints Matrix & 1-Tap Requisition Presets',
    'release_notes' => "• Requisition Cart Urgency Matrix: Naka-align na sa 3 Tiers (Emergency / High, Medium, Routine / Low).\n• 1-Tap Reklamo Presets: Mabilisang pagpili ng starter relay, tirik sa daan, preno, alternator, flat tire, change oil nang walang pagta-type.\n• MCDA Urgency Alignment: Awtomatikong pinapataas ang priority score batay sa napiling sira ng truck.",
    'updated_at' => date('Y-m-d')
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
