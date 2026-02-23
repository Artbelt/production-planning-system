<?php
require_once('tools/tools.php');
require_once('settings.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

if (isset($_POST['date_of_operation']) & ($_POST['date_of_operation'] !='' ) ){
    $input_date = $_POST['date_of_operation'];

    if (isset($_POST['name_of_cap']) & ($_POST['name_of_cap']) != ''){
        $input_cap = $_POST['name_of_cap'];

        if (isset($_POST['input_cap_count']) & ($_POST['input_cap_count'] != '')){
            $input_count= $_POST['input_cap_count'];

            $cap = $_POST['name_of_cap'];
            $cap_count = $_POST['input_cap_count'];
            $st1 = $pdo->prepare("INSERT INTO cap_log (date_of_operation, name_of_cap_field, count_of_caps, cap_action) VALUES (?, ?, ?, 'IN')");
            if (!$st1->execute([$input_date, $input_cap, $input_count])) {
                echo "Ошибка записи в cap_log"; exit;
            }
            $st2 = $pdo->prepare("UPDATE list_of_caps SET cap_count = cap_count + ? WHERE name_of_cap = ?");
            if (!$st2->execute([$cap_count, $cap])) {
                echo "Ошибка обновления list_of_caps"; exit;
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

            $st1 = $pdo->prepare("INSERT INTO cap_log (date_of_operation, name_of_cap_field, count_of_caps, cap_action) VALUES (?, ?, ?, 'FILL')");
            if (!$st1->execute([$input_date, $input_cap, $input_count])) {
                echo "Ошибка записи в cap_log"; exit;
            }
            $st2 = $pdo->prepare("UPDATE list_of_filled_caps SET cap_count = cap_count + ? WHERE name_of_cap = ?");
            if (!$st2->execute([$cap_count, $cap])) {
                echo "Ошибка обновления list_of_filled_caps"; exit;
            }
            $st3 = $pdo->prepare("UPDATE list_of_caps SET cap_count = cap_count - ? WHERE name_of_cap = ?");
            if (!$st3->execute([$cap_count, $cap])) {
                echo "Ошибка обновления list_of_caps"; exit;
            }

        }
    }

}


?>


<script type="text/javascript">
    location.replace("http://192.168.1.111/plan_U3/cap_storage.php");
</script>