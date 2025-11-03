<?php
/**
 * API для проверки авторизации
 * Используется существующими системами для проверки прав доступа
 */

define('AUTH_SYSTEM', true);
require_once '../includes/config.php';
require_once '../includes/auth-functions.php';

// Установка заголовков для API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

try {
    // Проверка сессии
    $session = $auth->checkSession();
    
    if (!$session) {
        http_response_code(401);
        echo json_encode([
            'authenticated' => false,
            'error' => 'Не авторизован'
        ]);
        exit;
    }
    
    // Получение информации о пользователе
    $userDepartments = $auth->getUserDepartments($session['user_id']);
    
    // Формирование ответа
    $response = [
        'authenticated' => true,
        'user' => [
            'id' => $session['user_id'],
            'phone' => $session['phone'],
            'full_name' => $session['full_name'],
            'current_department' => $_SESSION['auth_department'] ?? null,
            'last_login' => $session['last_login']
        ],
        'departments' => [],
        'session' => [
            'id' => $session['id'],
            'expires_at' => $session['expires_at'],
            'last_activity' => $session['last_activity']
        ]
    ];
    
    // Добавление информации о цехах
    $departments = [
        'U2' => ['name' => 'Участок 2'],
        'U3' => ['name' => 'Участок 3'],
        'U4' => ['name' => 'Участок 4'],
        'U5' => ['name' => 'Участок 5']
    ];
    
    foreach ($userDepartments as $dept) {
        $response['departments'][] = [
            'code' => $dept['department_code'],
            'name' => $departments[$dept['department_code']]['name'] ?? $dept['department_code'],
            'role' => $dept['role_name'],
            'role_display_name' => $dept['role_display_name']
        ];
    }
    
    // Проверка конкретного цеха (если указан в параметрах)
    if (isset($_GET['department'])) {
        $departmentCode = $_GET['department'];
        $hasAccess = $auth->hasAccessToDepartment($session['user_id'], $departmentCode);
        
        $response['department_access'] = [
            'department' => $departmentCode,
            'has_access' => $hasAccess
        ];
        
        if (!$hasAccess) {
            http_response_code(403);
            $response['error'] = 'Нет доступа к цеху ' . $departmentCode;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'authenticated' => false,
        'error' => 'Внутренняя ошибка сервера',
        'debug' => DEV_CONFIG['debug_mode'] ? $e->getMessage() : null
    ], JSON_UNESCAPED_UNICODE);
}

?>
