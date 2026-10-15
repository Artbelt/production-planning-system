<?php
/**
 * Подключение к БД diagnostics через env.php (как press_module).
 */

function diagnostics_db_config(): array
{
    static $loaded = false;
    if (!$loaded) {
        $env = __DIR__ . '/../env.php';
        if (file_exists($env)) {
            require_once $env;
        }
        $loaded = true;
    }
    return [
        'host' => defined('DB_HOST') ? DB_HOST : '127.0.0.1',
        'user' => defined('DB_USER') ? DB_USER : 'root',
        'pass' => defined('DB_PASS') ? DB_PASS : '',
        'name' => 'diagnostics',
    ];
}

/**
 * PDO к БД diagnostics. При первом вызове создаёт таблицы (не саму БД).
 */
function diagnostics_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    require_once __DIR__ . '/schema.php';

    $cfg = diagnostics_db_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        $cfg['name']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Unknown database') || str_contains($msg, '1049')
            || str_contains($msg, '1044') || str_contains($msg, 'Access denied')) {
            throw new RuntimeException(
                'Нет доступа к БД diagnostics. От root выполните: '
                . 'CREATE DATABASE IF NOT EXISTS diagnostics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; '
                . "GRANT ALL PRIVILEGES ON diagnostics.* TO '" . $cfg['user'] . "'@'localhost'; FLUSH PRIVILEGES;",
                0,
                $e
            );
        }
        throw $e;
    }

    diagnostics_ensure_schema($pdo);
    return $pdo;
}
