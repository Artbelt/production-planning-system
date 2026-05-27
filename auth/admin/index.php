<?php
/**
 * Главная страница административной панели (дашборд)
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

$totalUsers = (int) ($db->selectOne('SELECT COUNT(*) AS c FROM auth_users')['c'] ?? 0);
$activeUsers = (int) ($db->selectOne('SELECT COUNT(*) AS c FROM auth_users WHERE is_active = 1')['c'] ?? 0);
$activeSessionsCount = (int) ($db->selectOne('SELECT COUNT(*) AS c FROM auth_sessions WHERE expires_at > NOW()')['c'] ?? 0);
$workshopsCount = (int) ($db->selectOne(
    'SELECT COUNT(DISTINCT department_code) AS c FROM auth_user_departments WHERE department_code IS NOT NULL AND TRIM(department_code) <> \'\''
)['c'] ?? 0);
$logins24h = (int) ($db->selectOne(
    "SELECT COUNT(*) AS c FROM auth_logs WHERE action = 'login' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
)['c'] ?? 0);

$recentLogs = $db->select("
    SELECT l.created_at, l.action, l.user_id, u.phone, u.full_name
    FROM auth_logs l
    LEFT JOIN auth_users u ON u.id = l.user_id
    WHERE l.action <> 'page_view'
    ORDER BY l.created_at DESC
    LIMIT 28
") ?: [];

$activeSessionsList = $db->select("
    SELECT s.user_id, s.last_activity, s.ip_address, s.department_code, u.phone, u.full_name,
           TIMESTAMPDIFF(MINUTE, s.last_activity, NOW()) AS inactive_minutes
    FROM auth_sessions s
    JOIN auth_users u ON u.id = s.user_id
    WHERE s.expires_at > NOW()
    ORDER BY s.last_activity DESC
    LIMIT 18
") ?: [];

$actionLabels = [
    'login' => ['ru' => 'Вход', 'class' => 'badge-in'],
    'logout' => ['ru' => 'Выход', 'class' => 'badge-out'],
    'failed_login' => ['ru' => 'Ошибка входа', 'class' => 'badge-warn'],
    'page_view' => ['ru' => 'Страница', 'class' => 'badge-muted'],
    'department_switch' => ['ru' => 'Смена цеха', 'class' => 'badge-muted'],
    'account_locked' => ['ru' => 'Блокировка', 'class' => 'badge-warn'],
    'account_unlocked' => ['ru' => 'Разблокировка', 'class' => 'badge-muted'],
    'session_terminated_by_admin' => ['ru' => 'Сессия снята', 'class' => 'badge-warn'],
    'all_sessions_terminated_by_admin' => ['ru' => 'Все сессии сняты', 'class' => 'badge-warn'],
    'expired_sessions_cleanup' => ['ru' => 'Очистка сессий', 'class' => 'badge-muted'],
];

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Административная панель — <?= htmlspecialchars(UI_CONFIG['app_name'] ?? 'AlphaFilter') ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .admin-wrap { max-width: 1280px; margin: 0 auto; padding: 20px; min-height: 100vh; }
        .top-bar {
            background: #fff; border-radius: var(--border-radius-lg); box-shadow: var(--shadow);
            padding: 16px 20px; margin-bottom: 18px;
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;
        }
        .welcome { margin: 0; font-size: 1.15rem; font-weight: 600; color: var(--gray-900); }
        .welcome span { color: var(--gray-600); font-weight: 400; }
        .nav-top { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: flex-end; }
        .nav-top .btn { white-space: nowrap; font-size: 13px; padding: 8px 14px; }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px; margin-bottom: 18px;
        }
        .stat-card {
            background: #fff; border-radius: var(--border-radius-lg); box-shadow: var(--shadow);
            padding: 16px; text-align: center;
        }
        .stat-card .num { font-size: 1.75rem; font-weight: 700; color: var(--primary); line-height: 1.2; }
        .stat-card .lbl { font-size: 12px; color: var(--gray-600); margin-top: 4px; }
        .two-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 18px;
        }
        @media (max-width: 900px) { .two-cols { grid-template-columns: 1fr; } }
        .panel {
            background: #fff; border-radius: var(--border-radius-lg); box-shadow: var(--shadow);
            overflow: hidden;
        }
        .panel-h {
            padding: 12px 16px; background: var(--gray-100); border-bottom: 1px solid var(--gray-200);
            font-weight: 600; font-size: 15px;
        }
        .panel-b { max-height: 420px; overflow-y: auto; }
        .row-item {
            padding: 10px 16px; border-bottom: 1px solid var(--gray-100);
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px;
            font-size: 13px;
        }
        .row-item:last-child { border-bottom: none; }
        .who .phone { font-weight: 500; color: var(--gray-900); }
        .who .name { font-size: 12px; color: var(--gray-600); }
        .meta { text-align: right; font-size: 12px; color: var(--gray-500); }
        .badge-in, .badge-out, .badge-warn, .badge-muted {
            display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;
        }
        .badge-in { background: var(--success-light); color: var(--success); }
        .badge-out { background: var(--gray-200); color: var(--gray-700); }
        .badge-warn { background: var(--warning-light); color: var(--warning); }
        .badge-muted { background: var(--gray-100); color: var(--gray-600); }
        .hint-box {
            background: #fff; border-radius: var(--border-radius-lg); box-shadow: var(--shadow);
            padding: 18px 20px; font-size: 14px; color: var(--gray-700); line-height: 1.55;
        }
        .hint-box h2 { margin: 0 0 10px; font-size: 1rem; color: var(--gray-900); }
        .hint-box ol { margin: 8px 0 0 18px; padding: 0; }
        .hint-box li { margin-bottom: 6px; }
    </style>
</head>
<body>
    <div class="admin-wrap">
        <div class="top-bar">
            <p class="welcome">Добро пожаловать, <span><?= htmlspecialchars($session['full_name'] ?? '') ?></span>.</p>
            <nav class="nav-top">
                <a class="btn btn-primary" href="users.php">Пользователи</a>
                <a class="btn btn-primary" href="roles.php">Роли</a>
                <a class="btn btn-primary" href="sessions.php">Сессии</a>
                <a class="btn btn-primary" href="password-stats.php">Пароли</a>
                <a class="btn btn-primary" href="page-stats.php">Посещения страниц</a>
                <a class="btn btn-primary" href="logs.php">Логи</a>
                <a class="btn btn-secondary" href="../select-department.php">К системам</a>
                <a class="btn btn-secondary" href="../logout.php">Выход</a>
            </nav>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="num"><?= $totalUsers ?></div>
                <div class="lbl">Всего пользователей</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $activeUsers ?></div>
                <div class="lbl">Активных пользователей</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $activeSessionsCount ?></div>
                <div class="lbl">Активных сессий</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $workshopsCount ?></div>
                <div class="lbl">Цехов в системе</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $logins24h ?></div>
                <div class="lbl">Входов за 24ч</div>
            </div>
        </div>

        <div class="two-cols">
            <div class="panel">
                <div class="panel-h">Последние действия</div>
                <div class="panel-b">
                    <?php if (empty($recentLogs)): ?>
                        <div class="row-item"><span class="meta">Нет записей в auth_logs</span></div>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <?php
                            $al = $actionLabels[$log['action']] ?? ['ru' => $log['action'], 'class' => 'badge-muted'];
                            $t = strtotime($log['created_at']);
                            $timeStr = $t ? date('H:i d.m', $t) : '';
                            ?>
                            <div class="row-item">
                                <div class="who">
                                    <div class="phone"><?= htmlspecialchars($log['phone'] ?? '—') ?></div>
                                    <div class="name"><?= htmlspecialchars($log['full_name'] ?? '') ?></div>
                                </div>
                                <div>
                                    <span class="<?= htmlspecialchars($al['class']) ?>"><?= htmlspecialchars($al['ru']) ?></span>
                                    <div class="meta"><?= htmlspecialchars($timeStr) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel">
                <div class="panel-h">Активные сессии</div>
                <div class="panel-b">
                    <?php if (empty($activeSessionsList)): ?>
                        <div class="row-item"><span class="meta">Нет активных сессий</span></div>
                    <?php else: ?>
                        <?php foreach ($activeSessionsList as $s): ?>
                            <?php
                            $la = strtotime($s['last_activity']);
                            $actStr = $la ? date('H:i d.m', $la) : '';
                            ?>
                            <div class="row-item">
                                <div class="who">
                                    <div class="phone"><?= htmlspecialchars($s['phone'] ?? '') ?></div>
                                    <div class="name"><?= htmlspecialchars($s['full_name'] ?? '') ?></div>
                                    <div class="name">Цех: <?= htmlspecialchars($s['department_code'] ?? '—') ?></div>
                                </div>
                                <div class="meta">
                                    Акт.: <?= htmlspecialchars($actStr) ?><br>
                                    <?= htmlspecialchars($s['ip_address'] ?? '') ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="hint-box">
            <h2>Доступ к странице аналитики</h2>
            <p style="margin: 0 0 8px;">
                Кнопка «Аналитика по участкам» на главной странице приложения видна только пользователям с ролью
                <strong>«Директор»</strong> хотя бы в одном цеху.
            </p>
            <ol>
                <li>Откройте раздел <strong>«Пользователи»</strong> и выберите пользователя.</li>
                <li>В блоке прав доступа к цехам назначьте роль <strong>«Директор»</strong> для нужного цеха (например, U2, U5).</li>
            </ol>
            <p style="margin: 10px 0 0;">
                После этого у этого пользователя на странице <code>/index.php</code> приложения появится доступ к аналитике.
            </p>
        </div>
    </div>
</body>
</html>
