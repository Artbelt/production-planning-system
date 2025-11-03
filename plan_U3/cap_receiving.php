<?php
require_once('tools/tools.php');
require_once('settings.php');

$mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);

if (isset($_POST['date_of_operation']) & ($_POST['date_of_operation'] !='' ) ){
    $input_date = $_POST['date_of_operation'];

    if (isset($_POST['name_of_cap']) & ($_POST['name_of_cap']) != ''){
        $input_cap = $_POST['name_of_cap'];

        if (isset($_POST['input_cap_count']) & ($_POST['input_cap_count'] != '')){
            $input_count= $_POST['input_cap_count'];

            $cap = $_POST['name_of_cap'];
            $cap_count = $_POST['input_cap_count'];
            /** Запись поступивших крышек на склад */
            $sql = "INSERT INTO cap_log (date_of_operation, name_of_cap_field, count_of_caps, cap_action) VALUES ('$input_date', '$input_cap', '$input_count','IN');";
            /** Если запрос не удачный -> exit */
            if (!$result = $mysqli->query($sql)) {
                echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }
            // $sql = "SELECT * FROM list_of_caps WHERE name_of_cap = '$cap';";
            $sql = "UPDATE list_of_caps SET cap_count = cap_count +'$cap_count'  WHERE name_of_cap = '$cap';";
            if (!$result = $mysqli->query($sql)) {
                echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;

            }
        }

    }
}

if (isset($_POST['date_of_filled_operation']) & ($_POST['date_of_filled_operation'] != '')){
    $input_date = $_POST['date_of_filled_operation'];

    if (isset($_POST['name_of_filled_cap']) & ($_POST['name_of_filled_cap'] != '')){
        $input_cap = $_POST['name_of_filled_cap'];

        if (isset($_POST['input_filled_cap_count'])& ($_POST['input_filled_cap_count'] != '')){
            $input_count= $_POST['input_filled_cap_count'];

            $cap = $_POST['name_of_filled_cap'];
            $cap_count = $_POST['input_filled_cap_count'];

            /** Запись залитых крышек в лог */
            $sql = "INSERT INTO cap_log (date_of_operation, name_of_cap_field, count_of_caps, cap_action) VALUES ('$input_date', '$input_cap', '$input_count','FILL');";
            /** Если запрос не удачный -> exit */
            if (!$result = $mysqli->query($sql)) {
                echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }

            /** Обновляем данные в тадлице залитой крышки */
            $sql = "UPDATE list_of_filled_caps SET cap_count = cap_count +'$cap_count'  WHERE name_of_cap = '$cap';";
            if (!$result = $mysqli->query($sql)) {
                echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }


             $sql = "SELECT * FROM list_of_caps WHERE name_of_cap = '$cap'; ";
            if (!$result = $mysqli->query($sql)) {
                echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }
             $sql = "UPDATE list_of_caps SET cap_count = cap_count -'$cap_count'  WHERE name_of_cap = '$cap';";
            if (!$result = $mysqli->query($sql)) {
                echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n" . "Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
                exit;
            }

        }
    }

}


?>


<script type="text/javascript">
    location.replace("http://192.168.1.111/plan_U3/cap_storage.php");
</script>