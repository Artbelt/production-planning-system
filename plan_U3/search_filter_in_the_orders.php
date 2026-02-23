<?php
/** СТраница отображает заявки в которых присутствует запрашиваемый фильтр */

/** ПОдключаем функции */
require_once('settings.php') ;
require_once ('tools/tools.php');

$filter =$_POST['filter'];

echo "<h4>Информация по наличию фильтра " . $filter . " в заявках</h4><p>";

/** Создаем подключение к БД */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$stmt = $pdo->prepare("SELECT order_number FROM orders WHERE filter = ?");
$stmt->execute([$filter]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Заявки, в которых присутствует эта позиция:<br>";
echo '<form action="show_order.php" method="post">';
$st_count = $pdo->prepare("SELECT count FROM orders WHERE order_number = ? AND filter = ?");
foreach ($rows as $orders_data) {
    echo "<input type='submit' name='order_number' value=".htmlspecialchars($orders_data['order_number'])." style=\"height: 20px; width: 220px\">";
    $st_count->execute([$orders_data['order_number'], $filter]);
    $show_count = $st_count->fetch(PDO::FETCH_ASSOC);
    echo " заказано: ".($show_count['count'] ?? 0)." изготовлено: ".(int)select_produced_filters_by_order($filter,$orders_data['order_number'])[1]."<p>";
}
echo '</form>';


