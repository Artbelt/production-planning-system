<?php

require_once('settings.php');
require_once('tools/tools.php');
require_once('style/table_1.txt');

global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;

/** Создаем подключение к БД */
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

/** @var  $plan_name  идентификатор плана*/
$plan_name = $_POST['selected_plan'];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>...</title>
    <style>
        .highlight {
            background-color: yellow; /* Змініть колір підсвічування за потребою */
        }
        table {
            border-collapse: collapse; /* Убираем двойные линии между ячейками */
            margin-right: 20px; /* Добавляем отступ между таблицами */
        }
        td, th {
            padding: 3px; /* Поля вокруг содержимого таблицы */
            border: 1px solid #333; /* Параметры рамки */
        }
        tr:hover {
            background: #65994c; /* Цвет фона при наведении */
            color: #fff; /* Цвет текста при наведении */
        }
        .container {
            display: flex;
        }
    </style>
    <script>
        // Функция, которая сработает при загрузке страницы
        window.onload = function() {
            // Ищем кнопку по ID, замените 'buttonID' на ваш идентификатор кнопки
            var button = document.getElementById('filterButton');

            // Если кнопка найдена, то кликаем по ней
            if (button) {
                button.click();
            }
        };
    </script>
</head>
<body>

<div id="print-content" class="container">
    <?php
    echo '<p>';

    /** Выполняем запрос SQL для загрузки заявок*/
    $sql = "SELECT * FROM work_plan WHERE name = '" . $plan_name . "';";

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    while ($plan_data = $result->fetch_assoc()) {
        //читаем в массив из json полученного пост запросом
        $plan = json_decode($plan_data['plan'], true);
        // дата первого дня плана
        (string)$start_date = $plan_data['start_date'];
        //количество строк в таблице = шапка + count($plan)
        $row_count = count($plan);
        // количество столбцов
        $column_cont = count($plan[0]);
        //создаем шапку таблицы
        echo "<table id='main_table_plan' border='1' style='font-size: 13px'>"
            ."<tr><td colspan='7'>ПЛАН @". $plan_name."</td></tr>"
            . "<tr><td> Заявка </td>"
            . "<td> Фильтр </td> ";
        //добавляем ячейки с датами
        for ($td = 0; $td < $column_cont - 2; $td++) {
            echo "<td>" . modify_date($start_date, $td) . "</td>";
            $finish_date = modify_date($start_date, $td);
        }
        //конец шапки таблицы
        echo "</tr><tr>";
        //проходим по бокам массива (количество позиций распланированных)
        for ($x = 0; $x < count($plan); $x++) {
            // изменение индексов массива с 0,1,9,10,11 на 0,1,2,3...
            $piece_of_plan = array_values($plan[$x]);
            for ($y = 0; $y < count($piece_of_plan); $y++) {
                echo "<td>" . $piece_of_plan[$y];
                echo "</td>";
            }
            echo "</tr>";
        }
    }
    //закрываем таблицу
    echo "</table>";
    ?>

    <?php
    //наполняем массив дат которые мы рассматриваем
    $dates = array();
    //наполняем массив дат датами
    $dates = fill_dates_in_range($start_date, $finish_date);

    //создаем шапку таблицы
    echo "<table id='main_table_fact' style='font-size: 13px'>"
        ."<tr><td colspan='7'>ФАКТ@". $plan_name."</td></tr>".
        "<tr><td> _______ </td><td> Фильтр </td> ";
    //заполняем даты
    for ($td = 0; $td < $column_cont - 2; $td++) {
        echo "<td>" . $dates[$td] . "</td>";
    }
    echo '</tr>';
    //конец шапки таблицы

    //массив произведенных фильтров имеет формат:
    $produced_filters = (get_produced_filters_in_time($start_date, $finish_date));

    //массив куда будут загружены названия всех фильтров из выпуска
    $name_filter_array = array();
    //выбираем названия фильтров в массив с помощью которого будем сортировать записи массива $produced_filters
    foreach ($produced_filters as $value) {
        array_push($name_filter_array, $value[2]);
    }
    //выбираем уникальные названия фильтров убирая дубликаты
    $name_filter_array = array_unique($name_filter_array);
    //переименовываем ключи массива в названия фильтров
    $name_filter_array = array_fill_keys($name_filter_array, $void = array());

    //наполняем массив $name_filter_array выпущенной продукцией
    foreach ($produced_filters as $value) {
        array_push($name_filter_array[$value[2]], $value);
    }

    //проходим по массиву $name_filter_array для занесения каждого элемента в таблицу
    $marker = 0;
    foreach ($name_filter_array as $key => $value) {
        echo '<tr>';
        echo '<td>_______</td><td>' . $key . '</td>';

        for ($x = 0; $x < count($dates); $x++) { //для каждого значения даты проверяем наличие такой даты в массиве value
            $marker = 0;
            foreach ($value as $value_) {
                if ($value_[0] == $dates[$x]) {
                    echo '<td title="заявка:' . $value_[1] . '">' . $value_[3] . '   <font size="1"><br></font></td>';
                    $marker = 1;
                }
            }

            if ($marker == 0) {
                echo '<td>    <font size="1"><br></font></td>';
            }
        }
        echo '</tr>';
    }

    echo '</table>';

    $result->close();
    $mysqli->close();
    ?>
</div>
<p>
    <button onclick='CallPrint();'>Распечатать план</button>
    <button id="filterButton">Убрать пустые значения</button>
</p>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var rows1 = document.querySelectorAll('#main_table_plan tr');
        var rows2 = document.querySelectorAll('#main_table_fact tr');

        rows1.forEach(function(row1) {
            row1.addEventListener('mouseover', function() {
                var filterValue = row1.cells[1]?.innerText; // Проверка наличия ячейки
                if (filterValue) {
                    rows2.forEach(function(row2) {
                        if (row2.cells[1] && row2.cells[1].innerText === filterValue) { // Проверка наличия ячейки
                            row2.classList.add('highlight');
                        } else {
                            row2.classList.remove('highlight');
                        }
                    });
                }
            });

            row1.addEventListener('mouseout', function() {
                rows2.forEach(function(row2) {
                    row2.classList.remove('highlight');
                });
            });
        });
    });
</script>

<script>
    function CallPrint() {
        var time = new Date().toLocaleDateString()
        var newWindow = window.open();
        newWindow.document.write('<title>План производства</title>')
        newWindow.document.write('<style> table {border-collapse: collapse;} td, th {padding: 3px;border: 1px solid #333;} tr:hover {background: #65994c;color: #fff;}</style>')
        newWindow.document.write(document.getElementById("print-content").innerHTML);
        newWindow.document.write('<p>Задание составил:_______________</p>');
        newWindow.document.write('<p>Дата создания: ' + time + '</p>');
        newWindow.print();
        newWindow.close();
    }
</script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const filterButton = document.getElementById("filterButton");
        const table = document.getElementById("main_table_plan");

        filterButton.addEventListener("click", function() {
            const rows = table.getElementsByTagName("tr");
            const dateColumnStartIndex = 2; // Индекс первого столбца с датами (нумерация начинается с нуля)

            for (let i = rows.length - 1; i > 0; i--) { // Перебор строк таблицы с конца к началу
                const cells = rows[i].getElementsByTagName("td");
                let hasData = false;

                for (let j = dateColumnStartIndex; j < cells.length; j++) {
                    if (cells[j].innerText.trim() !== "") {
                        hasData = true;
                        break;
                    }
                }

                if (!hasData) {
                    rows[i].parentNode.removeChild(rows[i]); // Удаление строки, если в столбцах с датами нет данных
                }
            }
        });
    });
</script>

</body>
</html>