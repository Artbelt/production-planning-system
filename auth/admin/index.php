<?php
/**
 * Главная страница административной панели
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
$userRole = getUserRoleInDepartment($session['user_id'], $_SESSION['auth_department'] ?? 'U2');
if ($userRole !== 'director') {
    header('Location: ../select-department.php?error=access_denied');
    exit;
}

// Получение статистики
$db = Database::getInstance();

$stats = [
    'total_users' => $db->selectOne("SELECT COUNT(*) as count FROM auth_users")['count'],
    'active_users' => $db->selectOne("SELECT COUNT(*) as count FROM auth_users WHERE is_active = 1")['count'],
    'total_sessions' => $db->selectOne("SELECT COUNT(*) as count FROM auth_sessions WHERE expires_at > NOW()")['count'],
    'total_departments' => $db->selectOne("SELECT COUNT(DISTINCT department_code) as count FROM auth_user_departments")['count'],
    'recent_logins' => $db->selectOne("SELECT COUNT(*) as count FROM auth_logs WHERE action = 'login' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['count']
];

// Последние действия
$recentLogs = $db->select("
    SELECT l.*, u.phone, u.full_name 
    FROM auth_logs l 
    LEFT JOIN auth_users u ON l.user_id = u.id 
    ORDER BY l.created_at DESC 
    LIMIT 10
");

// Активные сессии
$activeSessions = $db->select("
    SELECT s.*, u.phone, u.full_name 
    FROM auth_sessions s 
    JOIN auth_users u ON s.user_id = u.id 
    WHERE s.expires_at > NOW() 
    ORDER BY s.last_activity DESC 
    LIMIT 5
");

function getUserRoleInDepartment($userId, $departmentCode) {
    $db = Database::getInstance();
    $sql = "SELECT r.name FROM auth_user_departments ud 
            JOIN auth_roles r ON ud.role_id = r.id 
            WHERE ud.user_id = ? AND ud.department_code = ? AND ud.is_active = 1";
    
    $result = $db->selectOne($sql, [$userId, $departmentCode]);
    return $result ? $result['name'] : null;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Административная панель - <?= UI_CONFIG['app_name'] ?></title>
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
        
        .admin-nav {
            display: flex;
            gap: 15px;
        }
        
        .nav-link {
            padding: 8px 16px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: all 0.15s ease;
        }
        
        .nav-link:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }
        
        .nav-link.secondary {
            background: var(--gray-500);
        }
        
        .nav-link.secondary:hover {
            background: var(--gray-600);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
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
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
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
        
        .log-item, .session-item {
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .log-item:last-child, .session-item:last-child {
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
        
        .user-info {
            font-size: 14px;
        }
        
        .user-phone {
            font-weight: 500;
            color: var(--gray-900);
        }
        
        .user-name {
            color: var(--gray-600);
            font-size: 12px;
        }
        
        .time-info {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .admin-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .admin-nav {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1 style="margin: 0; color: var(--gray-900);">Административная панель</h1>
                <p style="margin: 5px 0 0; color: var(--gray-600);">
                    Добро пожаловать, <?= htmlspecialchars($session['full_name']) ?>
                </p>
            </div>
            <div class="admin-nav">
                <a href="users.php" class="nav-link">👥 Пользователи</a>
                <a href="roles.php" class="nav-link">🔐 Роли</a>
                <a href="sessions.php" class="nav-link">🔄 Сессии</a>
                <a href="password-stats.php" class="nav-link">🔑 Пароли</a>
                <a href="logs.php" class="nav-link">📋 Логи</a>
                <a href="../select-department.php" class="nav-link secondary">🔙 К системам</a>
                <a href="../logout.php" class="nav-link secondary">🚪 Выход</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total_users'] ?></div>
                <div class="stat-label">Всего пользователей</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['active_users'] ?></div>
                <div class="stat-label">Активных пользователей</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total_sessions'] ?></div>
                <div class="stat-label">Активных сессий</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total_departments'] ?></div>
                <div class="stat-label">Цехов в системе</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['recent_logins'] ?></div>
                <div class="stat-label">Входов за 24ч</div>
            </div>
        </div>

        <div class="content-grid">
            <div class="content-card">
                <div class="card-header">📋 Последние действия</div>
                <div class="card-content">
                    <?php if (empty($recentLogs)): ?>
                        <p class="text-center text-gray-500">Нет записей</p>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <div class="log-item">
                                <div>
                                    <div class="user-info">
                                        <span class="user-phone">
                                            <?= $log['phone'] ? htmlspecialchars($log['phone']) : 'Неизвестный' ?>
                                        </span>
                                        <?php if ($log['full_name']): ?>
                                            <div class="user-name"><?= htmlspecialchars($log['full_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="log-action action-<?= $log['action'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $log['action'])) ?>
                                    </div>
                                    <div class="time-info">
                                        <?= date('H:i d.m', strtotime($log['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">🟢 Активные сессии</div>
                <div class="card-content">
                    <?php if (empty($activeSessions)): ?>
                        <p class="text-center text-gray-500">Нет активных сессий</p>
                    <?php else: ?>
                        <?php foreach ($activeSessions as $session): ?>
                            <div class="session-item">
                                <div>
                                    <div class="user-info">
                                        <span class="user-phone"><?= htmlspecialchars($session['phone']) ?></span>
                                        <div class="user-name"><?= htmlspecialchars($session['full_name']) ?></div>
                                        <?php if ($session['department_code']): ?>
                                            <div class="user-name">Цех: <?= htmlspecialchars($session['department_code']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="time-info">
                                        Активность: <?= date('H:i d.m', strtotime($session['last_activity'])) ?>
                                    </div>
                                    <div class="time-info">
                                        IP: <?= htmlspecialchars($session['ip_address']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">📊 Доступ к странице аналитики</div>
                <div class="card-content">
                    <p style="font-size: 14px; color: var(--gray-700); margin-top: 0;">
                        Кнопка <strong>«Аналитика по участкам»</strong> на главной странице видна только пользователям
                        с ролью <strong>«Директор» (director)</strong> хотя бы в одном участке.
                    </p>
                    <p style="font-size: 13px; color: var(--gray-600);">
                        Чтобы выдать доступ к аналитике:
                    </p>
                    <ol style="font-size: 13px; color: var(--gray-600); padding-left: 18px; margin-top: 0;">
                        <li>Откройте раздел <strong>«Пользователи»</strong> и выберите нужного пользователя.</li>
                        <li>На странице редактирования в блоке <strong>«Права доступа к цехам»</strong> назначьте ему
                            роль <strong>«Директор»</strong> в нужном участке (U2–U5).</li>
                    </ol>
                    <p style="font-size: 13px; color: var(--gray-600); margin-bottom: 0;">
                        После этого кнопка аналитики появится у пользователя на странице <strong>/index.php</strong>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
