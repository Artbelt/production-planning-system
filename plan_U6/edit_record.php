<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once ('style/table_1.txt');
global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

$date_of_production = $_POST['date_of_production'];
$name_of_filter =  $_POST['name_of_filter'];
$count_of_filters =  $_POST['count_of_filters'];
$name_of_order =  $_POST['name_of_order'];
$new_date_of_production = $_POST['new_date_of_production'];
$new_count_of_filters =  $_POST['new_count_of_filters'];
$new_name_of_order =  $_POST['selected_order'];

/** Создаем подключение к БД */
$mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

/** Выполняем запрос SQL для загрузки заявок*/
$sql = "UPDATE manufactured_production
                                    SET date_of_production ='".$new_date_of_production."', 
                                         count_of_filters = '".$new_count_of_filters."', 
                                         name_of_order = '".$new_name_of_order."'
 WHERE 
                                        date_of_production ='".$date_of_production."' 
                                        AND name_of_filter = '".$name_of_filter."'
                                        AND count_of_filters = '".$count_of_filters."' 
                                        AND name_of_order = '".$name_of_order."';";

/** Если запрос не удачный -> exit */
if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
    exit;
}

echo 'Запись ['.$date_of_production.' : '.$name_of_filter.' : '.$count_of_filters.' : '.$name_of_order.'] изменена на:<br>';
echo 'Запись ['.$new_date_of_production.' : '.$name_of_filter.' : '.$new_count_of_filters.' : '.$new_name_of_order.']<br>';

?>

<button onclick="window.close();">Закрыть окно</button>
