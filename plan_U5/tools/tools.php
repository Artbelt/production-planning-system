<?php /** tools.php в файле прописаны разные функции */

/** ПОдключаем функции */
require_once('C:/xampp/htdocs/plan_U5/settings.php') ;


/** Вывод массива в удобном виде
 * @param $a
 */
function print_r_my ($a){
    if (gettype($a)=='array') {
        echo "<pre>";
        print_r($a);
        echo "</pre>";
    }
}

/**  */
function show_ads(){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    // ОПТИМИЗАЦИЯ: кэшируем объявления на 60 секунд
    static $cached_ads = null;
    static $cache_time = null;
    
    if ($cached_ads !== null && $cache_time !== null && (time() - $cache_time) < 60) {
        $ads = $cached_ads;
    } else {
        $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
        if ($mysqli->connect_errno) {
            $ads = [];
        } else {
            $stmt = $mysqli->prepare("SELECT title, content, expires_at FROM ads WHERE expires_at >= NOW() ORDER BY expires_at ASC");
            if ($stmt && $stmt->execute()) {
                $result = $stmt->get_result();
                $ads = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                $ads = [];
            }
            $mysqli->close();
        }
        $cached_ads = $ads;
        $cache_time = time();
    }
    ?>
    <style>
        .ads-container {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        .ads-container h1 {
            font-size: 24px;
            color: #0056b3;
            border-bottom: 2px solid #0056b3;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        .ads-container ul {
            list-style: none;
            padding: 0;
        }
        .ads-container li {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 10px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .ads-container h2 {
            font-size: 18px;
            margin: 0 0 10px;
            color: #333;
        }
        .ads-container p {
            font-size: 14px;
            margin: 0 0 10px;
        }
        .ads-container small {
            font-size: 12px;
            color: #666;
        }
        .ads-container .no-ads {
            font-size: 16px;
            color: #888;
            text-align: center;
        }
    </style>
    <div class="ads-container">
       Объявления:
        <?php if (!empty($ads)): ?>
            <ul>
                <?php foreach ($ads as $ad): ?>
                    <li>
                        <h2><?= htmlspecialchars($ad['title']) ?></h2>
                        <p><?= htmlspecialchars($ad['content']) ?></p>
                        <small>Действительно до: <?= htmlspecialchars($ad['expires_at']) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="no-ads">Нет актуальных объявлений.</p>
        <?php endif; ?>
    </div>
    <?php

}

/** Отображение выпуска продукции за последнюю неделю */
function show_weekly_production(){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    
    $count = 0;
    
    // Начинаем плашку (карточку)
    echo '<div class="production-card">';
    echo '<div class="production-card-header">';
    echo '<h3 class="production-card-title">Изготовленная продукция за последние 10 дней</h3>';
    echo '</div>';
    echo '<div class="production-card-body">';
    
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        echo "Ошибка подключения к БД";
        return;
    }
    
    $SHIFT_HOURS = 11.5; // длительность смены в часах
    
    // Собираем данные за все дни
    $all_days_data = [];
    
    for ($a = 1; $a < 11; $a++) {
        $production_date = date("Y-m-d", time() - (60 * 60 * 24 * $a));
        $production_date = reverse_date($production_date);
        
        // Получаем данные с build_complexity для расчета процентов
        $sql = "SELECT 
                    mp.team,
                    mp.count_of_filters,
                    COALESCE(sfs.build_complexity, 0) AS build_complexity
                FROM manufactured_production mp
                LEFT JOIN salon_filter_structure sfs ON sfs.filter = mp.name_of_filter
                WHERE mp.date_of_production = '$production_date'
                ORDER BY mp.team";
        
        $result = $mysqli->query($sql);
        
        if (!$result) {
            continue;
        }
        
        // Группируем по бригадам, затем объединяем по машинам
        $teams_data = [];
        $total_count = 0;
        
        while ($row = $result->fetch_assoc()) {
            $team = (int)$row['team'];
            $count_filters = (int)$row['count_of_filters'];
            $build_complexity = (float)$row['build_complexity'];
            
            if (!isset($teams_data[$team])) {
                $teams_data[$team] = [
                    'count' => 0,
                    'norms_sum' => 0.0 // сумма норм (count / build_complexity)
                ];
            }
            
            $teams_data[$team]['count'] += $count_filters;
            $total_count += $count_filters;
            
            // Рассчитываем нормы для процента выполнения
            if ($build_complexity > 0) {
                $teams_data[$team]['norms_sum'] += $count_filters / $build_complexity;
            }
        }
        
        // Объединяем данные по машинам:
        // Машина 1: бригады 1 и 2
        // Машина 2: бригады 3 и 4
        $machines_data = [];
        for ($machine = 1; $machine <= 2; $machine++) {
            $machines_data[$machine] = [
                'count' => 0,
                'norms_sum' => 0.0
            ];
            
            // Определяем какие бригады относятся к этой машине
            $team_start = ($machine == 1) ? 1 : 3;
            $team_end = ($machine == 1) ? 2 : 4;
            
            for ($team = $team_start; $team <= $team_end; $team++) {
                if (isset($teams_data[$team])) {
                    $machines_data[$machine]['count'] += $teams_data[$team]['count'];
                    $machines_data[$machine]['norms_sum'] += $teams_data[$team]['norms_sum'];
                }
            }
        }
        
        // Сохраняем данные для этого дня
        $all_days_data[] = [
            'date' => $production_date,
            'total_count' => $total_count,
            'machines' => $machines_data
        ];
        
        $count = $count + $total_count;
        
        if ($result) {
            $result->free();
        }
    }
    
    $mysqli->close();
    
    // Выводим таблицу
    echo '<style>
        .production-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(2, 8, 20, 0.06);
            margin: 12px 0;
            overflow: hidden;
        }
        .production-card-header {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            background-color: #ffffff;
        }
        .production-card-title {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }
        .production-card-body {
            padding: 10px 12px;
            background-color: #ffffff;
        }
        .production-card-footer {
            padding: 10px 12px;
            background-color: #ffffff;
            border-top: 1px solid #e5e7eb;
        }
        .production-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            max-width: 1000px;
            margin: 8px 0;
            font-size: 12px;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .production-table th {
            background-color: #f8f9fa;
            padding: 6px 10px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            font-weight: normal;
            color: #495057;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .production-table th:first-child {
            border-top-left-radius: 8px;
        }
        .production-table th:last-child {
            border-top-right-radius: 8px;
        }
        .production-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #f1f3f5;
            color: #6c757d;
        }
        .production-table tbody tr {
            background-color: #ffffff;
            transition: background-color 0.2s ease;
        }
        .production-table tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }
        .production-table tbody tr:hover {
            background-color: #f0f4f8;
        }
        .production-table tbody tr:last-child td {
            border-bottom: none;
        }
        .production-table .date-col {
            font-weight: normal;
            color: #495057;
        }
        .production-table .total-col {
            font-weight: normal;
            text-align: right;
            color: #495057;
        }
        .production-table .team-col {
            text-align: right;
            color: #6c757d;
        }
        .production-table .team-col .percentage-link {
            color: #4a90e2;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .production-table .team-col .percentage-link:hover {
            color: #357abd;
            text-decoration: underline;
        }
        .production-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
        }
        .production-stat-label {
            color: #6c757d;
            font-weight: 500;
        }
        .production-stat-value {
            font-weight: 600;
            font-size: 13px;
        }
    </style>';
    
    echo '<table class="production-table">';
    echo '<thead><tr>';
    echo '<th class="date-col">Дата</th>';
    echo '<th class="total-col">Всего</th>';
    
    // Заголовки для машин
    for ($m = 1; $m <= 2; $m++) {
        echo '<th class="team-col">Машина ' . $m . '</th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';
    
    // Выводим данные по дням
    foreach ($all_days_data as $day_data) {
        echo '<tr>';
        echo '<td class="date-col">' . htmlspecialchars($day_data['date']) . '</td>';
        echo '<td class="total-col">' . $day_data['total_count'] . '</td>';
        
        // Выводим данные по машинам
        for ($m = 1; $m <= 2; $m++) {
            echo '<td class="team-col">';
            if (isset($day_data['machines'][$m]) && $day_data['machines'][$m]['count'] > 0) {
                $machine_data = $day_data['machines'][$m];
                $machine_count = $machine_data['count'];
                $norms_sum = $machine_data['norms_sum'];
                $percentage = $norms_sum > 0 ? round($norms_sum * 100, 0) : 0;
                
                // Определяем какие бригады относятся к этой машине для клика
                $team_start = ($m == 1) ? 1 : 3;
                $team_end = ($m == 1) ? 2 : 4;
                $team_ids = range($team_start, $team_end);
                $team_ids_str = implode(',', $team_ids);
                
                echo $machine_count . ' <span class="percentage-link" data-date="' . htmlspecialchars($day_data['date']) . '" data-teams="' . htmlspecialchars($team_ids_str) . '" style="cursor: pointer; color: #0066cc; text-decoration: underline;">' . $percentage . '%</span>';
            } else {
                echo '-';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>'; // закрываем production-card-body
    
    // Футер карточки со статистикой
    echo '<div class="production-card-footer">';
    $count_per_day = $count / 10;
    if ($count_per_day > 1000){
        echo "<div class='production-stat'><span class='production-stat-label'>Среднее количество в смену:</span> <span class='production-stat-value highlight_green' title='Это количество обеспечит 30 000 фильтров в месяц'>".round($count_per_day, 0)."</span></div>";
    } else {
        echo "<div class='production-stat'><span class='production-stat-label'>Среднее количество в смену:</span> <span class='production-stat-value highlight_red' title='Это количество НЕ обеспечит 30 000 фильтров в месяц'>".round($count_per_day, 0)."</span></div>";
    }
    echo '</div>'; // закрываем production-card-footer
    echo '</div>'; // закрываем production-card
    
    // Модальное окно для детального расчета процентов
    ?>
    <div id="percentageDetailModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center;">
        <div style="background-color: white; padding: 20px; border-radius: 8px; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1); position: relative;">
            <span id="closePercentageModal" style="position: absolute; right: 15px; top: 15px; font-size: 28px; font-weight: bold; color: #999; cursor: pointer; line-height: 1;">&times;</span>
            <h3 id="percentageModalTitle" style="margin-top: 0; margin-bottom: 15px;">Детальный расчет процентов</h3>
            <div id="percentageModalContent" style="min-height: 200px;">
                <div style="text-align: center; padding: 40px;">Загрузка данных...</div>
            </div>
        </div>
    </div>
    
    <style>
        .percentage-link:hover {
            color: #004499 !important;
        }
        #percentageDetailModal table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        #percentageDetailModal th {
            background-color: #f3f4f6;
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
            font-weight: bold;
        }
        #percentageDetailModal td {
            padding: 8px;
            border: 1px solid #ddd;
        }
        #percentageDetailModal tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .percentage-summary {
            background-color: #eff6ff;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
    </style>
    
    <script>
    (function() {
        // Проверяем, не инициализированы ли уже обработчики
        if (window.percentageModalInitialized) {
            return;
        }
        window.percentageModalInitialized = true;
        
        // Обработчик клика на проценты (делегирование событий)
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('percentage-link')) {
                e.preventDefault();
                const date = e.target.getAttribute('data-date');
                const teams = e.target.getAttribute('data-teams') || e.target.getAttribute('data-team');
                showPercentageDetails(date, teams);
            }
        });
        
        // Закрытие модального окна
        const closeBtn = document.getElementById('closePercentageModal');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                document.getElementById('percentageDetailModal').style.display = 'none';
            });
        }
        
        // Закрытие при клике вне модального окна
        const modal = document.getElementById('percentageDetailModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target.id === 'percentageDetailModal') {
                    this.style.display = 'none';
                }
            });
        }
        
        // Закрытие по клавише Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
                modal.style.display = 'none';
            }
        });
        
        // Функция показа детального расчета
        function showPercentageDetails(date, teams) {
            const modal = document.getElementById('percentageDetailModal');
            const title = document.getElementById('percentageModalTitle');
            const content = document.getElementById('percentageModalContent');
            
            // Определяем название (машина или бригада)
            const teamArray = teams.split(',');
            let titleText = '';
            if (teamArray.length > 1) {
                const machineNum = teamArray[0] <= 2 ? 1 : 2;
                titleText = 'Детальный расчет процентов - Машина ' + machineNum + ' (' + date + ')';
            } else {
                titleText = 'Детальный расчет процентов - Бригада ' + teams + ' (' + date + ')';
            }
            
            title.textContent = titleText;
            content.innerHTML = '<div style="text-align: center; padding: 40px;">Загрузка данных...</div>';
            modal.style.display = 'flex';
            
            // Загружаем данные через AJAX
            fetch('get_team_percentage_details.php?date=' + encodeURIComponent(date) + '&teams=' + encodeURIComponent(teams))
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        content.innerHTML = '<div style="color: red; text-align: center; padding: 20px;">Ошибка: ' + escapeHtml(data.error) + '</div>';
                        return;
                    }
                    
                    if (!data.items || data.items.length === 0) {
                        content.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">Нет данных для отображения</div>';
                        return;
                    }
                    
                    let html = '<div class="percentage-summary">';
                    html += '<strong>Итого:</strong> <strong>' + data.total_count + ' шт</strong> | ';
                    html += '<strong>Сумма норм:</strong> <strong>' + (data.norms_sum ? data.norms_sum.toFixed(3) : '0.000') + '</strong> | ';
                    html += '<strong>Процент выполнения:</strong> <strong>' + data.percentage + '%</strong>';
                    html += '</div>';
                    
                    html += '<table>';
                    html += '<thead><tr><th>Фильтр</th><th>Заявка</th><th>Изготовлено</th><th>Норма (шт/смену)</th><th>Норм</th><th>% выполнения</th></tr></thead>';
                    html += '<tbody>';
                    
                    data.items.forEach(function(item) {
                        html += '<tr>';
                        html += '<td>' + escapeHtml(item.filter_name || '-') + '</td>';
                        html += '<td>' + escapeHtml(item.order_number || '-') + '</td>';
                        html += '<td>' + item.count + ' шт</td>';
                        html += '<td>' + (item.build_complexity > 0 ? item.build_complexity.toFixed(2) : '-') + '</td>';
                        html += '<td>' + (item.norms > 0 ? item.norms.toFixed(3) : '-') + '</td>';
                        html += '<td>' + (item.item_percentage > 0 ? item.item_percentage + '%' : '-') + '</td>';
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                    content.innerHTML = html;
                })
                .catch(error => {
                    content.innerHTML = '<div style="color: red; text-align: center; padding: 20px;">Ошибка загрузки данных: ' + escapeHtml(error.message) + '</div>';
                });
        }
        
        // Функция экранирования HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    })();
    </script>
    <?php

}
/** Выборка имен фильтров */
function get_all_filters() {
    // Замените на вашу реальную логику получения данных
    $pdo = get_connection();
    $stmt = $pdo->query("SELECT id, filter_name FROM salon_filters ORDER BY filter_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Отображение выпуска продукции за месяц */
function show_monthly_production(){
    $first_day = date("Y-m-01"); // первое число текущего месяца
    $today = date("Y-m-d");

    $first_day_reversed = reverse_date($first_day);
    $today_reversed = reverse_date($today);

    $sql = "SELECT SUM(count_of_filters) as total FROM manufactured_production 
            WHERE date_of_production >= '$first_day_reversed' AND date_of_production <= '$today_reversed';";
    $result = mysql_execute($sql);

    $total = 0;
    if ($result) {
        $row = $result->fetch_assoc();  // вот здесь — корректно работаем с mysqli_result
        if ($row && isset($row['total'])) {
            $total = $row['total'];
        }
    }

    echo "<p>В текущем месяце произведено $total фильтров (с 1 числа по сегодня).";
}

/** Отображение сгофрированных гофропакетов за последние 10 дней */
function show_weekly_corrugation(){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    
    $count = 0;
    
    // Начинаем плашку (карточку)
    echo '<div class="production-card">';
    echo '<div class="production-card-header">';
    echo '<h3 class="production-card-title">Сгофрированные гофропакеты за последние 10 дней</h3>';
    echo '</div>';
    echo '<div class="production-card-body">';
    
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        echo "Ошибка подключения к БД";
        return;
    }
    
    // Собираем данные за все дни
    $all_days_data = [];
    
    for ($a = 1; $a < 11; $a++) {
        $production_date = date("Y-m-d", time() - (60 * 60 * 24 * $a));
        $production_date = reverse_date($production_date);
        
        // Получаем данные о сгофрированных гофропакетах из corrugation_plan
        $sql = "SELECT 
                    SUM(COALESCE(fact_count, 0)) as total_count
                FROM corrugation_plan
                WHERE plan_date = '$production_date'
                AND COALESCE(fact_count, 0) > 0";
        
        $result = $mysqli->query($sql);
        
        if (!$result) {
            continue;
        }
        
        $total_count = 0;
        if ($row = $result->fetch_assoc()) {
            $total_count = (int)($row['total_count'] ?? 0);
        }
        
        // Сохраняем данные для этого дня
        $all_days_data[] = [
            'date' => $production_date,
            'total_count' => $total_count
        ];
        
        $count = $count + $total_count;
        
        if ($result) {
            $result->free();
        }
    }
    
    $mysqli->close();
    
    // Выводим таблицу
    echo '<table class="production-table">';
    echo '<thead><tr>';
    echo '<th class="date-col">Дата</th>';
    echo '<th class="total-col">Всего гофропакетов</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    // Выводим данные по дням
    foreach ($all_days_data as $day_data) {
        echo '<tr>';
        echo '<td class="date-col">' . htmlspecialchars($day_data['date']) . '</td>';
        echo '<td class="total-col">' . $day_data['total_count'] . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>'; // закрываем production-card-body
    
    // Футер карточки со статистикой
    echo '<div class="production-card-footer">';
    $count_per_day = $count / 10;
    echo "<div class='production-stat'><span class='production-stat-label'>Среднее количество в день:</span> <span class='production-stat-value'>".round($count_per_day, 0)."</span></div>";
    echo '</div>'; // закрываем production-card-footer
    echo '</div>'; // закрываем production-card
}


/** Создание <SELECT> списка с перечнем заявок */
//function load_orders($list){
//
//    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
//
//    /** Создаем подключение к БД */
//    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
//
//    /** Выполняем запрос SQL для загрузки заявок*/
//    $sql = "SELECT DISTINCT order_number, workshop FROM orders;";
//
//    /** Если запрос не удачный -> exit */
//    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
//        exit;
//    }
//
//    if ($list == '0') {
//
//        /** Разбор массива значений для выпадающего списка */
//        echo "<select id='selected_order'>";
//        while ($orders_data = $result->fetch_assoc()) {
//            echo "<option name='order_number' value=" . $orders_data['order_number'] . ">" . $orders_data['order_number'] . "</option>";
//        }
//        echo "</select>";
//    } else {
//        echo 'Перечень заявок';
//        /** Разбор массива значений для списка чекбоксов */
//        echo "<form action='orders_editor.php' method='post'>";
//        while ($orders_data = $result->fetch_assoc()) {
//            echo "<input type='checkbox' name='order_name[]'value=".$orders_data['order_number']." <label>".$orders_data['order_number'] ."</label><br>";
//        }
//        echo "<button type='submit'>Объединить для расчета</button>";
//        echo "</form>";
//
//    }
//    /** Закрываем соединение */
//    $result->close();
//    $mysqli->close();
//}

// СМОТРИ СТАРУЮ ВЕРСИЯ ФУНКЦИИ ВІШЕ!!!!!
function load_orders($list){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
    $sql = "SELECT DISTINCT order_number, workshop FROM orders;";
    if (!$result = $mysqli->query($sql)){
        echo "Ошибка: {$mysqli->error}";
        exit;
    }

    if ($list == 0) {
        echo "<select id='selected_order' name='order_number'>";
        while ($row = $result->fetch_assoc()) {
            $val = htmlspecialchars($row['order_number'], ENT_QUOTES, 'UTF-8');
            echo "<option value=\"$val\">$val</option>";
        }
        echo "</select>";
    } else {
        echo 'Перечень заявок';
        echo "<form action='orders_editor.php' method='post'>";
        while ($row = $result->fetch_assoc()) {
            $val = htmlspecialchars($row['order_number'], ENT_QUOTES, 'UTF-8');
            echo "<label><input type='checkbox' name='order_name[]' value=\"$val\"> $val</label><br>";
        }
        echo "<button type='submit'>Объединить для расчета</button>";
        echo "</form>";
    }

    $result->close();
    $mysqli->close();
}



/** Создание <SELECT> списка с перечнем распланированных заявок */
function load_planned_orders(){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    // ОПТИМИЗАЦИЯ: кэшируем список распланированных заявок на 2 минуты
    static $cached_orders = null;
    static $cache_time = null;
    
    if ($cached_orders !== null && $cache_time !== null && (time() - $cache_time) < 120) {
        $orders = $cached_orders;
    } else {
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
        
        // Оптимизированный запрос: используем подзапрос вместо JOIN для скорости
        $sql = "SELECT DISTINCT order_number 
                FROM build_plan 
                WHERE order_number IS NOT NULL 
                ORDER BY order_number DESC 
                LIMIT 500";
        
        if (!$result = $mysqli->query($sql)){
            echo "Ошибка: {$mysqli->error}";
            exit;
        }

        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row['order_number'];
        }
        
        $result->close();
        $mysqli->close();
        
        // Сохраняем в кэш
        $cached_orders = $orders;
        $cache_time = time();
    }

    echo "<select id='selected_order' name='order_number'>";
    foreach ($orders as $order_num) {
        $val = htmlspecialchars($order_num, ENT_QUOTES, 'UTF-8');
        echo "<option value=\"$val\">$val</option>";
    }
    echo "</select>";
}

/** СОздание <SELECT> списка с перечнем фильтров имеющихся в БД */
function load_filters_into_select(){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    // ОПТИМИЗАЦИЯ: кэшируем список фильтров на 5 минут
    static $cached_filters = null;
    static $cache_time = null;
    
    if ($cached_filters !== null && $cache_time !== null && (time() - $cache_time) < 300) {
        $sorted_values = $cached_filters;
    } else {
        /** Создаем подключение к БД */
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

        /** Выполняем запрос SQL с LIMIT и фильтрацией пустых значений */
        $sql = "SELECT DISTINCT filter FROM salon_filter_structure WHERE filter IS NOT NULL AND filter != '' ORDER BY filter LIMIT 5000;";

        /** Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ 
            echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            exit;
        }

        /** Собираем фильтры в массив (уже отсортированные запросом) */
        $sorted_values = array();
        while ($orders_data = $result->fetch_assoc()){
            $sorted_values[] = $orders_data['filter'];
        }
        
        /** Закрываем соединение */
        $result->close();
        $mysqli->close();
        
        // Сохраняем в кэш
        $cached_filters = $sorted_values;
        $cache_time = time();
    }
    
    /** Выводим select с экранированием */
    echo "<select name='analog_filter'>";
    echo "<option value=''>выбор фильтра</option>";
    foreach ($sorted_values as $filter){
        echo "<option value='".htmlspecialchars($filter)."'>".htmlspecialchars($filter)."</option>";
    }
    echo "</select>";
}


/** Списание фильтров в выпущенную продукцию
 * @param $date_of_production
 * @param $order_number
 * @param $filters
 * @return bool
 */
function write_of_filters($date_of_production, $order_number, $filters, $team){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Цикл для разбора значений массива со значениями "фильтер - количество" */
    foreach ($filters as $filter_record) {

        /** Получили значение {елемент масива[фильтр][количество]} */
        $filter_name = $filter_record[0];
        $filter_count = $filter_record[1];

        /** Форматируем sql-запрос, "записать в БД -> дата -> заявка -> фильтер -> количство" */
        $sql = "INSERT INTO manufactured_production (date_of_production, name_of_filter, count_of_filters, name_of_order, team) 
                VALUES ('$date_of_production','$filter_name','$filter_count','$order_number', '$team')";

        /** Выполняем запрос. Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            /** в случае неудачи функция выводит FALSЕ */
            return false;
            exit;
        }
    }

    /** Закрываем соединение */
    return true;
}


/** Функция возвращает получает дату в формате dd-mm-yy а возвращает yy-mm-dd */
function reverse_date($date){

    $reverse_date=date('Y-m-d',strtotime($date));
    return $reverse_date;
}

/** Функция возвращает количество произведенных указанных фильтров по указанной заявке */
/** функция возвращает массив ARRAY['ПЕРЕЧЕНЬ_ДАТ_И_КОЛИЧЕСТВ','КОЛИЧЕСТВО_СУММАРНОЕ'] */
function select_produced_filters_by_order($filter_name, $order_name){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    $count = 0;
    /** Подключение к БД   */
    $mysqli = new mysqli($mysql_host,$mysql_user, $mysql_user_pass, $mysql_database);

    /** Если не получилось подключиться.  */
    if ($mysqli->connect_errno) {
        echo  "Номер ошибки: " . $mysqli->connect_errno . "\n" . "Ошибка: " . $mysqli->connect_error . "\n";
        return "ERROR#02";
    }
    /** Выполняем запрос SQL по подключению */
    $sql = "SELECT * FROM manufactured_production WHERE name_of_order = '$order_name' AND name_of_filter = '$filter_name';";

    /** Если запрос не удался */
    if (!$result = $mysqli->query($sql)) {
        echo "Номер ошибки: " . $mysqli->errno . "\n". "Ошибка: " . $mysqli->error . "\n";
        return "ERROR#01";
    }

    $dates = [];

    /** Разбираем результата запроса */
    while ($row = $result->fetch_assoc()){
        $count += $row['count_of_filters'];
        array_push($dates, $row['date_of_production'],$row['count_of_filters']);
    }

    /** Создаем массив для вывода результата*/
    $result_part_one = $dates;
    $result_part_two = $count;
    $result_array = [];
    array_push($result_array,$result_part_one);
    array_push($result_array,$result_part_two);

    return $result_array;}

/** Функция выполняет запрос к БД и создает выборку заявки по выбранному номеру */
function show_order($order_number){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    /** Подключаемся к БД для вывода заявки */
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        /** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'
            . "Номер ошибки: " . $mysqli->connect_errno . "\n"
            . "Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }
    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM orders WHERE order_number = '$order_number';";
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }
    return $result; // Выход из функции, дальше какая-то лажа


//************************************************** надо разобраться, тут какая-то лажа получилась*********************************//
    /** Формируем шапку таблицы для вывода заявки */
    echo "<table style='border: 1px solid black; border-collapse: collapse; font-size: 14px;'>
        <tr>
            <th style=' border: 1px solid black'> Фильтр
            </th>
            <th style=' border: 1px solid black'> Количество, шт           
            </th>                                                     
        </tr>";

    /**  массив для сохранения заявки в объект планирования */
    $order_array = array();

    /** Разбор массива значений по подключению */
    while ($row = $result->fetch_assoc()){

        echo "<tr>"
            ."<td style=' border: 1px solid black'>".$row['filter']."</td>"
            ."<td style=' border: 1px solid black'>".$row['count']."</td>"
            ."</tr>";

        /** наполняем массив для сохранения заявки */
        $temp_array = array();
        array_push($temp_array, $row['filter']);
        array_push($temp_array, $row['count']);
        array_push($order_array,$temp_array);
    }

    echo "</table>";
//************************************************** конец лажи где надо разобраться********************************************//


}

/** Функция складывает одинаковые элементы массива. ПРимер
 *      [[1][100]    =>  [[1][140]
 *       [2][ 50]         [2][100]
 *       [2][ 50]         [3][ 10]]
 *       [1][ 40]
 *       [3][ 10]]
 */
function summ_the_same_elements_of_array($input_array){

    function compare($a, $b){ // функция для сортировки массива в usort
        if ($a == $b){
            return 0;
        }
        return ($a < $b) ? -1 : 1;
    }

    usort($input_array,"compare");


    $finish_array = array();
    $summ = 0;
    $x = 0;
    $b = 0;
    $c = 0;
    $size = count($input_array) - 1;
    for ($n = 0; $n <= $size; $n++) {

        if ($n == $size) {
            $a = $input_array[$size][0];
            $summ += $input_array[$n][1];
            $x++;
            array_push($finish_array, array($a,$summ));
            $summ = 0;
        } else {
            $a = $input_array[$n][0];
            $b = $input_array[$n + 1][0];
            $c = (int)$b - (int)$a;

            switch ($c) {
                case 0:
                    $summ += $input_array[$n][1];
                    break;
                case ($c != 0):
                    $summ += $input_array[$n][1];
                    $x++;
                    array_push($finish_array, array($a,$summ));
                    $summ = 0;
                    break;
            }
        }
    }
    return $finish_array;
}


/** Функция из заявки возвращает массив вида ...[[filter][count]][[filter][count]] */
function get_order($order_number){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    /** Подключаемся к БД для вывода заявки */
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        /** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'
            . "Номер ошибки: " . $mysqli->connect_errno . "\n"
            . "Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }
    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM orders WHERE order_number = '$order_number';";
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    /**  массив для сохранения заявки в объект планирования */
    $order_array = array();

    /** Разбор массива значений по подключению */
    while ($row = $result->fetch_assoc()){

        /** наполняем массив для сохранения заявки */
        $temp_array = array();
        array_push($temp_array, $row['filter']);
        array_push($temp_array, $row['count']);
        array_push($order_array,$temp_array);
    }
    return $order_array;
}

/** Функция обеспечивает подключение к БД и выполняет запрос sql */
/** возвращает результат выполнения sql запроса */
function mysql_execute($sql){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    /** Подключаемся к БД */
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        /** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'
            . "Номер ошибки: " . $mysqli->connect_errno . "\n"
            . "Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }
    /** Выполняем запрос SQL */
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    return $result;
}

/** Функция формирует список коробок имеющихся в БД
 * если в функцию передается переменная, то выбирается коробка, соответствующая переменной
 */
function select_boxes($index){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
    /** Подключаемся к БД */
    if ($mysqli->connect_errno){/** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'."Номер ошибки: " . $mysqli->connect_errno . "\n"."Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }

    $sql = "SELECT * FROM box ORDER BY b_name";
    #$sql = "SELECT * FROM box";
    /** Выполняем запрос SQL */
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }
    /** извлечение ассоциативного массива */

    echo "<option></option>";

    while ($row = $result->fetch_assoc()) {
        echo "<option";
        // если номер коробки указан, то делаем ее выбранной
        if ($row['b_name'] == $index) echo " selected ";
        echo ">".$row['b_name']."</option>";
    }

    /* удаление выборки */
    $result->free();

}

/** Функция формирует список ящиков имеющихся в БД */
function select_g_boxes($index){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
    /** Подключаемся к БД */
    if ($mysqli->connect_errno){/** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'."Номер ошибки: " . $mysqli->connect_errno . "\n"."Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }

    $sql = "SELECT * FROM g_box";
    /** Выполняем запрос SQL */
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }
    /** извлечение ассоциативного массива */
    echo "<option></option>";


     while ($row = $result->fetch_assoc()) {
        echo "<option";
        // если номер ящика указан, то делаем ее выбранной
        if ($row['gb_name'] == $index) echo " selected ";
        echo ">".$row['gb_name']."</option>";
    }



    /* удаление выборки */
    $result->free();

}

/** Проверка наличия фильтра в БД */
function check_filter($filter){

    $result = mysql_execute("SELECT * FROM salon_filter_structure WHERE filter = '$filter'");

    if ($result->num_rows > 0) {
        $a = true;
    } else {
        $a = false;
    }

    return $a;
}

/** Получаем всю информацию о фильтре:
 * -------------------------------
 * гофропакет: длина, ширина, высота, Количество ребер, усилитель, поставщик, комментарий
 * каркас: длина, ширина, материал, поставщик
 * предфильтр: длина, ширина, материал, поставщик, комментарий
 * индивидуальная упаковка
 * групповая упаковка
 * примечание
 * ----------------------------
 *
 */
function get_salon_filter_data($target_filter){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** @var  $result_array  массив вывода результата*/
    $result_array = array();

    /** ГОФРОПАКЕТ */
    $result_array['paper_package_name'] = 'гофропакет '.$target_filter;

    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM paper_package_salon WHERE p_p_name = '".$result_array['paper_package_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $paper_package_data = $result->fetch_assoc();

    if (isset($paper_package_data['p_p_width'])){$result_array['paper_package_width'] = $paper_package_data['p_p_width'];}else{$result_array['paper_package_width'] ='';}
    if (isset( $paper_package_data['p_p_height'])){$result_array['paper_package_height'] = $paper_package_data['p_p_height'];}else{$result_array['paper_package_height'] ='';}
    if (isset( $paper_package_data['p_p_pleats_count'])){$result_array['paper_package_pleats_count'] = $paper_package_data['p_p_pleats_count'];}else{$result_array['paper_package_pleats_count'] ='';}
    if (isset( $paper_package_data['p_p_supplier'])){$result_array['paper_package_supplier'] = $paper_package_data['p_p_supplier'];}else{$result_array['paper_package_supplier'] = '';}
    if (isset( $paper_package_data['p_p_remark'])){$result_array['paper_package_remark'] = $paper_package_data['p_p_remark'];}else{$result_array['paper_package_remark'] = '';}
    if (isset( $paper_package_data['p_p_material'])){$result_array['paper_package_material'] = $paper_package_data['p_p_material'];}else{$result_array['paper_package_material'] ='';}


    /** Получаем все данные из salon_filter_structure одним запросом */
    $sql = "SELECT * FROM salon_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
   /** Разбор массива значений */
    $salon_filter_data = $result->fetch_assoc();

    /** Вставка */
    if (isset($salon_filter_data['insertion_count'])){$result_array['insertion_count'] = $salon_filter_data['insertion_count'];}else{$result_array['insertion_count'] = '';}

    /** КОРОБКА ИНДИВИДУАЛЬНАЯ */
    if (isset($salon_filter_data['box'])){$result_array['box'] = $salon_filter_data['box'];}else{$result_array['box'] ='';}

    /** КОРОБКА ГРУППОВАЯ */
    if (isset($salon_filter_data['g_box'])){$result_array['g_box'] = $salon_filter_data['g_box'];}else{$result_array['g_box'] = '';}

    /** ПРИМЕЧАНИЯ */
    if (isset($salon_filter_data['comment'])){$result_array['comment'] = $salon_filter_data['comment'];}else{$result_array['comment'] = '';}

    /** Поролон */
    if (isset($salon_filter_data['foam_rubber'])){$result_array['foam_rubber'] = $salon_filter_data['foam_rubber'];}else{$result_array['foam_rubber'] = '';}

    if ($result_array['foam_rubber'] == 'поролон'){
        $result_array['foam_rubber_checkbox_state'] = 'checked';
    } else  $result_array['foam_rubber_checkbox_state'] = '';

    /** Язычек */
    if (isset($salon_filter_data['tail'])){$result_array['tail'] = $salon_filter_data['tail'];}else{$result_array['tail'] = '';}

    if ($result_array['tail']  == 'язычек'){
        $result_array['tail_checkbox_state'] = 'checked';
    } else  $result_array['tail_checkbox_state'] = '';

    /** Форма */
    if (isset($salon_filter_data['form_factor'])){$result_array['form_factor'] = $salon_filter_data['form_factor'];}else{$result_array['form_factor'] ='';}

    if ($result_array['form_factor'] == 'трапеция'){
        $result_array['form_factor_checkbox_state'] = 'checked';
    } else  $result_array['form_factor_checkbox_state'] = '';

    /** Высота ленты*/
    if (isset($salon_filter_data['side_type'])){$result_array['side_type'] = $salon_filter_data['side_type'];}else{$result_array['side_type'] ='';}

    /** Надрезы */
    if (isset($salon_filter_data['has_edge_cuts'])){
        $result_array['has_edge_cuts'] = intval($salon_filter_data['has_edge_cuts']);
    } else {
        $result_array['has_edge_cuts'] = 0;
    }

    /** Тариф и сложность производства */
    $tariff_complexity_data = $salon_filter_data;

    if (isset($tariff_complexity_data['tariff_id']) && $tariff_complexity_data['tariff_id']){
        $result_array['tariff_id'] = $tariff_complexity_data['tariff_id'];
        
        // Получаем информацию о тарифе из таблицы salary_tariffs
        $tariff_id = $tariff_complexity_data['tariff_id'];
        $sql_tariff = "SELECT tariff_name, rate_per_unit, type FROM salary_tariffs WHERE id = '".$tariff_id."';";
        if ($result_tariff = $mysqli->query($sql_tariff)){
            $tariff_data = $result_tariff->fetch_assoc();
            if ($tariff_data){
                $result_array['tariff_name'] = $tariff_data['tariff_name'] ?? '';
                $result_array['tariff_rate'] = $tariff_data['rate_per_unit'] ?? '';
                $result_array['tariff_type'] = $tariff_data['type'] ?? '';
            } else {
                $result_array['tariff_name'] = '';
                $result_array['tariff_rate'] = '';
                $result_array['tariff_type'] = '';
            }
            $result_tariff->close();
        } else {
            $result_array['tariff_name'] = '';
            $result_array['tariff_rate'] = '';
            $result_array['tariff_type'] = '';
        }
    } else {
        $result_array['tariff_id'] = '';
        $result_array['tariff_name'] = '';
        $result_array['tariff_rate'] = '';
        $result_array['tariff_type'] = '';
    }

    if (isset($tariff_complexity_data['build_complexity'])){$result_array['build_complexity'] = $tariff_complexity_data['build_complexity'];}else{$result_array['build_complexity'] = '';}

    /** Закрываем соединение */
    $result->close();
    $mysqli->close();

    return $result_array;
}

/** Расчет  необходимого количества каркасов для выполнения запявки*/
function component_analysis_wireframe($order_number){

    // шапка таблицы
    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку комплектующих для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    // запрос для выборки необходимых каркасов для выполнения заявки
    $sql = "SELECT orders.filter, salon_filter_structure.wireframe, salon_filter_structure.filter, orders.count ".
        "FROM orders, salon_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = salon_filter_structure.filter ".
        "AND salon_filter_structure.wireframe!='';";

    $result = mysql_execute($sql);

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($result as $value){

              echo '<tr><td>'.$i.'</td><td>'.$value['wireframe'].'</td><td>'.$value['count'].'</td><td><input type="text"></td>';
              $i++;
    }

    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Дата составления заявки:</td><td colspan="2">'.date('d.m.y ').'</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Заявку составил:</td><td colspan="2"><input type="text"></td></tr>';
    echo '</table>';
}

/** Расчет  необходимого количества предфильтров для выполнения запявки*/
function component_analysis_prefilter($order_number){

    // шапка таблицы
    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку комплектующих для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    // запрос для выборки необходимых каркасов для выполнения заявки
    $sql = "SELECT orders.filter, salon_filter_structure.prefilter, salon_filter_structure.filter, orders.count ".
        "FROM orders, salon_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = salon_filter_structure.filter ".
        "AND salon_filter_structure.prefilter!='';";

    $result = mysql_execute($sql);

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($result as $value){

        echo '<tr><td>'.$i.'</td><td>'.$value['prefilter'].'</td><td>'.$value['count'].'</td><td><input type="text"></td>';
        $i++;
    }

    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Дата составления заявки:</td><td colspan="2">'.date('d.m.y ').'</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Заявку составил:</td><td colspan="2"><input type="text"></td></tr>';
    echo '</table>';
}

/** Расчет  необходимого количества гофропакетов для выполнения запявки*/
function component_analysis_paper_package($order_number){

    // шапка таблицы
    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку комплектующих для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    // запрос для выборки необходимых каркасов для выполнения заявки
    $sql = "SELECT orders.filter, salon_filter_structure.paper_package, salon_filter_structure.filter, orders.count ".
        "FROM orders, salon_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = salon_filter_structure.filter ".
        "AND salon_filter_structure.paper_package!='';";

    $result = mysql_execute($sql);

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($result as $value){

        echo '<tr><td>'.$i.'</td><td>'.$value['paper_package'].'</td><td>'.$value['count'].'</td><td><input type="text"></td>';
        $i++;
    }

    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Дата составления заявки:</td><td colspan="2">'.date('d.m.y ').'</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Заявку составил:</td><td colspan="2"><input type="text"></td></tr>';
    echo '</table>';
}

/** Расчет  необходимого количества групповых ящиков для выполнения заявки*/
function component_analysis_group_box($order_number){

    // запрос для выборки необходимых каркасов для выполнения заявки
    $sql = "SELECT orders.filter, salon_filter_structure.paper_package, salon_filter_structure.g_box, orders.count ".
        "FROM orders, salon_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = salon_filter_structure.filter ".
        "AND salon_filter_structure.g_box!='';";

    $result = mysql_execute($sql);
    $temp_array = array(); // массив для сложения одинковых элементов

    foreach ($result as $value){
        array_push($temp_array,array($value['g_box'],$value['count']));
    }

     $temp_array = summ_the_same_elements_of_array($temp_array);

    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку ящиков груповых для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($temp_array as $value){
        echo '<tr><td>'.$i.'</td><td>'.$value[0].'</td><td>'.round(($value[1]/10)).'</td><td><input type="text"></td>';
        $i++;
    }

    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Дата составления заявки:</td><td colspan="2">'.date('d.m.y ').'</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Заявку составил:</td><td colspan="2"><input type="text"></td></tr>';
    echo '</table>';

}


/** Расчет  необходимого количества коробок индивидуальных для выполнения заявки*/
function component_analysis_box($order_number){

    // запрос для выборки необходимых каркасов для выполнения заявки
    $sql = "SELECT orders.filter, salon_filter_structure.box, orders.count ".
        "FROM orders, salon_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = salon_filter_structure.filter ".
        "AND salon_filter_structure.box!='';";

    $result = mysql_execute($sql);
    $temp_array = array(); // массив для сложения одинковых элементов

    foreach ($result as $value){
        array_push($temp_array,array($value['box'],$value['count']));
    }

    /** временно выключаем функцию сложения однаковых позиций, так как в ней очевидно ошибка */
   // $temp_array = summ_the_same_elements_of_array($temp_array);


    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку коробок индивидуальных для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($temp_array as $value){
        echo '<tr><td>'.$i.'</td><td>'.$value[0].'</td><td>'.$value[1].'</td><td><input type="text"></td>';
        $i++;
    }

    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Дата составления заявки:</td><td colspan="2">'.date('d.m.y ').'</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Заявку составил:</td><td colspan="2"><input type="text"></td></tr>';
    echo '</table>';
}

?>
