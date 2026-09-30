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

        // Enable SSL verification exemption for cloud providers (TiDB / Aiven / PlanetScale)
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Keep MySQL's NOW()/CURDATE()/CURRENT_TIMESTAMP in step with the
            // app's Asia/Manila clock (set in config.php), regardless of the
            // MySQL server's own default timezone (XAMPP defaults to SYSTEM,
            // which on many machines is UTC). This matters for the daily
            // requisition-validity and office-hours checks.
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            // Never leak connection details to the browser.
            error_log('DB connection failed: ' . $e->getMessage());
            die('Database connection failed. Please contact the administrator.');
        }
    }

    return $pdo;
}
