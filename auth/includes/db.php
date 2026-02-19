<?php
/**
 * Общее подключение к БД по имени базы. Использует env.php (DB_HOST, DB_USER, DB_PASS).
 */
if (!defined('DB_HOST')) {
    $envFile = __DIR__ . '/../../env.php';
    if (file_exists($envFile)) require $envFile;
}
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

function getPdo($database) {
    static $pdoCache = [];
    if (isset($pdoCache[$database])) return $pdoCache[$database];
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdoCache[$database] = $pdo;
    return $pdo;
}
