<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$order = $_POST['order'] ?? '';
$raw = $_POST['plan_data'] ?? '';

if (!$order || !$raw) {
    die("Ошибка: отсутствуют данные");
}

$data = json_decode($raw, true);

// Удалим предыдущий план для этой заявки
$stmt = $pdo->prepare("DELETE FROM corrugation_plan WHERE order_number = ?");
$stmt->execute([$order]);

// Вставим новые строки
$stmt = $pdo->prepare("INSERT INTO corrugation_plan (order_number, plan_date, filter_label, count) VALUES (?, ?, ?, ?)");

foreach ($data as $date => $items) {
    foreach ($items as $item) {
        if (preg_match('/(.+)\s\((\d+)\sшт\)/', $item, $matches)) {
            $label = $matches[1];
            $count = intval($matches[2]);
            $stmt->execute([$order, $date, $label, $count]);
        }
    }
}

// Перенаправление обратно на страницу планирования
header("Location: ../NP_cut_index.php");
exit;
