<?php

if(isset($_GET['filter'])){
    if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
    $mysqli = new mysqli(defined('DB_HOST') ? DB_HOST : '127.0.0.1', defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', 'plan');
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
