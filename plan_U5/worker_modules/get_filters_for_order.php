<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan_u5');

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






