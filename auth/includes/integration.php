<?php
/**
 * Файл интеграции с существующими системами
 * Подключается в начале каждой страницы существующих систем
 */

if (!defined('AUTH_SYSTEM')) {
    define('AUTH_SYSTEM', true);
}

// Определение пути к системе авторизации
$authPath = dirname(dirname(__FILE__));
if (!file_exists($authPath . '/includes/config.php')) {
    // Попытка найти auth директорию
    $possiblePaths = [
        $_SERVER['DOCUMENT_ROOT'] . '/auth',
        dirname($_SERVER['DOCUMENT_ROOT']) . '/auth',
        __DIR__ . '/../../auth'
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/includes/config.php')) {
            $authPath = $path;
            break;
        }
    }
}

require_once $authPath . '/includes/config.php';
require_once $authPath . '/includes/auth-functions.php';

/**
 * Проверка авторизации для существующих систем
 * 
 * @param string|null $requiredDepartment Код цеха (U2, U3, U4, U5)
 * @param string|null $requiredRole Минимальная роль (worker, manager, supervisor, director)
 * @return array|false Информация о пользователе или false
 */
function checkAuthIntegration($requiredDepartment = null, $requiredRole = null) {
    try {
        initAuthSystem();
        
        $auth = new AuthManager();
        $session = $auth->checkSession();
        
        if (!$session) {
            redirectToAuth();
            return false;
        }
        
        // Проверка доступа к цеху
        if ($requiredDepartment) {
            if (!$auth->hasAccessToDepartment($session['user_id'], $requiredDepartment)) {
                showAccessDenied('Нет доступа к цеху ' . $requiredDepartment);
                return false;
            }
            
            // Установка текущего цеха если не установлен
            if (empty($_SESSION['auth_department'])) {
                $auth->switchDepartment($requiredDepartment);
            }
        }
        
        // Проверка роли (если требуется)
        if ($requiredRole) {
            $userRole = getUserRoleInDepartment($session['user_id'], $requiredDepartment);
            if (!checkRolePermission($userRole, $requiredRole)) {
                showAccessDenied('Недостаточно прав доступа');
                return false;
            }
        }
        
        return [
            'user_id' => $session['user_id'],
            'phone' => $session['phone'],
            'full_name' => $session['full_name'],
            'current_department' => $_SESSION['auth_department'] ?? null,
            'session_id' => $session['id']
        ];
        
    } catch (Exception $e) {
        if (DEV_CONFIG['debug_mode']) {
            die('Ошибка авторизации: ' . $e->getMessage());
        } else {
            redirectToAuth();
            return false;
        }
    }
}

/**
 * Получение роли пользователя в цехе
 */
function getUserRoleInDepartment($userId, $departmentCode) {
    $db = Database::getInstance();
    $sql = "SELECT r.name FROM auth_user_departments ud 
            JOIN auth_roles r ON ud.role_id = r.id 
            WHERE ud.user_id = ? AND ud.department_code = ? AND ud.is_active = 1";
    
    $result = $db->selectOne($sql, [$userId, $departmentCode]);
    return $result ? $result['name'] : null;
}

/**
 * Проверка прав роли
 */
function checkRolePermission($userRole, $requiredRole) {
    $roleHierarchy = ['worker' => 1, 'manager' => 2, 'supervisor' => 3, 'director' => 4];
    
    $userLevel = $roleHierarchy[$userRole] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

/**
 * Перенаправление на страницу авторизации
 */
function redirectToAuth() {
    $authUrl = '/auth/login.php';
    
    // Сохранение текущего URL для возврата после авторизации
    $returnUrl = $_SERVER['REQUEST_URI'] ?? '';
    if ($returnUrl) {
        $authUrl .= '?return=' . urlencode($returnUrl);
    }
    
    header('Location: ' . $authUrl);
    exit;
}

/**
 * Показ страницы отказа в доступе
 */
function showAccessDenied($message = 'Доступ запрещен') {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Доступ запрещен</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                text-align: center; 
                padding: 50px; 
                background: #f5f5f5; 
            }
            .error-container { 
                background: white; 
                padding: 40px; 
                border-radius: 8px; 
                box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
                max-width: 500px; 
                margin: 0 auto; 
            }
            .error-code { 
                font-size: 72px; 
                font-weight: bold; 
                color: #dc2626; 
                margin: 0; 
            }
            .error-message { 
                font-size: 18px; 
                color: #374151; 
                margin: 20px 0; 
            }
            .btn { 
                display: inline-block; 
                padding: 12px 24px; 
                background: #2563eb; 
                color: white; 
                text-decoration: none; 
                border-radius: 6px; 
                margin: 10px; 
            }
            .btn:hover { 
                background: #1d4ed8; 
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-code">403</div>
            <div class="error-message"><?= htmlspecialchars($message) ?></div>
            <a href="/auth/select-department.php" class="btn">Выбрать цех</a>
            <a href="/auth/logout.php" class="btn">Выйти</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Получение информации о текущем пользователе
 */
function getCurrentAuthUser() {
    static $user = null;
    
    if ($user === null) {
        $user = checkAuthIntegration();
    }
    
    return $user;
}

/**
 * Проверка конкретного разрешения
 */
function hasPermission($permission, $departmentCode = null) {
    $user = getCurrentAuthUser();
    if (!$user) return false;
    
    $userRole = getUserRoleInDepartment($user['user_id'], $departmentCode ?: $_SESSION['auth_department']);
    
    // Директор имеет все права
    if ($userRole === 'director') return true;
    
    // Проверка конкретных прав по роли
    $rolePermissions = [
        'worker' => ['view_orders', 'update_order_status', 'view_production_plan', 'mark_task_complete'],
        'manager' => ['view_orders', 'create_orders', 'edit_orders', 'delete_orders', 'view_production_plan', 'edit_production_plan', 'view_reports', 'manage_filters'],
        'supervisor' => ['*_orders', '*_production_plan', '*_reports', '*_filters', 'manage_workers', 'view_analytics', 'export_data']
    ];
    
    $permissions = $rolePermissions[$userRole] ?? [];
    
    // Проверка точного совпадения
    if (in_array($permission, $permissions)) return true;
    
    // Проверка масок (например, *_orders для всех операций с заказами)
    foreach ($permissions as $perm) {
        if (strpos($perm, '*_') === 0) {
            $suffix = substr($perm, 2);
            if (strpos($permission, $suffix) !== false) return true;
        }
    }
    
    return false;
}

/**
 * Логирование действий пользователя
 */
function logUserAction($action, $details = null, $departmentCode = null) {
    $user = getCurrentAuthUser();
    if (!$user) return;
    
    $db = Database::getInstance();
    $sql = "INSERT INTO auth_logs (user_id, action, department_code, ip_address, user_agent, details) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $db->insert($sql, [
        $user['user_id'],
        $action,
        $departmentCode ?: $_SESSION['auth_department'],
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        $details ? json_encode($details) : null
    ]);
}

?>
















