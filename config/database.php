<?php
/**
 * DuaRTE — Database Connection
 * Returns a single shared PDO instance using prepared statements
 * throughout the app (no raw string interpolation into SQL, ever).
 */

require_once __DIR__ . '/config.php';

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Enable SSL for cloud MySQL providers (TiDB Cloud / Aiven) when not on localhost
        $is_local_db = in_array(strtolower(DB_HOST), ['localhost', '127.0.0.1', '::1']);
        if (!$is_local_db && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
            } elseif (file_exists('C:\\xampp\\apache\\bin\\curl-ca-bundle.crt')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
            }
        }
        if (!$is_local_db && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Safe timezone adjustment
            try {
                $pdo->exec("SET time_zone = '+08:00'");
            } catch (Exception $tzErr) {
                // Ignore if cloud provider manages timezone
            }
        } catch (PDOException $e) {
            // Never leak connection details to the browser.
            error_log('DB connection failed: ' . $e->getMessage());
            die('Database connection failed. Please contact the administrator.');
        }
    }

    return $pdo;
}
