<?php
/**
 * Управление пользователями
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

// Проверка прав директора
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

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_user') {
        $userId = (int)$_POST['user_id'];
        $currentStatus = (int)$_POST['current_status'];
        $newStatus = $currentStatus ? 0 : 1;
        
        $result = $db->update("UPDATE auth_users SET is_active = ? WHERE id = ?", [$newStatus, $userId]);
        
        if ($result !== false) {
            $message = $newStatus ? 'Пользователь активирован' : 'Пользователь деактивирован';
        } else {
            $error = 'Ошибка изменения статуса пользователя';
        }
    }
    
    if ($action === 'create_user') {
        require_once '../includes/password-functions.php';
        
        $phone = trim($_POST['phone']);
        $fullName = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        
        if (empty($phone) || empty($fullName)) {
            $error = 'Заполните обязательные поля';
        } else {
            // Проверка уникальности телефона
            $existing = $db->selectOne("SELECT id FROM auth_users WHERE phone = ?", [$phone]);
            
            if ($existing) {
                $error = 'Пользователь с таким номером уже существует';
            } else {
                $result = createUserWithDefaultPassword($phone, $fullName, $email);
                
                if ($result['success']) {
                    $message = "Пользователь создан! Базовый пароль: <strong>{$result['default_password']}</strong>";
                } else {
                    $error = $result['error'];
                }
            }
        }
    }
    
    if ($action === 'unlock_account') {
        $userId = (int)$_POST['user_id'];
        
        // Проверка существования пользователя
        $user = $db->selectOne("SELECT id, full_name FROM auth_users WHERE id = ?", [$userId]);
        
        if (!$user) {
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
                
                $message = "Аккаунт пользователя {$user['full_name']} успешно разблокирован";
            } else {
                $error = 'Ошибка разблокировки аккаунта';
            }
        }
    }
    
    if ($action === 'reset_password') {
        require_once '../includes/password-functions.php';
        
        $userId = (int)$_POST['user_id'];
        
        // Проверка существования пользователя
        $user = $db->selectOne("SELECT id, full_name, phone FROM auth_users WHERE id = ?", [$userId]);
        
        if (!$user) {
            $error = 'Пользователь не найден';
        } else {
            $result = resetPasswordToDefault($userId, $session['user_id']);
            
            if ($result['success']) {
                $message = "Пароль пользователя {$user['full_name']} сброшен на дефолтный: <strong>{$result['default_password']}</strong>";
            } else {
                $error = $result['error'];
            }
        }
    }
}

// Получение списка пользователей с их ролями
$users = $db->select("
    SELECT u.*, 
           GROUP_CONCAT(CONCAT(ud.department_code, ':', r.display_name) SEPARATOR ', ') as roles
    FROM auth_users u
    LEFT JOIN auth_user_departments ud ON u.id = ud.user_id AND ud.is_active = 1
    LEFT JOIN auth_roles r ON ud.role_id = r.id
    GROUP BY u.id
    ORDER BY u.created_at DESC
");

// Получение ролей для формы
$roles = $db->select("SELECT * FROM auth_roles WHERE is_active = 1 ORDER BY id");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление пользователями - Админ панель</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .admin-container {
            max-width: 1200px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
        }
        
        .users-table {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .table-header {
            padding: 15px 20px;
            background: var(--gray-100);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray-100);
        }
        
        th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active {
            background: var(--success-light);
            color: var(--success);
        }
        
        .status-inactive {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-toggle {
            background: var(--warning);
            color: white;
        }
        
        .btn-edit {
            background: var(--primary);
            color: white;
        }
        
        .btn-unlock {
            background: var(--success);
            color: white;
        }
        
        .btn-reset-password {
            background: var(--warning);
            color: white;
        }
        
        .status-locked {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .lock-info {
            font-size: 11px;
            color: var(--gray-600);
            margin-top: 4px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-title {
            margin: 0;
            color: var(--gray-900);
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray-500);
        }
        
        .roles-list {
            font-size: 12px;
            color: var(--gray-600);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1 style="margin: 0;">👥 Управление пользователями</h1>
                <p style="margin: 5px 0 0; color: var(--gray-600);">
                    Всего пользователей: <?= count($users) ?>
                </p>
            </div>
            <div class="btn-group">
                <button onclick="openCreateModal()" class="btn btn-primary">➕ Добавить пользователя</button>
                <a href="index.php" class="btn btn-secondary">🔙 Назад</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="users-table">
            <div class="table-header">
                <h3 style="margin: 0;">Список пользователей</h3>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Телефон</th>
                        <th>ФИО</th>
                        <th>Роли в цехах</th>
                        <th>Статус</th>
                        <th>Блокировка</th>
                        <th>Последний вход</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td><strong><?= htmlspecialchars($user['phone']) ?></strong></td>
                            <td><?= htmlspecialchars($user['full_name']) ?></td>
                            <td>
                                <div class="roles-list">
                                    <?= $user['roles'] ? htmlspecialchars($user['roles']) : 'Нет ролей' ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $user['is_active'] ? 'Активен' : 'Неактивен' ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $isLocked = $user['locked_until'] && strtotime($user['locked_until']) > time();
                                if ($isLocked): 
                                ?>
                                    <span class="status-badge status-locked">
                                        🔒 Заблокирован
                                    </span>
                                    <div class="lock-info">
                                        До: <?= date('d.m.Y H:i', strtotime($user['locked_until'])) ?>
                                    </div>
                                    <div class="lock-info">
                                        Попыток: <?= $user['failed_login_attempts'] ?? 0 ?>
                                    </div>
                                <?php elseif ($user['locked_until']): ?>
                                    <span style="color: var(--gray-500); font-size: 12px;">
                                        Разблокирован
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--success); font-size: 12px;">
                                        ✓ Не заблокирован
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $user['last_login'] ? date('d.m.Y H:i', strtotime($user['last_login'])) : 'Никогда' ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                    <?php if ($isLocked): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="unlock_account">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn-small btn-unlock" 
                                                    onclick="return confirm('Разблокировать аккаунт пользователя <?= htmlspecialchars($user['full_name']) ?>?')">
                                                🔓 Разблокировать
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <button type="submit" class="btn-small btn-reset-password" 
                                                onclick="return confirm('Сбросить пароль пользователя <?= htmlspecialchars($user['full_name']) ?> на дефолтный (последние 4 цифры телефона)?\n\nВсе активные сессии пользователя будут завершены.')">
                                            🔑 Сбросить пароль
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_user">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <input type="hidden" name="current_status" value="<?= $user['is_active'] ?>">
                                        <button type="submit" class="btn-small btn-toggle" 
                                                onclick="return confirm('Изменить статус пользователя?')">
                                            <?= $user['is_active'] ? 'Деактивировать' : 'Активировать' ?>
                                        </button>
                                    </form>
                                    <a href="user-edit.php?id=<?= $user['id'] ?>" class="btn-small btn-edit">Редактировать</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Модальное окно создания пользователя -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeCreateModal()">&times;</button>
            <div class="modal-header">
                <h2 class="modal-title">Создать нового пользователя</h2>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                
                <div class="form-group">
                    <label class="form-label">Номер телефона</label>
                    <input type="tel" name="phone" class="form-input" placeholder="+380 (99) 000-00-00" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">ФИО</label>
                    <input type="text" name="full_name" class="form-input" placeholder="Иван Иванович Иванов" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email (опционально)</label>
                    <input type="email" name="email" class="form-input" placeholder="user@example.com">
                </div>
                
                <div class="form-group" style="background: var(--primary-light); padding: 10px; border-radius: 4px;">
                    <small style="color: var(--primary);">
                        <strong>ℹ️ Пароль:</strong> Будет автоматически создан из последних 4 цифр номера телефона
                    </small>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Создать пользователя</button>
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('createModal').style.display = 'block';
        }
        
        function closeCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }
        
        // Закрытие модального окна при клике вне его
        window.onclick = function(event) {
            const modal = document.getElementById('createModal');
            if (event.target === modal) {
                closeCreateModal();
            }
        }
        
        // Форматирование номера телефона (украинский формат)
        document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.length > 0) {
                // Обработка украинских номеров
                if (value.startsWith('380')) {
                    value = value.slice(0, 12);
                    let formatted = '+380';
                    if (value.length > 3) {
                        formatted += ' (' + value.slice(3, 5);
                        if (value.length > 5) {
                            formatted += ') ' + value.slice(5, 8);
                            if (value.length > 8) {
                                formatted += '-' + value.slice(8, 10);
                                if (value.length > 10) {
                                    formatted += '-' + value.slice(10, 12);
                                }
                            }
                        }
                    }
                    e.target.value = formatted;
                } else if (value.startsWith('0')) {
                    // Украинский номер без кода страны
                    value = '380' + value.slice(1);
                    value = value.slice(0, 12);
                    let formatted = '+380';
                    if (value.length > 3) {
                        formatted += ' (' + value.slice(3, 5);
                        if (value.length > 5) {
                            formatted += ') ' + value.slice(5, 8);
                            if (value.length > 8) {
                                formatted += '-' + value.slice(8, 10);
                                if (value.length > 10) {
                                    formatted += '-' + value.slice(10, 12);
                                }
                            }
                        }
                    }
                    e.target.value = formatted;
                } else {
                    e.target.value = '+' + value;
                }
            }
        });
    </script>
</body>
</html>
