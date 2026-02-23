<?php
require_once('tools/tools.php');
require_once('settings.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

if (isset($_POST['date_of_filled_operation']) & ($_POST['date_of_filled_operation'] != '')){
    $input_date = $_POST['date_of_filled_operation'];

    if (isset($_POST['input_filled_cap']) & ($_POST['input_filled_cap'] != '')){
        $input_cap = $_POST['input_filled_cap'];

        if (isset($_POST['input_filled_cap_count'])& ($_POST['input_filled_cap_count'] != '')){
            $input_count= $_POST['input_filled_cap_count'];

            echo $input_date;
            echo $input_cap;
            echo $input_count;
        }
    }

}





if (isset($_POST['name_of_cap'])) {
    $cap = $_POST['name_of_cap'];
    $cap_count = $_POST['input_cap_count'];
    $st = $pdo->prepare("INSERT INTO log (date_of_operation, name_of_cap_field, count_of_caps, cap_action) VALUES (?, ?, ?, 'FILL')");
    if (!$st->execute([$input_date, $input_cap, $input_count])) {
        echo "Ошибка записи"; exit;
    }
}


?>
<script type="text/javascript">
    location.replace("http://192.168.1.90/plan_U3/cap_storage.php");
</script>