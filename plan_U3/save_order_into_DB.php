<?php
require_once('tools/tools.php');

/** Блок сохранения заявки в БД */
if ($_POST['order_str']) {

   // print_r( $_POST['order_str']);

    echo 'сохранение заявки...';
    /** соединение  с БД*/

    $order = unserialize($_POST['order_str']);
    //$order = json_decode($_POST['order_str']);

    /** Если появляется ошибка unserialize(): Error at offset ххх of ххх bytes in ххх  надо проверить нет ли переноса строки в фчейках Экселевского файла*/
    //print_r_my($order);

    $workshop = $_POST['workshop'];
    if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    $ins = $pdo->prepare("INSERT INTO orders (order_number, workshop, filter, count, marking, personal_packaging, personal_label, group_packaging, packaging_rate, group_label, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach($order as $value) {
        $ins->execute([
            $_POST['order_name'],
            $workshop,
            $value['B'] ?? '', $value['C'] ?? 0, $value['D'] ?? '', $value['E'] ?? '', $value['F'] ?? '',
            $value['G'] ?? '', $value['H'] ?? 0, $value['I'] ?? '', $value['J'] ?? ''
        ]);
    }
    echo 'успешно завершено <p>';
    echo "<a href='main.php'>на главную</a>";

}
?>