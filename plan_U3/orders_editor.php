<?php
/** подключение фалйа настроек */
require_once('settings.php') ;
require_once('tools/tools.php') ;
global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

$order_count = count($_POST['order_name']);
echo 'выбраны'.$order_count.' заявки для объединения';

for ($x = 0; $x < $order_count; $x++){
    echo '<br>';
    echo $_POST['order_name'][$x];

}

/** Даем имя объединенной заявке */
$combined_order_name = '[O]|';

/** @var  $combined_order  массив объединенной заявки*/
$combined_order =[];

/** СОхдаем имя объединенной заявки */
for ($x=0;$x<count($_POST['order_name']);$x++){
    $combined_order_name = $combined_order_name.$_POST['order_name'][$x].'|';
}

echo '<p>Заявке присвоено имя: '.$combined_order_name.'<br>';

/** Записываем элементы заявок под новым именем */
for ($x=0;$x<count($_POST['order_name']);$x++) {

    $sql = "SELECT * FROM orders WHERE order_number ='" . $_POST['order_name'][$x] . "';";

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)) {echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";exit; }

   while ($order_data = $result->fetch_assoc()) {
       array_push($combined_order,$order_data);
   }

}

/** запись позиций заявки в таблицу под новым именем */
for ($z = 0; $z<count($combined_order); $z++){

    $sql1 = "INSERT INTO orders VALUES ("."'"
        . $combined_order_name . "',"."'"
        . $combined_order[$z]['workshop'] . "',"."'"
        . $combined_order[$z]['filter'] . "',"."'"
        . $combined_order[$z]['count'] . "',"."'"
        . $combined_order[$z]['marking']. "',"."'"
        . $combined_order[$z]['personal_packaging']. "',"."'"
        . $combined_order[$z]['personal_label'] . "',"."'"
        . $combined_order[$z]['group_packaging'] . "',"."'"
        . $combined_order[$z]['packaging_rate'] . "',"."'"
        . $combined_order[$z]['group_label'] . "',"."'"
        . $combined_order[$z]['remark'] . "',"."'"
        . $combined_order[$z]['hide']. "')";
    if (!$result1 = $mysqli->query($sql1)) {echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql1 . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }

}

/** Выполняем запрос*/

/** Закрываем соединение */
$result->close();
$mysqli->close();

?>
<button onclick="window.close();">Закрыть окно</button>
