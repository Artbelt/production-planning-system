<?php /** tools.php в файле прописаны разные функции */

/** ПОдключаем функции */
require_once('C:/xampp/htdocs/plan_U3/settings.php') ;

global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

function show_ads(){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    $host = $mysql_host;
    $db = $mysql_database;
    $user = $mysql_user;
    $pass = $mysql_user_pass;

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Запрос активных объявлений
        $stmt = $pdo->prepare("SELECT * FROM ads WHERE expires_at >= NOW() ORDER BY expires_at ASC");
        $stmt->execute();
        $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Ошибка подключения: " . $e->getMessage());
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
    //echo '<button>Дать доступ для редактирования</button>';
    echo '<form action="edit_access_processing.php" target="_blank" method="post">';
    echo '<input type = "submit" value="Дать доступ для редактрования"/>';
    echo '</form>';

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

/** Функция возвращает массив дат от стартовой даты до конечной даты в виде
 * @param $start_date стартовая дата
 * @param $finish_date конечная дата
 * @return возвращает массив дат в текстовом формате
 */
function fill_dates_in_range($start_date, $finish_date){
    //дата начала
    $date1 = date_create($start_date);
    $date2 =date_create($finish_date);
    //массив дат
    $result_array = array();
    //добавляем первый день в массив
    array_push($result_array,date_format($date1, 'Y-m-d'));
    //пока дата 1 меньше даты 2 добавляем 1 день к первой дате и записываем ее в массив дат
    while ($date1 < $date2){
        //модифицируем дату
        date_modify($date1, '+1 day');
        array_push($result_array,date_format($date1, 'Y-m-d'));
    }
    //возвращаем массив дат в формате '13-04-2024'
    return $result_array;
}

/** Получает изготовленные фильтры за период и возвращает массив выпущенных фильтров
 * @param  $start_date = дата от
 * @param  $finish_date = дата до
 * @return возвращает массив фильтров изготовленных за указанный период
 */
function get_produced_filters_in_time ($start_date,$finish_date){

//массив произведенных фильтров имеет формат:
//array (size=ххх)
//  0 =>
//    array (size=4)
//      0 => string '2024-04-15' (length=10)
//      1 => string '14-22-24' (length=8)
//      2 => string 'AF0167pe' (length=8)
//      3 => string '43' (length=2)

    $start_date = reverse_date($start_date);
    $finish_date = reverse_date($finish_date);

    $sql = "SELECT * FROM manufactured_production WHERE date_of_production >= '$start_date' AND date_of_production <= '$finish_date';";

    $result = mysql_execute($sql);

    $result_array = array();

    foreach ($result as $variant){
        $temp_array = array();
        $temp_date ='';
        //$x += $variant['count_of_filters'];
       // echo "<tr style=' border: 1px solid black'><td>".$variant['date_of_production'].'</td><td>'.$variant['name_of_filter'].'</td><td>'.$variant['count_of_filters'].'</td><td>'.$variant['name_of_order'].'</td></tr>';
        //$temp_date =
        array_push($temp_array,reverse_date($variant['date_of_production']));
        array_push($temp_array,$variant['name_of_order']);
        array_push($temp_array,$variant['name_of_filter']);
        array_push($temp_array,$variant['count_of_filters']);

        array_push($result_array,$temp_array);
    }
    return $result_array;
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

/** Отображение выпуска продукции за последнюю неделю */
function show_weekly_production(){
    $average_value = 0;
    for ($a = 1; $a < 11; $a++) {

        $production_date = date("Y-m-d", time() - (60 * 60 * 24 * $a));;
        $production_date = reverse_date($production_date);
        $sql = "SELECT * FROM manufactured_production WHERE date_of_production = '$production_date';";
        $result = mysql_execute($sql);
        /** @var $x $variant counter */
        $x = 0;
        foreach ($result as $variant) {
            $x += $variant['count_of_filters'];

        }
        $average_value += $x;
        /** Выводим сумму фильтров */
        echo $production_date . " " . $x . " шт <br>";

    }
    echo "<br>Суммарно:".$average_value."шт <br> Среднесменное количество = ".($average_value/10)." шт/смену ";
}

/** Отображение выпуска гофропакетов за последнюю неделю */
function show_weekly_parts(){
    $average_value = 0;
    for ($a = 1; $a < 11; $a++) {

        $production_date = date("Y-m-d", time() - (60 * 60 * 24 * $a));;
        $production_date = reverse_date($production_date);
        $sql = "SELECT * FROM manufactured_parts WHERE date_of_production = '$production_date';";
        $result = mysql_execute($sql);
        /** @var $x $variant counter */
        $x = 0;
        foreach ($result as $variant) {
            $x += $variant['count_of_parts'];
        }
        $average_value += $x;
        /** Выводим сумму фильтров */
        echo $production_date . " " . $x . " шт <br>";

    }
    echo "<br>Суммарно:".$average_value."шт <br> Среднесменное количество = ".($average_value/10)." шт/смену ";
}

/** Отображение остатка крышек по позиции */
function show_prepared_caps($filter){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    $up_cap='';
    $down_cap='';
    $up_cap_filled_count='';
    $up_cap_not_filled_count='';
    $down_cap_count='';

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Определяем какие крішки входят в состав фильтра*/
    $sql = "SELECT up_cap, down_cap FROM round_filter_structure WHERE filter = '$filter';";

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
        exit;
    }
    $cap_data = $result->fetch_assoc();
    $up_cap = $cap_data['up_cap']??'';

//    if (isset($cap_data['up_cap'])){
//        $up_cap = $cap_data['up_cap'];
//    } else{
//        $up_cap = 'not_defined';
//    }

    $down_cap = $cap_data['down_cap']??'';


        /** если металлические крышки в фильтре присутствуют, то находим их колическтво на остатке */
       // if ($up_cap != ''){
        if (isset($up_cap)){
            $sql="SELECT current_quantity FROM cap_stock WHERE cap_name = '$up_cap'";
            /** Если запрос не удачный -> exit */
            if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }
            $up_cap_data = $result->fetch_assoc();
            $up_cap_not_filled_count = $up_cap_data['current_quantity']??'';

            /** Получаем информацию по залитому уплотнителю */
            $sql="SELECT cap_count FROM list_of_filled_caps WHERE name_of_cap = '$up_cap'";
            if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }
            $filled_up_cap_data = $result->fetch_assoc();
            $up_cap_filled_count = $filled_up_cap_data['cap_count']??'';

        } else {//если не определены крышки для этого фильтра -> заменяем просто чем угодно
            $up_cap_filled_count = '(У)';
            $up_cap_not_filled_count = '(В)';
        }

    //if ($down_cap != ''){
        if (isset($down_cap)){
            $sql="SELECT current_quantity FROM cap_stock WHERE cap_name = '$down_cap'";
            /** Если запрос не удачный -> exit */
            if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }
            $down_cap_data = $result->fetch_assoc();
            $down_cap_count = $down_cap_data['current_quantity']??'';
        } else {
            $down_cap_count = '(H)';
        }

        /** надо проверить обращение к Trying to access array offset on value of type null in */


    return '['.($cap_data['up_cap']??'').' '.$up_cap_not_filled_count.'][ '.($cap_data['down_cap']??'').' '.$down_cap_count.']['.'У'.$up_cap_filled_count.']';
    //return '['.$cap_data['up_cap'].' '.$up_cap_not_filled_count.'][ '.$cap_data['down_cap'].' '.$down_cap_count.']['.'У'.$up_cap_filled_count.']';
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
    $sql = "SELECT DISTINCT order_number, workshop FROM orders WHERE hide IS NULL OR hide = 0;";

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

/** Создание <SELECT> списка с перечнем PP вставок */
function load_insertions(){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Выполняем запрос SQL для загрузки заявок*/
    $sql = "SELECT DISTINCT * FROM insertions order by i_name;";

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
        exit;
    }
    /** Разбор массива значений  */
    echo "<select id='pp_insertion' name = 'pp_insertion'>";
    echo "<option></option>";
    while ($pp_insertions_data = $result->fetch_assoc()){
        echo "<option name='pp_insertion' value=".$pp_insertions_data['i_name'].">".$pp_insertions_data['i_name']."</option>";
    }
    echo "</select>";

    /** Закрываем соединение */
    $result->close();
    $mysqli->close();

}

/** Создание <SELECT> списка с перечнем крышек */
function load_caps($name_of_list){


        global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

        /** Создаем подключение к БД */
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

        /** Выполняем запрос SQL для загрузки заявок*/
        $sql = "SELECT DISTINCT * FROM cap_stock order by cap_name;";

        /** Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            exit;
        }
    if ($name_of_list ==''){
        /** Разбор массива значений  */
        echo "<select id='name_of_cap' name = 'name_of_cap'>";
        echo "<option></option>";
        while ($caps_data = $result->fetch_assoc()){
            echo "<option name='name_of_cap' value=".$caps_data['cap_name'].">".$caps_data['cap_name']."</option>";
        }
        echo "</select>";

        /** Закрываем соединение */
        $result->close();
        $mysqli->close();
    }else{
        /** Разбор массива значений  */
        echo "<select id=".$name_of_list." name = ".$name_of_list.">";
        echo "<option></option>";
        while ($caps_data = $result->fetch_assoc()){
            echo "<option name='name_of_cap' value=".$caps_data['cap_name'].">".$caps_data['cap_name']."</option>";
        }
        echo "</select>";

        /** Закрываем соединение */
        $result->close();
        $mysqli->close();

    }
}

/** Создание <SELECT> списка с перечнем залитых крышек */
function load_filled_caps(){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Выполняем запрос SQL для загрузки заявок*/
    $sql = "SELECT DISTINCT * FROM list_of_filled_caps;";

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
        exit;
    }


    /** Разбор массива значений  */
    echo "<select id='name_of_filled_cap' name = 'name_of_filled_cap'>";
    while ($caps_data = $result->fetch_assoc()){
        echo "<option name='name_of_filled_cap' value=".$caps_data['name_of_cap'].">".$caps_data['name_of_cap']."</option>";
    }
    echo "</select>";

    /** Закрываем соединение */
    $result->close();
    $mysqli->close();
}

/** Вывод списка операции с крышками по маркеру:  */
function load_cap_history($target){
    if ($target == 'input'){
        global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

        /** Создаем подключение к БД */
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

        /** Выполняем запрос SQL для загрузки заявок*/
       // $sql = "SELECT * FROM log;";
        $sql = "SELECT * FROM cap_log WHERE cap_action = 'IN';";


        /** Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            exit;
        }
        while ($log_data = $result->fetch_assoc()){
            echo $log_data['date_of_operation']." поступило ".$log_data['name_of_cap_field']." в количестве ".$log_data['count_of_caps']." шт"."\r\n";
        }

    }
    if ($target == 'filled'){
        global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

        /** Создаем подключение к БД */
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

        /** Выполняем запрос SQL для загрузки заявок*/
        $sql = "SELECT * FROM cap_log WHERE cap_action = 'FILL';";

        /** Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            exit;
        }
        while ($log_data = $result->fetch_assoc()){
            echo $log_data['date_of_operation']." поступило ".$log_data['name_of_cap_field']." в количестве ".$log_data['count_of_caps']." шт"."\r\n";
        }

    }
}


/** СОздание <SELECT> списка с перечнем фильтров имеющихся в БД */
function load_filters_into_select(){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

    /** Выполняем запрос SQL для загрузки заявок*/
    $sql = "SELECT DISTINCT filter FROM round_filter_structure ORDER BY filter;";

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    /** Разбор массива значений  */
    echo "<select name='analog_filter'>";
    echo "<option value=''>выбор аналога</option>";
    while ($orders_data = $result->fetch_assoc()){
        echo "<option value=".$orders_data['filter'].">".$orders_data['filter']."</option>";
    }
    echo "</select>";

    /** Закрываем соединение */
    $result->close();
    $mysqli->close();
}

/** Функция модифицирует дату, записанную в текстовом формате на заданное количество дней */
function modify_date($string_date, $modifier){
    $date = date_create($string_date);
    date_modify($date, '+'.$modifier.' day');
    return date_format($date, 'd-m-Y');
}


/** Списание фильтров в выпущенную продукцию, + списание крышек в выпущенные фильтры (новая система)
 * @param $date_of_production
 * @param $order_number
 * @param $filters
 * @param $user_id - ID пользователя (опционально)
 * @param $user_name - Имя пользователя (опционально)
 * @return bool
 */
function write_of_filters($date_of_production, $order_number, $filters, $user_id = null, $user_name = null){

    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

    // Пытаемся получить user_id и user_name из сессии если не переданы
    if ($user_id === null || $user_name === null) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if ($user_id === null && isset($_SESSION['auth_user_id'])) {
            $user_id = $_SESSION['auth_user_id'];
        }
        if ($user_name === null && isset($_SESSION['auth_full_name'])) {
            $user_name = $_SESSION['auth_full_name'];
        }
    }

    /** Создаем подключение к БД */
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
    if ($mysqli->connect_errno) {
        echo "Ошибка подключения к БД: " . $mysqli->connect_error;
        return false;
    }
    $mysqli->set_charset("utf8mb4");

    // Инициализируем таблицы если их нет
    $cap_db_init_path = dirname(__DIR__) . '/cap_db_init.php';
    if (file_exists($cap_db_init_path)) {
        require_once($cap_db_init_path);
    }

    /** Цикл для разбора значений массива со значениями "фильтер - количество" */
    foreach ($filters as $filter_record) {

        /** Получили значение {елемент масива[фильтр][количество]} */
        $filter_name = $filter_record[0];
        $filter_count = intval($filter_record[1]);

        /** Форматируем sql-запрос, "записать в БД -> дата -> заявка -> фильтер -> количство" */
        $sql = "INSERT INTO manufactured_production (date_of_production, name_of_filter, count_of_filters, name_of_order) 
                VALUES ('$date_of_production','$filter_name','$filter_count','$order_number')";

        /** Выполняем запрос. Если запрос не удачный -> exit */
        if (!$result = $mysqli->query($sql)){ 
            echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
            /** в случае неудачи функция выводит FALSЕ */
            return false;
        }

        /** СПИСАНИЕ КРЫШЕК - НОВАЯ СИСТЕМА */
        /** Получаем информацию о крышках для фильтра */
        $sql = "SELECT up_cap, down_cap FROM round_filter_structure WHERE filter = ?";
        $stmt = $mysqli->prepare($sql);
        
        if (!$stmt) {
            echo "Ошибка подготовки запроса: " . $mysqli->error;
            continue;
        }
        
        $stmt->bind_param("s", $filter_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $caps_data = $result->fetch_assoc();
        $stmt->close();

        if ($caps_data) {
            // Обрабатываем верхнюю крышку
            if (!empty($caps_data['up_cap'])) {
                $up_cap = trim($caps_data['up_cap']);
                write_off_cap($mysqli, $up_cap, $filter_count, $date_of_production, $order_number, $filter_name, $user_id, $user_name);
            }

            // Обрабатываем нижнюю крышку
            if (!empty($caps_data['down_cap'])) {
                $down_cap = trim($caps_data['down_cap']);
                write_off_cap($mysqli, $down_cap, $filter_count, $date_of_production, $order_number, $filter_name, $user_id, $user_name);
            }
        }

        // Также записываем в старый журнал для совместимости
        if (!empty($caps_data['up_cap'])) {
            $up_cap = trim($caps_data['up_cap']);
            $sql = "INSERT INTO cap_log (date_of_operation, name_of_cap_field, count_of_caps, cap_action) 
                    VALUES ('$date_of_production','$up_cap','$filter_count','OUT')
                    ON DUPLICATE KEY UPDATE count_of_caps = count_of_caps";
            $mysqli->query($sql);
        }
        if (!empty($caps_data['down_cap'])) {
            $down_cap = trim($caps_data['down_cap']);
            $sql = "INSERT INTO cap_log (date_of_operation, name_of_cap_field, count_of_caps, cap_action) 
                    VALUES ('$date_of_production','$down_cap','$filter_count','OUT')
                    ON DUPLICATE KEY UPDATE count_of_caps = count_of_caps";
            $mysqli->query($sql);
        }
    }

    /** Закрываем соединение */
    $mysqli->close();
    return true;
}

/** Вспомогательная функция для списания крышки
 * @param $mysqli - соединение с БД
 * @param $cap_name - название крышки
 * @param $quantity - количество для списания
 * @param $date - дата операции
 * @param $order_number - номер заявки
 * @param $filter_name - название фильтра
 * @param $user_id - ID пользователя
 * @param $user_name - имя пользователя
 */
function write_off_cap($mysqli, $cap_name, $quantity, $date, $order_number, $filter_name, $user_id, $user_name) {
    // Проверяем остаток
    $stmt = $mysqli->prepare("SELECT current_quantity FROM cap_stock WHERE cap_name = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $cap_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $stock_row = $result->fetch_assoc();
    $stmt->close();

    $current_qty = $stock_row ? intval($stock_row['current_quantity']) : 0;
    
    // Если крышки нет в таблице остатков, создаем запись с нулевым остатком
    if (!$stock_row) {
        $stmt = $mysqli->prepare("INSERT INTO cap_stock (cap_name, current_quantity) VALUES (?, 0)");
        if ($stmt) {
            $stmt->bind_param("s", $cap_name);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Списываем крышку (уменьшаем остаток)
    $new_qty = max(0, $current_qty - $quantity);
    $stmt = $mysqli->prepare("UPDATE cap_stock SET current_quantity = ?, last_updated = CURRENT_TIMESTAMP WHERE cap_name = ?");
    if ($stmt) {
        $stmt->bind_param("is", $new_qty, $cap_name);
        $stmt->execute();
        $stmt->close();
    }

    // Записываем движение в cap_movements
    $stmt = $mysqli->prepare("
        INSERT INTO cap_movements 
        (date, cap_name, operation_type, quantity, order_number, filter_name, production_date, user_id, user_name)
        VALUES (?, ?, 'PRODUCTION_OUT', ?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        // Типы: s=string, i=integer: date(s), cap_name(s), quantity(i), order_number(s), filter_name(s), production_date(s), user_id(i), user_name(s)
        // Всего 8 параметров: date, cap_name, quantity, order_number, filter_name, production_date, user_id, user_name
        $stmt->bind_param("ssisssis", $date, $cap_name, $quantity, $order_number, $filter_name, $date, $user_id, $user_name);
        $stmt->execute();
        $stmt->close();
    }

    // Старая таблица list_of_caps больше не используется
}

/** Занесение комплектующих. Внесение гофропакетов в БД в таблицу "лог(manufactured_parts)" и в таблицу "склад(list_of_parts)"
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
    //echo '111111';
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

    /** Разбираем результата запроса */
    while ($row = $result->fetch_assoc()){
        $count += $row['count_of_filters'];
    }

    /** Создаем массив для вывода результата*/
    $result_part_one = "#in_construction";
    $result_part_two = $count;
    $result_array = [];
    array_push($result_array,$result_part_one);
    array_push($result_array,$result_part_two);

    return $result_array;
}


/** Функция выполняет запрос к БД и возвращает количество комплектующих определенного изделия по выбранной заявке */
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

    return $count;
}

/** Функция выполняет запрос к БД и возвращает количество комплектующих определенного изделия на складе */
function count_of_manufactured_parts_in_the_storage($part) {

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
    $sql = "SELECT * FROM list_of_parts WHERE part LIKE '%$part%'";
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
        $count += $row['count'];
    }

    return $count;
}

/** Функция подсчитывает количество выпущенной продукции указанного наименования по указанной заявке,
 *возвращает количество наработанной продукции
 */
function produced_filter_count_by_order ($filter,$order){
    $result = 0;


    return $result;
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
        if (($width_of_paper_package <= 195) AND ($width_of_paper_package >= 174)){
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

    echo "</table>";
    echo "<input type='submit'>";
    echo "</form>";
}



/** Функция возвращает массив номеров заявок актуальных */
function select_actual_orders(){
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    /** Подключаемся к БД для вывода заявки по подключению №1*/
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        /** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'
            . "Номер ошибки: " . $mysqli->connect_errno . "\n"
            . "Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }

    /** Выполняем запрос SQL для загрузки заявок*/
    $sql = "SELECT DISTINCT order_number FROM orders  WHERE hide IS NULL OR hide = 0;";

    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    $temp_array = array();

    while ($row = $result->fetch_assoc()){
        /** наполняем массив для сохранения заявки */
        array_push($temp_array, $row['order_number']);
    }

    return $temp_array;
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

    $result = mysql_execute("SELECT * FROM round_filter_structure WHERE filter = '$filter'");

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
/*Array
            *ОБРАЗЕЦ*
 *
    [paper_package_name] => гофропакет SX1211
    [paper_package_height] => 60
    [paper_package_diameter] => 108
    [paper_package_ext_wireframe] => 2
    [paper_package_int_wireframe] => У2
    [paper_package_paper_width] =>
    [paper_package_fold_height] =>
    [paper_package_fold_count] =>
    [paper_package_remark] =>
    [ext_wireframe_name] =>
    [ext_wireframe_material] =>
    [int_wireframe_name] =>
    [int_wireframe_material] =>
    [up_cap]=>
    [down_cap]=>
    [pp_insertion]=>
    [prefilter_name] =>
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
    $sql = "SELECT * FROM paper_package_round WHERE p_p_name = '".$result_array['paper_package_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $paper_package_data = $result->fetch_assoc();

    //$result_array['paper_package_height'] = $paper_package_data['p_p_height'];

    if (isset($paper_package_data['p_p_diameter'])){
        $result_array['paper_package_diameter'] = $paper_package_data['p_p_diameter'];
    } else{
        $result_array['paper_package_diameter'] = '';
    }
    if (isset($paper_package_data['p_p_ext_wireframe'])){
        $result_array['paper_package_ext_wireframe'] = $paper_package_data['p_p_ext_wireframe'];
    } else{
        $result_array['paper_package_ext_wireframe'] = '';
    }
    if (isset($paper_package_data['p_p_int_wireframe'])){
        $result_array['paper_package_int_wireframe'] = $paper_package_data['p_p_int_wireframe'];
    } else{
        $result_array['paper_package_int_wireframe'] = '';
    }
    if (isset($paper_package_data['p_p_paper_width'])){
        $result_array['paper_package_paper_width'] = $paper_package_data['p_p_paper_width'];
    }else{
        $result_array['paper_package_paper_width'] = '';
    }
    if (isset($paper_package_data['p_p_fold_height'])){
        $result_array['paper_package_fold_height'] = $paper_package_data['p_p_fold_height'];
    }else{
        $result_array['paper_package_fold_height'] = '';
    }
    if (isset($paper_package_data['p_p_fold_count'])){
        $result_array['paper_package_fold_count'] = $paper_package_data['p_p_fold_count'];
    }else{
        $result_array['paper_package_fold_count'] = '';
    }
    if (isset($paper_package_data['comment'])){
        $result_array['paper_package_remark'] = $paper_package_data['comment'];
    }else{
        $result_array['paper_package_remark'] = '';
    }


//    /**КРІШКА ВЕРХНЯЯ */
//    $result_array['up_cap'] = '';
//    /** Выполняем запрос SQL */
//    $sql = "SELECT * FROM round_filter_structure WHERE filter = '".$target_filter."';";
//    /** Если запрос не удачный -> exit */
//    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
//    /** Разбор массива значений  */
//    $up_cap_data = $result->fetch_assoc();
//    if ($up_cap_data == null){
//        $result_array['up_cap'] = $up_cap_data['PU_up_cap'];
//    } else{
//        $result_array['up_cap'] = $up_cap_data['up_cap'];
//    }
//
//    /**КРІШКА НИЖНЯЯ */
//   $result_array['down_cap_cap'] = '';
//    /** Выполняем запрос SQL */
//    $sql = "SELECT * FROM round_filter_structure WHERE filter = '".$target_filter."';";
//    /** Если запрос не удачный -> exit */
//   if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
//    /** Разбор массива значений  */
//   $down_cap_data = $result->fetch_assoc();
//    if ($down_cap_data ==null){
//        //$result_array['down_cap'] = $down_cap_data['PU_down_cap'];
//        $result_array['down_cap'] = '1';
//    } else{
//        //$result_array['down_cap'] =  $down_cap_data['down_cap'];
//        $result_array['down_cap'] =  '2';
//    }


    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM round_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $filter_data = $result->fetch_assoc();
    
    // Проверяем что данные найдены
    if ($filter_data !== null) {
        if (isset($filter_data['comment'])){
            $result_array['comment'] = $filter_data['comment'];
        }else{
            $result_array['comment'] = '';
        }
        if (isset($filter_data['Diametr_outer'])){
            $result_array['Diametr_outer'] = $filter_data['Diametr_outer'];
        }else{
            $result_array['Diametr_outer'] = '';
        }
        if (isset($filter_data['Diametr_inner_1'])){
            $result_array['Diametr_inner_1'] = $filter_data['Diametr_inner_1'];
        }else{
            $result_array['Diametr_inner_1'] = '';
        }
        if (isset( $filter_data['Diametr_inner_2'])){
            $result_array['Diametr_inner_2'] = $filter_data['Diametr_inner_2'];
        }else{
            $result_array['Diametr_inner_2'] = '';
        }
        if (isset($filter_data['Height'])){
            $result_array['Height'] = $filter_data['Height'];
        }else{
            $result_array['Height'] = '';
        }
    } else {
        // Если данных нет, заполняем пустыми значениями
        $result_array['comment'] = '';
        $result_array['Diametr_outer'] = '';
        $result_array['Diametr_inner_1'] = '';
        $result_array['Diametr_inner_2'] = '';
        $result_array['Height'] = '';
    }

    //крышка нижняя
    if ($filter_data !== null) {
        if (!isset($filter_data['down_cap'])){
            $result_array['down_cap'] = $filter_data['PU_down_cap'] ?? '';
        }else{
            $result_array['down_cap'] =  $filter_data['down_cap'];
        }
    } else {
        $result_array['down_cap'] = '';
    }

//    if ($filter_data['down_cap'] == null){
//        $result_array['down_cap'] = $filter_data['PU_down_cap'];
//    } else{
//        $result_array['down_cap'] =  $filter_data['down_cap'];
//    }

    //крышка верхняя
    if ($filter_data !== null) {
        if(!isset($filter_data['up_cap'])){
            $result_array['up_cap'] = $filter_data['PU_up_cap'] ?? '';
        }else{
            $result_array['up_cap'] =  $filter_data['up_cap'];
        }
    } else {
        $result_array['up_cap'] = '';
    }

//    if ($filter_data['up_cap'] == null){
//        $result_array['up_cap'] = $filter_data['PU_up_cap'];
//    } else{
//        $result_array['up_cap'] =  $filter_data['up_cap'];
//    }

    if ($filter_data !== null && isset($filter_data['PU_up_cap'])){
        $result_array['up_cap_PU'] = $filter_data['PU_up_cap'];
    }else{
        $result_array['up_cap_PU'] = '';
    }
    if ($filter_data !== null && isset($filter_data['PU_down_cap'])){
        $result_array['down_cap_PU'] = $filter_data['PU_down_cap'];
    }else{
        $result_array['down_cap_PU'] = '';
    }

    /** КАРКАС Наружній*/
    $result_array['paper_package_ext_wireframe_name'] =$target_filter.' наружный каркас';
    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM wireframe_round WHERE w_name = '".$result_array['paper_package_ext_wireframe_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $wireframe_data = $result->fetch_assoc();
    if ($wireframe_data == null) {
        $result_array['paper_package_ext_wireframe_name']=null;
        $result_array['paper_package_ext_wireframe_material'] = null;
    } else {
        $result_array['paper_package_ext_wireframe_material'] = $wireframe_data['w_material'];
    }

    /** КАРКАС внутренний*/
    $result_array['paper_package_int_wireframe_name'] =$target_filter.' внутренний каркас';
    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM wireframe_round WHERE w_name = '".$result_array['paper_package_int_wireframe_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $wireframe_data = $result->fetch_assoc();
    if ($wireframe_data == null) {
        $result_array['paper_package_int_wireframe_name']=null;
        $result_array['paper_package_int_wireframe_material'] = null;
    } else {
        $result_array['paper_package_int_wireframe_material'] = $wireframe_data['w_material'];
    }


    /** ПРЕДФИЛЬТР */
    $result_array['prefilter_name'] = 'предфильтр '.$target_filter;

    /** Выполняем запрос SQL */
    $sql = "SELECT * FROM prefilter_round WHERE pf_name = '".$result_array['prefilter_name']."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $prefilter_data = $result->fetch_assoc();

    if ($prefilter_data == null){
        $result_array['prefilter_name'] = '';
    } else {
        $result_array['prefilter_name'] =  $prefilter_data['pf_name'];
    }



    /** РР ВСТАВКА */
    $result_array['pp_insertion'] = 'РР вставка '.$target_filter;
    /** Выполняем запрос SQL */
        $sql = "SELECT * FROM insertions WHERE i_name = 'PP_вставка_".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */

    $pp_insertion_data = $result->fetch_assoc();
    if (isset($pp_insertion_data)){
        $result_array['pp_insertion'] = $pp_insertion_data['i_name'];
    }else{
        $result_array['pp_insertion'] =  'нет';
    }
    

    /** Упаковка */
    $result_array['packing'] = '';
    /** Выполняем запрос SQL */
    $sql = "SELECT packing FROM round_filter_structure WHERE filter = '".$target_filter."';";
    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }
    /** Разбор массива значений  */
    $packing_data = $result->fetch_assoc();
    if (isset($packing_data['packing'])){
        $result_array['packing'] = $packing_data['packing'];
    }else{
        $result_array['packing'] = '';
    }

    if ($packing_data == null){
        $result_array['packing'] = 'нет';
    } else {
        $result_array['packing'] =  $packing_data['packing'];
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
    $sql = "SELECT orders.filter, round_filter_structure.wireframe, round_filter_structure.filter, orders.count ".
        "FROM orders, round_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = round_filter_structure.filter ".
        "AND round_filter_structure.wireframe!='';";

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

/** Расчет  необходимого количества каркасов для выполнения запявки*/
function component_analysis_caps($order_number){



    // запрос для выборки необходимых каркасов для выполнения заявки
    $sql = "SELECT orders.filter, round_filter_structure.up_cap,round_filter_structure.down_cap, round_filter_structure.filter, orders.count ".
        "FROM orders, round_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = round_filter_structure.filter ".
        "AND round_filter_structure.up_cap!='';";

    $result = mysql_execute($sql);

    $sorted_array = array();
    //$temp_array = [];

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($result as $value){



        $sorted_array[] = $value['up_cap'];
        $sorted_array[] = $value['count'];

        //array_push($sorted_array, ['cap' => $value['up_cap'], 'count' => $value['count']]);
        $i++;


        $sorted_array[] = $value['down_cap'];
        $sorted_array[] = $value['count'];

        //array_push($sorted_array, ['cap' => $value['down_cap'], 'count'=> $value['count']]);
        $i++;
    }


    //print_r($sorted_array);

    // Инициализируем ассоциативный массив для хранения сумм.
    $result = [];

    for ($i = 0; $i < count($sorted_array); $i += 2) {
        $name = $sorted_array[$i];
        $quantity = $sorted_array[$i + 1];

        // Суммируем значения, если название уже встречалось.
        if (isset($result[$name])) {
            $result[$name] += $quantity;
        } else {
            $result[$name] = $quantity;
        }
    }
    echo "<br>";
    //print_r($result);

    // шапка таблицы
    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку комплектующих для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    $i = 1;
    foreach ($result as $name => $quantity){
        echo '<tr><td>'.$i.'</td><td>'.$name.'</td><td>'.$quantity.'</td><td><input type="text"></td>';
        $i++;
    }


    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Дата составления заявки:</td><td colspan="2">'.date('d.m.y ').'</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td colspan="2">Заявку составил:</td><td colspan="2"><input type="text"></td></tr>';
    echo '</table>';

}

/** Расчет  необходимого количества вставок полипропиленовіх для выполнения заявки*/
function component_analysis_insertions($order_number){

    // запрос для выборки необходимых каркасов для выполнения заявки
    $sql = "SELECT orders.filter, round_filter_structure.plastic_insertion, round_filter_structure.filter, orders.count ".
        "FROM orders, round_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = round_filter_structure.filter ".
        "AND round_filter_structure.plastic_insertion!='';";

    $result = mysql_execute($sql);

    print_r($result);

    $sorted_array = array();
    //$temp_array = [];

    $i=1;// счетчик циклов для отображения в таблице порядкового номера
    foreach ($result as $value){
                $sorted_array[] = $value['plastic_insertion'];
                $sorted_array[] = $value['count'];
                echo $value;
              $i++;

    }


    //print_r($sorted_array);

    // Инициализируем ассоциативный массив для хранения сумм.
    $result = [];

    for ($i = 0; $i < count($sorted_array); $i += 2) {
        $name = $sorted_array[$i];
        $quantity = $sorted_array[$i + 1];

        // Суммируем значения, если название уже встречалось.
        if (isset($result[$name])) {
            $result[$name] += $quantity;
        } else {
            $result[$name] = $quantity;
        }
    }
    echo "<br>";
    //print_r($result);

    // шапка таблицы
    echo '<table style=" border-collapse: collapse;">';
    echo '<tr><td colspan="4"><h3 style="font-family: Calibri; size: 20px;text-align: center">Заявка</h3></td></tr>';
    echo '<tr><td colspan="4">на поставку комплектующих для: У2</td></tr>';
    echo '<tr><td colspan="4"><pre> </pre></td></tr>';
    echo '<tr><td>№п/п</td><td>Комплектующее</td><td>Кол-во</td><td>Дата поставки</td></tr>';

    $i = 1;
    foreach ($result as $name => $quantity){
        echo '<tr><td>'.$i.'</td><td>'.$name.'</td><td>'.$quantity.'</td><td><input type="text"></td>';
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
    $sql = "SELECT orders.filter, round_filter_structure.prefilter, round_filter_structure.filter, orders.count ".
        "FROM orders, round_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = round_filter_structure.filter ".
        "AND round_filter_structure.prefilter!='';";

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
    $sql = "SELECT orders.filter, round_filter_structure.paper_package, round_filter_structure.filter, orders.count ".
        "FROM orders, round_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = round_filter_structure.filter ".
        "AND round_filter_structure.paper_package!='';";

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
    $sql = "SELECT orders.filter, round_filter_structure.paper_package, round_filter_structure.g_box, orders.count ".
        "FROM orders, round_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = round_filter_structure.filter ".
        "AND round_filter_structure.g_box!='';";

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
    $sql = "SELECT orders.filter, round_filter_structure.box, orders.count ".
        "FROM orders, round_filter_structure ".
        "WHERE orders.order_number='$order_number' ".
        "AND orders.filter = round_filter_structure.filter ".
        "AND round_filter_structure.box!='';";

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
