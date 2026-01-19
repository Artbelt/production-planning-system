<?php
/** СТраница отображает заявки в которых присутствует запрашиваемый фильтр */

/** ПОдключаем функции */
require_once('settings.php') ;
require_once ('tools/tools.php');

$filter = $_POST['filter'];
$show_all = isset($_POST['show_all']) && $_POST['show_all'] == '1';

echo "<h4>Информация по наличию фильтра " . htmlspecialchars($filter) . " в заявках</h4><p>";

/** Создаем подключение к БД */
$mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
if ($mysqli->connect_errno) {
    echo "Ошибка подключения к БД: " . $mysqli->connect_error;
    exit;
}

// Вычисляем дату год назад
$one_year_ago = date('Y-m-d', strtotime('-1 year'));

/** Выполняем запрос SQL с фильтрацией по дате */
if ($show_all) {
    // Показываем все заявки
    $sql = "SELECT DISTINCT o.order_number 
            FROM orders o
            WHERE o.filter = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $filter);
} else {
    // Показываем только заявки за последний год
    // Используем максимальную дату из build_plan или manufactured_corrugated_packages для определения даты заявки
    $sql = "SELECT DISTINCT o.order_number 
            FROM orders o
            LEFT JOIN (
                SELECT order_number, MAX(plan_date) as max_date 
                FROM build_plan 
                GROUP BY order_number
            ) bp ON bp.order_number = o.order_number
            LEFT JOIN (
                SELECT order_number, MAX(date_of_production) as max_date 
                FROM manufactured_corrugated_packages 
                GROUP BY order_number
            ) mcp ON mcp.order_number = o.order_number
            WHERE o.filter = ?
            AND (
                (bp.max_date IS NOT NULL AND bp.max_date >= ?) 
                OR (mcp.max_date IS NOT NULL AND mcp.max_date >= ?)
            )";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('sss', $filter, $one_year_ago, $one_year_ago);
}

/** Если запрос не удачный -> exit */
if (!$stmt->execute()) {
    echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; 
    exit;
}

$result = $stmt->get_result();

/** Разбор массива значений  */
echo "";
if ($show_all) {
    echo "Заявки, в которых присутствует эта позиция (все):<br>";
} else {
    echo "Заявки, в которых присутствует эта позиция (за последний год):<br>";
}

echo '<form action="show_order.php" method="post">';
$has_orders = false;
while ($orders_data = $result->fetch_assoc()){
    $has_orders = true;
    $order_number = htmlspecialchars($orders_data['order_number'], ENT_QUOTES, 'UTF-8');
    echo "<input type='submit' name='order_number' value='".$order_number."' style='margin: 5px; padding: 5px 10px; cursor: pointer;'>";

    /** Выполняем запрос о количестве заказанных фильтров */
    $sql_count = "SELECT count FROM orders WHERE order_number=? AND filter =?";
    $stmt_count = $mysqli->prepare($sql_count);
    $stmt_count->bind_param('ss', $orders_data['order_number'], $filter);
    
    /** Если запрос не удачный -> exit */
    if (!$stmt_count->execute()){ 
        echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql_count . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; 
        continue;
    }
    
    $result_count = $stmt_count->get_result();
    $show_count = $result_count->fetch_assoc();
    $stmt_count->close();

    echo " заказано: ".($show_count['count'] ?? 0)." изготовлено: ".(int)select_produced_filters_by_order($filter,$orders_data['order_number'])[1]."<p>";
}

if (!$has_orders) {
    echo "<div style='color: #666; padding: 10px;'>Заявки не найдены.</div>";
}

echo '</form>';

/** Закрываем соединение */
$result->close();
$stmt->close();
$mysqli->close();


