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
global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
/** Подключаемся к БД для вывода заявки */
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {   /** Если не получилось подключиться */  echo 'Возникла проблема на сайте'. "Номер ошибки: " . $mysqli->connect_errno . "\n" . "Ошибка: " . $mysqli->connect_error . "\n"; exit;}

/** Выполняем запрос SQL */
$sql = "INSERT INTO work_plan (name, plan, status, start_date) VALUES('".$order_number."','".$plan."','".$status."','".$start_date."')";
if (!$result = $mysqli->query($sql)) {    echo "Ошибка: Наш запрос не удался и вот почему: \n". "Запрос: " . $sql . "\n". "Номер ошибки: " . $mysqli->errno . "\n" . "Ошибка: " . $mysqli->error . "\n";exit;}


echo "План сохранен";
//echo "<p>order_number=".$order_number;
