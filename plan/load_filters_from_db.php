<?php

if(isset($_GET['filter'])){
    $mysqli = new mysqli('127.0.0.1','root','','plan');
    if ($mysqli->connect_errno){/** Если не получилось подключиться */
        echo 'Возникла проблема на сайте'."Номер ошибки: " . $mysqli->connect_errno . "\n"."Ошибка: " . $mysqli->connect_error . "\n";
        exit;
    }
    if (strlen($_GET['filter'])<2) die();

    $sql = "SELECT filter FROM panel_filter_structure WHERE filter LIKE '%".$_GET['filter']."%'";
        /** Выполняем запрос SQL */
        if (!$result = $mysqli->query($sql)) {
            echo "Ошибка: Наш запрос не удался и вот почему: \n"
                . "Запрос: " . $sql . "\n"
                . "Номер ошибки: " . $mysqli->errno . "\n"
                . "Ошибка: " . $mysqli->error . "\n";
            exit;
        }
    /** извлечение ассоциативного массива */



    echo "<select id='select_filter' width='150' size=".$result->num_rows.">";
    while ($row = $result->fetch_assoc()) {
        echo "<option size = '250' title=".$row['filter'].">".$row['filter']."</option><br>";
    }
    echo "</select>";
    /* удаление выборки */
    $result->free();
}
?>
