<?php
require_once('tools/tools.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gb_name = $_POST['gb_name'] ?? '';
    $gb_length = floatval(str_replace(',', '.', $_POST['gb_length'] ?? 0));
    $gb_width = floatval(str_replace(',', '.', $_POST['gb_width'] ?? 0));
    $gb_heght = floatval(str_replace(',', '.', $_POST['gb_heght'] ?? 0));
    $gb_supplier = $_POST['gb_supplier'] ?? '';
    
    // Валидация
    if (empty($gb_name) || $gb_length <= 0 || $gb_width <= 0 || $gb_heght <= 0) {
        echo json_encode(['success' => false, 'error' => 'Не заполнены обязательные поля']);
        exit;
    }
    
    // Подключение к БД plan
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    try {
        // Вставка в таблицу g_box
        $stmt = $pdo->prepare("INSERT INTO g_box (gb_name, gb_length, gb_width, gb_heght, gb_supplier) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$gb_name, $gb_length, $gb_width, $gb_heght, $gb_supplier]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
}
?>


