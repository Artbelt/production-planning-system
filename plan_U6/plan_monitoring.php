<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;

require_once ('style/table_1.txt');

global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

/** Создаем подключение к БД */
$mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

/** @var  $plan_name  идентификатор плана*/
$plan_name = $_POST['selected_plan'];
?>

    <style>
        table {
            /*width: 100%;  Ширина таблицы */
            border-collapse: collapse; /* Убираем двойные линии между ячейками */
        }
        td, th {
            padding: 3px; /* Поля вокруг содержимого таблицы */
            border: 1px solid #333; /* Параметры рамки */
        }
        tr:hover {
            background: #65994c; /* Цвет фона при наведении */
            color: #fff; /* Цвет текста при наведении */
        }
    </style>


    <script>
        function CallPrint() {
            var time = new Date().toLocaleDateString()
            var newWindow = window.open();
            newWindow.document.write('<title>План производства</title>')
            newWindow.document.write('<style> table {border-collapse: collapse;}{ td, th {padding: 3px;border: 1px solid #333;}tr:hover {background: #65994c;color: #fff;}</style>')
            newWindow.document.write(document.getElementById("print-content").innerHTML);
            newWindow.document.write('<p>Задание составил:_______________');
            newWindow.document.write('<p>');
            newWindow.document.write('<p>');
            newWindow.document.write('<p>Дата создания: ');
            newWindow.document.write(time);

        }
    </script>

<div id="print-content">

<?php

echo "ЗАПЛАНИРОВАННЫЙ ВЫПУСК ПРОДУКЦИИ: ".$plan_name;
echo '<p>';

/** Выполняем запрос SQL для загрузки заявок*/
$sql = "SELECT * FROM work_plan WHERE name = '".$plan_name."';";

/** Если запрос не удачный -> exit */
if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
    exit;
}



while ($plan_data = $result->fetch_assoc()) {
    //читаем в массив из json полученного пост запросом
    $plan = json_decode($plan_data['plan'],true);
    // дата первого дня плана
    (string)$start_date = $plan_data['start_date'];
    //количество строк в таблице = шапка + count($plan)
    $row_count = count($plan);
    // количество столбцов
    $column_cont = count($plan[0]);
    //создаем шапку таблицы
    echo "<table  id='main_table' border='1' style='font-size: 13px'>"
        ."<tr><td> Заявка </td>"
        ."<td> Фильтр </td> ";
    //добавляем ячейки с датами
    for ($td = 0; $td < $column_cont-2; $td++){
        echo "<td>".modify_date($start_date,$td)."</td>";
        $finish_date = modify_date($start_date,$td);
    }
    //конец шапки таблицы
    echo   "</tr><tr>";
    //проходим по бокам массива (количество позиций распланированных)
    for ($x=0; $x < count($plan); $x++){
       // изменение индексов массива с 0,1,9,10,11 на 0,1,2,3...
        $piece_of_plan = array_values($plan[$x]);
       for ($y=0; $y < count($piece_of_plan); $y++) {
           //var_dump($piece_of_plan[$y]);
           echo "<td>".$piece_of_plan[$y];
           echo "</td>";
       }
       echo "</tr>";
    }
}
//закрываем таблицу
echo "</table>";

?>
</div>
<p>
    <button onclick='CallPrint();'>Распечатать план</button><p>
<?php


echo 'ФАКТИЧЕСКИЙ ВЫПУСК ПРОДУКЦИИ: '.$plan_name.'<p>';
//наполняем массив дат которые мы рассматриваем
//array
//  0 => string '13-04-2024' (length=10)
//  1 => string '14-04-2024' (length=10)
//  2 => string '15-04-2024' (length=10) ...
$dates = array();
//наполняем массив дат датами
$dates = fill_dates_in_range($start_date,$finish_date);

//создаем шапку таблицы
echo "<table  id='main_table' style='font-size: 13px'><tr><td> _______ </td><td> Фильтр </td> ";
//заполняем даты
for ($td = 0; $td < $column_cont-2; $td++){
    echo "<td>".$dates[$td]."</td>";
}
echo '</tr>';
//конец шапки таблицы

//массив произведенных фильтров имеет формат:
//array (size=ххх)
//  0 =>
//    array (size=4)
//      0 => string '2024-04-15' (length=10)
//      1 => string '14-22-24' (length=8)
//      2 => string 'AF0167pe' (length=8)
//      3 => string '43' (length=2)

$produced_filters = (get_produced_filters_in_time ($start_date,$finish_date));

//таблицу надо составлять отталкиваясь от названий фильтров
//т.е. берем информацию про один фильтр и его расписываем по дням
//массив куда будут загружены названия всех фильтров из выпуска
$name_filter_array=array();
//выбираем названия фильтров в массив с помощью которого будем сортировать записи массива $produced_filters
foreach ($produced_filters as $value) {
    array_push($name_filter_array, $value[2]);
}
//выбираем уникальные названия фильтров убирая дубликаты
$name_filter_array = array_unique($name_filter_array);
//переименовываем ключи массива в названия фильтров
$name_filter_array = array_fill_keys($name_filter_array,$void = array());
//var_dump($name_filter_array);
//наполняем массив $name_filter_array выпущенной продукцией
foreach ($produced_filters as $value) {
    array_push($name_filter_array[$value[2]], $value);
}

//          0 => string '2024-04-15' (length=10)
//          1 => string '14-22-24' (length=8)
//          2 => string 'AF0167pe' (length=8)
//          3 => string '43' (length=2)

//проходим по массиву $name_filter_array для занесения каждого элемента в таблицу
$marker = 0;
foreach ($name_filter_array as $key => $value){
    echo '<tr>';
    echo '<td>_______</td><td>'.$key.'</td>';

    for ($x=0; $x < count($dates); $x++){//для каждого хначения даты проверяем наличие такой даты в массиве value
        $marker = 0;
        foreach ($value as $value_){

            if ($value_[0] == $dates[$x]){
                echo '<td title="заявка:'.$value_[1].'">'.$value_[3].'   <font size="1"><br></font></td>';
                $marker = 1;
            }
        }

        if ($marker == 0) {
            echo '<td>    <font size="1"><br></font></td>';
        }
    }
}

echo '</table>';


$result->close();
$mysqli->close();