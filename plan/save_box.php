<?php
require_once('tools/tools.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b_name = $_POST['b_name'] ?? '';
    $b_length = floatval(str_replace(',', '.', $_POST['b_length'] ?? 0));
    $b_width = floatval(str_replace(',', '.', $_POST['b_width'] ?? 0));
    $b_heght = floatval(str_replace(',', '.', $_POST['b_heght'] ?? 0));
    $b_supplier = $_POST['b_supplier'] ?? '';
    
    // Валидация
    if (empty($b_name) || $b_length <= 0 || $b_width <= 0 || $b_heght <= 0) {
        echo json_encode(['success' => false, 'error' => 'Не заполнены обязательные поля']);
        exit;
    }
    
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan');
    
    try {
        // Вставка в таблицу box
        $stmt = $pdo->prepare("INSERT INTO box (b_name, b_length, b_width, b_heght, b_supplier) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$b_name, $b_length, $b_width, $b_heght, $b_supplier]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
}
?>


