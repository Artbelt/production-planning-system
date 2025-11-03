<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once ('style/table_1.txt');

global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

$date_of_production = $_POST['date_of_production'];
$name_of_filter = $_POST['name_of_filter'];
$count_of_filters = $_POST['count_of_filters'];
$name_of_order = $_POST['name_of_order'];

////получаем статус возможности редактирования
if (is_edit_access_granted()){
    $disabled = '';
    echo '<div id = "alert_div_1" style="width: 200; height: 20; color: forestgreen; background-color: lightgreen; text-align: center;"> Редактирование доступно</div><p>';
}else{
    $disabled = 'disabled';
    echo '<div id = "alert_div_2" style="width: 200; height: 20; color: white; background-color: red; text-align: center;"> Редактирование не доступно</div><p>';
}

/** Таблица удаления */
echo '<form id="delete_form" action="delete_record.php" method="post">';
echo "<table  id='main_table' border='1' style='font-size: 13px'><tr><td width='180' style='color: red'><center><b>УДАЛЕНИЕ ЗАПИСИ</b></center></td><td width='120''> Дата производства </td><td width='120'> Фильтр </td><td> Количество </td><td width='120'> Заявка </td><td>Действие</td>";
echo '<tr>';
echo '<td>Текущие значение</td>';
echo '<td> <input type="text" form="delete_form" name="date_of_production" value="'.$date_of_production.'" size = "12" readonly /></td>';
echo '<td> <input type="text" form="delete_form" name="name_of_filter" value="'.$name_of_filter.'" size="12" readonly /></td>';
echo '<td><input type="text" form="delete_form" name="count_of_filters" value="'.$count_of_filters.'" size="3" readonly/></td>';
echo '<td><input type="text" form="delete_form" name="name_of_order" value="'.$name_of_order.'" size="11" /></td>';
echo '<td><button '.$disabled.'>Удаление текущей записи</button></td>';
echo '</tr>';
echo '</table><p>';
echo '</form>';

/** Таблица редактирования */
echo '<form id="edit_form" action="edit_record.php" method="post">';
echo "<table  id='main_table' border='1' style='font-size: 13px'><tr><td width='180' style='color: blue'>РЕДАКТИРОВАНИЕ ЗАПИСИ</td><td width='120'> Дата производства </td><td width='120'> Фильтр </td><td> Количество </td><td width='120'> Заявка </td><td>Действие</td>";
echo '<tr>';
echo '<td>Текущие значение</td>';
echo '<td> <input type="text" form="edit_form" name="date_of_production" value="'.$date_of_production.'" size = "12" readonly /></td>';
echo '<td> <input type="text" form="edit_form" name="name_of_filter" value="'.$name_of_filter.'" size="12" readonly /></td>';
echo '<td><input type="text" form="edit_form" name="count_of_filters" value="'.$count_of_filters.'" size="3" readonly /></td>';
echo '<td><input type="text" form="edit_form" name="name_of_order" value="'.$name_of_order.'" size="11" /></td>';
echo '<td></td>';
echo '</tr>';
echo '<tr>';
echo '<td>Новые значения</td>';
echo '<td> <input type="text" form="edit_form" name="new_date_of_production" value="'.$date_of_production.'" size = "12"  /></td>';
echo '<td>'.$name_of_filter.'</td>';
echo '<td><input type="text" form="edit_form" name="new_count_of_filters" value="'.$count_of_filters.'" size="3"   /></td>';
echo '<td>';
load_orders(0,$name_of_order,'edit_form');
echo '</td>';
echo '<td><button '.$disabled.'>Изменить текущую запись</button></td>';
echo '</tr>';
echo '</table>';
echo '</form>';
?>

<p>
    <button onclick="window.close();">Закрыть окно</button>
