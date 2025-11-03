<?php
/**
 * Диагностический endpoint для отладки проблем с API
 */

// Оптимизация для мобильных устройств
ini_set('max_execution_time', 30);
ini_set('memory_limit', '128M');
error_reporting(E_ERROR | E_WARNING);

// Подключаем новую систему авторизации
define('AUTH_SYSTEM', true);
require_once '../../auth/includes/config.php';
require_once '../../auth/includes/auth-functions.php';

try {
    // Инициализация системы
    initAuthSystem();
    $auth = new AuthManager();

    // Проверка авторизации
    $session = $auth->checkSession();
    if (!$session) {
        throw new Exception('Unauthorized');
    }

    // Получаем информацию о пользователе
    $db = Database::getInstance();
    $users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
    $user = $users[0] ?? null;

    $userDepartments = $db->select("
        SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
        FROM auth_user_departments ud
        JOIN auth_roles r ON ud.role_id = r.id
        WHERE ud.user_id = ?
    ", [$session['user_id']]);

    // Проверяем доступ
    $hasLaserOperatorAccess = false;
    foreach ($userDepartments as $dept) {
        if (in_array($dept['role_name'], ['admin', 'director', 'laser_operator'])) {
            $hasLaserOperatorAccess = true;
            break;
        }
    }

    if (!$hasLaserOperatorAccess) {
        throw new Exception('Access denied');
    }

    // Проверяем файлы настроек
    $settings_files = [
        'U2' => '../../plan/settings.php',
        'U3' => '../../plan_U3/settings.php',
        'U4' => '../../plan_U4/settings.php',
        'U5' => '../../plan_U5/settings.php'
    ];

    $settings_status = [];
    $databases = [];

    foreach ($settings_files as $dept => $path) {
        $status = [
            'file_exists' => file_exists($path),
            'readable' => is_readable($path),
            'path' => realpath($path) ?: $path
        ];

        if ($status['file_exists']) {
            try {
                $mysql_host = $mysql_user = $mysql_user_pass = null;
                ob_start();
                include $path;
                ob_end_clean();

                $status['host'] = $mysql_host ?? 'null';
                $status['user'] = $mysql_user ?? 'null';
                $status['pass'] = (isset($mysql_user_pass) && $mysql_user_pass !== '') ? 'SET' : 'EMPTY';

                $databases[$dept] = [
                    'host' => $mysql_host ?? '127.0.0.1',
                    'user' => $mysql_user ?? 'root',
                    'pass' => $mysql_user_pass ?? '',
                    'name' => $dept === 'U2' ? 'plan' : "plan_{$dept}"
                ];
            } catch (Exception $e) {
                $status['error'] = $e->getMessage();
            }
        }

        $settings_status[$dept] = $status;
    }

    // Тестируем подключения к базам данных
    $db_tests = [];
    foreach ($databases as $dept => $config) {
        $test_result = [
            'department' => $dept,
            'config' => [
                'host' => $config['host'],
                'user' => $config['user'],
                'name' => $config['name']
            ],
            'connection' => false,
            'table_exists' => false,
            'error' => null
        ];

        try {
            $mysqli = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
            
            if ($mysqli->connect_errno) {
                $test_result['error'] = "Connection failed: " . $mysqli->connect_error;
            } else {
                $test_result['connection'] = true;
                
                // Проверяем таблицу
                $tableCheck = $mysqli->query("SHOW TABLES LIKE 'laser_requests'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    $test_result['table_exists'] = true;
                    
                    // Получаем количество записей
                    $countResult = $mysqli->query("SELECT COUNT(*) as count FROM laser_requests");
                    if ($countResult) {
                        $row = $countResult->fetch_assoc();
                        $test_result['records_count'] = $row['count'];
                    }
                }
                
                $mysqli->close();
            }
        } catch (Exception $e) {
            $test_result['error'] = $e->getMessage();
        }

        $db_tests[] = $test_result;
    }

    // Устанавливаем заголовки
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    echo json_encode([
        'status' => 'ok',
        'timestamp' => time(),
        'user' => [
            'id' => $session['user_id'],
            'name' => $user['name'] ?? 'unknown',
            'departments' => $userDepartments
        ],
        'settings_files' => $settings_status,
        'database_tests' => $db_tests,
        'server' => [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'include_path' => get_include_path()
        ]
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    http_response_code(500);
    
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => time()
    ]);
}
?>







