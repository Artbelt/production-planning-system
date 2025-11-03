<?php
/**
 * Основные функции авторизации
 */

if (!defined('AUTH_SYSTEM')) {
    die('Прямой доступ запрещен');
}

require_once 'database.php';

class AuthManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Аутентификация пользователя
     */
    public function authenticate($phone, $password) {
        // Очистка номера телефона
        $phone = $this->cleanPhone($phone);
        
        // Поиск пользователя
        $user = $this->getUserByPhone($phone);
        
        if (!$user) {
            $this->logFailedAttempt($phone, 'user_not_found');
            return ['success' => false, 'error' => 'Неверный номер телефона или пароль'];
        }
        
        if (!$user['is_active']) {
            $this->logFailedAttempt($phone, 'account_disabled');
            return ['success' => false, 'error' => 'Аккаунт отключен'];
        }
        
        // Проверка блокировки
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $unlockTime = date('H:i', strtotime($user['locked_until']));
            return ['success' => false, 'error' => "Аккаунт заблокирован до {$unlockTime}"];
        }
        
        // Проверка пароля
        if (!password_verify($password, $user['password_hash'])) {
            $this->incrementFailedAttempts($user['id']);
            $this->logFailedAttempt($phone, 'wrong_password');
            return ['success' => false, 'error' => 'Неверный номер телефона или пароль'];
        }
        
        // Успешная аутентификация
        $this->resetFailedAttempts($user['id']);
        $this->updateLastLogin($user['id']);
        $this->logSuccessfulLogin($user['id']);
        
        return ['success' => true, 'user' => $user];
    }
    
    /**
     * Создание сессии
     */
    public function createSession($userId, $departmentCode = null) {
        $sessionId = $this->generateSessionId();
        $expiresAt = date('Y-m-d H:i:s', time() + AUTH_SECURITY['session_lifetime']);
        
        $sql = "INSERT INTO auth_sessions (id, user_id, department_code, ip_address, user_agent, expires_at) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $params = [
            $sessionId,
            $userId,
            $departmentCode,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            $expiresAt
        ];
        
        $result = $this->db->insert($sql, $params);
        
        if ($result) {
            // Установка сессии PHP
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['auth_session_id'] = $sessionId;
            $_SESSION['auth_user_id'] = $userId;
            $_SESSION['auth_department'] = $departmentCode;
            $_SESSION['auth_expires'] = time() + AUTH_SECURITY['session_lifetime'];
            
            return $sessionId;
        }
        
        // Логирование ошибки создания сессии
        if (DEV_CONFIG['debug_mode']) {
            error_log("Failed to create session for user $userId: " . print_r($params, true));
        }
        
        return false;
    }
    
    /**
     * Проверка активной сессии
     */
    public function checkSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['auth_session_id']) || !isset($_SESSION['auth_user_id'])) {
            return false;
        }
        
        // Проверка времени истечения
        if (isset($_SESSION['auth_expires']) && $_SESSION['auth_expires'] < time()) {
            $this->destroySession();
            return false;
        }
        
        // Проверка сессии в БД
        $sql = "SELECT s.*, u.phone, u.full_name, u.is_active 
                FROM auth_sessions s 
                JOIN auth_users u ON s.user_id = u.id 
                WHERE s.id = ? AND s.expires_at > NOW()";
        
        $session = $this->db->selectOne($sql, [$_SESSION['auth_session_id']]);
        
        if (!$session || !$session['is_active']) {
            $this->destroySession();
            return false;
        }
        
        // Обновление активности
        $this->updateSessionActivity($_SESSION['auth_session_id']);
        
        return $session;
    }
    
    /**
     * Уничтожение сессии
     */
    public function destroySession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['auth_session_id'])) {
            // Удаление из БД
            $sql = "DELETE FROM auth_sessions WHERE id = ?";
            $this->db->delete($sql, [$_SESSION['auth_session_id']]);
            
            // Логирование выхода
            if (isset($_SESSION['auth_user_id'])) {
                $this->logLogout($_SESSION['auth_user_id']);
            }
        }
        
        // Очистка сессии PHP
        session_unset();
        session_destroy();
    }
    
    /**
     * Получение пользователя по номеру телефона
     */
    public function getUserByPhone($phone) {
        $cleanPhone = $this->cleanPhone($phone);
        
        // Сначала ищем по точному совпадению очищенного номера
        $sql = "SELECT * FROM auth_users WHERE phone = ?";
        $user = $this->db->selectOne($sql, [$cleanPhone]);
        
        if ($user) {
            return $user;
        }
        
        // Если не найден, ищем среди всех номеров, сравнивая очищенные версии
        $allUsers = $this->db->select("SELECT * FROM auth_users");
        foreach ($allUsers as $user) {
            $userCleanPhone = $this->cleanPhone($user['phone']);
            if ($userCleanPhone === $cleanPhone) {
                return $user;
            }
        }
        
        return null;
    }
    
    /**
     * Получение доступных цехов для пользователя
     */
    public function getUserDepartments($userId) {
        $sql = "SELECT ud.department_code, ud.is_active, r.name as role_name, r.display_name as role_display_name
                FROM auth_user_departments ud
                JOIN auth_roles r ON ud.role_id = r.id
                WHERE ud.user_id = ? AND ud.is_active = 1
                ORDER BY ud.department_code";
        
        return $this->db->select($sql, [$userId]);
    }
    
    /**
     * Проверка прав доступа к цеху
     */
    public function hasAccessToDepartment($userId, $departmentCode) {
        $sql = "SELECT COUNT(*) as count FROM auth_user_departments 
                WHERE user_id = ? AND department_code = ? AND is_active = 1";
        
        $result = $this->db->selectOne($sql, [$userId, $departmentCode]);
        return $result && $result['count'] > 0;
    }
    
    
    /**
     * Очистка номера телефона
     */
    private function cleanPhone($phone) {
        // Удаляем все кроме цифр и знака +
        $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
        
        // Приводим к стандартному формату
        if (strlen($cleanPhone) === 11 && substr($cleanPhone, 0, 1) === '8') {
            $cleanPhone = '+7' . substr($cleanPhone, 1);
        } elseif (strlen($cleanPhone) === 10) {
            $cleanPhone = '+7' . $cleanPhone;
        } elseif (strlen($cleanPhone) === 11 && substr($cleanPhone, 0, 1) === '7') {
            $cleanPhone = '+' . $cleanPhone;
        } elseif (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 2) === '38') {
            // Украинские номера: 380xxxxxxxxx -> +380xxxxxxxxx
            $cleanPhone = '+' . $cleanPhone;
        } elseif (strlen($cleanPhone) === 11 && substr($cleanPhone, 0, 1) === '3') {
            // Украинские номера: 380xxxxxxxx -> +380xxxxxxxx
            $cleanPhone = '+' . $cleanPhone;
        } elseif (strlen($cleanPhone) === 10 && substr($cleanPhone, 0, 2) === '80') {
            // Украинские номера: 80xxxxxxxx -> +380xxxxxxxx
            $cleanPhone = '+3' . $cleanPhone;
        } elseif (strlen($cleanPhone) === 9 && substr($cleanPhone, 0, 2) === '80') {
            // Украинские номера: 80xxxxxxx -> +380xxxxxxx
            $cleanPhone = '+3' . $cleanPhone;
        }
        
        return $cleanPhone;
    }
    
    /**
     * Генерация ID сессии
     */
    private function generateSessionId() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Увеличение счетчика неудачных попыток
     */
    private function incrementFailedAttempts($userId) {
        $sql = "UPDATE auth_users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = ?";
        $this->db->update($sql, [$userId]);
        
        // Проверка необходимости блокировки
        $user = $this->db->selectOne("SELECT failed_login_attempts FROM auth_users WHERE id = ?", [$userId]);
        
        if ($user && $user['failed_login_attempts'] >= AUTH_SECURITY['max_failed_attempts']) {
            $lockUntil = date('Y-m-d H:i:s', time() + AUTH_SECURITY['lockout_duration']);
            $sql = "UPDATE auth_users SET locked_until = ? WHERE id = ?";
            $this->db->update($sql, [$lockUntil, $userId]);
            
            $this->logAccountLocked($userId);
        }
    }
    
    /**
     * Сброс счетчика неудачных попыток
     */
    private function resetFailedAttempts($userId) {
        $sql = "UPDATE auth_users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?";
        $this->db->update($sql, [$userId]);
    }
    
    /**
     * Обновление времени последнего входа
     */
    private function updateLastLogin($userId) {
        $sql = "UPDATE auth_users SET last_login = NOW() WHERE id = ?";
        $this->db->update($sql, [$userId]);
    }
    
    /**
     * Обновление активности сессии
     */
    private function updateSessionActivity($sessionId) {
        $sql = "UPDATE auth_sessions SET last_activity = NOW() WHERE id = ?";
        $this->db->update($sql, [$sessionId]);
    }
    
    /**
     * Логирование неудачной попытки входа
     */
    private function logFailedAttempt($phone, $reason) {
        $sql = "INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) 
                VALUES (NULL, 'failed_login', ?, ?, ?)";
        
        $details = json_encode(['phone' => $phone, 'reason' => $reason]);
        
        $this->db->insert($sql, [
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            $details
        ]);
    }
    
    /**
     * Логирование успешного входа
     */
    private function logSuccessfulLogin($userId) {
        $sql = "INSERT INTO auth_logs (user_id, action, ip_address, user_agent) 
                VALUES (?, 'login', ?, ?)";
        
        $this->db->insert($sql, [
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    }
    
    /**
     * Логирование выхода
     */
    private function logLogout($userId) {
        $sql = "INSERT INTO auth_logs (user_id, action, ip_address, user_agent) 
                VALUES (?, 'logout', ?, ?)";
        
        $this->db->insert($sql, [
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    }
    
    /**
     * Логирование блокировки аккаунта
     */
    private function logAccountLocked($userId) {
        $sql = "INSERT INTO auth_logs (user_id, action, ip_address, user_agent) 
                VALUES (?, 'account_locked', ?, ?)";
        
        $this->db->insert($sql, [
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    }
    
    
    /**
     * Завершение сессии по ID (для админов)
     */
    public function terminateSession($sessionId) {
        // Получаем информацию о сессии перед удалением
        $sessionInfo = $this->db->selectOne("SELECT user_id FROM auth_sessions WHERE id = ?", [$sessionId]);
        
        if (!$sessionInfo) {
            return false;
        }
        
        // Удаляем сессию
        $result = $this->db->delete("DELETE FROM auth_sessions WHERE id = ?", [$sessionId]);
        
        return $result !== false;
    }
    
    /**
     * Завершение всех сессий пользователя
     */
    public function terminateUserSessions($userId) {
        // Получаем количество активных сессий
        $count = $this->db->selectOne("SELECT COUNT(*) as count FROM auth_sessions WHERE user_id = ? AND expires_at > NOW()", [$userId]);
        
        // Удаляем все активные сессии пользователя
        $result = $this->db->delete("DELETE FROM auth_sessions WHERE user_id = ? AND expires_at > NOW()", [$userId]);
        
        return $result !== false ? $count['count'] : false;
    }
    
    /**
     * Очистка истекших сессий
     */
    public function cleanupExpiredSessions() {
        $result = $this->db->delete("DELETE FROM auth_sessions WHERE expires_at <= NOW()");
        
        return $result !== false ? $result : 0;
    }
}

?>
