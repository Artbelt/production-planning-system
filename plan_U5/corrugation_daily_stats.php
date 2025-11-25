<?php
/**
 * Статистика производства гофропакетов по дням для У5
 * Показывает сколько гофропакетов было сделано каждый день
 */
header('Content-Type: text/html; charset=utf-8');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Получаем параметры фильтрации
$period = $_GET['period'] ?? 'last_month';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

try {
    // Определяем диапазон дат
    $dateFilter = '';
    $dateCondition = '';
    
    if ($period === 'last_month') {
        // За последний месяц (30 дней)
        $dateFilter = date('Y-m-d', strtotime('-30 days'));
        $dateCondition = "WHERE plan_date >= '$dateFilter'";
    } elseif ($period === 'last_year') {
        // За последний год (365 дней)
        $dateFilter = date('Y-m-d', strtotime('-1 year'));
        $dateCondition = "WHERE plan_date >= '$dateFilter'";
    } elseif ($period === 'custom' && $date_from && $date_to) {
        // Пользовательский диапазон
        $dateCondition = "WHERE plan_date >= '$date_from' AND plan_date <= '$date_to'";
    } else {
        // За все время
        $dateCondition = "";
    }
    
    // Запрос для получения статистики по дням
    $sql = "
        SELECT 
            plan_date,
            SUM(COALESCE(fact_count, 0)) as total_packages,
            COUNT(DISTINCT order_number) as orders_count,
            COUNT(DISTINCT filter_label) as filters_count
        FROM corrugation_plan
        $dateCondition
        AND COALESCE(fact_count, 0) > 0
        GROUP BY plan_date
        ORDER BY plan_date DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll();
    
    // Получаем детальную информацию по каждому дню
    $detailsByDate = [];
    if (count($results) > 0) {
        $dates = array_column($results, 'plan_date');
        $placeholders = str_repeat('?,', count($dates) - 1) . '?';
        
        $detailsSql = "
            SELECT 
                plan_date,
                order_number,
                filter_label,
                SUM(COALESCE(fact_count, 0)) as total_count
            FROM corrugation_plan
            WHERE plan_date IN ($placeholders)
            AND COALESCE(fact_count, 0) > 0
            GROUP BY plan_date, order_number, filter_label
            ORDER BY plan_date DESC, order_number, filter_label
        ";
        
        $detailsStmt = $pdo->prepare($detailsSql);
        $detailsStmt->execute($dates);
        $detailsResults = $detailsStmt->fetchAll();
        
        // Группируем детали по датам
        foreach ($detailsResults as $detail) {
            $date = $detail['plan_date'];
            if (!isset($detailsByDate[$date])) {
                $detailsByDate[$date] = [];
            }
            if (!isset($detailsByDate[$date][$detail['order_number']])) {
                $detailsByDate[$date][$detail['order_number']] = [];
            }
            $detailsByDate[$date][$detail['order_number']][] = [
                'filter' => $detail['filter_label'],
                'count' => (int)$detail['total_count']
            ];
        }
    }
    
    // Вычисляем общую статистику
    $total_packages = 0;
    $total_days = count($results);
    $max_day = 0;
    $min_day = PHP_INT_MAX;
    
    foreach ($results as $row) {
        $total_packages += (int)$row['total_packages'];
        $day_count = (int)$row['total_packages'];
        if ($day_count > $max_day) {
            $max_day = $day_count;
        }
        if ($day_count < $min_day && $day_count > 0) {
            $min_day = $day_count;
        }
    }
    
    $avg_per_day = $total_days > 0 ? round($total_packages / $total_days, 1) : 0;
    if ($min_day === PHP_INT_MAX) {
        $min_day = 0;
    }
    
    // Функция для получения дня недели на русском
    function getDayOfWeek($date) {
        $days = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
        $dayNum = (int)date('w', strtotime($date));
        return $days[$dayNum] ?? '';
    }
    
    // Функция для форматирования даты
    function formatDate($date) {
        return date('d.m.Y', strtotime($date));
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Статистика производства гофропакетов по дням</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                background-color: #f5f5f5;
            }
            .container {
                max-width: 1400px;
                margin: 0 auto;
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            h1 {
                color: #333;
                margin-bottom: 20px;
            }
            .filters {
                background: #fff3cd;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                border: 1px solid #ffc107;
            }
            .filters form {
                display: flex;
                align-items: center;
                gap: 20px;
                flex-wrap: wrap;
            }
            .filters label {
                font-weight: bold;
            }
            select, input[type="date"] {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 14px;
                cursor: pointer;
            }
            select:hover, input[type="date"]:hover {
                border-color: #2196F3;
            }
            select:focus, input[type="date"]:focus {
                outline: none;
                border-color: #2196F3;
                box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.2);
            }
            button {
                padding: 8px 16px;
                background-color: #2196F3;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 14px;
                font-weight: bold;
            }
            button:hover {
                background-color: #1976D2;
            }
            .stats-summary {
                background: #e3f2fd;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                display: flex;
                gap: 30px;
                flex-wrap: wrap;
            }
            .stat-item {
                display: flex;
                flex-direction: column;
            }
            .stat-label {
                font-size: 12px;
                color: #666;
                margin-bottom: 5px;
            }
            .stat-value {
                font-size: 24px;
                font-weight: bold;
                color: #2196F3;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th {
                background-color: #2196F3;
                color: white;
                padding: 12px;
                text-align: left;
                font-weight: bold;
                cursor: pointer;
                user-select: none;
                position: relative;
            }
            th:hover {
                background-color: #1976D2;
            }
            th.sortable::after {
                content: ' ↕';
                opacity: 0.5;
                font-size: 0.8em;
            }
            th.sort-asc::after {
                content: ' ↑';
                opacity: 1;
            }
            th.sort-desc::after {
                content: ' ↓';
                opacity: 1;
            }
            td {
                padding: 10px;
                border-bottom: 1px solid #ddd;
            }
            tr:hover {
                background-color: #f5f5f5;
            }
            .date-col {
                font-weight: bold;
            }
            .count-col {
                text-align: center;
                font-weight: bold;
                color: #2196F3;
            }
            .day-of-week {
                color: #666;
                font-size: 0.9em;
                font-weight: normal;
            }
            .no-data {
                padding: 20px;
                text-align: center;
                color: #666;
            }
            .chart-container {
                margin-top: 30px;
                padding: 20px;
                background: #f9f9f9;
                border-radius: 5px;
            }
            .bar-chart {
                display: flex;
                align-items: flex-end;
                gap: 2px;
                height: 300px;
                padding: 10px 0;
                overflow-x: auto;
            }
            .bar-item {
                flex: 1;
                min-width: 30px;
                display: flex;
                flex-direction: column;
                align-items: center;
                position: relative;
            }
            .bar {
                width: 100%;
                background: linear-gradient(to top, #2196F3, #64B5F6);
                border-radius: 4px 4px 0 0;
                position: relative;
                transition: opacity 0.2s;
                cursor: help;
            }
            .bar:hover {
                opacity: 0.8;
            }
            .bar-item:hover .tooltip {
                visibility: visible;
                opacity: 1;
            }
            .bar-item .tooltip {
                position: absolute;
                bottom: calc(100% + 10px);
                left: 50%;
                transform: translateX(-50%);
                z-index: 1001;
            }
            .bar-item .tooltip::after {
                top: 100%;
                border-top-color: transparent;
                border-bottom-color: #333;
            }
            .bar-label {
                margin-top: 5px;
                font-size: 10px;
                color: #666;
                text-align: center;
                transform: rotate(-45deg);
                transform-origin: top left;
                white-space: nowrap;
            }
            .bar-value {
                position: absolute;
                top: -20px;
                left: 50%;
                transform: translateX(-50%);
                font-size: 11px;
                font-weight: bold;
                color: #2196F3;
                white-space: nowrap;
            }
            /* Tooltip стили */
            .count-col {
                position: relative;
                cursor: help;
            }
            .tooltip {
                visibility: hidden;
                opacity: 0;
                position: absolute;
                z-index: 1000;
                background-color: #333;
                color: #fff;
                text-align: left;
                padding: 12px;
                border-radius: 6px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                font-size: 13px;
                line-height: 1.5;
                min-width: 300px;
                max-width: 500px;
                white-space: normal;
                pointer-events: none;
                transition: opacity 0.3s, visibility 0.3s;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                margin-bottom: 10px;
            }
            .tooltip::after {
                content: "";
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: #333;
            }
            .count-col:hover .tooltip {
                visibility: visible;
                opacity: 1;
            }
            .tooltip-header {
                font-weight: bold;
                font-size: 14px;
                margin-bottom: 10px;
                padding-bottom: 8px;
                border-bottom: 1px solid #555;
                color: #fff;
            }
            .tooltip-section {
                margin-bottom: 10px;
            }
            .tooltip-order {
                margin-bottom: 8px;
            }
            .tooltip-order-title {
                font-weight: bold;
                color: #64B5F6;
                margin-bottom: 4px;
            }
            .tooltip-item {
                padding-left: 15px;
                margin-bottom: 3px;
                font-size: 12px;
            }
            .tooltip-item-count {
                color: #81C784;
                font-weight: bold;
            }
            .tooltip-total {
                margin-top: 8px;
                padding-top: 8px;
                border-top: 1px solid #555;
                font-weight: bold;
                color: #FFD54F;
            }
        </style>
        <script>
            // Функция сортировки таблицы
            let sortDirection = {};
            
            function sortTable(columnIndex, type) {
                const table = document.getElementById('resultsTable');
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const headers = table.querySelectorAll('th');
                
                // Определяем направление сортировки
                if (!sortDirection[columnIndex]) {
                    sortDirection[columnIndex] = 'asc';
                } else {
                    sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
                }
                
                // Убираем классы сортировки со всех заголовков
                headers.forEach((header, index) => {
                    header.classList.remove('sort-asc', 'sort-desc');
                    if (index === columnIndex) {
                        header.classList.add('sort-' + sortDirection[columnIndex]);
                    }
                });
                
                // Сортируем строки
                rows.sort(function(a, b) {
                    let aValue = a.cells[columnIndex].textContent.trim();
                    let bValue = b.cells[columnIndex].textContent.trim();
                    
                    if (type === 'number') {
                        aValue = parseFloat(aValue.replace(/[^\d.-]/g, '')) || 0;
                        bValue = parseFloat(bValue.replace(/[^\d.-]/g, '')) || 0;
                    } else if (type === 'date') {
                        // Преобразуем дату из формата dd.mm.yyyy в timestamp
                        const aParts = aValue.split('.');
                        const bParts = bValue.split('.');
                        if (aParts.length === 3 && bParts.length === 3) {
                            aValue = new Date(aParts[2], aParts[1] - 1, aParts[0]).getTime();
                            bValue = new Date(bParts[2], bParts[1] - 1, bParts[0]).getTime();
                        }
                    }
                    
                    let comparison = 0;
                    if (aValue > bValue) {
                        comparison = 1;
                    } else if (aValue < bValue) {
                        comparison = -1;
                    }
                    
                    return sortDirection[columnIndex] === 'asc' ? comparison : -comparison;
                });
                
                // Пересчитываем номера строк для первого столбца
                rows.forEach((row, index) => {
                    row.cells[0].textContent = index + 1;
                    tbody.appendChild(row);
                });
            }
            
            // Автоматическая отправка формы при изменении периода
            function autoSubmit() {
                document.getElementById('filterForm').submit();
            }
        </script>
    </head>
    <body>
        <div class="container">
            <h1>Статистика производства гофропакетов по дням</h1>
            
            <div class="filters">
                <form method="GET" action="" id="filterForm">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>Период:</label>
                        <select name="period" onchange="autoSubmit()">
                            <option value="last_month" <?php echo $period === 'last_month' ? 'selected' : ''; ?>>За последний месяц</option>
                            <option value="last_year" <?php echo $period === 'last_year' ? 'selected' : ''; ?>>За последний год</option>
                            <option value="all_time" <?php echo $period === 'all_time' ? 'selected' : ''; ?>>За все время</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Выбрать период</option>
                        </select>
                    </div>
                    
                    <?php if ($period === 'custom'): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>С:</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" onchange="autoSubmit()">
                        <label>По:</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" onchange="autoSubmit()">
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit">Применить</button>
                </form>
            </div>
            
            <div class="stats-summary">
                <div class="stat-item">
                    <div class="stat-label">Всего гофропакетов</div>
                    <div class="stat-value"><?php echo number_format($total_packages, 0, '.', ' '); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Дней с производством</div>
                    <div class="stat-value"><?php echo $total_days; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Среднее в день</div>
                    <div class="stat-value"><?php echo number_format($avg_per_day, 1, '.', ' '); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Максимум за день</div>
                    <div class="stat-value"><?php echo number_format($max_day, 0, '.', ' '); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Минимум за день</div>
                    <div class="stat-value"><?php echo number_format($min_day, 0, '.', ' '); ?></div>
                </div>
            </div>
            
            <?php if (count($results) > 0): ?>
            <table id="resultsTable">
                <thead>
                    <tr>
                        <th class="sortable" onclick="sortTable(0, 'number')">№</th>
                        <th class="sortable" onclick="sortTable(1, 'date')">Дата</th>
                        <th class="sortable" onclick="sortTable(2, 'number')">Гофропакетов</th>
                        <th class="sortable" onclick="sortTable(3, 'number')">Заявок</th>
                        <th class="sortable" onclick="sortTable(4, 'number')">Фильтров</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $index = 1;
                    foreach ($results as $row): 
                        $dayOfWeek = getDayOfWeek($row['plan_date']);
                        $date = $row['plan_date'];
                        $dayDetails = $detailsByDate[$date] ?? [];
                        
                        // Формируем HTML для tooltip
                        $tooltipHtml = '';
                        if (!empty($dayDetails)) {
                            $tooltipHtml .= '<div class="tooltip-header">' . formatDate($date) . '</div>';
                            $totalTooltip = 0;
                            
                            foreach ($dayDetails as $orderNumber => $filters) {
                                $orderTotal = 0;
                                foreach ($filters as $filter) {
                                    $orderTotal += $filter['count'];
                                    $totalTooltip += $filter['count'];
                                }
                                
                                $tooltipHtml .= '<div class="tooltip-section">';
                                $tooltipHtml .= '<div class="tooltip-order">';
                                $tooltipHtml .= '<div class="tooltip-order-title">Заявка: ' . htmlspecialchars($orderNumber) . ' (всего: ' . $orderTotal . ')</div>';
                                
                                foreach ($filters as $filter) {
                                    $tooltipHtml .= '<div class="tooltip-item">';
                                    $tooltipHtml .= htmlspecialchars($filter['filter']) . ': <span class="tooltip-item-count">' . $filter['count'] . '</span>';
                                    $tooltipHtml .= '</div>';
                                }
                                
                                $tooltipHtml .= '</div>';
                                $tooltipHtml .= '</div>';
                            }
                            
                            $tooltipHtml .= '<div class="tooltip-total">Всего: ' . $totalTooltip . ' гофропакетов</div>';
                        }
                    ?>
                    <tr>
                        <td><?php echo $index++; ?></td>
                        <td class="date-col">
                            <?php echo formatDate($row['plan_date']); ?>
                            <span class="day-of-week">(<?php echo $dayOfWeek; ?>)</span>
                        </td>
                        <td class="count-col">
                            <?php echo number_format((int)$row['total_packages'], 0, '.', ' '); ?>
                            <?php if (!empty($tooltipHtml)): ?>
                            <div class="tooltip"><?php echo $tooltipHtml; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="count-col"><?php echo (int)$row['orders_count']; ?></td>
                        <td class="count-col"><?php echo (int)$row['filters_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (count($results) > 0): ?>
            <div class="chart-container">
                <h3 style="margin-top: 0;">Визуализация производства по дням</h3>
                <div class="bar-chart">
                    <?php 
                    // Ограничиваем количество столбцов для читаемости (последние 30 дней)
                    $chartData = array_slice($results, 0, 30);
                    $maxValue = $max_day > 0 ? $max_day : 1;
                    
                    foreach ($chartData as $row): 
                        $height = ($row['total_packages'] / $maxValue) * 100;
                        $dateFormatted = formatDate($row['plan_date']);
                        $date = $row['plan_date'];
                        $dayDetails = $detailsByDate[$date] ?? [];
                        
                        // Формируем HTML для tooltip графика
                        $chartTooltipHtml = '';
                        if (!empty($dayDetails)) {
                            $chartTooltipHtml .= '<div class="tooltip-header">' . formatDate($date) . '</div>';
                            $totalTooltip = 0;
                            
                            foreach ($dayDetails as $orderNumber => $filters) {
                                $orderTotal = 0;
                                foreach ($filters as $filter) {
                                    $orderTotal += $filter['count'];
                                    $totalTooltip += $filter['count'];
                                }
                                
                                $chartTooltipHtml .= '<div class="tooltip-section">';
                                $chartTooltipHtml .= '<div class="tooltip-order">';
                                $chartTooltipHtml .= '<div class="tooltip-order-title">Заявка: ' . htmlspecialchars($orderNumber) . ' (всего: ' . $orderTotal . ')</div>';
                                
                                foreach ($filters as $filter) {
                                    $chartTooltipHtml .= '<div class="tooltip-item">';
                                    $chartTooltipHtml .= htmlspecialchars($filter['filter']) . ': <span class="tooltip-item-count">' . $filter['count'] . '</span>';
                                    $chartTooltipHtml .= '</div>';
                                }
                                
                                $chartTooltipHtml .= '</div>';
                                $chartTooltipHtml .= '</div>';
                            }
                            
                            $chartTooltipHtml .= '<div class="tooltip-total">Всего: ' . $totalTooltip . ' гофропакетов</div>';
                        }
                    ?>
                    <div class="bar-item">
                        <div class="bar" style="height: <?php echo $height; ?>%; position: relative;">
                            <div class="bar-value"><?php echo (int)$row['total_packages']; ?></div>
                        </div>
                        <?php if (!empty($chartTooltipHtml)): ?>
                        <div class="tooltip">
                            <?php echo $chartTooltipHtml; ?>
                        </div>
                        <?php endif; ?>
                        <div class="bar-label"><?php echo $dateFormatted; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="no-data">
                <p>За выбранный период данных о производстве гофропакетов не найдено.</p>
            </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    echo "<h1>Ошибка</h1>";
    echo "<p style='color: red;'>Ошибка базы данных: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

