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
$github_apk_url = 'https://github.com/Darr2004/duarte/releases/download/v1.3.2/duarte-app.apk';

$response = [
    'status' => 'success',
    'app_name' => 'DuaRTE Mobile',
    'latest_version_code' => 17,
    'latest_version_name' => '1.3.2',
    'min_supported_version_code' => 17,
    'apk_url' => $github_apk_url,
    'direct_apk_url' => $github_apk_url,
    'force_update' => true,
    'release_title' => 'Oras ng Alis / Schedule ng Biyahe (MCDA Dispatch Urgency)',
    'release_notes' => "• Bagong Dispatch Schedule Selector: Piliin kung aalis Mamaya (Within 12h), Bukas (Within 24h), o Standby sa garahe.\n• Mas Matalinong MCDA Algorithm: Awtomatikong pinapataas ang priority score (95%) ng mga truck na aalis mamaya kaysa bukas o nakatambay.\n• Web & Mobile Synchronization: Kitang-kita ng mga supervisor ang departure urgency badge sa pending queue.",
    'updated_at' => date('Y-m-d')
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
