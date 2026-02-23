<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once ('style/table_1.txt');
$date_of_production = $_POST['date_of_production'];
$name_of_filter =  $_POST['name_of_filter'];
$count_of_filters =  $_POST['count_of_filters'];
$name_of_order =  $_POST['name_of_order'];
$new_date_of_production = $_POST['new_date_of_production'];
$new_count_of_filters =  $_POST['new_count_of_filters'];
$new_name_of_order =  $_POST['selected_order'];

require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$stmt = $pdo->prepare("UPDATE manufactured_production SET date_of_production = ?, count_of_filters = ?, name_of_order = ? WHERE date_of_production = ? AND name_of_filter = ? AND count_of_filters = ? AND name_of_order = ?");
$stmt->execute([$new_date_of_production, $new_count_of_filters, $new_name_of_order, $date_of_production, $name_of_filter, $count_of_filters, $name_of_order]);

echo 'Запись ['.$date_of_production.' : '.$name_of_filter.' : '.$count_of_filters.' : '.$name_of_order.'] изменена на:<br>';
echo 'Запись ['.$new_date_of_production.' : '.$name_of_filter.' : '.$new_count_of_filters.' : '.$new_name_of_order.']<br>';

?>

<button onclick="window.close();">Закрыть окно</button>
