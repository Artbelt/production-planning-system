<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $filter = $_GET['filter'] ?? '';
    
    if (empty($filter)) {
        echo json_encode(['success' => false, 'orders' => []]);
        exit;
    }

    // Получаем список заявок для данного фильтра из corrugation_plan
    $stmt = $pdo->prepare("
        SELECT DISTINCT order_number 
        FROM corrugation_plan 
        WHERE filter_label = ? 
          AND order_number IS NOT NULL 
          AND order_number != ''
          AND plan_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY order_number DESC
    ");
    $stmt->execute([$filter]);
    $orders = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'orders' => [],
        'error' => $e->getMessage()
    ]);
}






