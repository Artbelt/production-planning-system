<?php
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

// Проверяем, есть ли у пользователя доступ к цеху U3
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U3' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    die('У вас нет доступа к цеху U3');
}

require_once('tools/tools.php');
require_once('settings.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
require_once('cap_db_init.php');

// Получаем параметры фильтрации
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Валидация дат
if (!strtotime($date_from) || !strtotime($date_to)) {
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = date('Y-m-d');
}

// Получаем данные о приходе крышек по дням (в штуках)
$income_data = [];
$income_tooltips = []; // Детальная информация для tooltip

$sql_income = "
    SELECT 
        date,
        cap_name,
        SUM(quantity) as total_quantity
    FROM cap_movements
    WHERE operation_type = 'INCOME'
      AND date BETWEEN ? AND ?
    GROUP BY date, cap_name
    ORDER BY date DESC
";

$stmt_income = $pdo->prepare($sql_income);
if ($stmt_income && $stmt_income->execute([$date_from, $date_to])) {
    $income_details = [];
    while ($row = $stmt_income->fetch(PDO::FETCH_ASSOC)) {
        $date = $row['date'];
        $cap_name = trim($row['cap_name']);
        $quantity = (int)$row['total_quantity'];
        
        if (!isset($income_details[$date])) {
            $income_details[$date] = [];
        }
        $income_details[$date][$cap_name] = $quantity;
    }
    
    // Подсчитываем общее количество крышек по дням (в штуках)
    foreach ($income_details as $date => $caps) {
        $total_pieces = 0;
        $tooltip_info = [];
        
        foreach ($caps as $cap_name => $quantity) {
            $total_pieces += $quantity;
            $tooltip_info[] = [
                'cap' => $cap_name,
                'qty' => $quantity
            ];
        }
        
        $income_data[$date] = $total_pieces;
        $income_tooltips[$date] = $tooltip_info;
    }
}

// Получаем данные о расходе крышек по дням (в штуках)
// Сначала получаем количество произведенных фильтров по датам
$filter_counts_by_date = [];
$sql_filters = "
    SELECT 
        date_of_production as date,
        name_of_filter,
        SUM(count_of_filters) as total_filters
    FROM manufactured_production
    WHERE date_of_production BETWEEN ? AND ?
    GROUP BY date_of_production, name_of_filter
";

$stmt_filters = $pdo->prepare($sql_filters);
if ($stmt_filters && $stmt_filters->execute([$date_from, $date_to])) {
    while ($row = $stmt_filters->fetch(PDO::FETCH_ASSOC)) {
        $date = $row['date'];
        $filter_name = trim($row['name_of_filter'] ?? '');
        $count = (int)$row['total_filters'];
        
        if (!isset($filter_counts_by_date[$date])) {
            $filter_counts_by_date[$date] = [];
        }
        $filter_counts_by_date[$date][$filter_name] = $count;
    }
}

// Получаем детальную информацию о крышках по фильтрам
$production_data = [];
$production_tooltips = []; // Детальная информация для tooltip

$sql_production = "
    SELECT 
        date,
        filter_name,
        cap_name,
        SUM(quantity) as total_quantity
    FROM cap_movements
    WHERE operation_type = 'PRODUCTION_OUT'
      AND date BETWEEN ? AND ?
    GROUP BY date, filter_name, cap_name
    ORDER BY date DESC, filter_name, cap_name
";

$stmt_production = $pdo->prepare($sql_production);
if ($stmt_production && $stmt_production->execute([$date_from, $date_to])) {
    $production_details = [];
    while ($row = $stmt_production->fetch(PDO::FETCH_ASSOC)) {
        $date = $row['date'];
        $filter_name = trim($row['filter_name'] ?? '');
        $cap_name = trim($row['cap_name'] ?? '');
        $quantity = (int)$row['total_quantity'];
        
        if (!isset($production_details[$date])) {
            $production_details[$date] = [];
        }
        if (!isset($production_details[$date][$filter_name])) {
            $production_details[$date][$filter_name] = [];
        }
        $production_details[$date][$filter_name][$cap_name] = $quantity;
    }
    
    // Подсчитываем общее количество крышек и формируем tooltip
    foreach ($production_details as $date => $filters) {
        $total_pieces = 0;
        $tooltip_info = [];
        
        foreach ($filters as $filter_name => $caps) {
            // Количество произведенных фильтров из manufactured_production
            $filter_count = $filter_counts_by_date[$date][$filter_name] ?? 0;
            
            // Подсчитываем общее количество крышек для этого фильтра
            $total_caps = 0;
            $filter_caps = [];
            
            foreach ($caps as $cap_name => $quantity) {
                $total_pieces += $quantity;
                $total_caps += $quantity;
                $filter_caps[] = [
                    'cap' => $cap_name,
                    'qty' => $quantity
                ];
            }
            
            $tooltip_info[] = [
                'filter' => $filter_name ?: 'Не указан',
                'filter_count' => $filter_count,
                'total_caps' => $total_caps,
                'caps' => $filter_caps
            ];
        }
        
        $production_data[$date] = $total_pieces;
        $production_tooltips[$date] = $tooltip_info;
    }
}

// Создаем массив всех дат в выбранном периоде
$all_dates = [];
$current_date = new DateTime($date_from);
$end_date = new DateTime($date_to);
$end_date->modify('+1 day'); // Добавляем день, чтобы включить конечную дату

while ($current_date < $end_date) {
    $all_dates[] = $current_date->format('Y-m-d');
    $current_date->modify('+1 day');
}

// Сортируем по убыванию (новые даты сверху)
rsort($all_dates);

// Подсчитываем итоги
$total_income = array_sum($income_data);
$total_production = array_sum($production_data);
$total_balance = $total_income - $total_production;

// Подсчитываем средние значения за весь выбранный период
$date_from_obj = new DateTime($date_from);
$date_to_obj = new DateTime($date_to);
$period_days = $date_from_obj->diff($date_to_obj)->days + 1; // +1 чтобы включить обе даты
$avg_income = $period_days > 0 ? round($total_income / $period_days, 1) : 0;
$avg_production = $period_days > 0 ? round($total_production / $period_days, 1) : 0;

// Подготовка данных для графика (накопленный баланс)
// Сортируем даты по возрастанию для графика
$chart_dates = array_unique(array_merge(array_keys($income_data), array_keys($production_data)));
sort($chart_dates); // Сортируем по возрастанию (старые даты сначала)

$chart_labels = [];
$chart_balance = [];
$cumulative_balance = 0;

foreach ($chart_dates as $date) {
    $income = $income_data[$date] ?? 0;
    $production = $production_data[$date] ?? 0;
    $daily_balance = $income - $production;
    $cumulative_balance += $daily_balance;
    
    $chart_labels[] = date('d.m', strtotime($date));
    $chart_balance[] = $cumulative_balance;
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Анализ баланса потребления крышки - U3</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 10px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #6495ed;
            padding-bottom: 6px;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 22px;
        }
        .filters {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-group label {
            font-weight: bold;
            color: #555;
            font-size: 13px;
        }
        .filter-group input[type="date"] {
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 13px;
        }
        button {
            background: #6495ed;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            height: 32px;
        }
        button:hover {
            background: #4169e1;
        }
        .summary {
            background: #e8f0fe;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .summary-averages {
            background: #f0f8ff;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            border: 1px solid #b0d4f1;
        }
        .summary-item {
            text-align: center;
        }
        .summary-item .label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        .summary-item .value {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        .summary-item .value.positive {
            color: #28a745;
        }
        .summary-item .value.negative {
            color: #dc3545;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #6495ed;
            color: white;
            font-weight: bold;
            font-size: 13px;
            position: sticky;
            top: 0;
        }
        th:nth-child(1) { width: 15%; }
        th:nth-child(2) { width: 20%; text-align: right; }
        th:nth-child(3) { width: 20%; text-align: right; }
        th:nth-child(4) { width: 20%; text-align: right; }
        th:nth-child(5) { width: 25%; text-align: right; }
        td {
            text-align: left;
        }
        td:nth-child(2),
        td:nth-child(3),
        td:nth-child(4),
        td:nth-child(5) {
            text-align: right;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .income {
            color: #28a745;
            font-weight: bold;
        }
        .production {
            color: #dc3545;
            font-weight: bold;
        }
        .balance {
            font-weight: bold;
        }
        .balance.positive {
            color: #28a745;
        }
        .balance.negative {
            color: #dc3545;
        }
        .balance.zero {
            color: #666;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 15px;
            color: #6495ed;
            text-decoration: none;
            font-size: 13px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .income-cell, .production-cell {
            cursor: help;
            position: relative;
        }
        .tooltip {
            position: absolute;
            background: #2d3748;
            color: #fff;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 12px;
            z-index: 1000;
            box-shadow: 0 4px 16px rgba(0,0,0,0.4);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s;
            max-width: 350px;
            white-space: normal;
            line-height: 1.5;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .tooltip.visible {
            opacity: 1;
        }
        .tooltip-title {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding-bottom: 6px;
            color: #e2e8f0;
        }
        .tooltip-item {
            margin: 6px 0;
            padding: 6px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .tooltip-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .tooltip-filter-name {
            font-weight: 600;
            font-size: 13px;
            color: #90cdf4;
            margin-right: 8px;
            display: inline-block;
        }
        .tooltip-filter-count {
            font-size: 11px;
            color: #a0aec0;
            display: inline-block;
        }
        .tooltip-caps-list {
            margin-left: 8px;
            margin-top: 4px;
            display: inline-block;
        }
        .tooltip-cap-item {
            font-size: 11px;
            color: #cbd5e0;
            margin: 0 8px 0 0;
            padding-left: 12px;
            position: relative;
            display: inline-block;
        }
        .tooltip-cap-item:before {
            content: '▸';
            position: absolute;
            left: 0;
            color: #718096;
        }
        .tooltip-pair {
            color: #90ee90;
        }
        .tooltip-unpaired {
            color: #ffb6c1;
        }
        .tooltip-single {
            color: #87ceeb;
        }
        .chart-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 20px;
            position: relative;
        }
        .chart-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }
        .chart-wrapper {
            position: relative;
            height: 200px;
            width: 100%;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>Анализ баланса потребления крышек (в штуках)</h1>
        
        <div class="filters">
            <div class="filter-group">
                <label for="date_from">Дата от:</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label for="date_to">Дата до:</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <button onclick="applyFilters()">Применить фильтр</button>
        </div>
        
        <div class="summary">
            <div class="summary-item">
                <div class="label">Приход крышек (шт)</div>
                <div class="value positive"><?php echo number_format($total_income, 0, ',', ' '); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Расход крышек (шт)</div>
                <div class="value production"><?php echo number_format($total_production, 0, ',', ' '); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Баланс (приход - расход)</div>
                <div class="value <?php echo $total_balance > 0 ? 'positive' : ($total_balance < 0 ? 'negative' : ''); ?>">
                    <?php echo number_format($total_balance, 0, ',', ' '); ?>
                </div>
            </div>
        </div>
        
        <div class="summary-averages">
            <div class="summary-item">
                <div class="label">Средний приход за период (шт/день)</div>
                <div class="value positive"><?php echo number_format($avg_income, 1, ',', ' '); ?></div>
                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                    за <?php echo $period_days; ?> <?php echo $period_days == 1 ? 'день' : ($period_days < 5 ? 'дня' : 'дней'); ?>
                </div>
            </div>
            <div class="summary-item">
                <div class="label">Средний расход за период (шт/день)</div>
                <div class="value production"><?php echo number_format($avg_production, 1, ',', ' '); ?></div>
                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                    за <?php echo $period_days; ?> <?php echo $period_days == 1 ? 'день' : ($period_days < 5 ? 'дня' : 'дней'); ?>
                </div>
            </div>
        </div>
        
        <?php if (!empty($chart_labels)): ?>
        <div class="chart-container">
            <div class="chart-title">Изменение склада крышек (накопленный баланс в штуках)</div>
            <div class="chart-wrapper">
                <canvas id="balanceChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (empty($all_dates)): ?>
            <div class="no-data">
                <p>Нет данных за выбранный период</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Приход крышек<br>(шт)</th>
                        <th>Расход крышек<br>(шт)</th>
                        <th>Баланс<br>(приход - расход)</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_dates as $date): 
                        $income = $income_data[$date] ?? 0;
                        $production = $production_data[$date] ?? 0;
                        $balance = $income - $production;
                        $status_class = $balance > 0 ? 'positive' : ($balance < 0 ? 'negative' : 'zero');
                        $status_text = $balance > 0 ? 'Накопление' : ($balance < 0 ? 'Уменьшение' : 'Баланс');
                    ?>
                        <tr>
                            <td><?php echo date('d.m.Y', strtotime($date)); ?></td>
                            <td class="income income-cell" 
                                data-date="<?php echo htmlspecialchars($date); ?>"
                                data-tooltip="<?php echo htmlspecialchars(json_encode($income_tooltips[$date] ?? [], JSON_UNESCAPED_UNICODE)); ?>">
                                <?php echo $income > 0 ? '+' . number_format($income, 0, ',', ' ') : '-'; ?>
                                <div class="tooltip" id="tooltip-<?php echo str_replace('-', '', $date); ?>"></div>
                            </td>
                            <td class="production production-cell" 
                                data-date="<?php echo htmlspecialchars($date); ?>"
                                data-tooltip="<?php echo htmlspecialchars(json_encode($production_tooltips[$date] ?? [], JSON_UNESCAPED_UNICODE)); ?>">
                                <?php echo $production > 0 ? number_format($production, 0, ',', ' ') : '-'; ?>
                                <div class="tooltip" id="tooltip-prod-<?php echo str_replace('-', '', $date); ?>"></div>
                            </td>
                            <td class="balance <?php echo $status_class; ?>">
                                <?php 
                                if ($balance > 0) {
                                    echo '+' . number_format($balance, 0, ',', ' ');
                                } elseif ($balance < 0) {
                                    echo number_format($balance, 0, ',', ' ');
                                } else {
                                    echo '0';
                                }
                                ?>
                            </td>
                            <td class="balance <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <script>
    function applyFilters() {
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        
        if (!dateFrom || !dateTo) {
            alert('Пожалуйста, выберите обе даты');
            return;
        }
        
        if (dateFrom > dateTo) {
            alert('Дата "от" не может быть больше даты "до"');
            return;
        }
        
        window.location.href = 'cap_balance_analysis.php?date_from=' + dateFrom + '&date_to=' + dateTo;
    }
    
    // Применяем фильтр при нажатии Enter в полях дат
    document.getElementById('date_from').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
    
    document.getElementById('date_to').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
    
    // Инициализация tooltip для прихода
    const incomeCells = document.querySelectorAll('.income-cell');
    incomeCells.forEach(cell => {
        const tooltipData = cell.getAttribute('data-tooltip');
        if (!tooltipData || tooltipData === '[]') {
            return; // Нет данных для tooltip
        }
        
        const tooltipInfo = JSON.parse(tooltipData);
        const tooltipId = cell.querySelector('.tooltip').id;
        const tooltip = document.getElementById(tooltipId);
        
        // Формируем содержимое tooltip
        let tooltipContent = '<div class="tooltip-title">Детали прихода</div>';
        
        tooltipInfo.forEach((item, index) => {
            if (index > 0) tooltipContent += ' • ';
            tooltipContent += `${item.cap}: <strong>${item.qty} шт</strong>`;
        });
        
        tooltip.innerHTML = tooltipContent;
        
        // Позиционирование tooltip
        cell.addEventListener('mouseenter', function(e) {
            tooltip.classList.add('visible');
            updateTooltipPosition(tooltip, e);
        });
        
        cell.addEventListener('mousemove', function(e) {
            updateTooltipPosition(tooltip, e);
        });
        
        cell.addEventListener('mouseleave', function() {
            tooltip.classList.remove('visible');
        });
    });
    
    // Инициализация tooltip для расхода
    const productionCells = document.querySelectorAll('.production-cell');
    productionCells.forEach(cell => {
        const tooltipData = cell.getAttribute('data-tooltip');
        if (!tooltipData || tooltipData === '[]') {
            return; // Нет данных для tooltip
        }
        
        const tooltipInfo = JSON.parse(tooltipData);
        const tooltipId = cell.querySelector('.tooltip').id;
        const tooltip = document.getElementById(tooltipId);
        
        // Формируем содержимое tooltip
        let tooltipContent = '<div class="tooltip-title">Детали расхода</div>';
        
        tooltipInfo.forEach((item, index) => {
            tooltipContent += `<div class="tooltip-item">`;
            tooltipContent += `<span class="tooltip-filter-name">${item.filter}</span>`;
            if (item.filter_count > 0) {
                tooltipContent += `<span class="tooltip-filter-count">${item.filter_count} ${item.filter_count == 1 ? 'фильтр' : (item.filter_count < 5 ? 'фильтра' : 'фильтров')}</span>`;
            }
            if (item.caps && item.caps.length > 0) {
                tooltipContent += `<div class="tooltip-caps-list">`;
                item.caps.forEach((cap, capIndex) => {
                    if (capIndex > 0) tooltipContent += ' ';
                    tooltipContent += `<span class="tooltip-cap-item">${cap.cap}: ${cap.qty} шт</span>`;
                });
                tooltipContent += `</div>`;
            }
            tooltipContent += `</div>`;
        });
        
        tooltip.innerHTML = tooltipContent;
        
        // Позиционирование tooltip
        cell.addEventListener('mouseenter', function(e) {
            tooltip.classList.add('visible');
            updateTooltipPosition(tooltip, e);
        });
        
        cell.addEventListener('mousemove', function(e) {
            updateTooltipPosition(tooltip, e);
        });
        
        cell.addEventListener('mouseleave', function() {
            tooltip.classList.remove('visible');
        });
    });
    
    function updateTooltipPosition(tooltip, event) {
        const rect = tooltip.parentElement.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        
        // Позиционируем справа от ячейки
        let left = rect.right + 10;
        let top = rect.top;
        
        // Если не помещается справа, показываем слева
        if (left + tooltipRect.width > viewportWidth) {
            left = rect.left - tooltipRect.width - 10;
        }
        
        // Если не помещается снизу, поднимаем
        if (top + tooltipRect.height > viewportHeight) {
            top = viewportHeight - tooltipRect.height - 10;
        }
        
        // Если не помещается сверху, опускаем
        if (top < 10) {
            top = 10;
        }
        
        tooltip.style.left = (left - rect.left) + 'px';
        tooltip.style.top = (top - rect.top) + 'px';
    }
    
    // Инициализация графика
    <?php if (!empty($chart_labels)): ?>
    const ctx = document.getElementById('balanceChart').getContext('2d');
    const balanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
            datasets: [{
                label: 'Накопленный баланс (шт)',
                data: <?php echo json_encode($chart_balance); ?>,
                borderColor: '#6495ed',
                backgroundColor: 'rgba(100, 149, 237, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#6495ed',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 13
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed.y;
                            const sign = value >= 0 ? '+' : '';
                            return 'Баланс: ' + sign + value.toLocaleString('ru-RU') + ' шт';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    title: {
                        display: true,
                        text: 'Накопленный баланс (шт)',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString('ru-RU');
                        }
                    },
                    grid: {
                        color: function(context) {
                            if (context.tick.value === 0) {
                                return '#dc3545';
                            }
                            return 'rgba(0, 0, 0, 0.1)';
                        },
                        lineWidth: function(context) {
                            if (context.tick.value === 0) {
                                return 2;
                            }
                            return 1;
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Дата',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
