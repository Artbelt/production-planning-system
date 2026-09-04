<?php
/**
 * API входа (JSON) — для модального логина без редиректа
 */

define('AUTH_SYSTEM', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth-functions.php';
require_once __DIR__ . '/../includes/password-functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

initAuthSystem();

$auth = new AuthManager();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$phone = trim((string)($input['phone'] ?? ''));
$password = trim((string)($input['password'] ?? ''));
$department = trim((string)($input['department'] ?? ''));

if ($phone === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Заполните все поля'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $existing = $auth->checkSession();
    if ($existing) {
        echo json_encode([
            'success' => true,
            'already_authenticated' => true,
            'user' => [
                'id' => (int)$existing['user_id'],
                'phone' => $existing['phone'],
                'full_name' => $existing['full_name'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $auth->authenticate($phone, $password);
    if (!$result['success']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user = $result['user'];
    $userDepartments = $auth->getUserDepartments($user['id']);
    $firstDepartment = $userDepartments[0]['department_code'] ?? 'U2';
    if ($department !== '' && $auth->hasAccessToDepartment($user['id'], $department)) {
        $firstDepartment = $department;
    }

    $sessionId = $auth->createSession($user['id'], $firstDepartment);
    if (!$sessionId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Ошибка создания сессии'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['auth_department'] = $firstDepartment;

    if (!empty($user['is_default_password'])) {
        $_SESSION['has_default_password'] = true;
        if (shouldRemindPasswordChange($user['id'])) {
            markPasswordReminderSent($user['id']);
            $_SESSION['password_reminder_shown'] = true;
        }
    } else {
        $_SESSION['has_default_password'] = false;
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int)$user['id'],
            'phone' => $user['phone'],
            'full_name' => $user['full_name'],
            'department' => $firstDepartment,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера',
        'debug' => !empty(DEV_CONFIG['debug_mode']) ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
