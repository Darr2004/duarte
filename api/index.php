<?php
// If opened in a web browser, redirect directly to the login page
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!isset($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false)) {
    require_once __DIR__ . '/../config/config.php';
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'online',
    'app' => 'DuaRTE Logistics & Inventory API',
    'version' => '1.0'
]);
exit;
