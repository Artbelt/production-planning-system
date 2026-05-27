<?php
/**
 * Редактирование пользователя и управление его правами
 */

define('AUTH_SYSTEM', true);
require_once '../includes/config.php';
require_once '../includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка авторизации и прав администратора
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../login.php');
    exit;
}

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
    header('Location: ../select-department.php?error=access_denied');
    exit;
}

$db = Database::getInstance();
$message = '';
$error = '';

// Получение ID пользователя
$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: users.php');
    exit;
}

// Получение данных пользователя
$user = $db->selectOne("SELECT * FROM auth_users WHERE id = ?", [$userId]);
if (!$user) {
    header('Location: users.php?error=user_not_found');
    exit;
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_user') {
        $phone = trim($_POST['phone']);
        $fullName = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $newPassword = trim($_POST['new_password']);
        
        if (empty($phone) || empty($fullName)) {
            $error = 'Заполните обязательные поля';
        } else {
            // Проверка уникальности телефона (кроме текущего пользователя)
            $existing = $db->selectOne("SELECT id FROM auth_users WHERE phone = ? AND id != ?", [$phone, $userId]);
            
            if ($existing) {
                $error = 'Пользователь с таким номером уже существует';
            } else {
                $updateData = [$phone, $fullName, $email, $userId];
                $sql = "UPDATE auth_users SET phone = ?, full_name = ?, email = ? WHERE id = ?";
                
                // Если указан новый пароль
                if (!empty($newPassword)) {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $sql = "UPDATE auth_users SET phone = ?, full_name = ?, email = ?, password_hash = ? WHERE id = ?";
                    $updateData = [$phone, $fullName, $email, $passwordHash, $userId];
                }
                
                $result = $db->update($sql, $updateData);
                
                if ($result !== false) {
                    $message = 'Данные пользователя обновлены';
                    // Обновляем данные для отображения
                    $user['phone'] = $phone;
                    $user['full_name'] = $fullName;
                    $user['email'] = $email;
                } else {
                    $error = 'Ошибка обновления данных';
                }
            }
        }
    }
    
    if ($action === 'update_departments') {
        $departments = $_POST['departments'] ?? [];
        
        // Удаляем все текущие назначения
        $db->delete("DELETE FROM auth_user_departments WHERE user_id = ?", [$userId]);
        
        // Добавляем новые назначения
        $success = true;
        foreach ($departments as $deptCode => $roleId) {
            if (!empty($roleId)) {
                $result = $db->insert("INSERT INTO auth_user_departments (user_id, department_code, role_id, granted_by) VALUES (?, ?, ?, ?)", 
                    [$userId, $deptCode, $roleId, $session['user_id']]);
                
                if (!$result) {
                    $success = false;
                }
            }
        }
        
        if ($success) {
            $message = 'Права доступа обновлены';
        } else {
            $error = 'Ошибка обновления прав доступа';
        }
    }
    
    if ($action === 'unlock_account') {
        // Проверка существования пользователя
        $checkUser = $db->selectOne("SELECT id, full_name FROM auth_users WHERE id = ?", [$userId]);
        
        if (!$checkUser) {
            $error = 'Пользователь не найден';
        } else {
            // Разблокировка аккаунта
            $result = $db->update("UPDATE auth_users SET locked_until = NULL, failed_login_attempts = 0 WHERE id = ?", [$userId]);
            
            if ($result !== false) {
                // Логирование разблокировки
                $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'account_unlocked', ?, ?, ?)", [
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    json_encode(['unlocked_by' => $session['user_id'], 'unlocked_by_name' => $session['full_name']])
                ]);
                
                $message = "Аккаунт пользователя успешно разблокирован";
                // Обновляем данные пользователя для отображения
                $user = $db->selectOne("SELECT * FROM auth_users WHERE id = ?", [$userId]);
            } else {
                $error = 'Ошибка разблокировки аккаунта';
            }
        }
    }
    
    if ($action === 'reset_password') {
        require_once '../includes/password-functions.php';
        
        // Проверка существования пользователя
        $checkUser = $db->selectOne("SELECT id, full_name, phone FROM auth_users WHERE id = ?", [$userId]);
        
        if (!$checkUser) {
            $error = 'Пользователь не найден';
        } else {
            $result = resetPasswordToDefault($userId, $session['user_id']);
            
            if ($result['success']) {
                $message = "Пароль пользователя сброшен на дефолтный: <strong>{$result['default_password']}</strong>";
                // Обновляем данные пользователя для отображения
                $user = $db->selectOne("SELECT * FROM auth_users WHERE id = ?", [$userId]);
            } else {
                $error = $result['error'];
            }
        }
    }
}

// Получение ролей
$roles = $db->select("SELECT * FROM auth_roles WHERE is_active = 1 ORDER BY id");

// Получение текущих назначений пользователя
$userDepartments = $db->select("
    SELECT ud.*, r.display_name as role_name 
    FROM auth_user_departments ud 
    JOIN auth_roles r ON ud.role_id = r.id 
    WHERE ud.user_id = ? AND ud.is_active = 1
", [$userId]);

$currentDepartments = [];
foreach ($userDepartments as $dept) {
    $currentDepartments[$dept['department_code']] = $dept['role_id'];
}

// Получение логов пользователя
$userLogs = $db->select("
    SELECT * FROM auth_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
", [$userId]);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование пользователя - Админ панель</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .admin-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background: var(--gray-50);
            min-height: 100vh;
        }
        
        .admin-header {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .content-card {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            padding: 20px;
        }
        
        .card-title {
            margin: 0 0 20px;
            color: var(--gray-900);
            font-size: 18px;
        }
        
        .departments-grid {
            display: grid;
            gap: 15px;
        }
        
        .department-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius);
        }
        
        .department-name {
            font-weight: 500;
        }
        
        .logs-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .log-item {
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .log-item:last-child {
            border-bottom: none;
        }
        
        .log-action {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .action-login { background: var(--success-light); color: var(--success); }
        .action-logout { background: var(--gray-200); color: var(--gray-700); }
        .action-failed_login { background: var(--danger-light); color: var(--danger); }
        .action-department_switch { background: var(--primary-light); color: var(--primary); }
        .action-account_locked { background: var(--danger-light); color: var(--danger); }
        .action-account_unlocked { background: var(--success-light); color: var(--success); }
        
        .lock-status-card {
            background: var(--warning-light);
            border: 1px solid var(--warning);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .lock-status-card.locked {
            background: var(--danger-light);
            border-color: var(--danger);
        }
        
        .lock-status-card.unlocked {
            background: var(--success-light);
            border-color: var(--success);
        }
        
        .password-info-card {
            background: var(--primary-light);
            border: 1px solid var(--primary);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .password-status-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .password-status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .password-default {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .password-custom {
            background: var(--success-light);
            color: var(--success);
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1 style="margin: 0;">✏️ Редактирование пользователя</h1>
            <p style="margin: 5px 0 0; color: var(--gray-600);">
                <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['phone']) ?>)
            </p>
            <div style="margin-top: 15px;">
                <a href="users.php" class="btn btn-secondary">🔙 К списку пользователей</a>
                <a href="page-stats.php" class="btn btn-secondary">📊 Страницы</a>
                <a href="index.php" class="btn btn-secondary">🏠 Панель</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php 
        // Проверка статуса блокировки
        $isLocked = $user['locked_until'] && strtotime($user['locked_until']) > time();
        if ($isLocked || $user['locked_until']): 
        ?>
            <div class="lock-status-card <?= $isLocked ? 'locked' : 'unlocked' ?>">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin: 0 0 10px;">
                            <?= $isLocked ? '🔒 Аккаунт заблокирован' : '✓ Аккаунт разблокирован' ?>
                        </h3>
                        <?php if ($isLocked): ?>
                            <div style="margin-bottom: 5px;">
                                <strong>Заблокирован до:</strong> <?= date('d.m.Y H:i:s', strtotime($user['locked_until'])) ?>
                            </div>
                            <div style="margin-bottom: 5px;">
                                <strong>Неудачных попыток входа:</strong> <?= $user['failed_login_attempts'] ?? 0 ?>
                            </div>
                            <div style="font-size: 12px; color: var(--gray-600);">
                                Осталось времени: <?php
                                    $remaining = strtotime($user['locked_until']) - time();
                                    $minutes = floor($remaining / 60);
                                    $seconds = $remaining % 60;
                                    echo "{$minutes} мин. {$seconds} сек.";
                                ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 12px; color: var(--gray-600);">
                                Аккаунт был разблокирован автоматически
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($isLocked): ?>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="unlock_account">
                            <button type="submit" class="btn btn-success" 
                                    onclick="return confirm('Разблокировать аккаунт пользователя <?= htmlspecialchars($user['full_name']) ?>?')">
                                🔓 Разблокировать сейчас
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Информация о пароле -->
        <div class="password-info-card">
            <div class="password-status-info">
                <div>
                    <h3 style="margin: 0 0 10px;">🔑 Статус пароля</h3>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                        <span>Тип пароля:</span>
                        <?php if ($user['is_default_password']): ?>
                            <span class="password-status-badge password-default">Базовый пароль</span>
                        <?php else: ?>
                            <span class="password-status-badge password-custom">Персональный пароль</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($user['password_changed_at']): ?>
                        <div style="font-size: 12px; color: var(--gray-600);">
                            Последняя смена: <?= date('d.m.Y H:i', strtotime($user['password_changed_at'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" class="btn btn-warning" 
                            onclick="return confirm('Сбросить пароль пользователя <?= htmlspecialchars($user['full_name']) ?> на дефолтный (последние 4 цифры телефона)?\n\nВсе активные сессии пользователя будут завершены.')">
                        🔑 Сбросить пароль
                    </button>
                </form>
            </div>
            <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px; font-size: 12px; color: var(--gray-700);">
                <strong>ℹ️ Информация:</strong> При сбросе пароля пользователь сможет войти в систему, используя последние 4 цифры своего номера телефона. 
                После входа рекомендуется сменить пароль на персональный через страницу "Смена пароля".
            </div>
        </div>

        <div class="content-grid">
            <!-- Основные данные -->
            <div class="content-card">
                <h2 class="card-title">👤 Основные данные</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_user">
                    
                    <div class="form-group">
                        <label class="form-label">Номер телефона *</label>
                        <input type="tel" name="phone" class="form-input" 
                               value="<?= htmlspecialchars($user['phone']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">ФИО *</label>
                        <input type="text" name="full_name" class="form-input" 
                               value="<?= htmlspecialchars($user['full_name']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" 
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Новый пароль</label>
                        <input type="password" name="new_password" class="form-input" 
                               placeholder="Оставьте пустым, чтобы не менять">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-full">💾 Сохранить изменения</button>
                </form>
            </div>

            <!-- Права доступа -->
            <div class="content-card">
                <h2 class="card-title">🔐 Права доступа к цехам</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_departments">
                    
                    <div class="departments-grid">
                        <?php 
                        $departments = [
                            'U2' => ['name' => 'Участок 2', 'is_active' => true],
                            'U3' => ['name' => 'Участок 3', 'is_active' => true],
                            'U4' => ['name' => 'Участок 4', 'is_active' => true],
                            'U5' => ['name' => 'Участок 5', 'is_active' => true]
                        ];
                        foreach ($departments as $deptCode => $deptInfo): ?>
                            <?php if ($deptInfo['is_active']): ?>
                                <div class="department-item">
                                    <div class="department-name">
                                        <?= htmlspecialchars($deptInfo['name']) ?>
                                        <div style="font-size: 12px; color: var(--gray-500);">
                                            <?= htmlspecialchars($deptCode) ?>
                                        </div>
                                    </div>
                                    <select name="departments[<?= $deptCode ?>]" class="form-select" style="width: 150px;">
                                        <option value="">Нет доступа</option>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= $role['id'] ?>" 
                                                    <?= isset($currentDepartments[$deptCode]) && $currentDepartments[$deptCode] == $role['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($role['display_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-full" style="margin-top: 20px;">
                        🔄 Обновить права доступа
                    </button>
                </form>
            </div>
        </div>

        <!-- История действий -->
        <div class="content-card full-width">
            <h2 class="card-title">📋 История действий пользователя</h2>
            
            <div class="logs-list">
                <?php if (empty($userLogs)): ?>
                    <p class="text-center text-gray-500">Нет записей</p>
                <?php else: ?>
                    <?php foreach ($userLogs as $log): ?>
                        <div class="log-item">
                            <div>
                                <div class="log-action action-<?= $log['action'] ?>">
                                    <?php
                                    $actionNames = [
                                        'account_locked' => 'Аккаунт заблокирован',
                                        'account_unlocked' => 'Аккаунт разблокирован',
                                        'failed_login' => 'Неудачный вход',
                                        'login' => 'Успешный вход',
                                        'logout' => 'Выход',
                                        'department_switch' => 'Переключение цеха'
                                    ];
                                    echo $actionNames[$log['action']] ?? ucfirst(str_replace('_', ' ', $log['action']));
                                    ?>
                                </div>
                                <?php if ($log['department_code']): ?>
                                    <div style="font-size: 12px; color: var(--gray-500);">
                                        Цех: <?= htmlspecialchars($log['department_code']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($log['details']): ?>
                                    <div style="font-size: 12px; color: var(--gray-500);">
                                        <?= htmlspecialchars($log['details']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 12px; color: var(--gray-500);">
                                    <?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?>
                                </div>
                                <div style="font-size: 12px; color: var(--gray-500);">
                                    IP: <?= htmlspecialchars($log['ip_address']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
