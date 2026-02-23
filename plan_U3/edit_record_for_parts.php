<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once ('style/table_1.txt');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

$date_of_production = $_POST['date_of_production'];
$name_of_parts =  $_POST['name_of_parts'];
$count_of_parts =  $_POST['count_of_parts'];
$name_of_order =  $_POST['name_of_order'];
$new_date_of_production = $_POST['new_date_of_production'];
$new_count_of_parts =  $_POST['new_count_of_parts'];
$new_name_of_order =  $_POST['selected_order'];

$stmt = $pdo->prepare("UPDATE manufactured_parts SET date_of_production = ?, count_of_parts = ?, name_of_order = ? WHERE date_of_production = ? AND name_of_parts = ? AND count_of_parts = ? AND name_of_order = ?");
if (!$stmt->execute([$new_date_of_production, $new_count_of_parts, $new_name_of_order, $date_of_production, $name_of_parts, $count_of_parts, $name_of_order])) {
    echo "Ошибка обновления";
    exit;
}

echo 'Запись ['.$date_of_production.' : '.$name_of_parts.' : '.$count_of_parts.' : '.$name_of_order.'] изменена на:<br>';
echo 'Запись ['.$new_date_of_production.' : '.$name_of_parts.' : '.$new_count_of_parts.' : '.$new_name_of_order.']<br>';

?>

<button onclick="window.close();">Закрыть окно</button>
