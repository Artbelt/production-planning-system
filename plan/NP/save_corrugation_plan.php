<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
$order = isset($_POST['order']) ? trim((string)$_POST['order']) : '';
$raw = isset($_POST['plan_data']) ? (string)$_POST['plan_data'] : '';

if ($order === '' || $raw === '') {
    header('Content-Type: text/plain; charset=utf-8');
    die("Ошибка: отсутствуют данные (order или plan_data)");
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    header('Content-Type: text/plain; charset=utf-8');
    die("Ошибка: неверный формат данных плана (ожидался JSON-объект)");
}

// Удалим предыдущий план для этой заявки
$stmt = $pdo->prepare("DELETE FROM corrugation_plan WHERE order_number = ?");
$stmt->execute([$order]);

// Вставим новые строки
$stmt = $pdo->prepare("INSERT INTO corrugation_plan (order_number, plan_date, filter_label, count) VALUES (?, ?, ?, ?)");

$savedItems = 0;
foreach ($data as $date => $items) {
    foreach ($items as $item) {
        // Парсим строку формата "Label (123 шт)" или "Label (Infinity шт)"
        if (preg_match('/^(.+?)\s*\((.+?)\s*шт\)$/', trim($item), $matches)) {
            $label = trim($matches[1]);
            $count_str = trim($matches[2]);
            
            // Обрабатываем различные случаи количества
            if (strtolower($count_str) === 'infinity' || !is_numeric($count_str)) {
                continue; // Пропускаем элементы с некорректным количеством
            }
            
            $count = intval($count_str);
            
            // Пропускаем если количество <= 0
            if ($count <= 0) {
                continue;
            }
            
            $stmt->execute([$order, $date, $label, $count]);
            $savedItems++;
        }
    }
}

// Устанавливаем статус готовности corrugation плана при завершении
// (кнопка "Завершить" всегда должна отмечать corr_ready = 1)
$stmt = $pdo->prepare("UPDATE orders SET corr_ready = 1 WHERE order_number = ?");
$stmt->execute([$order]);

// Перенаправление обратно на страницу планирования
header("Location: ../NP_cut_index.php");
exit;
