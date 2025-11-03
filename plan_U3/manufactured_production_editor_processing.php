<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once ('style/table_1.txt');
global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

//echo $_POST['date'];
//echo $_POST['reason'];


$reason = $_POST['reason']; // причина 1 -> вызов функций выбора продукции по дате
$date = $_POST['date'];



/** Создаем подключение к БД */
$mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

/** Выполняем запрос SQL для загрузки заявок*/
$sql = "SELECT * FROM manufactured_production WHERE date_of_production ='".$date."';";

/** Если запрос не удачный -> exit */
if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
    exit;
}

//создаем отдельно формы для обработки полей в таблице, каждая строка будет относиться к отдельной форме
$row_count = mysqli_num_rows($result);
for ($x = 0; $x < $row_count; $x++){
    echo '<form id="form_'.$x.'" action="record_editor.php" method="post" target="_blank"></form>';
}

<<<HTML
input[type=text]:not(:focus) {
  border: 0;
}
HTML;


/** Создаем шапку таблицы */
echo "<table  id='main_table' border='1' style='font-size: 13px'><tr><td> Дата производства </td><td> Количество </td><td> Фильтр </td><td> Заявка </td> <td> Действие </td>";

//счетчик
$a=0;
while ($prodused_data = $result->fetch_assoc()){

    echo '<tr><td>';
    echo "<input type = text name ='date_of_production' form='form_".$a."'value ='".$prodused_data['date_of_production']."' size='10' readonly></input>";
    echo '</td><td>';
    echo "<input type = text name ='name_of_filter' form='form_".$a."' value ='".$prodused_data['name_of_filter']."'size='12' readonly></input>";
    echo '</td><td>';
    echo "<input type = text name ='count_of_filters' form='form_".$a."' value ='".$prodused_data['count_of_filters']."'size='3' readonly></input>";
    echo '</td><td>';
    echo "<input type = text name ='name_of_order' form='form_".$a."' value ='".$prodused_data['name_of_order']."'size='7' readonly></input>";
    echo '</td><td>';
    echo '<input type="submit"  form="form_'.$a.'" value="Редактировать" />';
    echo '</td></tr>';
    $a++;


}
echo '</table>';

