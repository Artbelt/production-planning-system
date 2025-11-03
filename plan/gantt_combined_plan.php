<?php
// Комплексный план производства: порезка + гофрирование + сборка (U2)
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

// Инициализация системы авторизации
initAuthSystem();

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Подключение к базе данных
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Получаем номер заявки из параметров
$orderNumber = $_GET['order'] ?? '';

if (empty($orderNumber)) {
    die('Не указан номер заявки. Укажите параметр ?order=НОМЕР_ЗАЯВКИ');
}

// Получаем список фильтров по заявке
$sqlFilters = "
    SELECT DISTINCT 
        TRIM(SUBSTRING_INDEX(o.filter, ' [', 1)) as filter_base,
        o.filter as filter_full,
        o.count as total_count
    FROM orders o
    WHERE o.order_number = :order
    AND (o.hide IS NULL OR o.hide = 0)
    ORDER BY o.filter
";

$stmtFilters = $pdo->prepare($sqlFilters);
$stmtFilters->execute([':order' => $orderNumber]);
$filters = $stmtFilters->fetchAll();

if (empty($filters)) {
    die('Заявка не найдена или в ней нет фильтров');
}

// Получаем даты для плана порезки (из roll_plans)
$sqlCutDates = "
    SELECT DISTINCT rp.work_date, cp.filter
    FROM roll_plans rp
    JOIN cut_plans cp ON cp.order_number = rp.order_number AND cp.bale_id = rp.bale_id
    WHERE rp.order_number = :order
    ORDER BY rp.work_date
";

$stmtCutDates = $pdo->prepare($sqlCutDates);
$stmtCutDates->execute([':order' => $orderNumber]);
$cutDates = [];
while ($row = $stmtCutDates->fetch()) {
    $filterBase = trim(preg_replace('/\[.*?\]/', '', $row['filter']));
    if (!isset($cutDates[$filterBase])) {
        $cutDates[$filterBase] = [];
    }
    $cutDates[$filterBase][] = $row['work_date'];
}

// Получаем даты для плана гофрирования (из corrugation_plan)
$sqlCorrDates = "
    SELECT DISTINCT plan_date, filter_label
    FROM corrugation_plan
    WHERE order_number = :order
    ORDER BY plan_date
";

$stmtCorrDates = $pdo->prepare($sqlCorrDates);
$stmtCorrDates->execute([':order' => $orderNumber]);
$corrDates = [];
while ($row = $stmtCorrDates->fetch()) {
    $filterBase = trim(preg_replace('/\[.*?\]/', '', $row['filter_label']));
    if (!isset($corrDates[$filterBase])) {
        $corrDates[$filterBase] = [];
    }
    $corrDates[$filterBase][] = $row['plan_date'];
}

// Получаем даты для плана сборки (из build_plan)
$sqlBuildDates = "
    SELECT DISTINCT assign_date, filter_label
    FROM build_plan
    WHERE order_number = :order
    ORDER BY assign_date
";

$stmtBuildDates = $pdo->prepare($sqlBuildDates);
$stmtBuildDates->execute([':order' => $orderNumber]);
$buildDates = [];
while ($row = $stmtBuildDates->fetch()) {
    $filterBase = trim(preg_replace('/\[.*?\]/', '', $row['filter_label']));
    if (!isset($buildDates[$filterBase])) {
        $buildDates[$filterBase] = [];
    }
    $buildDates[$filterBase][] = $row['assign_date'];
}

// Собираем все уникальные даты
$allDates = [];
foreach ($filters as $filter) {
    $filterBase = $filter['filter_base'];
    if (isset($cutDates[$filterBase])) {
        $allDates = array_merge($allDates, $cutDates[$filterBase]);
    }
    if (isset($corrDates[$filterBase])) {
        $allDates = array_merge($allDates, $corrDates[$filterBase]);
    }
    if (isset($buildDates[$filterBase])) {
        $allDates = array_merge($allDates, $buildDates[$filterBase]);
    }
}

$allDates = array_unique($allDates);
sort($allDates);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Комплексный план производства - <?= htmlspecialchars($orderNumber) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }

        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .header p {
            color: #7f8c8d;
        }

        .gantt-container {
            background: white;
            border-radius: 8px;
            overflow-x: auto;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .gantt-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        .gantt-table thead th {
            background: #34495e;
            color: white;
            padding: 12px 8px;
            font-weight: 600;
            text-align: center;
            border: 1px solid #2c3e50;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .gantt-table tbody td {
            border: 1px solid #e0e0e0;
            padding: 0;
            vertical-align: middle;
        }

        .filter-cell {
            padding: 8px;
            font-weight: 500;
            background: #ecf0f1;
            min-width: 200px;
        }

        .count-cell {
            padding: 8px;
            text-align: center;
            background: #ecf0f1;
            min-width: 80px;
        }

        .date-cell {
            min-width: 80px;
            text-align: center;
            font-size: 11px;
        }

        .operation-row {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 60px;
        }

        .operation-sub-row {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid #e0e0e0;
            position: relative;
            min-height: 20px;
        }

        .operation-sub-row:last-child {
            border-bottom: none;
        }

        .operation-sub-row.cut {
            background: #fff9e6;
        }

        .operation-sub-row.corr {
            background: #e6f7ff;
        }

        .operation-sub-row.build {
            background: #e6ffe6;
        }

        .operation-label {
            position: absolute;
            left: 5px;
            font-size: 10px;
            color: #666;
            font-weight: 500;
        }

        .gantt-bar {
            height: 80%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            width: 90%;
        }

        .gantt-bar.cut {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .gantt-bar.corr {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .gantt-bar.build {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .legend {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-color {
            width: 30px;
            height: 20px;
            border-radius: 3px;
        }

        .legend-color.cut {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .legend-color.corr {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .legend-color.build {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        @media print {
            body {
                padding: 0;
            }
            .header {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        Комплексный план производства (U2)
        Заявка: <strong><?= htmlspecialchars($orderNumber) ?></strong></p>
        <p>Всего фильтров: <strong><?= count($filters) ?></strong></p>
    </div>

    <div class="legend">
        <div class="legend-item">
            <div class="legend-color cut"></div>
            <span>Порезка</span>
        </div>
        <div class="legend-item">
            <div class="legend-color corr"></div>
            <span>Гофрирование</span>
        </div>
        <div class="legend-item">
            <div class="legend-color build"></div>
            <span>Сборка</span>
        </div>
    </div>

    <div class="gantt-container">
        <table class="gantt-table">
            <thead>
                <tr>
                    <th>Фильтр</th>
                    <th>Кол-во</th>
                    <?php foreach ($allDates as $date): ?>
                        <th class="date-cell"><?= date('d.m', strtotime($date)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filters as $filter): 
                    $filterBase = $filter['filter_base'];
                ?>
                    <tr>
                        <td class="filter-cell"><?= htmlspecialchars($filter['filter_full']) ?></td>
                        <td class="count-cell"><?= htmlspecialchars($filter['total_count']) ?></td>
                        <?php foreach ($allDates as $date): ?>
                            <td>
                                <div class="operation-row">
                                    <!-- Порезка -->
                                    <div class="operation-sub-row cut">
                                        <span class="operation-label">П</span>
                                        <?php if (isset($cutDates[$filterBase]) && in_array($date, $cutDates[$filterBase])): ?>
                                            <div class="gantt-bar cut">●</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Гофрирование -->
                                    <div class="operation-sub-row corr">
                                        <span class="operation-label">Г</span>
                                        <?php if (isset($corrDates[$filterBase]) && in_array($date, $corrDates[$filterBase])): ?>
                                            <div class="gantt-bar corr">●</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Сборка -->
                                    <div class="operation-sub-row build">
                                        <span class="operation-label">С</span>
                                        <?php if (isset($buildDates[$filterBase]) && in_array($date, $buildDates[$filterBase])): ?>
                                            <div class="gantt-bar build">●</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        // Автопечать если указан параметр print=1
        if (window.location.search.includes('print=1')) {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        }
    </script>
</body>
</html>

