<?php

require_once('tools/tools.php');
require_once('settings.php');
require_once('Planned_order.php');


if (isset($_POST)){

    print_r_my($_POST);

    /** Читаем данные переданные в пост_запросе в массив */

    /** @var  $separated_order массив позиций, которые надо кроить */
    $separated_order = array();
    /** @var  $ignored_positions массив позиций, которые не надо кроить*/
    $ignored_positions = array();

/* Образец данных, переданых в POST
    [filter_1] => SX1211
    [count_1] => 1000
    [width_1] => 123.5
    [chck_box_1] => */

    for ($x = 0; $x <= (count($_POST) / 4); $x = $x + 4){
        //echo $x;
        /**  временный массив для сбора позиций */
        $temp_array = array();
        array_push($temp_array,$_POST['filter_'.($x+1)]);
        array_push($temp_array,$_POST['count_'.($x+1)]);
        array_push($temp_array,$_POST['width_'.($x+1)]);
        /** Если нажато галочка игнорировать: */
        if ($_POST['chck_box_'.($x+3)] == 'checked'){
            /** Заносим в массив с игнорируемымыи позициями */
            array_push($ignored_positions, $temp_array);
        } else {
            /** Заносим в массив для раскроя  */
            array_push($separated_order, $temp_array);
        }
    }
    echo "<p>Позиции для раскроя<p>";
    print_r_my($separated_order);
    echo "<p>Игнорируемые позиции<p>";
    print_r_my($ignored_positions);
}
?>

