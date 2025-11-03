<?php /** [DEL]show_order_AJAX.php  файл отображает выбранную заявку в режиме просмотра*/

require_once('tools/tools.php');
require_once('settings.php');
require_once('Planned_order.php');

global $main_roll_length;
global $max_gap;
global $min_gap;
global $width_of_main_roll;


/** Номер заявки которую надо нарисовать */
$order_number = $_POST['order_number'];

/** Отображаем позиции заявки */
show_order($order_number);                                                                             //---------SERVICE_FUNCTION_MUST_BE_HIDE

/** Создаем объект планирования заявки */
$initial_order = new Planned_order;

/** Задаем ему имя */
$initial_order->set_name($order_number);

/** Задаем ему заявку */
$initial_order->set_order(get_order($order_number));

/** Проверяем на наличие фильтров в БД */
$initial_order->check_for_new_filters();

/** получаем данные для расчета раскроя (параметры гофропакетов) */
$initial_order->get_data_for_cutting($main_roll_length);

/** инициализируем массив для формирования раскроев */
$initial_order->cut_array_and_half_cut_array_init();

/** соритруем cut_array по высоте шторы */
$initial_order->sort_cut_array();

/** соритруем cut_array по высоте шторы */
$initial_order->sort_half_cut_array();

/** отображаем заявку с загруженными данными по г/пакетам*/
$initial_order->show_order($main_roll_length);                                                                           //--------SERVICE_FUNCTION_MUST_BE_HIDE

/** отображаем cut_array массив подготовленный для раскроя*/
$initial_order->show_cut_array();                                                                       //---------SERVICE_FUNCTION_MUST_BE_HIDE

/** отображаем half_cut_array массив подготовленный для раскроя*/
$initial_order->show_half_cut_array();

/** ---------------------------------------------------------------- */
/** Делаем раскрой для целых бухт*/
$initial_order->cut_execute($width_of_main_roll, $max_gap, $min_gap);

/** отображаем сформированные рулоны */
$initial_order->show_completed_rolls();

/** сортируем позиции не вошедшие в раскрой по высоте валков */
$initial_order->sort_not_completed_rolls_array();

/** отображаем не вошедшие в раскрой рулоны */
$initial_order->show_not_completed_rolls();
/** --------------------------------------------------------------- */

/** Делаем раскрой для половинок бухт */
$initial_order->cut_execute_half_array($width_of_main_roll, $max_gap, $min_gap);

/** отображаем сформированные рулоны */
$initial_order->show_half_completed_rolls();

/** сортируем позиции не вошедшие в раскрой по высоте валков */
$initial_order->sort_not_completed_rolls_array();

/** отображаем не вошедшие в раскрой рулоны */
$initial_order->show_not_completed_rolls();




echo "<form action='prepared_order_for_spare_parts.php' method='post' target='_blank'>";
echo "<input type='submit' value='Подготовить заявку на поставку гофропакетов'>";
echo "<input type='hidden' name='order' value='".$order_number."'>";
echo "<input type='hidden' name='part' value='paper_package'><br>";
echo "</form>";

echo "<form action='prepared_order_for_spare_parts.php' method='post' target='_blank'>";
echo "<input type='submit' value='Подготовить заявку на поставку каркасов'>";
echo "<input type='hidden' name='order' value='".$order_number."'>";
echo "<input type='hidden' name='part' value='wireframe'><br>";
echo "</form>";

echo "<form action='prepared_order_for_spare_parts.php' method='post' target='_blank'>";
echo "<input type='submit' value='Подготовить заявку на поставку предфильтров'>";
echo "<input type='hidden' name='order' value='".$order_number."'>";
echo "<input type='hidden' name='part' value='prefilter'><br>";
echo "</form>";

echo "<form action='prepared_order_for_spare_parts.php' method='post' target='_blank'>";
echo "<input type='submit' value='Подготовить заявку на поставку коробок индивидуальных'>";
echo "<input type='hidden' name='order' value='".$order_number."'>";
echo "<input type='hidden' name='part' value='box'><br>";
echo "</form>";

echo "<form action='prepared_order_for_spare_parts.php' method='post' target='_blank'>";
echo "<input type='submit' value='Подготовить заявку на поставку ящиков групповых'>";
echo "<input type='hidden' name='order' value='".$order_number."'>";
echo "<input type='hidden' name='part' value='g_box'><br>";
echo "</form>";


