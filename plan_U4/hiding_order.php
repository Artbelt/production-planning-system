<?php

require_once('tools/tools.php');
require_once ('style/table.txt');

if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$mysqli = new mysqli(defined('DB_HOST') ? DB_HOST : '127.0.0.1', defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', 'plan_u4');

$order = $_POST['order_number'];

echo "Заявка отправлена в архив <p>";

/** Выполняем запрос SQL для загрузки заявок*/

$sql = "UPDATE orders SET hide = 1 WHERE order_number = '$order'";
if (!$result = $mysqli->query($sql)){
    echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"
        ."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
    exit;
}

echo "<button class='a' onclick='window.close();' style='cursor: pointer;'>Закрыть страницу</button>";