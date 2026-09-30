<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: text/plain');

echo "=== DuaRTE DB Diagnostic ===\n";
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_PORT: " . DB_PORT . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_USER: " . DB_USER . "\n";
echo "DB_PASS: " . (strlen(DB_PASS) > 0 ? "Set (" . strlen(DB_PASS) . " chars)" : "EMPTY") . "\n";

$port = defined('DB_PORT') ? DB_PORT : 3306;
$dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
    if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
        echo "SSL CA: /etc/ssl/certs/ca-certificates.crt (found)\n";
    } else {
        echo "SSL CA: Not found\n";
    }
}
if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    echo "RESULT: SUCCESS! Connected to database.\n";
    $stmt = $pdo->query("SHOW TABLES;");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "TABLES COUNT: " . count($tables) . "\n";
} catch (Exception $e) {
    echo "RESULT: FAILED\n";
    echo "ERROR MESSAGE: " . $e->getMessage() . "\n";
}
