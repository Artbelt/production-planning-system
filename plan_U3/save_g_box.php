<?php
header('Content-Type: application/json; charset=utf-8');

try {
    // Подключение к БД
    if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    
    // Получение данных
    $gb_name = $_POST['gb_name'] ?? '';
    $gb_length = $_POST['gb_length'] ?? '';
    $gb_width = $_POST['gb_width'] ?? '';
    $gb_heght = $_POST['gb_heght'] ?? '';
    $gb_supplier = $_POST['gb_supplier'] ?? 'УУ';
    
    // Валидация
    if (empty($gb_name) || empty($gb_length) || empty($gb_width) || empty($gb_heght)) {
        throw new Exception('Не все обязательные поля заполнены');
    }
    
    // Проверка на числа
    if (!is_numeric($gb_length) || !is_numeric($gb_width) || !is_numeric($gb_heght)) {
        throw new Exception('Длина, ширина и высота должны быть числами');
    }
    
    $st = $pdo->prepare("SELECT gb_name FROM g_box WHERE gb_name = ?");
    $st->execute([$gb_name]);
    if ($st->fetch()) {
        throw new Exception('Ящик с номером "' . htmlspecialchars($gb_name) . '" уже существует');
    }
    
    $ins = $pdo->prepare("INSERT INTO g_box (gb_name, gb_length, gb_width, gb_heght, gb_supplier) VALUES (?, ?, ?, ?, ?)");
    $ins->execute([$gb_name, $gb_length, $gb_width, $gb_heght, $gb_supplier]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Ящик успешно добавлен',
        'gb_name' => $gb_name
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}


