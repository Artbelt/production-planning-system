<?php
/**
 * Управление ролями и правами доступа
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

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_role') {
        $name = trim($_POST['name']);
        $displayName = trim($_POST['display_name']);
        $description = trim($_POST['description']);
        
        if (empty($name) || empty($displayName)) {
            $error = 'Заполните обязательные поля';
        } else {
            // Проверка уникальности имени роли
            $existing = $db->selectOne("SELECT id FROM auth_roles WHERE name = ?", [$name]);
            
            if ($existing) {
                $error = 'Роль с таким именем уже существует';
            } else {
                $roleId = $db->insert("INSERT INTO auth_roles (name, display_name, description, is_active) VALUES (?, ?, ?, 1)", 
                    [$name, $displayName, $description]);
                
                if ($roleId) {
                    $message = 'Роль создана успешно';
                } else {
                    $error = 'Ошибка создания роли';
                }
            }
        }
    }
    
    if ($action === 'toggle_role') {
        $roleId = (int)$_POST['role_id'];
        $currentStatus = (int)$_POST['current_status'];
        $newStatus = $currentStatus ? 0 : 1;
        
        // Проверяем, что это не системная роль
        $role = $db->selectOne("SELECT * FROM auth_roles WHERE id = ?", [$roleId]);
        
        if ($role && $role['id'] <= 4) {
            $error = 'Нельзя деактивировать системные роли';
        } else {
            $result = $db->update("UPDATE auth_roles SET is_active = ? WHERE id = ?", [$newStatus, $roleId]);
            
            if ($result !== false) {
                $message = $newStatus ? 'Роль активирована' : 'Роль деактивирована';
            } else {
                $error = 'Ошибка изменения статуса роли';
            }
        }
    }
}

// Получение списка ролей с количеством пользователей
$roles = $db->select("
    SELECT r.*, 
           COUNT(DISTINCT ud.user_id) as users_count,
           COUNT(DISTINCT ud.department_code) as departments_count
    FROM auth_roles r
    LEFT JOIN auth_user_departments ud ON r.id = ud.role_id AND ud.is_active = 1
    GROUP BY r.id
    ORDER BY r.id
");

// Статистика по ролям в цехах
$roleStats = $db->select("
    SELECT r.display_name, ud.department_code, COUNT(*) as count
    FROM auth_roles r
    JOIN auth_user_departments ud ON r.id = ud.role_id AND ud.is_active = 1
    GROUP BY r.id, ud.department_code
    ORDER BY r.id, ud.department_code
");

$statsMatrix = [];
foreach ($roleStats as $stat) {
    $statsMatrix[$stat['display_name']][$stat['department_code']] = $stat['count'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление ролями - Админ панель</title>
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
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .roles-table {
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
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .role-worker { background: var(--primary-light); color: var(--primary); }
        .role-manager { background: var(--warning-light); color: var(--warning); }
        .role-supervisor { background: var(--info-light); color: var(--info); }
        .role-director { background: var(--success-light); color: var(--success); }
        
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
        
        .stats-card {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stats-matrix {
            display: grid;
            gap: 10px;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: 120px repeat(7, 1fr);
            gap: 5px;
            align-items: center;
        }
        
        .stats-cell {
            padding: 5px;
            text-align: center;
            background: var(--gray-100);
            border-radius: 4px;
            font-size: 12px;
        }
        
        .stats-header {
            font-weight: 600;
            background: var(--primary-light);
            color: var(--primary);
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
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-row {
                grid-template-columns: 80px repeat(7, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1 style="margin: 0;">🔐 Управление ролями</h1>
                <p style="margin: 5px 0 0; color: var(--gray-600);">
                    Всего ролей: <?= count($roles) ?>
                </p>
            </div>
            <div class="btn-group">
                <button onclick="openCreateModal()" class="btn btn-primary">➕ Добавить роль</button>
                <a href="index.php" class="btn btn-secondary">🔙 Назад</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="content-grid">
            <div class="roles-table">
                <div class="table-header">
                    <h3 style="margin: 0;">Список ролей</h3>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Роль</th>
                            <th>Описание</th>
                            <th>Пользователей</th>
                            <th>Цехов</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $role): ?>
                            <tr>
                                <td><?= $role['id'] ?></td>
                                <td>
                                    <div class="role-badge role-<?= $role['name'] ?>">
                                        <?= htmlspecialchars($role['display_name']) ?>
                                    </div>
                                    <div style="font-size: 12px; color: var(--gray-500); margin-top: 2px;">
                                        <?= htmlspecialchars($role['name']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($role['description']) ?></td>
                                <td><?= $role['users_count'] ?></td>
                                <td><?= $role['departments_count'] ?></td>
                                <td>
                                    <span class="status-badge <?= $role['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $role['is_active'] ? 'Активна' : 'Неактивна' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($role['id'] > 4): // Только для пользовательских ролей ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_role">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $role['is_active'] ?>">
                                            <button type="submit" class="btn-small btn-toggle" 
                                                    onclick="return confirm('Изменить статус роли?')">
                                                <?= $role['is_active'] ? 'Деактивировать' : 'Активировать' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--gray-500); font-size: 12px;">Системная</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div>
                <div class="stats-card">
                    <h3 style="margin: 0 0 15px;">📊 Распределение по цехам</h3>
                    
                    <div class="stats-matrix">
                        <!-- Заголовок -->
                        <div class="stats-row">
                            <div class="stats-cell stats-header">Роль</div>
                            <?php 
                            $departments = [
                                'U2' => ['name' => 'Участок 2', 'is_active' => true],
                                'U3' => ['name' => 'Участок 3', 'is_active' => true],
                                'U4' => ['name' => 'Участок 4', 'is_active' => true],
                                'U5' => ['name' => 'Участок 5', 'is_active' => true]
                            ];
                            foreach ($departments as $deptCode => $deptInfo): ?>
                                <?php if ($deptInfo['is_active']): ?>
                                    <div class="stats-cell stats-header"><?= $deptCode ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Данные -->
                        <?php foreach ($roles as $role): ?>
                            <?php if ($role['is_active']): ?>
                                <div class="stats-row">
                                    <div class="stats-cell" style="text-align: left; font-weight: 500;">
                                        <?= htmlspecialchars($role['display_name']) ?>
                                    </div>
                                    <?php foreach ($departments as $deptCode => $deptInfo): ?>
                                        <?php if ($deptInfo['is_active']): ?>
                                            <div class="stats-cell">
                                                <?= $statsMatrix[$role['display_name']][$deptCode] ?? 0 ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно создания роли -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeCreateModal()">&times;</button>
            <div class="modal-header">
                <h2 class="modal-title">Создать новую роль</h2>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_role">
                
                <div class="form-group">
                    <label class="form-label">Системное имя роли *</label>
                    <input type="text" name="name" class="form-input" 
                           placeholder="custom_role" pattern="[a-z_]+" required>
                    <small style="color: var(--gray-500);">Только строчные буквы и подчеркивания</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Отображаемое имя *</label>
                    <input type="text" name="display_name" class="form-input" 
                           placeholder="Пользовательская роль" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-input" rows="3" 
                              placeholder="Описание роли и её полномочий"></textarea>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Создать роль</button>
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
    </script>
</body>
</html>
