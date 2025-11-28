<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $order = $_GET['order'] ?? '';
    
    if (empty($order)) {
        echo json_encode(['success' => false, 'filters' => []]);
        exit;
    }

    // Получаем список фильтров для данной заявки из corrugation_plan
    $stmt = $pdo->prepare("
        SELECT DISTINCT filter_label 
        FROM corrugation_plan 
        WHERE order_number = ? 
          AND filter_label IS NOT NULL 
          AND filter_label != ''
        ORDER BY filter_label
    ");
    $stmt->execute([$order]);
    $filters = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'filters' => $filters
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'filters' => [],
        'error' => $e->getMessage()
    ]);
}




