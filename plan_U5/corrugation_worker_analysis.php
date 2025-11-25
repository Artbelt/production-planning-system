<?php
/**
 * Анализ работы гофропакетчика
 * Показывает неделю с запланированными позициями по каждому дню
 */
header('Content-Type: text/html; charset=utf-8');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Получаем параметры
$week_start = $_GET['week'] ?? date('Y-m-d');

try {
    // Определяем начало недели (понедельник)
    $startDate = new DateTime($week_start);
    $dayOfWeek = (int)$startDate->format('N'); // 1 = понедельник, 7 = воскресенье
    if ($dayOfWeek > 1) {
        $startDate->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    $weekStartStr = $startDate->format('Y-m-d');
    
    // Определяем конец недели (воскресенье)
    $endDate = clone $startDate;
    $endDate->modify('+6 days');
    $weekEndStr = $endDate->format('Y-m-d');
    
    // Предыдущая и следующая недели
    $prevWeek = clone $startDate;
    $prevWeek->modify('-7 days');
    $nextWeek = clone $startDate;
    $nextWeek->modify('+7 days');
    $today = date('Y-m-d');
    
    // Получаем план из corrugation_plan с детализацией позиций
    $planSql = "
        SELECT 
            plan_date,
            order_number,
            filter_label,
            SUM(count) as count
        FROM corrugation_plan
        WHERE plan_date >= ? AND plan_date <= ?
        GROUP BY plan_date, order_number, filter_label
        ORDER BY plan_date, order_number, filter_label
    ";
    $planStmt = $pdo->prepare($planSql);
    $planStmt->execute([$weekStartStr, $weekEndStr]);
    
    // Группируем по датам
    $planByDate = [];
    while ($row = $planStmt->fetch()) {
        $date = $row['plan_date'];
        if (!isset($planByDate[$date])) {
            $planByDate[$date] = [];
        }
        $planByDate[$date][] = [
            'order_number' => $row['order_number'],
            'filter_label' => $row['filter_label'],
            'count' => (int)$row['count']
        ];
    }
    
    // Получаем факт из manufactured_corrugated_packages с детализацией позиций
    // Группируем по дате, заявке и фильтру, но сохраняем ID первой записи для обновления
    $factSql = "
        SELECT 
            date_of_production,
            order_number,
            filter_label,
            SUM(count) as count,
            MIN(id) as first_id
        FROM manufactured_corrugated_packages
        WHERE date_of_production >= ? AND date_of_production <= ?
        GROUP BY date_of_production, order_number, filter_label
        ORDER BY date_of_production, order_number, filter_label
    ";
    $factStmt = $pdo->prepare($factSql);
    $factStmt->execute([$weekStartStr, $weekEndStr]);
    
    // Группируем по датам
    $factByDate = [];
    $factData = []; // Для общей суммы по дням
    while ($row = $factStmt->fetch()) {
        $date = $row['date_of_production'];
        if (!isset($factByDate[$date])) {
            $factByDate[$date] = [];
        }
        $factByDate[$date][] = [
            'order_number' => $row['order_number'],
            'filter_label' => $row['filter_label'],
            'count' => (int)$row['count'],
            'id' => (int)$row['first_id'],
            'date_of_production' => $date
        ];
        
        // Для общей суммы по дням
        if (!isset($factData[$date])) {
            $factData[$date] = 0;
        }
        $factData[$date] += (int)$row['count'];
    }
    
    // Функция для получения дня недели на русском
    function getDayOfWeek($date) {
        $days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $dayNum = (int)date('N', strtotime($date)) - 1;
        return $days[$dayNum] ?? '';
    }
    
    // Функция для форматирования даты
    function formatDate($date) {
        return date('d.m.Y', strtotime($date));
    }
    
    // Вычисляем общие суммы
    $totalPlan = 0;
    $totalFact = 0;
    foreach ($planByDate as $date => $items) {
        foreach ($items as $item) {
            $totalPlan += $item['count'];
        }
    }
    $totalFact = array_sum($factData);
    
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Анализ работы гофропакетчика</title>
        <style>
            :root {
                --primary-color: #2563eb;
                --primary-dark: #1d4ed8;
                --gray-50: #f9fafb;
                --gray-100: #f3f4f6;
                --gray-200: #e5e7eb;
                --gray-300: #d1d5db;
                --gray-500: #6b7280;
                --gray-700: #374151;
                --gray-800: #1f2937;
                --success-color: #10b981;
                --warning-color: #f59e0b;
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f5f5;
                padding: 20px;
                color: var(--gray-800);
            }
            
            .container {
                max-width: 1600px;
                margin: 0 auto;
                background: white;
                padding: 24px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .header-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 24px;
                flex-wrap: wrap;
                gap: 16px;
            }
            
            h1 {
                color: var(--gray-800);
                margin: 0;
                font-size: 1.25rem;
            }
            
            .nav {
                display: flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
            }
            
            .nav a {
                background: var(--primary-color);
                color: white;
                padding: 6px 12px;
                border-radius: 4px;
                text-decoration: none;
                font-weight: 500;
                font-size: 13px;
                transition: background 0.2s;
            }
            
            .nav a:hover {
                background: var(--primary-dark);
            }
            
            .section-with-title {
                display: flex;
                gap: 16px;
                margin-bottom: 32px;
            }
            
            .section-title-side {
                writing-mode: vertical-rl;
                text-orientation: mixed;
                font-size: 1.25rem;
                font-weight: 600;
                color: var(--gray-800);
                padding: 8px 4px;
                white-space: nowrap;
                align-self: flex-start;
            }
            
            .week-grid {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                gap: 12px;
                flex: 1;
            }
            
            .day-column {
                display: flex;
                flex-direction: column;
                border: 1px solid var(--gray-200);
                border-radius: 6px;
                overflow: hidden;
            }
            
            .day-header {
                background: var(--primary-color);
                color: white;
                padding: 4px 6px;
                text-align: center;
                font-weight: 600;
            }
            
            .day-header.today {
                background: var(--warning-color);
            }
            
            .day-number {
                font-size: 10px;
                line-height: 1.2;
            }
            
            .day-name {
                font-size: 9px;
                opacity: 0.85;
                margin-left: 4px;
            }
            
            .day-content {
                padding: 4px;
                flex: 1;
                min-height: 150px;
            }
            
            .day-summary {
                margin-bottom: 3px;
                padding-bottom: 3px;
                border-bottom: 1px solid var(--gray-200);
            }
            
            .day-summary-item {
                font-size: 11px;
                margin-bottom: 0;
            }
            
            .day-summary-label {
                color: var(--gray-500);
            }
            
            .day-summary-value {
                font-weight: 600;
            }
            
            .day-summary-value.plan {
                color: var(--primary-color);
            }
            
            .day-summary-value.fact {
                color: var(--success-color);
            }
            
            .positions-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            
            .position-item {
                padding: 4px;
                margin-bottom: 2px;
                background: white;
                border-radius: 2px;
                border: 1px solid var(--gray-300);
                position: relative;
            }
            
            .position-row {
                display: flex;
                align-items: center;
                gap: 6px;
                padding-right: 50px;
            }
            
            .position-filter {
                font-size: 12px;
                font-weight: 600;
                color: var(--gray-800);
                line-height: 1.3;
            }
            
            .position-order {
                font-size: 11px;
                font-weight: 400;
                color: var(--gray-700);
                line-height: 1.3;
            }
            
            .position-count {
                font-size: 11px;
                color: var(--primary-color);
                font-weight: 600;
                position: absolute;
                top: 6px;
                right: 6px;
            }
            
            .fact-position .position-count.editable-count {
                cursor: pointer;
                padding: 2px 4px;
                border-radius: 3px;
                transition: all 0.2s;
            }
            
            .fact-position .position-count.editable-count:hover {
                background-color: var(--gray-100);
                outline: 1px solid var(--primary-color);
            }
            
            .fact-position .position-count.editable-count:focus {
                background-color: white;
                outline: 2px solid var(--primary-color);
                cursor: text;
            }
            
            .no-positions {
                text-align: center;
                color: var(--gray-500);
                font-size: 13px;
                padding: 20px;
            }
            
            /* Modal styles */
            .modal {
                display: none;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                backdrop-filter: blur(5px);
            }
            
            .modal-content {
                background-color: white;
                margin: 5% auto;
                padding: 24px;
                border-radius: 8px;
                width: 90%;
                max-width: 800px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                position: relative;
                border: 1px solid var(--gray-200);
            }
            
            .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--gray-200);
            }
            
            .modal-title {
                font-size: 1.25rem;
                font-weight: 600;
                color: var(--gray-800);
                margin: 0;
            }
            
            .close {
                color: var(--gray-400);
                font-size: 24px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.2s;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
            }
            
            .close:hover {
                color: var(--gray-800);
                background-color: var(--gray-100);
            }
            
            .search-form {
                margin-bottom: 20px;
            }
            
            .search-input {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid var(--gray-300);
                border-radius: 4px;
                font-size: 14px;
                transition: all 0.2s;
            }
            
            .search-input:focus {
                outline: none;
                border-color: var(--primary-color);
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            }
            
            .search-results {
                max-height: 400px;
                overflow-y: auto;
                border: 1px solid var(--gray-200);
                border-radius: 4px;
            }
            
            .search-result-item {
                padding: 12px;
                border-bottom: 1px solid var(--gray-100);
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .search-result-item:hover {
                background-color: var(--gray-50);
            }
            
            .search-result-item:last-child {
                border-bottom: none;
            }
            
            .result-date {
                font-weight: 600;
                color: var(--primary-color);
                font-size: 14px;
            }
            
            .result-details {
                margin-top: 4px;
                color: var(--gray-600);
                font-size: 13px;
            }
            
            .result-order-number {
                background: var(--primary-color);
                color: white;
                padding: 4px 8px;
                border-radius: 4px;
                font-weight: 700;
                font-size: 14px;
                display: inline-block;
                margin-right: 8px;
            }
            
            .result-plan {
                color: var(--primary-color);
                font-weight: 500;
            }
            
            .result-fact {
                color: var(--success-color);
                font-weight: 500;
            }
            
            .no-results {
                text-align: center;
                padding: 30px 20px;
                color: var(--gray-500);
                font-size: 14px;
            }
            
            @media (max-width: 1200px) {
                .week-grid {
                    grid-template-columns: repeat(4, 1fr);
                }
            }
            
            @media (max-width: 768px) {
                body {
                    padding: 10px;
                }
                
                .container {
                    padding: 16px;
                }
                
                .week-grid {
                    grid-template-columns: 1fr;
                }
                
                .day-content {
                    min-height: auto;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header-row">
                <h1>Анализ работы гофропакетчика</h1>
                
                <!-- Навигация -->
                <div class="nav">
                    <a href="?week=<?= $prevWeek->format('Y-m-d') ?>">⬅️ Предыдущая неделя</a>
                    <a href="?week=<?= $today ?>">Сегодня</a>
                    <a href="?week=<?= $nextWeek->format('Y-m-d') ?>">Следующая неделя ➡️</a>
                    <button onclick="openFilterSearch()" style="background: var(--success-color); padding: 6px 12px; border: none; border-radius: 4px; color: white; cursor: pointer; font-size: 13px; font-weight: 500;">
                        Найти позицию
                    </button>
                </div>
            </div>
            
            <!-- План -->
            <div class="section-with-title">
                <div class="section-title-side">План</div>
                <div class="week-grid">
                <?php
                $currentDay = clone $startDate;
                for ($i = 0; $i < 7; $i++) {
                    $dateStr = $currentDay->format('Y-m-d');
                    $isToday = $dateStr === $today;
                    $dayClass = $isToday ? 'today' : '';
                    
                    $positions = $planByDate[$dateStr] ?? [];
                    $fact = $factData[$dateStr] ?? 0;
                    
                    // Считаем план за день
                    $dayPlan = 0;
                    foreach ($positions as $pos) {
                        $dayPlan += $pos['count'];
                    }
                    
                    echo '<div class="day-column">';
                    echo '<div class="day-header ' . $dayClass . '">';
                    echo '<div class="day-number">' . formatDate($dateStr) . ' <span class="day-name">' . getDayOfWeek($dateStr) . '</span></div>';
                    echo '</div>';
                    
                    echo '<div class="day-content">';
                    echo '<div class="day-summary">';
                    echo '<div class="day-summary-item">';
                    echo '<span class="day-summary-label">План: </span>';
                    echo '<span class="day-summary-value plan">' . number_format($dayPlan, 0, ',', ' ') . ' шт</span>';
                    echo '</div>';
                    echo '</div>';
                    
                    if (!empty($positions)) {
                        echo '<ul class="positions-list">';
                        foreach ($positions as $pos) {
                            echo '<li class="position-item">';
                            echo '<div class="position-count">' . number_format($pos['count'], 0, ',', ' ') . ' шт</div>';
                            echo '<div class="position-row">';
                            echo '<div class="position-filter">' . htmlspecialchars($pos['filter_label']) . '</div>';
                            echo '<div class="position-order">' . htmlspecialchars($pos['order_number']) . '</div>';
                            echo '</div>';
                            echo '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<div class="no-positions">Нет запланированных позиций</div>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                    
                    $currentDay->modify('+1 day');
                }
                ?>
                </div>
            </div>
            
            <!-- Факт -->
            <div class="section-with-title">
                <div class="section-title-side">Факт</div>
                <div class="week-grid">
                <?php
                $currentDayFact = clone $startDate;
                for ($i = 0; $i < 7; $i++) {
                    $dateStr = $currentDayFact->format('Y-m-d');
                    $isToday = $dateStr === $today;
                    $dayClass = $isToday ? 'today' : '';
                    
                    $factPositions = $factByDate[$dateStr] ?? [];
                    $factTotal = $factData[$dateStr] ?? 0;
                    
                    echo '<div class="day-column">';
                    echo '<div class="day-header ' . $dayClass . '" style="background: var(--success-color);">';
                    echo '<div class="day-number">' . formatDate($dateStr) . ' <span class="day-name">' . getDayOfWeek($dateStr) . '</span></div>';
                    echo '</div>';
                    
                    echo '<div class="day-content">';
                    echo '<div class="day-summary">';
                    echo '<div class="day-summary-item">';
                    echo '<span class="day-summary-label">Факт: </span>';
                    echo '<span class="day-summary-value fact">' . number_format($factTotal, 0, ',', ' ') . ' шт</span>';
                    echo '</div>';
                    echo '</div>';
                    
                    if (!empty($factPositions)) {
                        echo '<ul class="positions-list">';
                        foreach ($factPositions as $pos) {
                            echo '<li class="position-item fact-position" ';
                            echo 'data-id="' . htmlspecialchars($pos['id']) . '" ';
                            echo 'data-date="' . htmlspecialchars($pos['date_of_production']) . '" ';
                            echo 'data-order="' . htmlspecialchars($pos['order_number']) . '" ';
                            echo 'data-filter="' . htmlspecialchars($pos['filter_label']) . '" ';
                            echo 'data-count="' . (int)$pos['count'] . '">';
                            echo '<div class="position-count editable-count" contenteditable="true">' . number_format($pos['count'], 0, ',', ' ') . ' шт</div>';
                            echo '<div class="position-row">';
                            echo '<div class="position-filter">' . htmlspecialchars($pos['filter_label']) . '</div>';
                            echo '<div class="position-order">' . htmlspecialchars($pos['order_number']) . '</div>';
                            echo '</div>';
                            echo '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<div class="no-positions">Нет выпущенных позиций</div>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                    
                    $currentDayFact->modify('+1 day');
                }
                ?>
                </div>
            </div>
        </div>
        
        <!-- Modal для поиска позиций по фильтру -->
        <div id="filterSearchModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">Поиск позиций по фильтру</h2>
                    <span class="close" onclick="closeFilterSearch()">&times;</span>
                </div>
                <div class="search-form">
                    <input 
                        type="text" 
                        id="filterSearchInput" 
                        class="search-input" 
                        placeholder="Введите название фильтра для поиска..."
                        onkeyup="searchFilters()"
                    >
                </div>
                <div id="searchResults" class="search-results">
                    <div class="no-results">
                        Введите название фильтра для поиска
                    </div>
                </div>
            </div>
        </div>
        
        <script>
            // Функции для модального окна поиска
            function openFilterSearch() {
                document.getElementById('filterSearchModal').style.display = 'block';
                document.getElementById('filterSearchInput').focus();
            }
            
            function closeFilterSearch() {
                document.getElementById('filterSearchModal').style.display = 'none';
                document.getElementById('filterSearchInput').value = '';
                document.getElementById('searchResults').innerHTML = '<div class="no-results">Введите название фильтра для поиска</div>';
            }
            
            // Закрытие модального окна при клике вне его
            window.onclick = function(event) {
                const modal = document.getElementById('filterSearchModal');
                if (event.target === modal) {
                    closeFilterSearch();
                }
            }
            
            // Поиск фильтров
            async function searchFilters() {
                const searchTerm = document.getElementById('filterSearchInput').value.trim();
                const resultsDiv = document.getElementById('searchResults');
                
                if (searchTerm.length < 2) {
                    resultsDiv.innerHTML = '<div class="no-results">Введите минимум 2 символа для поиска</div>';
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('filter_name', searchTerm);
                    
                    const response = await fetch('worker_modules/search_filter_positions.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.results.length > 0) {
                        displaySearchResults(data.results);
                    } else {
                        resultsDiv.innerHTML = '<div class="no-results">Позиции с таким фильтром не найдены</div>';
                    }
                } catch (error) {
                    console.error('Ошибка поиска:', error);
                    resultsDiv.innerHTML = '<div class="no-results">Ошибка при поиске. Попробуйте еще раз.</div>';
                }
            }
            
            // Отображение результатов поиска
            function displaySearchResults(results) {
                const resultsDiv = document.getElementById('searchResults');
                let html = '';
                
                results.forEach(result => {
                    const planSum = result.plan_sum || 0;
                    const factSum = result.fact_sum || 0;
                    const ratio = planSum > 0 ? (factSum / planSum) : 0;
                    const ratioPercent = (ratio * 100).toFixed(1);
                    
                    // Затенение для выполнения 90%+
                    let bgStyle = '';
                    if (ratio >= 0.9) {
                        const H = 120;
                        const L = 85;
                        const S_min = 20, S_max = 60;
                        let S;
                        if (ratio >= 1) {
                            S = S_max;
                        } else {
                            const t = (ratio - 0.9) / 0.1;
                            S = S_min + (S_max - S_min) * t;
                        }
                        bgStyle = `background: hsl(${H}, ${S}%, ${L}%);`;
                    }
                    
                    html += `
                        <div class="search-result-item" onclick="goToDate('${result.plan_date}')" style="${bgStyle}">
                            <div class="result-date">${result.plan_date}</div>
                            <div class="result-details">
                                <span class="result-order-number">${result.order_number}</span>${result.filter_label}<br>
                                План: <span class="result-plan">${planSum} шт</span> | 
                                Факт: <span class="result-fact">${factSum} шт</span> | 
                                Выполнено: ${ratioPercent}%
                            </div>
                        </div>
                    `;
                });
                
                resultsDiv.innerHTML = html;
            }
            
            // Переход к найденной дате
            function goToDate(date) {
                closeFilterSearch();
                // Определяем начало недели для этой даты
                const d = new Date(date + 'T00:00:00');
                const dayOfWeek = d.getDay(); // 0 = воскресенье, 1 = понедельник
                const mondayOffset = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
                d.setDate(d.getDate() + mondayOffset);
                const weekStart = d.toISOString().split('T')[0];
                window.location.href = '?week=' + weekStart;
            }
            
            // Редактирование количества в факте
            document.addEventListener('DOMContentLoaded', function() {
                const editableCounts = document.querySelectorAll('.fact-position .editable-count');
                
                editableCounts.forEach(element => {
                    let originalValue = element.textContent.trim();
                    
                    element.addEventListener('blur', async function() {
                        const newText = this.textContent.trim();
                        const newValue = parseInt(newText.replace(/\s/g, '').replace('шт', ''));
                        
                        if (isNaN(newValue) || newValue < 0) {
                            this.textContent = originalValue;
                            alert('Введите корректное число');
                            return;
                        }
                        
                        if (newValue === parseInt(originalValue.replace(/\s/g, '').replace('шт', ''))) {
                            return; // Не изменилось
                        }
                        
                        const positionItem = this.closest('.fact-position');
                        const id = positionItem.dataset.id;
                        const date = positionItem.dataset.date;
                        const order = positionItem.dataset.order;
                        const filter = positionItem.dataset.filter;
                        
                        try {
                            const response = await fetch('worker_modules/update_manufactured_count.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: new URLSearchParams({
                                    id: id,
                                    date_of_production: date,
                                    order_number: order,
                                    filter_label: filter,
                                    count: newValue
                                })
                            });
                            
                            const data = await response.json();
                            
                            if (data.success) {
                                originalValue = newValue.toLocaleString('ru-RU') + ' шт';
                                this.textContent = originalValue;
                                positionItem.dataset.count = newValue;
                                
                                // Обновляем общий факт за день
                                updateDayFactTotal(positionItem);
                                
                                // Перезагружаем страницу для обновления всех данных
                                setTimeout(() => window.location.reload(), 500);
                            } else {
                                this.textContent = originalValue;
                                alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
                            }
                        } catch (error) {
                            console.error('Ошибка:', error);
                            this.textContent = originalValue;
                            alert('Ошибка при сохранении данных');
                        }
                    });
                    
                    element.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            this.blur();
                        }
                        if (e.key === 'Escape') {
                            this.textContent = originalValue;
                            this.blur();
                        }
                    });
                });
            });
            
            // Обновление общего факта за день
            function updateDayFactTotal(positionItem) {
                const dayContent = positionItem.closest('.day-content');
                const daySummary = dayContent.querySelector('.day-summary-value.fact');
                if (!daySummary) return;
                
                const positions = dayContent.querySelectorAll('.fact-position');
                let total = 0;
                positions.forEach(pos => {
                    total += parseInt(pos.dataset.count || 0);
                });
                
                daySummary.textContent = total.toLocaleString('ru-RU') + ' шт';
            }
        </script>
    </body>
    </html>
    <?php
    
} catch (PDOException $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}
