<?php
/**
 * Подробная информация о всех заявках на лазерную резку
 */

// Подключаем новую систему авторизации
define('AUTH_SYSTEM', true);
require_once '../auth/includes/config.php';
require_once '../auth/includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Получаем информацию о пользователе и его роли
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем, есть ли доступ к модулю оператора лазера
$hasLaserOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'laser_operator'])) {
        $hasLaserOperatorAccess = true;
        break;
    }
}

if (!$hasLaserOperatorAccess) {
    die("У вас нет доступа к модулю оператора лазерной резки");
}

// Настройки подключений к базам данных (из env.php)
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';
$databases = [
    'U2' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan'],
    'U3' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u3'],
    'U4' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u4'],
    'U5' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u5']
];

// Функция для получения всех заявок из всех баз данных
function getAllLaserRequestsDetailed($databases) {
    $allRequests = [];
    
    foreach ($databases as $department => $dbConfig) {
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
        
        if ($mysqli->connect_errno) {
            error_log("Ошибка подключения к БД {$department}: " . $mysqli->connect_error);
            continue;
        }
        
        // Проверяем существование колонки is_cancelled перед использованием
        $hasCancelledColumn = false;
        $checkColumn = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'is_cancelled'");
        if ($checkColumn && $checkColumn->fetch_row()[0] > 0) {
            $hasCancelledColumn = true;
        }
        
        // Получаем заявки из текущей БД (исключаем отмененные, если колонка существует)
        if ($hasCancelledColumn) {
            $sql = "SELECT *, '{$department}' as source_department FROM laser_requests WHERE (is_cancelled = FALSE OR is_cancelled IS NULL) ORDER BY created_at DESC";
        } else {
            $sql = "SELECT *, '{$department}' as source_department FROM laser_requests ORDER BY created_at DESC";
        }
        $result = $mysqli->query($sql);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $allRequests[] = $row;
            }
        }
        
        $mysqli->close();
    }
    
    // Сортируем все заявки по дате создания (новые сначала)
    usort($allRequests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return $allRequests;
}

// Обработка отметки выполнения заявки
if (isset($_POST['action']) && $_POST['action'] === 'mark_completed' && isset($_POST['request_id']) && isset($_POST['department'])) {
    $request_id = (int)$_POST['request_id'];
    $department = $_POST['department'];
    
    if (isset($databases[$department])) {
        $dbConfig = $databases[$department];
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
        
        if (!$mysqli->connect_errno) {
            // Обновляем статус заявки
            $update_sql = "UPDATE laser_requests SET is_completed = TRUE, completed_at = NOW() WHERE id = ?";
            $stmt = $mysqli->prepare($update_sql);
            $stmt->bind_param("i", $request_id);
            
            if ($stmt->execute()) {
                // Редирект для избежания повторной отправки формы
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                exit;
            } else {
                $error_message = "Ошибка при обновлении заявки";
            }
            
            $stmt->close();
            $mysqli->close();
        }
    }
}

// Проверяем GET параметр для отображения сообщения об успехе
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = "Заявка отмечена как выполненная!";
}

// Получаем все заявки
$allRequests = getAllLaserRequestsDetailed($databases);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подробная информация - Заявки на лазерную резку</title>
    <style>
        :root {
            --bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --bg-solid: #f8fafc;
            --panel: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --accent: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --accent-solid: #667eea;
            --accent-ink: #ffffff;
            --radius: 16px;
            --radius-sm: 12px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            color: var(--ink);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
            color: var(--accent-ink);
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            font-size: 16px;
            opacity: 0.9;
        }

        .panel {
            background: var(--panel);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .section-title {
            color: var(--ink);
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
        }

        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
            -webkit-overflow-scrolling: touch;
        }

        .requests-table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
            font-size: 14px;
        }

        .requests-table th,
        .requests-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .requests-table th {
            background: #f8fafc;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .requests-table td:nth-child(2),
        .requests-table td:nth-child(7) {
            text-align: center;
        }

        .status-completed {
            color: #059669;
            font-weight: 500;
        }

        .status-pending {
            color: #d97706;
            font-weight: 500;
        }

        .btn-complete {
            background: var(--accent-solid);
            color: var(--accent-ink);
            border: none;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-complete:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .department-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .department-U2 { background: #dbeafe; color: #1e40af; }
        .department-U3 { background: #dcfce7; color: #166534; }
        .department-U4 { background: #fef3c7; color: #92400e; }
        .department-U5 { background: #fce7f3; color: #be185d; }

        .success-message {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
        }

        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
        }

        .back-btn {
            display: inline-block;
            background: var(--accent-solid);
            color: var(--accent-ink);
            text-decoration: none;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-weight: 500;
            margin-bottom: 20px;
            transition: all 0.2s ease;
        }

        .back-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--panel);
            padding: 16px;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--border);
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent-solid);
        }

        .stat-label {
            color: var(--muted);
            font-size: 14px;
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .requests-table {
                min-width: 600px;
                font-size: 12px;
            }

            .requests-table th,
            .requests-table td {
                padding: 8px;
            }

            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">← Назад к основному модулю</a>

        <div class="header">
            <h1>Подробная информация</h1>
            <p>Все заявки на лазерную резку со всех участков</p>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <!-- Статистика -->
        <div class="stats">
            <?php
            $totalRequests = count($allRequests);
            $completedRequests = count(array_filter($allRequests, function($r) { return $r['is_completed']; }));
            $pendingRequests = $totalRequests - $completedRequests;
            $departmentCounts = [];
            foreach ($allRequests as $request) {
                $dept = $request['source_department'];
                $departmentCounts[$dept] = ($departmentCounts[$dept] ?? 0) + 1;
            }
            ?>
            <div class="stat-card">
                <div class="stat-number"><?= $totalRequests ?></div>
                <div class="stat-label">Всего заявок</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $pendingRequests ?></div>
                <div class="stat-label">В работе</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $completedRequests ?></div>
                <div class="stat-label">Выполнено</div>
            </div>
            <?php foreach ($departmentCounts as $dept => $count): ?>
            <div class="stat-card">
                <div class="stat-number"><?= $count ?></div>
                <div class="stat-label">Участок <?= $dept ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="panel">
            <div class="section-title">
                Полная информация по заявкам
            </div>

            <div class="table-wrapper">
                <table class="requests-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Участок</th>
                            <th>Пользователь</th>
                            <th>Комплектующие</th>
                            <th>Количество</th>
                            <th>Статус</th>
                            <th>Желаемая дата поставки</th>
                            <th>Дата создания</th>
                            <th>Дата выполнения</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($allRequests) > 0): ?>
                            <?php foreach ($allRequests as $request): ?>
                                <tr style="background: <?= $request['is_completed'] ? '#f0f9ff' : '#fff' ?>;">
                                    <td><?= $request['id'] ?></td>
                                    <td>
                                        <span class="department-badge department-<?= $request['source_department'] ?>">
                                            <?= $request['source_department'] ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($request['user_name'] ?? 'Не указано') ?></td>
                                    <td><?= htmlspecialchars($request['component_name']) ?></td>
                                    <td><?= $request['quantity'] ?></td>
                                    <td>
                                        <span class="<?= $request['is_completed'] ? 'status-completed' : 'status-pending' ?>">
                                            <?= $request['is_completed'] ? 'Выполнено' : 'В работе' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($request['desired_delivery_time']): ?>
                                            <?= date('d.m.Y H:i', strtotime($request['desired_delivery_time'])) ?>
                                        <?php else: ?>
                                            Не указано
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= date('d.m.Y H:i', strtotime($request['created_at'])) ?>
                                    </td>
                                    <td>
                                        <?php if ($request['completed_at']): ?>
                                            <?= date('d.m.Y H:i', strtotime($request['completed_at'])) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$request['is_completed']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="mark_completed">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <input type="hidden" name="department" value="<?= $request['source_department'] ?>">
                                                <button type="submit" class="btn-complete" 
                                                        onclick="return confirm('Отметить заявку как выполненную?')">
                                                    Выполнено
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="status-completed">✓</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" style="text-align: center; color: var(--muted); padding: 40px;">
                                    Нет заявок на лазерную резку
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
