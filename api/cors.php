<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Content-Type: application/json; charset=UTF-8');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function api_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return $_POST;
}

function api_response(bool $success, mixed $data = null, ?string $error = null, int $code = 200): void {
    if ($error !== null) {
        // Intercept and sanitize any raw SQLSTATE, PDO, code syntax, or file path leaks
        if (stripos($error, 'SQLSTATE') !== false ||
            stripos($error, 'PDOException') !== false ||
            stripos($error, 'syntax error') !== false ||
            stripos($error, 'stack trace') !== false ||
            stripos($error, 'SELECT ') !== false ||
            stripos($error, 'UPDATE ') !== false ||
            stripos($error, 'INSERT ') !== false ||
            preg_match('/[a-zA-Z]:\\\\|\/htdocs\/|\/var\//i', $error)) {
            error_log('[API SECURITY SANITIZED ERROR] ' . $error);
            $error = 'May naganap na error sa pagproseso ng kahilingan. Pakisubukan muli.';
        }
    }
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'error'   => $error
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
