<?php
/**
 * Статистика посещений страниц (на основе auth_logs, action = page_view)
 */

define('AUTH_SYSTEM', true);
require_once '../includes/config.php';
require_once '../includes/auth-functions.php';

initAuthSystem();

$auth = new AuthManager();

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

$days = isset($_GET['days']) ? (int) $_GET['days'] : 7;
if (!in_array($days, [1, 7, 30, 90], true)) {
    $days = 7;
}

$intervalExpr = (string) $days;

$topPages = $db->select("
    SELECT 
        JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.path')) AS page_path,
        COUNT(*) AS hits
    FROM auth_logs l
    WHERE l.action = 'page_view'
      AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$intervalExpr} DAY)
      AND JSON_EXTRACT(l.details, '$.path') IS NOT NULL
    GROUP BY page_path
    ORDER BY hits DESC
    LIMIT 80
") ?: [];

$topUsers = $db->select("
    SELECT 
        u.id,
        u.phone,
        u.full_name,
        COUNT(*) AS hits
    FROM auth_logs l
    JOIN auth_users u ON u.id = l.user_id
    WHERE l.action = 'page_view'
      AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$intervalExpr} DAY)
    GROUP BY u.id, u.phone, u.full_name
    ORDER BY hits DESC
    LIMIT 40
") ?: [];

$recent = $db->select("
    SELECT 
        l.created_at,
        l.ip_address,
        u.phone,
        u.full_name,
        JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.path')) AS page_path,
        JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.method')) AS http_method
    FROM auth_logs l
    JOIN auth_users u ON u.id = l.user_id
    WHERE l.action = 'page_view'
      AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$intervalExpr} DAY)
    ORDER BY l.created_at DESC
    LIMIT 150
") ?: [];

$totalHits = $db->selectOne("
    SELECT COUNT(*) AS c FROM auth_logs l
    WHERE l.action = 'page_view'
      AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$intervalExpr} DAY)
");
$totalHitsVal = $totalHits ? (int) $totalHits['c'] : 0;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Посещения страниц — админ-панель</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .admin-container { max-width: 1200px; margin: 0 auto; padding: 20px; background: var(--gray-50); min-height: 100vh; }
        .admin-header {
            background: white; padding: 20px; border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow); margin-bottom: 20px;
        }
        .filters { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .filters label { color: var(--gray-600); font-size: 14px; }
        .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 900px) { .content-grid { grid-template-columns: 1fr; } }
        .content-card {
            background: white; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); overflow: hidden;
        }
        .card-header {
            padding: 15px 20px; background: var(--gray-100); border-bottom: 1px solid var(--gray-200); font-weight: 600;
        }
        .card-content { padding: 0; max-height: 480px; overflow: auto; }
        table.stats-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.stats-table th, table.stats-table td {
            padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--gray-100);
        }
        table.stats-table th { background: var(--gray-50); color: var(--gray-700); font-weight: 600; position: sticky; top: 0; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--gray-500); font-size: 12px; margin-top: 8px; }
        .stat-pill {
            display: inline-block; padding: 6px 12px; border-radius: 999px; background: var(--primary-light, #e3f2fd);
            color: var(--primary, #1565c0); font-weight: 600; font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1 style="margin: 0;">📊 Посещения страниц</h1>
            <p style="margin: 6px 0 0; color: var(--gray-600);">
                Кто открывал какие PHP-страницы приложения (данные с момента включения учёта).
            </p>
            <div class="filters">
                <span class="stat-pill">Всего просмотров за период: <?= (int) $totalHitsVal ?></span>
                <label for="days">Период:</label>
                <select id="days" onchange="location.href='page-stats.php?days='+this.value">
                    <?php foreach ([1 => '1 день', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней'] as $d => $label): ?>
                        <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-top: 15px;">
                <a href="index.php" class="btn btn-secondary">🔙 К панели управления</a>
                <a href="password-stats.php" class="btn btn-secondary">Пароли</a>
                <a href="sessions.php" class="btn btn-secondary">Сессии</a>
            </div>
            <p class="muted">
                Запросы к <code>/auth/api/*</code> не пишутся в эту статистику (чтобы не засорять лог частыми проверками).
                Страницы без вызова проверки сессии (<code>AuthManager::checkSession</code>) здесь не появятся.
            </p>
        </div>

        <div class="content-grid">
            <div class="content-card">
                <div class="card-header">Популярные страницы</div>
                <div class="card-content">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th>Страница (SCRIPT_NAME)</th>
                                <th class="num">Просмотров</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topPages)): ?>
                                <tr><td colspan="2">Нет записей за выбранный период.</td></tr>
                            <?php else: ?>
                                <?php foreach ($topPages as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['page_path'] ?? '') ?></td>
                                        <td class="num"><?= (int) ($row['hits'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">Активность по пользователям</div>
                <div class="card-content">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th>Пользователь</th>
                                <th class="num">Просмотров</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topUsers)): ?>
                                <tr><td colspan="2">Нет записей за выбранный период.</td></tr>
                            <?php else: ?>
                                <?php foreach ($topUsers as $row): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($row['full_name'] ?? '') ?>
                                            <div class="muted"><?= htmlspecialchars($row['phone'] ?? '') ?></div>
                                        </td>
                                        <td class="num"><?= (int) ($row['hits'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="content-card" style="margin-top: 20px;">
            <div class="card-header">Последние просмотры</div>
            <div class="card-content" style="max-height: 560px;">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Время</th>
                            <th>Пользователь</th>
                            <th>Страница</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent)): ?>
                            <tr><td colspan="4">Нет записей.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($row['created_at']))) ?></td>
                                    <td>
                                        <?= htmlspecialchars($row['full_name'] ?? '') ?>
                                        <div class="muted"><?= htmlspecialchars($row['phone'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['page_path'] ?? '') ?>
                                        <?php if (!empty($row['http_method']) && $row['http_method'] !== 'GET'): ?>
                                            <span class="muted"><?= htmlspecialchars($row['http_method']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="muted"><?= htmlspecialchars($row['ip_address'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
