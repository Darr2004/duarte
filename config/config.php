<?php
/**
 * DuaRTE — Global Configuration
 * Change these values to match your local/production environment.
 */

// ---- Database connection & Environment Detection ----
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// ---- Reverse Proxy & Tunnel HTTPS Normalization ----
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

if (!defined('APP_ENV')) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $is_tunnel = (strpos($host, 'ngrok') !== false || strpos($host, 'trycloudflare.com') !== false || strpos($host, 'cloudflare') !== false);
    define('APP_ENV', getenv('APP_ENV') ?: ($is_tunnel ? 'tunnel' : 'development'));
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'duarte_db');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') !== false ? getenv('BASE_URL') : '/duarte');
}

// ---- App settings ----
define('APP_NAME', 'DuaRTE');

// ---- Timezone ----
// All requisition day-validity and office-hours checks are anchored to
// this timezone, regardless of the server's own default.
date_default_timezone_set('Asia/Manila');

// ---- Requisition window ----
// Requesters can only submit a new requisition inside this window.
// A requisition that is still pending/approved once its submission
// date has passed is automatically expired (see expire_stale_requisitions()).
define('OFFICE_HOURS_OPEN', '08:00');
define('OFFICE_HOURS_CLOSE', '23:00');

// ---- Session ----
// Must run before any output. Include this file first on every page.
if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $is_secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    } else {
        @session_start();
    }
}

// ---- Error visibility & Security Shield ----
// Raw code, SQL queries, and internal file paths are strictly prevented
// from displaying to end-users across all screens. All technical details
// are securely preserved in the server error log for system administrators.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Global uncaught exception shield
set_exception_handler(function ($e) {
    error_log('[DuaRTE UNCAUGHT EXCEPTION] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "A system error occurred: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        $is_api = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) ||
                  (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($is_api) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'May naganap na error sa server. Pakisubukan muli.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (file_exists(__DIR__ . '/../error.php')) {
            require __DIR__ . '/../error.php';
            exit;
        }
    }
    echo '<p style="font-family:sans-serif; color:#94382C; text-align:center; padding:2rem;">May naganap na error. Pakisubukan muli.</p>';
    exit;
});

// Global fatal shutdown shield
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("[DuaRTE FATAL ERROR] {$err['message']} in {$err['file']}:{$err['line']}");
        if (php_sapi_name() === 'cli') {
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            $is_api = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) ||
                      (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            if ($is_api) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => 'May naganap na error sa server. Pakisubukan muli.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (file_exists(__DIR__ . '/../error.php')) {
                require __DIR__ . '/../error.php';
                exit;
            }
        }
    }
});

/**
 * Sanitizes any exception or error string so that raw SQL queries, PDO exceptions,
 * internal file paths, or syntax errors are never shown to users.
 */
function clean_error_message(mixed $err, string $fallback = 'May naganap na error sa pagproseso ng kahilingan. Pakisubukan muli.'): string {
    $msg = ($err instanceof Throwable) ? $err->getMessage() : (string)$err;
    if ($err instanceof PDOException ||
        stripos($msg, 'SQLSTATE') !== false ||
        stripos($msg, 'PDOException') !== false ||
        stripos($msg, 'syntax error') !== false ||
        stripos($msg, 'stack trace') !== false ||
        stripos($msg, 'SELECT ') !== false ||
        stripos($msg, 'UPDATE ') !== false ||
        stripos($msg, 'INSERT ') !== false ||
        preg_match('/[a-zA-Z]:\\\\|\/htdocs\/|\/var\//i', $msg)) {
        error_log('[SECURITY SHIELD - ERROR SANITIZED] ' . $msg);
        return $fallback;
    }
    return $msg;
}


