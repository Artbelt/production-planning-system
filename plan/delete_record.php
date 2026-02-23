<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once ('style/table_1.txt');
$date_of_production = $_POST['date_of_production'];
$name_of_filter =  $_POST['name_of_filter'];
$count_of_filters =  $_POST['count_of_filters'];
$name_of_order =  $_POST['name_of_order'];

require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');
$stmt = $pdo->prepare("DELETE FROM manufactured_production WHERE date_of_production = ? AND name_of_filter = ? AND count_of_filters = ? AND name_of_order = ?");
$stmt->execute([$date_of_production, $name_of_filter, $count_of_filters, $name_of_order]);

echo 'Запись ['.$date_of_production.' : '.$name_of_filter.' : '.$count_of_filters.' : '.$name_of_order.'] удалена из БД<p>';


?>

<button onclick="window.close();">Закрыть окно</button>
