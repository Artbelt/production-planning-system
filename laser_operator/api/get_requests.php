<?php
/**
 * API endpoint для получения заявок на лазерную резку
 * Возвращает JSON с данными о заявках в реальном времени
 */

// Оптимизация для мобильных устройств
ini_set('max_execution_time', 30);
ini_set('memory_limit', '128M');
error_reporting(E_ERROR | E_WARNING);

// Подключаем новую систему авторизации
define('AUTH_SYSTEM', true);
require_once '../../auth/includes/config.php';
require_once '../../auth/includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Получаем информацию о пользователе и его роли
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем, есть ли доступ к модулю оператора лазера
$hasLaserOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'laser_operator'])) {
        $hasLaserOperatorAccess = true;
        break;
    }
}

if (!$hasLaserOperatorAccess) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Настройки подключений к базам данных всех участков
// Загружаем настройки из соответствующих settings.php файлов
function loadSettings($path) {
    try {
        if (file_exists($path)) {
            // Инициализируем переменные
            $mysql_host = $mysql_user = $mysql_user_pass = null;
            
            // Выполняем include в локальной области видимости
            $old_error_reporting = error_reporting();
            error_reporting(0); // Отключаем предупреждения для include
            
            // Захватываем вывод и выполняем include
            ob_start();
            $include_result = include $path;
            ob_end_clean();
            
            error_reporting($old_error_reporting);
            
            if ($include_result === false) {
                throw new Exception("Failed to include settings file: {$path}");
            }
            
            return [
                'host' => (!empty($mysql_host)) ? $mysql_host : '127.0.0.1',
                'user' => (!empty($mysql_user)) ? $mysql_user : 'root',
                'pass' => isset($mysql_user_pass) ? $mysql_user_pass : ''
            ];
        }
    } catch (Exception $e) {
        error_log("Error loading settings from {$path}: " . $e->getMessage());
    } catch (Error $e) {
        error_log("Fatal error loading settings from {$path}: " . $e->getMessage());
    }
    
    // Fallback — из env.php
    if (file_exists(__DIR__ . '/../../env.php')) require __DIR__ . '/../../env.php';
    return [
        'host' => defined('DB_HOST') ? DB_HOST : '127.0.0.1',
        'user' => defined('DB_USER') ? DB_USER : 'root',
        'pass' => defined('DB_PASS') ? DB_PASS : ''
    ];
}

// Загружаем настройки баз данных
try {
    $databases = [
        'U2' => array_merge(loadSettings('../../plan/settings.php'), ['name' => 'plan']),
        'U3' => array_merge(loadSettings('../../plan_U3/settings.php'), ['name' => 'plan_u3']),
        'U4' => array_merge(loadSettings('../../plan_U4/settings.php'), ['name' => 'plan_u4']),
        'U5' => array_merge(loadSettings('../../plan_U5/settings.php'), ['name' => 'plan_u5'])
    ];
    
    // Логируем загруженные настройки (без паролей)
    foreach ($databases as $dept => $config) {
        error_log("Database config for {$dept}: {$config['host']}:{$config['name']} user:{$config['user']}");
    }
    
} catch (Exception $e) {
    error_log("Error loading database configurations: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration error', 'details' => $e->getMessage()]);
    exit;
}

// Функция для получения всех заявок из всех баз данных
function getAllLaserRequests($databases) {
    $allRequests = [];
    $errors = [];
    
    foreach ($databases as $department => $dbConfig) {
        $mysqli = null;
        try {
            // Устанавливаем более короткий timeout для мобильных устройств
            $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
            $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $mysqli->options(MYSQLI_OPT_READ_TIMEOUT, 5);
            
            if ($mysqli->connect_errno) {
                $error = "Ошибка подключения к БД {$department}: " . $mysqli->connect_error;
                error_log($error);
                $errors[] = $error;
                continue;
            }
            
            // Проверяем существование таблицы перед запросом
            $tableCheck = $mysqli->query("SHOW TABLES LIKE 'laser_requests'");
            if (!$tableCheck || $tableCheck->num_rows == 0) {
                $error = "Таблица laser_requests не найдена в БД {$department}";
                error_log($error);
                $errors[] = $error;
                $mysqli->close();
                continue;
            }
            
            // Автомиграция: добавляем необходимые поля если их нет
            $columnCheck = $mysqli->query("SHOW COLUMNS FROM laser_requests LIKE 'progress_count'");
            if ($columnCheck && $columnCheck->num_rows === 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN progress_count INT NOT NULL DEFAULT 0 AFTER quantity");
            }
            
            // Проверяем существование поля is_cancelled
            $checkCancelled = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'is_cancelled'");
            if ($checkCancelled && $checkCancelled->fetch_row()[0] == 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN is_cancelled BOOLEAN DEFAULT FALSE AFTER is_completed");
            }
            
            // Проверяем существование поля cancelled_at
            $checkCancelledAt = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'cancelled_at'");
            if ($checkCancelledAt && $checkCancelledAt->fetch_row()[0] == 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN cancelled_at TIMESTAMP NULL AFTER is_cancelled");
            }
            
            // Проверяем существование поля completed_by
            $checkCompletedBy = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'completed_by'");
            if ($checkCompletedBy && $checkCompletedBy->fetch_row()[0] == 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN completed_by VARCHAR(255) NULL AFTER completed_at");
            }
            
            // Проверяем существование колонки is_cancelled перед использованием в запросе
            $hasCancelledColumn = false;
            $checkColumn = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'is_cancelled'");
            if ($checkColumn && $checkColumn->fetch_row()[0] > 0) {
                $hasCancelledColumn = true;
            }
            
            // Безопасный запрос с проверкой (исключаем отмененные заявки, если колонка существует)
            if ($hasCancelledColumn) {
                $sql = "SELECT id, user_name, department, component_name, quantity, progress_count, desired_delivery_time, is_completed, completed_at, created_at, '{$department}' as source_department FROM laser_requests WHERE (is_cancelled = FALSE OR is_cancelled IS NULL) ORDER BY created_at DESC LIMIT 100";
            } else {
                $sql = "SELECT id, user_name, department, component_name, quantity, progress_count, desired_delivery_time, is_completed, completed_at, created_at, '{$department}' as source_department FROM laser_requests ORDER BY created_at DESC LIMIT 100";
            }
            
            if (!$result = $mysqli->query($sql)) {
                $error = "Ошибка запроса к БД {$department}: " . $mysqli->error;
                error_log($error);
                $errors[] = $error;
                $mysqli->close();
                continue;
            }
            
            while ($row = $result->fetch_assoc()) {
                // Убеждаемся, что все поля существуют
                $allRequests[] = [
                    'id' => isset($row['id']) ? (int)$row['id'] : 0,
                    'user_name' => isset($row['user_name']) ? $row['user_name'] : '',
                    'department' => isset($row['department']) ? $row['department'] : $department,
                    'component_name' => isset($row['component_name']) ? $row['component_name'] : '',
                    'quantity' => isset($row['quantity']) ? (int)$row['quantity'] : 0,
                    'progress_count' => isset($row['progress_count']) ? (int)$row['progress_count'] : 0,
                    'desired_delivery_time' => isset($row['desired_delivery_time']) ? $row['desired_delivery_time'] : null,
                    'is_completed' => isset($row['is_completed']) ? (bool)$row['is_completed'] : false,
                    'completed_at' => isset($row['completed_at']) ? $row['completed_at'] : null,
                    'created_at' => isset($row['created_at']) ? $row['created_at'] : date('Y-m-d H:i:s'),
                    'source_department' => isset($row['source_department']) ? $row['source_department'] : $department
                ];
            }
            
            $result->free();
            
        } catch (Exception $e) {
            $error = "Исключение при работе с БД {$department}: " . $e->getMessage();
            error_log($error);
            $errors[] = $error;
        } finally {
            if ($mysqli) {
                $mysqli->close();
            }
        }
    }
    
    // Сортируем все заявки по дате создания (новые сначала)
    usort($allRequests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return ['requests' => $allRequests, 'errors' => $errors];
}

try {
    // Получаем timestamp последней проверки
    $lastCheck = $_GET['last_check'] ?? 0;

    // Получаем все заявки
    $result = getAllLaserRequests($databases);
    $allRequests = $result['requests'];
    $dbErrors = $result['errors'];

    // Фильтруем только новые заявки (созданные после последней проверки)
    $newRequests = [];
    if ($lastCheck) {
        foreach ($allRequests as $request) {
            if (strtotime($request['created_at']) > $lastCheck) {
                $newRequests[] = $request;
            }
        }
    }

    // Подготавливаем данные для ответа
    $response = [
        'requests' => $allRequests,
        'new_requests' => $newRequests,
        'timestamp' => time(),
        'has_new' => count($newRequests) > 0,
        'errors' => $dbErrors,
        'debug' => [
            'last_check' => $lastCheck,
            'total_requests' => count($allRequests),
            'new_count' => count($newRequests)
        ]
    ];

    // Устанавливаем заголовки для JSON
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Fatal error in get_requests.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    http_response_code(500);
    
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>
