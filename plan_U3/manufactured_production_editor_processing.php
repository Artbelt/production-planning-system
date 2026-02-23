<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once ('style/table_1.txt');

//echo $_POST['date'];
//echo $_POST['reason'];


$reason = $_POST['reason']; // причина 1 -> вызов функций выбора продукции по дате
$date = $_POST['date'];



/** Создаем подключение к БД */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$stmt = $pdo->prepare("SELECT * FROM manufactured_production WHERE date_of_production = ?");
$stmt->execute([$date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$row_count = count($rows);
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
foreach ($rows as $prodused_data) {

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

