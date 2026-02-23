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

$stmt = $pdo->prepare("DELETE FROM manufactured_parts WHERE date_of_production = ? AND name_of_parts = ? AND count_of_parts = ? AND name_of_order = ?");
if (!$stmt->execute([$date_of_production, $name_of_parts, $count_of_parts, $name_of_order])) {
    echo "Ошибка удаления";
    exit;
}

echo 'Запись ['.$date_of_production.' : '.$name_of_parts.' : '.$count_of_parts.' : '.$name_of_order.'] удалена из БД<p>';


?>

<button onclick="window.close();">Закрыть окно</button>
