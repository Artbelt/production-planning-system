<?php
/** Блок сохранения заявки в БД */
if ($_POST['order_str']) {
    echo 'сохранение заявки...';
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan');
    $order = unserialize($_POST['order_str']);
    $workshop = $_POST['workshop'];
    $stmt = $pdo->prepare("INSERT INTO orders (order_number, workshop, filter, count, marking, personal_packaging, personal_label, group_packaging, packaging_rate, group_label, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach($order as $value) {
        $stmt->execute([
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