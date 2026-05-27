<?php
/**
 * Статистика по паролям пользователей
 */

define('AUTH_SYSTEM', true);
require_once '../includes/config.php';
require_once '../includes/auth-functions.php';
require_once '../includes/password-functions.php';

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

// Получение статистики
$stats = getPasswordStats();

// Пользователи с базовыми паролями
$defaultPasswordUsers = $db->select("
    SELECT u.id, u.phone, u.full_name, u.password_changed_at, u.password_reminder_count,
           DATEDIFF(NOW(), u.password_changed_at) as days_since_change
    FROM auth_users u
    WHERE u.is_default_password = 1 AND u.is_active = 1
    ORDER BY u.password_changed_at ASC
");

// Недавние смены паролей
$recentChanges = $db->select("
    SELECT u.phone, u.full_name, u.password_changed_at
    FROM auth_users u
    WHERE u.is_default_password = 0 AND u.is_active = 1
    ORDER BY u.password_changed_at DESC
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика паролей - Админ панель</title>
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
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            text-align: center;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-number.default { color: var(--warning); }
        .stat-number.changed { color: var(--success); }
        .stat-number.need-reminder { color: var(--danger); }
        
        .stat-label {
            color: var(--gray-600);
            font-size: 12px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .content-card {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .card-header {
            padding: 15px 20px;
            background: var(--gray-100);
            border-bottom: 1px solid var(--gray-200);
            font-weight: 600;
        }
        
        .card-content {
            padding: 20px;
        }
        
        .user-item {
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-item:last-child {
            border-bottom: none;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-phone {
            font-weight: 500;
            color: var(--gray-900);
        }
        
        .user-name {
            color: var(--gray-600);
            font-size: 12px;
        }
        
        .days-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .days-warning {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .days-danger {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .days-success {
            background: var(--success-light);
            color: var(--success);
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
            <h1 style="margin: 0;">🔐 Статистика паролей</h1>
            <p style="margin: 5px 0 0; color: var(--gray-600);">
                Мониторинг безопасности паролей пользователей
            </p>
            <div style="margin-top: 15px;">
                <a href="index.php" class="btn btn-secondary">🔙 К панели управления</a>
                <a href="page-stats.php" class="btn btn-secondary">📊 Страницы</a>
            </div>
        </div>

        <!-- Общая статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total_users'] ?></div>
                <div class="stat-label">Всего пользователей</div>
            </div>
            <div class="stat-card">
                <div class="stat-number default"><?= $stats['default_passwords'] ?></div>
                <div class="stat-label">Базовые пароли</div>
            </div>
            <div class="stat-card">
                <div class="stat-number changed"><?= $stats['changed_passwords'] ?></div>
                <div class="stat-label">Персональные пароли</div>
            </div>
            <div class="stat-card">
                <div class="stat-number need-reminder"><?= $stats['need_reminders'] ?></div>
                <div class="stat-label">Нужны напоминания</div>
            </div>
        </div>

        <div class="content-grid">
            <!-- Пользователи с базовыми паролями -->
            <div class="content-card">
                <div class="card-header">⚠️ Пользователи с базовыми паролями</div>
                <div class="card-content">
                    <?php if (empty($defaultPasswordUsers)): ?>
                        <p style="text-align: center; color: var(--success);">
                            🎉 Все пользователи используют персональные пароли!
                        </p>
                    <?php else: ?>
                        <?php foreach ($defaultPasswordUsers as $user): ?>
                            <div class="user-item">
                                <div class="user-info">
                                    <div class="user-phone"><?= htmlspecialchars($user['phone']) ?></div>
                                    <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                                    <div class="user-name">
                                        Напоминаний отправлено: <?= $user['password_reminder_count'] ?>
                                    </div>
                                </div>
                                <div>
                                    <?php 
                                    $days = $user['days_since_change'];
                                    $badgeClass = 'days-success';
                                    if ($days >= 30) $badgeClass = 'days-danger';
                                    elseif ($days >= 7) $badgeClass = 'days-warning';
                                    ?>
                                    <div class="days-badge <?= $badgeClass ?>">
                                        <?= $days ?> дней
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Недавние смены паролей -->
            <div class="content-card">
                <div class="card-header">✅ Недавние смены паролей</div>
                <div class="card-content">
                    <?php if (empty($recentChanges)): ?>
                        <p style="text-align: center; color: var(--gray-500);">
                            Пока нет смен паролей
                        </p>
                    <?php else: ?>
                        <?php foreach ($recentChanges as $change): ?>
                            <div class="user-item">
                                <div class="user-info">
                                    <div class="user-phone"><?= htmlspecialchars($change['phone']) ?></div>
                                    <div class="user-name"><?= htmlspecialchars($change['full_name']) ?></div>
                                </div>
                                <div>
                                    <div class="days-badge days-success">
                                        <?= date('d.m H:i', strtotime($change['password_changed_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
















