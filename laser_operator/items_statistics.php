<?php
/**
 * Страница статистики по заказанным изделиям (component_name)
 * Сортировка: по популярности (сумма quantity) по всем участкам.
 */

define('AUTH_SYSTEM', true);
require_once '../auth/includes/config.php';
require_once '../auth/includes/auth-functions.php';

initAuthSystem();

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/daily_auth_load.php';
laser_operator_load_daily_auth_file();
laser_operator_require_same_calendar_day($auth);

$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

$hasLaserOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'laser_operator'])) {
        $hasLaserOperatorAccess = true;
        break;
    }
}

if (!$hasLaserOperatorAccess) {
    die("У вас нет доступа к статистике оператора лазерной резки");
}

// Подключение настроек БД по участкам (из env.php)
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';

$databases = [
    'U2' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan'],
    'U3' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u3'],
    'U4' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u4'],
    'U5' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u5'],
];

// Опциональный фильтр периода (YYYY-MM-DD)
$dateFromRaw = $_GET['date_from'] ?? '';
$dateToRaw = $_GET['date_to'] ?? '';

$useDateFilter = false;
$dateFromTs = '';
$dateToTs = '';

if ($dateFromRaw && $dateToRaw) {
    $isValidFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromRaw) === 1;
    $isValidTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToRaw) === 1;
    if ($isValidFrom && $isValidTo) {
        $useDateFilter = true;
        $dateFromTs = $dateFromRaw . ' 00:00:00';
        $dateToTs = $dateToRaw . ' 23:59:59';
    }
}

// component_name => ['total_quantity' => int, 'orders_count' => int]
$items = [];

foreach ($databases as $department => $dbConfig) {
    $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
    if ($mysqli->connect_errno) {
        error_log("Ошибка подключения к БД {$department}: " . $mysqli->connect_error);
        continue;
    }

    // Проверяем таблицу
    $tableCheck = $mysqli->query("SHOW TABLES LIKE 'laser_requests'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        $mysqli->close();
        continue;
    }

    // Проверяем наличие колонки is_cancelled
    $hasCancelledColumn = false;
    $checkColumn = $mysqli->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'laser_requests'
          AND COLUMN_NAME = 'is_cancelled'
    ");
    if ($checkColumn && $checkColumn->fetch_row()[0] > 0) {
        $hasCancelledColumn = true;
    }

    $where = "1=1";
    if ($hasCancelledColumn) {
        $where .= " AND (is_cancelled = FALSE OR is_cancelled IS NULL)";
    }

    if ($useDateFilter) {
        $where .= " AND created_at BETWEEN ? AND ?";
    }

    $sql = "
        SELECT
            COALESCE(TRIM(component_name), 'Без названия') AS component_name,
            SUM(COALESCE(quantity, 0)) AS total_quantity,
            COUNT(*) AS orders_count
        FROM laser_requests
        WHERE {$where}
        GROUP BY COALESCE(TRIM(component_name), 'Без названия')
    ";

    try {
        if ($useDateFilter) {
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('ss', $dateFromTs, $dateToTs);
        } else {
            $stmt = $mysqli->prepare($sql);
        }

        if (!$stmt) {
            $mysqli->close();
            continue;
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $componentName = $row['component_name'] ?? 'Без названия';
            $totalQuantity = (int)($row['total_quantity'] ?? 0);
            $ordersCount = (int)($row['orders_count'] ?? 0);

            if (!isset($items[$componentName])) {
                $items[$componentName] = ['total_quantity' => 0, 'orders_count' => 0];
            }
            $items[$componentName]['total_quantity'] += $totalQuantity;
            $items[$componentName]['orders_count'] += $ordersCount;
        }

        $stmt->close();
    } catch (Exception $e) {
        error_log("Ошибка статистики изделий ( {$department} ): " . $e->getMessage());
    } finally {
        $mysqli->close();
    }
}

$itemsList = [];
$grandQuantity = 0;
$grandOrders = 0;

foreach ($items as $name => $data) {
    $totalQuantity = (int)($data['total_quantity'] ?? 0);
    $ordersCount = (int)($data['orders_count'] ?? 0);

    $itemsList[] = [
        'component_name' => $name,
        'total_quantity' => $totalQuantity,
        'orders_count' => $ordersCount,
    ];

    $grandQuantity += $totalQuantity;
    $grandOrders += $ordersCount;
}

usort($itemsList, function ($a, $b) {
    // Сначала по популярности (кол-во штук), потом по кол-ву заказов
    if ($b['total_quantity'] === $a['total_quantity']) {
        return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
    }
    return ($b['total_quantity'] ?? 0) <=> ($a['total_quantity'] ?? 0);
});

$topLimit = 200;
$totalItems = count($itemsList);
$itemsList = array_slice($itemsList, 0, $topLimit);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика изделий по лазеру</title>
    <style>
        :root {
            --bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --bg-solid: #f8fafc;
            --panel: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --accent-solid: #667eea;
            --accent-ink: #ffffff;
            --radius: 16px;
            --radius-sm: 12px;
            --shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
        }

        body {
            margin: 0;
            background: var(--bg-solid);
            color: var(--ink);
            font: 16px/1.6 "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 10px;
        }

        .header {
            position: relative;
            background: var(--panel);
            border-radius: var(--radius);
            padding: 12px 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .header h1 {
            margin: 0;
            color: var(--ink);
            font-size: 18px;
            font-weight: 700;
        }

        .header p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .back-link {
            text-decoration: none;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            background: #f1f5f9;
            border: 1px solid var(--border);
            color: var(--ink);
            font-size: 13px;
            cursor: pointer;
            user-select: none;
        }

        .back-link:hover {
            background: #e2e8f0;
        }

        .summary {
            background: var(--panel);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .summary-item {
            min-width: 220px;
        }

        .filters {
            background: var(--panel);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filters .filter-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filters label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .filters input[type="date"] {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: white;
            font-size: 13px;
            color: var(--ink);
        }

        .filters input[type="date"]:focus {
            outline: none;
            border-color: #a5b4fc;
        }

        .btn {
            padding: 9px 14px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: #f1f5f9;
            color: var(--ink);
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            user-select: none;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn:hover {
            background: #e2e8f0;
        }

        .btn-primary {
            border-color: rgba(0,0,0,0);
            background: var(--accent-solid);
            color: var(--accent-ink);
        }

        .summary-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 2px;
        }

        .summary-value {
            font-size: 20px;
            font-weight: 800;
        }

        .panel {
            background: var(--panel);
            border-radius: var(--radius);
            padding: 18px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .table-wrapper {
            overflow-x: auto;
            margin-top: 12px;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 520px;
        }

        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: #f8fafc;
            font-weight: 700;
        }

        td.num {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .empty {
            padding: 28px 12px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Статистика заказанных изделий (лазер)</h1>
                <p>Сортировка: по популярности (сумма quantity).</p>
            </div>

            <div class="top-actions">
                <a class="back-link" href="index.php">Назад</a>
            </div>
        </div>

        <div class="summary">
            <div class="summary-item">
                <div class="summary-label">Всего штук</div>
                <div class="summary-value"><?= number_format($grandQuantity, 0, '.', ' ') ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Всего заказов</div>
                <div class="summary-value"><?= number_format($grandOrders, 0, '.', ' ') ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Уникальных изделий</div>
                <div class="summary-value"><?= number_format($totalItems, 0, '.', ' ') ?></div>
            </div>
        </div>

        <form class="filters" method="GET" action="">
            <div class="filter-field">
                <label for="date_from">Дата с</label>
                <input
                    id="date_from"
                    name="date_from"
                    type="date"
                    value="<?= htmlspecialchars($dateFromRaw) ?>"
                >
            </div>
            <div class="filter-field">
                <label for="date_to">Дата по</label>
                <input
                    id="date_to"
                    name="date_to"
                    type="date"
                    value="<?= htmlspecialchars($dateToRaw) ?>"
                >
            </div>
            <button class="btn btn-primary" type="submit">Показать</button>
            <a
                class="btn"
                href="items_statistics.php"
                title="Сбросить фильтр по датам"
            >Сброс</a>
        </form>

        <div class="panel">
            <div class="section-title">
                Топ изделий по популярности<?= ($useDateFilter ? " (за период {$dateFromRaw} - {$dateToRaw})" : '') ?>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Изделие</th>
                            <th class="num">Штук</th>
                            <th class="num">Заказов</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($itemsList) > 0): ?>
                            <?php foreach ($itemsList as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['component_name']) ?></td>
                                    <td class="num"><?= number_format((int)$row['total_quantity'], 0, '.', ' ') ?></td>
                                    <td class="num"><?= number_format((int)$row['orders_count'], 0, '.', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3">
                                    <div class="empty">Нет данных для выбранного периода.</div>
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

