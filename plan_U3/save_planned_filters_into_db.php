<?php
/** save_planned_filters_into_db.php  в данном файле производится добавление в БД распланированной продукции */

/** Подключаем инструменты */
require_once ('tools/tools.php');


//echo "Сохранение...<p>";
/**  план  */

$plan = $_POST['JSON_send_array'];

/**  Номер заявки  */
//$order_number = $_POST['order_number'];
$order_number = $_POST['order_number'];

/** @var  $status - актуальное состояние плана (метка какой план актуальный из сохраненных) */
$status = 0;

/** @var  $start_date - дата начала плана */
$start_date = $_POST['start_date'];
//$start_date ='01.01.2020';

/** Обработка массива фильтров. Запись их в БД  */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$st = $pdo->prepare("INSERT INTO work_plan (name, plan, status, start_date) VALUES (?, ?, ?, ?)");
if (!$st->execute([$order_number, $plan, (string)$status, $start_date])) {
    echo "Ошибка сохранения плана";
    exit;
}


echo "План сохранен";
//echo "<p>order_number=".$order_number;
