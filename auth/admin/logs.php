<?php
/**
 * Просмотр логов системы авторизации
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

// Параметры фильтрации
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$filters = [
    'action' => $_GET['action'] ?? '',
    'user_id' => (int)($_GET['user_id'] ?? 0),
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'ip_address' => $_GET['ip_address'] ?? ''
];

// Построение SQL запроса
$whereConditions = [];
$params = [];

if (!empty($filters['action'])) {
    $whereConditions[] = "l.action = ?";
    $params[] = $filters['action'];
}

if ($filters['user_id'] > 0) {
    $whereConditions[] = "l.user_id = ?";
    $params[] = $filters['user_id'];
}

if (!empty($filters['date_from'])) {
    $whereConditions[] = "DATE(l.created_at) >= ?";
    $params[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $whereConditions[] = "DATE(l.created_at) <= ?";
    $params[] = $filters['date_to'];
}

if (!empty($filters['ip_address'])) {
    $whereConditions[] = "l.ip_address LIKE ?";
    $params[] = '%' . $filters['ip_address'] . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Получение логов
$sql = "
    SELECT l.*, u.phone, u.full_name 
    FROM auth_logs l 
    LEFT JOIN auth_users u ON l.user_id = u.id 
    $whereClause
    ORDER BY l.created_at DESC 
    LIMIT $limit OFFSET $offset
";

$logs = $db->select($sql, $params);

// Подсчет общего количества записей
$countSql = "
    SELECT COUNT(*) as total 
    FROM auth_logs l 
    LEFT JOIN auth_users u ON l.user_id = u.id 
    $whereClause
";

$totalCount = $db->selectOne($countSql, $params)['total'];
$totalPages = ceil($totalCount / $limit);

// Получение статистики
$stats = [
    'total_logs' => $db->selectOne("SELECT COUNT(*) as count FROM auth_logs")['count'],
    'today_logs' => $db->selectOne("SELECT COUNT(*) as count FROM auth_logs WHERE DATE(created_at) = CURDATE()")['count'],
    'failed_logins_today' => $db->selectOne("SELECT COUNT(*) as count FROM auth_logs WHERE action = 'failed_login' AND DATE(created_at) = CURDATE()")['count'],
    'unique_ips_today' => $db->selectOne("SELECT COUNT(DISTINCT ip_address) as count FROM auth_logs WHERE DATE(created_at) = CURDATE()")['count']
];

// Получение списка действий для фильтра
$actions = $db->select("SELECT DISTINCT action FROM auth_logs ORDER BY action");

// Получение списка пользователей для фильтра
$users = $db->select("SELECT DISTINCT u.id, u.phone, u.full_name FROM auth_logs l JOIN auth_users u ON l.user_id = u.id ORDER BY u.full_name");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Логи системы - Админ панель</title>
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
        
        .filters-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .logs-table {
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
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--gray-100);
        }
        
        th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-700);
            font-size: 12px;
        }
        
        td {
            font-size: 13px;
        }
        
        .log-action {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .action-login { background: var(--success-light); color: var(--success); }
        .action-logout { background: var(--gray-200); color: var(--gray-700); }
        .action-failed_login { background: var(--danger-light); color: var(--danger); }
        .action-department_switch { background: var(--primary-light); color: var(--primary); }
        .action-session_expired { background: var(--warning-light); color: var(--warning); }
        .action-account_locked { background: var(--danger-light); color: var(--danger); }
        .action-account_unlocked { background: var(--success-light); color: var(--success); }
        .action-password_reset { background: var(--warning-light); color: var(--warning); }
        .action-password_changed { background: var(--primary-light); color: var(--primary); }
        
        .user-info {
            font-size: 12px;
        }
        
        .user-phone {
            font-weight: 500;
            color: var(--gray-900);
        }
        
        .user-name {
            color: var(--gray-600);
            font-size: 11px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 4px;
            text-decoration: none;
            color: var(--gray-700);
        }
        
        .pagination a:hover {
            background: var(--gray-100);
        }
        
        .pagination .current {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .details-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .ip-address {
            font-family: monospace;
            font-size: 11px;
            color: var(--gray-600);
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1 style="margin: 0;">📋 Логи системы авторизации</h1>
            <p style="margin: 5px 0 0; color: var(--gray-600);">
                Мониторинг активности пользователей
            </p>
            <div style="margin-top: 15px;">
                <a href="index.php" class="btn btn-secondary">🔙 К панели управления</a>
            </div>
        </div>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['total_logs']) ?></div>
                <div class="stat-label">Всего записей</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['today_logs'] ?></div>
                <div class="stat-label">Записей сегодня</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['failed_logins_today'] ?></div>
                <div class="stat-label">Неудачных входов</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['unique_ips_today'] ?></div>
                <div class="stat-label">Уникальных IP</div>
            </div>
        </div>

        <!-- Фильтры -->
        <div class="filters-card">
            <h3 style="margin: 0 0 15px;">🔍 Фильтры</h3>
            
            <form method="GET">
                <div class="filters-grid">
                    <div class="form-group">
                        <label class="form-label">Действие</label>
                        <select name="action" class="form-select">
                            <option value="">Все действия</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?= htmlspecialchars($action['action']) ?>" 
                                        <?= $filters['action'] === $action['action'] ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', $action['action'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Пользователь</label>
                        <select name="user_id" class="form-select">
                            <option value="">Все пользователи</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>" 
                                        <?= $filters['user_id'] == $user['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['phone']) ?> - <?= htmlspecialchars($user['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Дата от</label>
                        <input type="date" name="date_from" class="form-input" 
                               value="<?= htmlspecialchars($filters['date_from']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Дата до</label>
                        <input type="date" name="date_to" class="form-input" 
                               value="<?= htmlspecialchars($filters['date_to']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">IP адрес</label>
                        <input type="text" name="ip_address" class="form-input" 
                               placeholder="192.168.1.1" 
                               value="<?= htmlspecialchars($filters['ip_address']) ?>">
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">🔍 Применить фильтры</button>
                    <a href="logs.php" class="btn btn-secondary">🔄 Сбросить</a>
                </div>
            </form>
        </div>

        <!-- Таблица логов -->
        <div class="logs-table">
            <div class="table-header">
                <h3 style="margin: 0;">
                    Записи логов 
                    <?php if ($totalCount > 0): ?>
                        (<?= number_format($totalCount) ?> записей, страница <?= $page ?> из <?= $totalPages ?>)
                    <?php endif; ?>
                </h3>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Время</th>
                            <th>Пользователь</th>
                            <th>Действие</th>
                            <th>Цех</th>
                            <th>IP адрес</th>
                            <th>Детали</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--gray-500); padding: 40px;">
                                    Нет записей для отображения
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= $log['id'] ?></td>
                                    <td>
                                        <div><?= date('d.m.Y', strtotime($log['created_at'])) ?></div>
                                        <div style="font-size: 11px; color: var(--gray-500);">
                                            <?= date('H:i:s', strtotime($log['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($log['user_id']): ?>
                                            <div class="user-info">
                                                <div class="user-phone"><?= htmlspecialchars($log['phone']) ?></div>
                                                <div class="user-name"><?= htmlspecialchars($log['full_name']) ?></div>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--gray-500);">Система</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="log-action action-<?= $log['action'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $log['action'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= $log['department_code'] ? htmlspecialchars($log['department_code']) : '-' ?>
                                    </td>
                                    <td>
                                        <div class="ip-address"><?= htmlspecialchars($log['ip_address']) ?></div>
                                    </td>
                                    <td>
                                        <div class="details-cell" title="<?= htmlspecialchars($log['details']) ?>">
                                            <?= htmlspecialchars($log['details']) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">« Первая</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Предыдущая</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Следующая ›</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>">Последняя »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
















