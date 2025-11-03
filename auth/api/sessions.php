<?php
/**
 * API для управления сессиями
 */

define('AUTH_SYSTEM', true);
require_once '../includes/config.php';
require_once '../includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

header('Content-Type: application/json');

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Получение данных запроса
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

$auth = new AuthManager();

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Проверка прав администратора
function getUserRoleInDepartment($userId, $departmentCode) {
    $db = Database::getInstance();
    $sql = "SELECT r.name FROM auth_user_departments ud 
            JOIN auth_roles r ON ud.role_id = r.id 
            WHERE ud.user_id = ? AND ud.department_code = ? AND ud.is_active = 1";
    
    $result = $db->selectOne($sql, [$userId, $departmentCode]);
    return $result ? $result['name'] : null;
}

$userRole = getUserRoleInDepartment($session['user_id'], $_SESSION['auth_department'] ?? 'U2');
if ($userRole !== 'director') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$db = Database::getInstance();

try {
    switch ($action) {
        case 'terminate_session':
            $sessionId = $input['session_id'] ?? '';
            
            if (empty($sessionId)) {
                throw new Exception('Session ID is required');
            }
            
            $result = $auth->terminateSession($sessionId);
            
            if ($result) {
                // Логируем действие
                $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'session_terminated_by_admin', ?, ?, ?)", [
                    $session['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    json_encode(['session_id' => $sessionId])
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Session terminated']);
            } else {
                throw new Exception('Failed to terminate session');
            }
            break;
            
        case 'terminate_user_sessions':
            $userId = (int)($input['user_id'] ?? 0);
            
            if ($userId <= 0) {
                throw new Exception('User ID is required');
            }
            
            $count = $auth->terminateUserSessions($userId);
            
            if ($count !== false) {
                // Логируем действие
                $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'all_sessions_terminated_by_admin', ?, ?, ?)", [
                    $session['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    json_encode(['target_user_id' => $userId, 'sessions_count' => $count])
                ]);
                
                echo json_encode(['success' => true, 'message' => "Terminated $count sessions", 'count' => $count]);
            } else {
                throw new Exception('Failed to terminate user sessions');
            }
            break;
            
        case 'cleanup_expired':
            $count = $auth->cleanupExpiredSessions();
            
            // Логируем действие
            $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'expired_sessions_cleanup', ?, ?, ?)", [
                $session['user_id'],
                $_SERVER['REMOTE_ADDR'] ?? '::1',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                json_encode(['cleaned_sessions' => $count])
            ]);
            
            echo json_encode(['success' => true, 'message' => "Cleaned up $count expired sessions", 'count' => $count]);
            break;
            
        case 'get_active_sessions':
            $sessions = $db->select("
                SELECT s.*, u.phone, u.full_name,
                       TIMESTAMPDIFF(MINUTE, s.last_activity, NOW()) as inactive_minutes,
                       TIMESTAMPDIFF(MINUTE, NOW(), s.expires_at) as expires_in_minutes
                FROM auth_sessions s 
                JOIN auth_users u ON s.user_id = u.id 
                WHERE s.expires_at > NOW() 
                ORDER BY s.last_activity DESC
            ");
            
            echo json_encode(['success' => true, 'sessions' => $sessions]);
            break;
            
        default:
            throw new Exception('Unknown action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
















