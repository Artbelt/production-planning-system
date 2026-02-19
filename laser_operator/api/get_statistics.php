<?php
/**
 * API endpoint для получения статистики операторов лазерной резки за день
 * Возвращает JSON с данными о количестве выполненных заявок для каждого логина
 */

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

// Настройки подключений к базам данных (из env.php)
if (file_exists(__DIR__ . '/../../env.php')) require __DIR__ . '/../../env.php';
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';
$databases = [
    'U2' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan'],
    'U3' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u3'],
    'U4' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u4'],
    'U5' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u5']
];

header('Content-Type: application/json; charset=utf-8');

// Генерируем массив дат за последние 6 дней
$dates = [];
for ($i = 0; $i < 6; $i++) {
    $dates[] = date('Y-m-d', strtotime("-$i days"));
}

// Статистика по дням
$statisticsByDate = [];

foreach ($dates as $date) {
    $statisticsByDate[$date] = [
        'created_count' => 0,
        'completed_count' => 0
    ];
}

foreach ($databases as $department => $dbConfig) {
    try {
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
        
        if ($mysqli->connect_errno) {
            error_log("Ошибка подключения к БД {$department}: " . $mysqli->connect_error);
            continue;
        }
        
        $datePlaceholders = implode(',', array_fill(0, count($dates), '?'));
        
        // Получаем количество созданных заявок за день
        $sqlCreated = "SELECT 
                        DATE(created_at) as request_date,
                        COUNT(*) as created_count
                    FROM laser_requests 
                    WHERE DATE(created_at) IN ($datePlaceholders)
                    AND (is_cancelled = FALSE OR is_cancelled IS NULL)
                    GROUP BY DATE(created_at)";
        
        $stmtCreated = $mysqli->prepare($sqlCreated);
        $stmtCreated->bind_param(str_repeat('s', count($dates)), ...$dates);
        $stmtCreated->execute();
        $resultCreated = $stmtCreated->get_result();
        
        while ($row = $resultCreated->fetch_assoc()) {
            $date = $row['request_date'];
            if (isset($statisticsByDate[$date])) {
                $statisticsByDate[$date]['created_count'] += (int)$row['created_count'];
            }
        }
        $stmtCreated->close();
        
        // Получаем количество выполненных заявок за день
        $sqlCompleted = "SELECT 
                        DATE(completed_at) as completion_date,
                        COUNT(*) as completed_count
                    FROM laser_requests 
                    WHERE is_completed = TRUE 
                    AND DATE(completed_at) IN ($datePlaceholders)
                    AND completed_at IS NOT NULL
                    AND (is_cancelled = FALSE OR is_cancelled IS NULL)
                    GROUP BY DATE(completed_at)";
        
        $stmtCompleted = $mysqli->prepare($sqlCompleted);
        $stmtCompleted->bind_param(str_repeat('s', count($dates)), ...$dates);
        $stmtCompleted->execute();
        $resultCompleted = $stmtCompleted->get_result();
        
        while ($row = $resultCompleted->fetch_assoc()) {
            $date = $row['completion_date'];
            if (isset($statisticsByDate[$date])) {
                $statisticsByDate[$date]['completed_count'] += (int)$row['completed_count'];
            }
        }
        $stmtCompleted->close();
        
        $mysqli->close();
    } catch (Exception $e) {
        error_log("Ошибка получения статистики для {$department}: " . $e->getMessage());
    }
}

// Функция для получения фамилии оператора, который работал в этот день
function getOperatorSurnameForDate($date) {
    global $db;
    
    // Ищем оператора в таблице laser_operator_daily
    try {
        $operator = $db->selectOne("
            SELECT operator_surname 
            FROM laser_operator_daily 
            WHERE date = ?
        ", [$date]);
        
        if ($operator && !empty($operator['operator_surname'])) {
            return $operator['operator_surname'];
        }
    } catch (Exception $e) {
        // Таблица может не существовать - это нормально
        error_log("Ошибка получения оператора для даты {$date}: " . $e->getMessage());
    }
    
    return null;
}

// Преобразуем данные в нужный формат
$result = [];
foreach ($dates as $date) {
    $stats = $statisticsByDate[$date];
    
    // Получаем фамилию оператора, который логинился в модуль лазерной резки в этот день
    $operatorSurname = getOperatorSurnameForDate($date);
    
    $result[] = [
        'date' => $date,
        'date_formatted' => date('d.m.Y', strtotime($date)),
        'date_label' => $date === date('Y-m-d') ? 'Сегодня' : ($date === date('Y-m-d', strtotime('-1 day')) ? 'Вчера' : date('d.m.Y', strtotime($date))),
        'operator_surname' => $operatorSurname,
        'created_count' => $stats['created_count'],
        'completed_count' => $stats['completed_count']
    ];
}

echo json_encode([
    'success' => true,
    'days' => $result
], JSON_UNESCAPED_UNICODE);

