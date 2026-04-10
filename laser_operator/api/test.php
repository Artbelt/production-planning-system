<?php
/**
 * Простой тестовый endpoint для диагностики проблем с мобильными устройствами
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

require_once dirname(__DIR__) . '/daily_auth_load.php';
laser_operator_load_daily_auth_file(['json_errors' => true]);
laser_operator_require_same_calendar_day($auth, true);

// Устанавливаем заголовки для JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Простой тестовый ответ
$response = [
    'status' => 'ok',
    'timestamp' => time(),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'server_time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'session_id' => session_id(),
    'user_id' => $session['user_id'] ?? 'unknown'
];

echo json_encode($response);
?>







