
<?php

//header("Location: http://localhost/plan_U3/cap_storage.php");


require_once('tools/tools.php');
require_once('settings.php');

$mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);




/** Разметка таблицы экрана */
echo "<table><tr>";


/** Выводим склад на экран */
    /** Разметка таблицы экрана */
    echo "<td valign='top'>";

    /**------------------------------------ ШАпка таблицы склад-------------------------*/

    echo "<b>Склад не залитой крышки:</b>";

    /** Открываем контейнер для таблицы с ограниченной высотой и полосой прокрутки */
    echo "<div style='height: 50vh; overflow-y: scroll; border: 1px solid black'>";  // 50% от высоты экрана

    echo "<table border='1' cellpadding='3'>"; // Открываем таблицу

    /** Выполняем запрос SQL выборка из таблицы данных фильтров */
    $sql = "SELECT * FROM list_of_caps order by name_of_cap ASC;";

    /** Если запрос не удачный -> exit */
    if (!$result = $mysqli->query($sql)){
        echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    /** Разбор массива значений */
    while ($list_of_caps = $result->fetch_assoc()) {
        $cap = $list_of_caps['name_of_cap'];
        $cap_count = $list_of_caps['cap_count'];
        echo "<tr>";
        echo "<td>".$cap."</td>";
        echo "<td>".$cap_count." шт</td>";
        echo "</tr>";
    }

    $result->close();

    echo "</table>";
    echo "</div>";  // Закрываем контейнер


    /** Разметка таблицы экрана */
    echo "</td><td valign='top'>";

    /**------------------------------- ШАпка таблицы приход крышки----------------------------------*/
    echo "<table border='1' cellpadding='3'>";
    echo "<b>Поступление крышки без уплотнителя:</b>";
    echo "<tr bgcolor='white'><td>";
    echo "<form action='cap_receiving.php' method='post'>";
    echo "<textarea  rows='20' cols='53'>";
    load_cap_history('input');
    echo "</textarea>";
    echo "<br>";
    echo "<input type='date' id='date_of_operation' name='date_of_operation'/>";
    load_caps('');
    echo "<input type = 'text' name = 'input_cap_count' size='5'>";
    echo "<input type='submit' value='Принять на склад'>";
    echo "</form";
    echo "</td></tr>";
    echo "</tr></table>";

    /** Разметка таблицы экрана */
    echo "</td><td valign='top'>";

    /** ------------------------------ШАпка таблицы заливка уплотнителя------------------------------*/
echo "<div style='height: 50vh; overflow-y: scroll; border: 1px solid black'>";
echo "<table bgcolor='#a9a9a9'>";
echo "<b>Склад залитой крышки:</b>";

/** Выполняем запрос SQL  выборка из таблицы данных фильтров*/
$sql = "SELECT * FROM list_of_filled_caps order by name_of_cap ASC;";

/** Если запрос не удачный -> exit */
if (!$result = $mysqli->query($sql)){ echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n"; exit; }

/** Разбор массива значений  */

while ($list_of_caps = $result->fetch_assoc()) {
    $cap = $list_of_caps['name_of_cap'];
    $cap_count = $list_of_caps['cap_count'];
    echo "<tr  bgcolor='white'>";
    echo "<td>".$cap."</td>";
    echo "<td>".$cap_count." шт</td>";
    echo "</tr>";
}



$result->close();
echo "</table>";
echo "</div>";
/** Разметка таблицы экрана */
echo "</td><td valign='top'>";

/**------------------------------- ШАпка таблицы приход крышки----------------------------------*/
echo "<table bgcolor='#a9a9a9'>";
echo "<b>Заливка уплотнителя крышки:</b>";
echo "<tr bgcolor='white'><td>";

echo "<form action='cap_receiving.php' method='post'>";
echo "<textarea rows='20' cols='53'>";
load_cap_history('filled');
echo "</textarea>";
echo "<br>";
echo "<input type='date' id='date_of_operation' name='date_of_filled_operation'/>";

load_filled_caps();

echo "<input type = 'text' name = 'input_filled_cap_count' size='5'>";
echo "<input type='submit' value='Принять на склад'>";
echo "</form";

echo "</td><td></td></tr>";
echo "</tr></table>";

/** Разметка таблицы экрана */
echo "</td><td valign='top'>";



/** Разметка таблицы экрана */
echo "</tr></table>";
?>

<a href="http://localhost/plan_U3/enter.php">назад</a>
