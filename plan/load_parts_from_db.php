<?php

if(isset($_GET['part'])){
    $mysqli = new mysqli('127.0.0.1','root','','plan');
    if ($mysqli->connect_errno){/** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'."Номер ошибки: " . $mysqli->connect_errno . "\n"."Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }
    if (strlen($_GET['part'])<2) die();

    $sql = "SELECT p_p_name FROM paper_package_panel WHERE p_p_name LIKE '%".$_GET['part']."%'";
    /** Выполняем запрос SQL */
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }
    /** извлечение ассоциативного массива */



    echo "<select id='select_filter' size=".$result->num_rows.">";
    while ($row = $result->fetch_assoc()) {
        echo "<option>".$row['p_p_name']."</option><br>";
    }
    echo "</select>";
    /* удаление выборки */
    $result->free();
}
?>
