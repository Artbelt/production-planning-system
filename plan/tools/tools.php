<?php /** tools.php в файле прописаны разные функции */

/** ПОдключаем функции */
require_once('C:/xampp/htdocs/plan/settings.php') ;


function show_ads(){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    // ОПТИМИЗАЦИЯ: используем MySQLi вместо нового PDO подключения
    static $cached_ads = null;
    static $cache_time = null;
    
    // Кэшируем результат на 60 секунд
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
            background: #ffdddd; /* Светло-красный фон */
            border: 1px solid #dd5555; /* Красная рамка для контраста */
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

/** Проверяет является ли пользователь администратором */
function is_admin($user){
    // Если $user - это массив (новая система авторизации)
    if (is_array($user)) {
        // Проверяем роль пользователя в новой системе
        if (isset($user['role']) && in_array($user['role'], ['admin', 'director'])) {
            return true;
        }
        
        // Если нет информации о роли, проверяем в старой таблице по имени пользователя
        if (isset($user['full_name'])) {
            $sql = "SELECT * FROM users WHERE user = '".$user['full_name']."'";
            $result = mysql_execute($sql);
            $status = mysqli_fetch_assoc($result);
            return ($status && $status['Admin'] == 1);
        }
        return false;
    }
    
    // Если $user - это строка (старая система)
    $sql = "SELECT * FROM users WHERE user = '".$user."'";
    $result = mysql_execute($sql);
    $status = mysqli_fetch_assoc($result);
    if ($status && $status['Admin'] == 1){
        return true;
    } else {
        return false;
    }
}

/** Функция рисует кнопку предоставления доступа для редактирования */
function edit_access_button_draw(){
    // Обертка с классом для управления внешними отступами на странице
    echo '<div class="edit-access-wrap">';
    echo '<form action="edit_access_processing.php" target="_blank" method="post">';
    echo '<input type="submit" value="Дать доступ для редактирования"/>';
    echo '</form>';
    echo '</div>';

}

/** Функция показывает есть ли доступ к редактированию данных */
function is_edit_access_granted(){
    //получаем статус возможности редактирования
    /** Выполняем запрос SQL для загрузки заявок*/
    $sql = "SELECT * FROM editor_access_time";
    $result = mysql_execute($sql);
    $access_time_result = mysqli_fetch_assoc($result);
    $acces_time = $access_time_result['access_time'];
    $now_time = date("Y-m-d H:i:s");
    if ($acces_time > $now_time){
        return true;
    } else {
        return false;
    }
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
    
    // Собираем данные за все дни
    $all_days_data = [];
    
    for ($a = 1; $a < 11; $a++) {
        $production_date = date("Y-m-d", time() - (60 * 60 * 24 * $a));
        $production_date = reverse_date($production_date);
        
        // Получаем общее количество за день
        $sql = "SELECT SUM(count_of_filters) as total_count
                FROM manufactured_production
                WHERE date_of_production = '$production_date'";
        
        $result = $mysqli->query($sql);
        
        if (!$result) {
            continue;
        }
        
        $row = $result->fetch_assoc();
        $total_count = (int)($row['total_count'] ?? 0);
        
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
        .highlight_green {
            color: #10b981;
        }
        .highlight_red {
            color: #ef4444;
        }
    </style>';
    
    echo '<table class="production-table">';
    echo '<thead><tr>';
    echo '<th class="date-col">Дата</th>';
    echo '<th class="total-col">Всего</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    // Выводим данные по дням в обратном порядке (от новых к старым)
    // Цикл заполняет массив от вчера к 10 дням назад, переворачиваем для отображения от новых к старым
    $all_days_data = array_reverse($all_days_data);
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
    if ($count_per_day > 1000){
        echo "<div class='production-stat'><span class='production-stat-label'>Среднее количество в смену:</span> <span class='production-stat-value highlight_green' title='Это количество обеспечит 30 000 фильтров в месяц'>".round($count_per_day, 0)."</span></div>";
    } else {
        echo "<div class='production-stat'><span class='production-stat-label'>Среднее количество в смену:</span> <span class='production-stat-value highlight_red' title='Это количество НЕ обеспечит 30 000 фильтров в месяц'>".round($count_per_day, 0)."</span></div>";
    }
    echo '</div>'; // закрываем production-card-footer
    echo '</div>'; // закрываем production-card
}

/** Создание списка с перечнем заявок
 * @param $list = 0 => вЫпадающий список
 * @param $list = 1 => список
 * @param $selection - в варианте с выпадающим списком значение будет выбрано как активное
 * @param $form - принадлежность элемента input к форме(имя формы)
 * @return ни чего не возвращает, просто рисует список заявок
 */
function load_orders($list, $selection, $form){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Выполняем запрос SQL для загрузки заявок*/
    $sql = "SELECT DISTINCT order_number, workshop FROM orders;";

    if (!isset($form)){
        $form = 'form';
    }

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    if ($list == '0') {

        if (isset($selection)&&($selection != 0)){// если $selected определено
            /** Разбор массива значений для выпадающего списка */
            echo "<select id='selected_order' name = 'selected_order' form='".$form."'>";
            while ($orders_data = $result->fetch_assoc()) {
                if ($selection == $orders_data['order_number']){
                    echo "<option name='order_number' value=" . $orders_data['order_number'] . " selected>" . $orders_data['order_number'] . "</option>";
                } else {
                    echo "<option name='order_number' value=" . $orders_data['order_number'] . ">" . $orders_data['order_number'] . "</option>";
                }

            }
            echo "</select>";
        } else {

            /** Если $selected не определено то делаем список */
            /** Разбор массива значений для выпадающего списка */
            echo "<select id='selected_order'  name = 'selected_order' form='".$form."'>";
            while ($orders_data = $result->fetch_assoc()) {
                echo "<option name='order_number' value=" . $orders_data['order_number'] . ">" . $orders_data['order_number'] . "</option>";
            }
            echo "</select>";
        }
    } else {
        echo 'Перечень заявок';
        /** Разбор массива значений для списка чекбоксов */
        echo "<form action='orders_editor.php' method='post'>";
        while ($orders_data = $result->fetch_assoc()) {
            echo "<input type='checkbox' name='order_name[]'value=".$orders_data['order_number']." <label>".$orders_data['order_number'] ."</label><br>";
        }
        echo "<button type='submit'>Объединить для расчета</button>";
        echo "</form>";

    }
    /** Закрываем соединение */
    $result->close();
    $mysqli->close();
}
/** СОздание <SELECT> списка с перечнем фильтров имеющихся в БД */
function load_filters_into_select($text){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    // ОПТИМИЗАЦИЯ: кэшируем список фильтров на 5 минут
    static $cached_filters = null;
    static $cache_time = null;
    
    if ($cached_filters !== null && $cache_time !== null && (time() - $cache_time) < 300) {
        $filters = $cached_filters;
    } else {
        /** Создаем подключение к БД */
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

        /** Выполняем запрос SQL для загрузки фильтров (с LIMIT для безопасности) */
        $sql = "SELECT DISTINCT filter FROM panel_filter_structure WHERE filter IS NOT NULL AND filter != '' ORDER BY filter LIMIT 5000;";

        /** Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ 
            echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            exit;
        }

        /** Собираем все фильтры в массив */
        $filters = [];
        while ($row = $result->fetch_assoc()){
            $filters[] = $row['filter'];
        }
        
        /** Закрываем соединение */
        $result->close();
        $mysqli->close();
        
        // Сохраняем в кэш
        $cached_filters = $filters;
        $cache_time = time();
    }

    /** Выводим select */
    echo "<select name='analog_filter' >";
    echo "<option value=''>".htmlspecialchars($text)."</option>";
    foreach ($filters as $filter) {
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
function write_of_filters($date_of_production, $order_number, $filters){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Цикл для разбора значений массива со значениями "фильтер - количество" */
    foreach ($filters as $filter_record) {

        /** Получили значение {елемент масива[фильтр][количество]} */
        $filter_name = $filter_record[0];
        $filter_count = $filter_record[1];

        /** Форматируем sql-запрос, "записать в БД -> дата -> заявка -> фильтер -> количство" */
        $sql = "INSERT INTO manufactured_production (date_of_production, name_of_filter, count_of_filters, name_of_order) 
                VALUES ('$date_of_production','$filter_name','$filter_count','$order_number')";

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

/** Занесение комплектующих
 * @param $date_of_production
 * @param $order_number
 * @param $parts
 * @return bool
 */
function write_of_parts($date_of_production, $order_number, $parts){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Цикл для разбора значений массива со значениями "фильтер - количество" */
    foreach ($parts as $part_record) {

        /** Получили значение {елемент масива[фильтр][количество]} */
        $part_name = $part_record[0];
        $part_count = $part_record[1];

        /** Форматируем sql-запрос, "записать в БД -> дата -> заявка -> фильтер -> количство" */
        $sql = "INSERT INTO manufactured_parts (date_of_production, name_of_parts, count_of_parts, name_of_order) 
                VALUES ('$date_of_production','$part_name','$part_count','$order_number')";

        /** Выполняем запрос. Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            /** в случае неудачи функция выводит FALSЕ */
            return false;
            exit;
        }
    }
    echo '111111';
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

    return $result_array;
}


/** Функция выполняет запрос к БД и возвращает количество изготовленных комплектующих определенного изделия по выбранной заявке */
function manufactured_part_count($part,$order) {

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
    $sql = "SELECT * FROM manufactured_parts WHERE name_of_parts LIKE '%$part' AND name_of_order='$order'";
    //$sql = "SELECT * FROM orders WHERE order_number = '$order_number';";
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }
    $count = 0;

    /** Разбираем результата запроса */
    while ($row = $result->fetch_assoc()){
        $count += $row['count_of_parts'];
    }

    $temporary = $count.'_'.$part.'_'.$order;
    return $temporary;
    #return $count;
}


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
echo '<p>#########################<p>';
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

/** Функция выводит список заказа с чекбоксами для выбора позиций позиций для раскроя*/

function show_order_checkedlist($order_number){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    /** Подключаемся к БД для вывода заявки */
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        /** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'. "Номер ошибки: " . $mysqli->connect_errno . "\n" . "Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }
    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM orders WHERE order_number = '$order_number';";
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n". "Запрос: " . $sql . "\n". "Номер ошибки: " . $mysqli->errno . "\n" . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    /** форма отправки данных после выбора исключений для раскроя */
    echo "<form action='test.php' method='post'>";

    /** Формируем шапку таблицы для вывода заявки */
    echo "<table style='border: 1px solid black; border-collapse: collapse; font-size: 14px;'>
        <tr>
            <th style=' border: 1px solid black'> №
            </th>
            <th style=' border: 1px solid black'> Фильтр
            </th>
            <th style=' border: 1px solid black'> Количество, шт           
            </th>   
            <th style=' border: 1px solid black'> Ширина 
            </th> 
            <th style=' border: 1px solid black'> Исключить 
            </th>                                                
        </tr>";

    $counter = 0;
    /** Разбор массива значений по подключению */
    while ($row = $result->fetch_assoc()){
        $counter++;
        echo "<tr>"
            ."<td style=' border: 1px solid black'>".$counter."</td>"
            ."<td style=' border: 1px solid black'>".$row['filter']." <input type='hidden' name='filter ".$counter."' id='filter_id' value='".$row['filter']."'</td>"
            ."<td style=' border: 1px solid black'>".$row['count']." <input type='hidden' name='count_".$counter."' id='counter_id' value='".$row['count']."'</td>";
        $filter_data = get_filter_data($row['filter']);
        $width_of_paper_package = $filter_data['paper_package_width'];
        if (($width_of_paper_package <= 195) AND ($width_of_paper_package >= 170)){
            echo "<td style=' border: 1px solid black' bgcolor='yellow'>".$width_of_paper_package." <input type='hidden' name='width_".$counter."' id='width_id' value='".$width_of_paper_package."'</td>";
        } else {
            echo "<td style=' border: 1px solid black'>".$width_of_paper_package." <input type='hidden' name='width_".$counter."' id='width_id' value='".$width_of_paper_package."'</td>";

        }
           // ."<td style=' border: 1px solid black'>".get_filter_data($row['filter'])[paper_package_width]."</td>"
        echo "<td style=' border: 1px solid black'> "
            ."<form>"
            ."<label>";
        if (($width_of_paper_package <= 195) AND ($width_of_paper_package >= 175)){
            echo "<input type='checkbox' name='chck_box_".$counter."' value='checked' align='center' checked>";
        } else{
            echo "<input type='checkbox' name='chck_box_".$counter."' value='unchecked' align='center'> <input type='hidden' name='chck_box_".$counter."' value='0'>";
        }
           echo "</label>"
            ." </td>"
            ."</tr>";
    }

    echo "<input type='hidden' name='order_number' value ='".$order_number."'>";
    echo "</table>";
    echo "<input type='submit'>";
    echo "</form>";
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

    $sql = "SELECT * FROM g_box ORDER BY gb_name";
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

    $result = mysql_execute("SELECT * FROM panel_filter_structure WHERE filter = '$filter'");

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
 */
/*Array     *ОБРАЗЕЦ*
(
    [paper_package_name] => гофропакет SX1211
    [paper_package_length] => 335
    [paper_package_width] => 123.5
    [paper_package_height] => 60
    [paper_package_pleats_count] => 108
    [paper_package_amplifier] => 2
    [paper_package_supplier] => У2
    [paper_package_remark] =>
    [wireframe_name] =>
    [wireframe_length] =>
    [wireframe_width] =>
    [wireframe_material] =>
    [wireframe_supplier] =>
    [prefilter_name] =>
    [prefilter_length] =>
    [prefilter_width] =>
    [prefilter_material] =>
    [prefilter_supplier] =>
    [prefilter_remark] =>
    [box] => 11 Shafer
    [g_box] => 11
    [comment] =>
)*/
function get_filter_data($target_filter){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** @var  $result_array  массив вывода результата*/
    $result_array = array();

    /** ГОФРОПАКЕТ */
    $result_array['paper_package_name'] = 'гофропакет '.$target_filter;

    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM paper_package_panel WHERE p_p_name = '".$result_array['paper_package_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $paper_package_data = $result->fetch_assoc();

    if ($paper_package_data == null) {
        $result_array['paper_package_length'] = null;
        $result_array['paper_package_width'] = null;
        $result_array['paper_package_height'] = null;
        $result_array['paper_package_pleats_count'] = null;
        $result_array['paper_package_amplifier'] = null;
        $result_array['paper_package_supplier'] = null;
        $result_array['paper_package_remark'] = null;
    } else {
        $result_array['paper_package_length'] = $paper_package_data['p_p_length'];
        $result_array['paper_package_width'] = $paper_package_data['p_p_width'];
        $result_array['paper_package_height'] = $paper_package_data['p_p_height'];
        $result_array['paper_package_pleats_count'] = $paper_package_data['p_p_pleats_count'];
        $result_array['paper_package_amplifier'] = $paper_package_data['p_p_amplifier'];
        $result_array['paper_package_supplier'] = $paper_package_data['supplier'];
        $result_array['paper_package_remark'] = $paper_package_data['p_p_remark'];
    }

    /** КАРКАС */
    $result_array['wireframe_name'] = 'каркас '.$target_filter;

    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM wireframe_panel WHERE w_name = '".$result_array['wireframe_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $wireframe_data = $result->fetch_assoc();

    if ($wireframe_data == null) {
        $result_array['wireframe_name']=null;
        $result_array['wireframe_length'] = null;
        $result_array['wireframe_width'] = null;
        $result_array['wireframe_material'] = null;
        $result_array['wireframe_supplier'] = null;
    } else {
        $result_array['wireframe_length'] = $wireframe_data['w_length'];
        $result_array['wireframe_width'] = $wireframe_data['w_width'];
        $result_array['wireframe_material'] = $wireframe_data['w_material'];
        $result_array['wireframe_supplier'] = $wireframe_data['w_supplier'];
    }

    /** ПРЕДФИЛЬТР */
    $result_array['prefilter_name'] = 'предфильтр '.$target_filter;

    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM prefilter_panel WHERE p_name = '".$result_array['prefilter_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $prefilter_data = $result->fetch_assoc();

    if ($prefilter_data == null){
        $result_array['prefilter_name'] = null;
        $result_array['prefilter_length'] = null;
        $result_array['prefilter_width'] = null;
        $result_array['prefilter_material'] = null;
        $result_array['prefilter_supplier'] = null;
        $result_array['prefilter_remark'] = null;
    } else {
        $result_array['prefilter_length'] =  $prefilter_data['p_length'];
        $result_array['prefilter_width'] = $prefilter_data['p_width'];
        $result_array['prefilter_material'] = $prefilter_data['p_material'];
        $result_array['prefilter_supplier'] = $prefilter_data['p_supplier'];
        $result_array['prefilter_remark'] = $prefilter_data['p_remark'];
    }

    /** ПРОЛИВКА */
    /** Выполняем запрос SQL */
    $sql = "SELECT glueing, glueing_remark FROM panel_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $glueing_data = $result->fetch_assoc();
    if ($glueing_data == null) {
        $result_array['glueing'] = null;
        $result_array['glueing_remark'] = null;
    } else {
        $result_array['glueing'] = $glueing_data['glueing'];
        $result_array['glueing_remark'] = $glueing_data['glueing_remark'];
    }

    /** ФОРМ-ФАКТОР */
    /** Выполняем запрос SQL */
    $sql = "SELECT form_factor_id, form_factor_remark FROM panel_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $form_factor_data = $result->fetch_assoc();
    if ($form_factor_data == null) {
        $result_array['form_factor_id'] = null;
        $result_array['form_factor_remark'] = null;
    } else {
        $result_array['form_factor_id'] = $form_factor_data['form_factor_id'];
        $result_array['form_factor_remark'] = $form_factor_data['form_factor_remark'];
    }

    /** КОРОБКА ИНДИВИДУАЛЬНАЯ */
    /** Выполняем запрос SQL */
    $sql = "SELECT box FROM panel_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $box_data = $result->fetch_assoc();
    if ($box_data == null) {
        $result_array['box'] = null;
    } else {
        $result_array['box'] = $box_data['box'];
    }

    /** КОРОБКА ГРУППОВАЯ */
    /** Выполняем запрос SQL */
    $sql = "SELECT g_box FROM panel_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $g_box_data = $result->fetch_assoc();
    if ($g_box_data == null) {
        $result_array['g_box'] = null;
    } else {
        $result_array['g_box'] = $g_box_data['g_box'];
    }

    /** ПРИМЕЧАНИЯ */
    /** Выполняем запрос SQL */
    $sql = "SELECT comment FROM panel_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $comment_data = $result->fetch_assoc();
    if ($comment_data == null) {
        $result_array['comment'] = null;
    } else {
        $result_array['comment'] = $comment_data['comment'];
    }

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
    $sql = "SELECT orders.filter, panel_filter_structure.wireframe, panel_filter_structure.filter, orders.count ".
        "FROM orders, panel_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = panel_filter_structure.filter ".
        "AND panel_filter_structure.wireframe!='';";

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
    $sql = "SELECT orders.filter, panel_filter_structure.prefilter, panel_filter_structure.filter, orders.count ".
        "FROM orders, panel_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = panel_filter_structure.filter ".
        "AND panel_filter_structure.prefilter!='';";

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
    $sql = "SELECT orders.filter, panel_filter_structure.paper_package, panel_filter_structure.filter, orders.count ".
        "FROM orders, panel_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = panel_filter_structure.filter ".
        "AND panel_filter_structure.paper_package!='';";

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
    $sql = "SELECT orders.filter, panel_filter_structure.paper_package, panel_filter_structure.g_box, orders.count ".
        "FROM orders, panel_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = panel_filter_structure.filter ".
        "AND panel_filter_structure.g_box!='';";

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
    $sql = "SELECT orders.filter, panel_filter_structure.box, orders.count ".
        "FROM orders, panel_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = panel_filter_structure.filter ".
        "AND panel_filter_structure.box!='';";

    $result = mysql_execute($sql);
    $temp_array = array(); // массив для сложения одинковых элементов

    foreach ($result as $value){
        array_push($temp_array,array($value['box'],$value['count']));
    }

    /** Вывод коробок подсчитанных */
    function calculate_total_boxes($box_array) {
        $expenses = array();

        // Проходим по каждой строке таблицы
        foreach ($box_array as $row) {
            // Извлекаем имя и стоимость из строки
            $box = $row[0];
            $count = $row[1];

            // Если имя уже существует в массиве $expenses, добавляем стоимость, иначе создаем новую запись
            if (array_key_exists($box, $expenses)) {
                $expenses[$box] += $count;
            } else {
                $expenses[$box] = $count;
            }
        }

        return $expenses;
    }

    $result = calculate_total_boxes($temp_array);
    ksort($result);

    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку коробок индивидуальных для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($result as $key => $value){
        echo '<tr><td>'.$i.'</td><td>'.$key.'</td><td>'.$value.'</td><td><input type="text"></td>';
        $i++;
    }

    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Дата составления заявки:</td><td colspan="2">'.date('d.m.y ').'</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Заявку составил:</td><td colspan="2"><input type="text"></td></tr>';
    echo '</table>';

}

?>
