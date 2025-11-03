<?php
/**
 * Управление активными сессиями
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
    
    if ($action === 'terminate_session') {
        $sessionId = $_POST['session_id'] ?? '';
        $userId = (int)($_POST['user_id'] ?? 0);
        
        if (!empty($sessionId)) {
            // Удаляем сессию из БД
            $result = $db->delete("DELETE FROM auth_sessions WHERE id = ?", [$sessionId]);
            
            if ($result !== false) {
                // Логируем действие
                $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'session_terminated_by_admin', ?, ?, ?)", [
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    json_encode(['terminated_by' => $session['user_id'], 'session_id' => $sessionId])
                ]);
                
                $message = 'Сессия успешно завершена';
            } else {
                $error = 'Ошибка завершения сессии';
            }
        }
    }
    
    if ($action === 'terminate_user_sessions') {
        $userId = (int)($_POST['user_id'] ?? 0);
        
        if ($userId > 0) {
            // Получаем все сессии пользователя
            $userSessions = $db->select("SELECT id FROM auth_sessions WHERE user_id = ? AND expires_at > NOW()", [$userId]);
            
            // Удаляем все сессии пользователя
            $result = $db->delete("DELETE FROM auth_sessions WHERE user_id = ? AND expires_at > NOW()", [$userId]);
            
            if ($result !== false) {
                // Логируем действие
                $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'all_sessions_terminated_by_admin', ?, ?, ?)", [
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    json_encode(['terminated_by' => $session['user_id'], 'sessions_count' => count($userSessions)])
                ]);
                
                $message = 'Все сессии пользователя завершены (' . count($userSessions) . ' сессий)';
            } else {
                $error = 'Ошибка завершения сессий';
            }
        }
    }
    
    if ($action === 'cleanup_expired') {
        // Удаляем истекшие сессии
        $result = $db->delete("DELETE FROM auth_sessions WHERE expires_at <= NOW()");
        
        if ($result !== false) {
            // Логируем действие
            $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'expired_sessions_cleanup', ?, ?, ?)", [
                $session['user_id'],
                $_SERVER['REMOTE_ADDR'] ?? '::1',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                json_encode(['cleaned_sessions' => $result])
            ]);
            
            $message = "Очищено истекших сессий: $result";
        } else {
            $error = 'Ошибка очистки истекших сессий';
        }
    }
}

// Получение активных сессий
$activeSessions = $db->select("
    SELECT s.*, u.phone, u.full_name,
           TIMESTAMPDIFF(MINUTE, s.last_activity, NOW()) as inactive_minutes,
           TIMESTAMPDIFF(MINUTE, NOW(), s.expires_at) as expires_in_minutes
    FROM auth_sessions s 
    JOIN auth_users u ON s.user_id = u.id 
    WHERE s.expires_at > NOW() 
    ORDER BY s.last_activity DESC
");

// Статистика сессий
$stats = [
    'active_sessions' => count($activeSessions),
    'expired_sessions' => $db->selectOne("SELECT COUNT(*) as count FROM auth_sessions WHERE expires_at <= NOW()")['count'],
    'unique_users' => $db->selectOne("SELECT COUNT(DISTINCT user_id) as count FROM auth_sessions WHERE expires_at > NOW()")['count'],
    'unique_ips' => $db->selectOne("SELECT COUNT(DISTINCT ip_address) as count FROM auth_sessions WHERE expires_at > NOW()")['count']
];

// Группировка по пользователям
$userSessions = [];
foreach ($activeSessions as $sess) {
    $userSessions[$sess['user_id']]['user'] = [
        'id' => $sess['user_id'],
        'phone' => $sess['phone'],
        'full_name' => $sess['full_name']
    ];
    $userSessions[$sess['user_id']]['sessions'][] = $sess;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление сессиями - Админ панель</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .admin-container {
            max-width: 1400px;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-size: 12px;
        }
        
        .actions-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .user-sessions-card {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .user-header {
            padding: 15px 20px;
            background: var(--gray-100);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .user-details h4 {
            margin: 0;
            color: var(--gray-900);
        }
        
        .user-details p {
            margin: 2px 0 0;
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .sessions-list {
            padding: 0;
        }
        
        .session-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .session-item:last-child {
            border-bottom: none;
        }
        
        .session-info {
            flex: 1;
        }
        
        .session-id {
            font-family: monospace;
            font-size: 12px;
            color: var(--gray-500);
            margin-bottom: 5px;
        }
        
        .session-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            font-size: 13px;
        }
        
        .session-detail {
            color: var(--gray-600);
        }
        
        .session-detail strong {
            color: var(--gray-900);
        }
        
        .session-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-active {
            background: var(--success-light);
            color: var(--success);
        }
        
        .status-idle {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .status-expiring {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .session-actions {
            display: flex;
            gap: 10px;
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
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-info {
            background: var(--info);
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--gray-500);
        }
        
        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .session-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .session-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1 style="margin: 0;">🔄 Управление сессиями</h1>
                <p style="margin: 5px 0 0; color: var(--gray-600);">
                    Мониторинг и управление активными пользовательскими сессиями
                </p>
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-secondary">🔙 К панели управления</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['active_sessions'] ?></div>
                <div class="stat-label">Активных сессий</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['expired_sessions'] ?></div>
                <div class="stat-label">Истекших сессий</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['unique_users'] ?></div>
                <div class="stat-label">Активных пользователей</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['unique_ips'] ?></div>
                <div class="stat-label">Уникальных IP</div>
            </div>
        </div>

        <!-- Массовые действия -->
        <div class="actions-card">
            <h3 style="margin: 0 0 15px;">⚡ Массовые действия</h3>
            
            <div class="btn-group">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="cleanup_expired">
                    <button type="submit" class="btn btn-info" 
                            onclick="return confirm('Очистить все истекшие сессии?')">
                        🧹 Очистить истекшие сессии
                    </button>
                </form>
                
                <button onclick="refreshPage()" class="btn btn-secondary">
                    🔄 Обновить данные
                </button>
            </div>
        </div>

        <!-- Активные сессии по пользователям -->
        <?php if (empty($userSessions)): ?>
            <div class="user-sessions-card">
                <div class="empty-state">
                    <h3>🔍 Нет активных сессий</h3>
                    <p>В данный момент нет активных пользовательских сессий</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($userSessions as $userId => $userData): ?>
                <div class="user-sessions-card">
                    <div class="user-header">
                        <div class="user-info">
                            <div class="user-avatar">
                                <?= strtoupper(substr($userData['user']['full_name'], 0, 1)) ?>
                            </div>
                            <div class="user-details">
                                <h4><?= htmlspecialchars($userData['user']['full_name']) ?></h4>
                                <p><?= htmlspecialchars($userData['user']['phone']) ?></p>
                            </div>
                        </div>
                        <div>
                            <span class="badge">
                                <?= count($userData['sessions']) ?> сессий
                            </span>
                            <form method="POST" style="display: inline; margin-left: 10px;">
                                <input type="hidden" name="action" value="terminate_user_sessions">
                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                <button type="submit" class="btn-small btn-warning" 
                                        onclick="return confirm('Завершить ВСЕ сессии пользователя?')">
                                    ⚠️ Завершить все
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="sessions-list">
                        <?php foreach ($userData['sessions'] as $sess): ?>
                            <div class="session-item">
                                <div class="session-info">
                                    <div class="session-id">
                                        ID: <?= htmlspecialchars(substr($sess['id'], 0, 16)) ?>...
                                    </div>
                                    
                                    <div class="session-details">
                                        <div class="session-detail">
                                            <strong>Цех:</strong> <?= $sess['department_code'] ?: 'Не выбран' ?>
                                        </div>
                                        <div class="session-detail">
                                            <strong>IP:</strong> <?= htmlspecialchars($sess['ip_address']) ?>
                                        </div>
                                        <div class="session-detail">
                                            <strong>Последняя активность:</strong> 
                                            <?php if ($sess['inactive_minutes'] < 5): ?>
                                                <span class="session-status status-active">Активен</span>
                                            <?php elseif ($sess['inactive_minutes'] < 30): ?>
                                                <span class="session-status status-idle">
                                                    <?= $sess['inactive_minutes'] ?> мин назад
                                                </span>
                                            <?php else: ?>
                                                <span class="session-status status-idle">
                                                    <?= date('H:i d.m', strtotime($sess['last_activity'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="session-detail">
                                            <strong>Истекает через:</strong>
                                            <?php if ($sess['expires_in_minutes'] < 60): ?>
                                                <span class="session-status status-expiring">
                                                    <?= $sess['expires_in_minutes'] ?> мин
                                                </span>
                                            <?php else: ?>
                                                <span class="session-status status-active">
                                                    <?= round($sess['expires_in_minutes'] / 60, 1) ?> ч
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="session-detail">
                                            <strong>Создана:</strong> <?= date('H:i d.m.Y', strtotime($sess['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="session-actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="terminate_session">
                                        <input type="hidden" name="session_id" value="<?= htmlspecialchars($sess['id']) ?>">
                                        <input type="hidden" name="user_id" value="<?= $sess['user_id'] ?>">
                                        <button type="submit" class="btn-small btn-danger" 
                                                onclick="return confirm('Завершить эту сессию?')">
                                            ❌ Завершить
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function refreshPage() {
            window.location.reload();
        }
        
        // Автообновление каждые 30 секунд
        setInterval(function() {
            // Показываем индикатор обновления
            const indicator = document.createElement('div');
            indicator.style.cssText = 'position: fixed; top: 20px; right: 20px; background: var(--primary); color: white; padding: 10px; border-radius: 4px; z-index: 1000; font-size: 12px;';
            indicator.textContent = '🔄 Обновление...';
            document.body.appendChild(indicator);
            
            // Обновляем страницу
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }, 30000);
    </script>
</body>
</html>
















