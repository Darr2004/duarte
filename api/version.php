<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// DuaRTE System - Mobile App Version & In-App Auto-Update Endpoint
// Increment latest_version_code whenever a new APK is released.
$response = [
    'status' => 'success',
    'app_name' => 'DuaRTE Mobile',
    'latest_version_code' => 1,
    'latest_version_name' => '1.0.0',
    'min_supported_version_code' => 1,
    'apk_url' => 'https://duarte.onrender.com/duarte-app.apk',
    'force_update' => false,
    'release_title' => 'Bagong Update ng DuaRTE',
    'release_notes' => "• Direct Cloud Connection (No XAMPP/Ngrok needed)\n• QR scanning & tool borrowing improvements\n• Mas mabilis na offline syncing",
    'updated_at' => '2026-09-30'
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
