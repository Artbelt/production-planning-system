<?php
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$order = $_POST['order'] ?? '';
$raw = $_POST['plan_data'] ?? '';

if (!$order || !$raw) {
    echo json_encode(['success' => false, 'message' => 'Отсутствуют данные']);
    exit;
}

try {
    $data = json_decode($raw, true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Ошибка в данных плана']);
        exit;
    }

    // Отладка данных
    error_log("Save plan data: " . json_encode($data));

    $pdo->beginTransaction();

    // Удалим предыдущий план для этой заявки
    $stmt = $pdo->prepare("DELETE FROM corrugation_plan WHERE order_number = ?");
    $stmt->execute([$order]);

    // Вставим новые строки
    $stmt = $pdo->prepare("INSERT INTO corrugation_plan (order_number, plan_date, filter_label, count) VALUES (?, ?, ?, ?)");

    $savedItems = 0;
    foreach ($data as $date => $items) {
        foreach ($items as $item) {
            error_log("Processing item: '$item'");
            
            // Парсим строку формата "Label (123 шт)" или "Label (Infinity шт)"
            if (preg_match('/^(.+?)\s*\((.+?)\s*шт\)$/', trim($item), $matches)) {
                $label = trim($matches[1]);
                $count_str = trim($matches[2]);
                
                // Обрабатываем различные случаи количества
                if (strtolower($count_str) === 'infinity' || !is_numeric($count_str)) {
                    error_log("Skipping item with invalid count: '$item'");
                    continue; // Пропускаем элементы с некорректным количеством
                }
                
                $count = intval($count_str);
                
                // Пропускаем если количество <= 0
                if ($count <= 0) {
                    error_log("Skipping item with zero/negative count: '$item'");
                    continue;
                }
                
                error_log("Parsed: label='$label', count=$count");
                $stmt->execute([$order, $date, $label, $count]);
                $savedItems++;
            } else {
                error_log("Failed to parse item format: '$item'");
            }
        }
    }

    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'План сохранен успешно! Сохранено позиций: ' . $savedItems
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении: ' . $e->getMessage()]);
}
?>
